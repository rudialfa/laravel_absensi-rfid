<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\School;
use App\Models\User;
use App\Support\SchoolContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TestCardKeyPrevious extends Command
{
    protected $signature = 'school4:test {--url=http://127.0.0.1:8000}';
    protected $description = 'Uji kunci kartu sebelumnya untuk ESP32 (HANYA project uji coba)';

    private const SEKOLAH       = 'Sekolah Uji 6c5';
    private const SEKOLAH_KOSONG = 'Sekolah Uji 6c5 Kosong';
    private const EMAIL_SUPER   = 'super-uji6c5@contoh.test';
    private const KUNCI_MANUAL  = 'AB12CD34EF56';

    private int $lulus = 0;
    private int $gagal = 0;
    private string $base = '';

    public function handle(): int
    {
        if (!app()->environment('local')) {
            $this->error('Hanya boleh dijalankan di environment local.');
            return self::FAILURE;
        }

        $this->base = rtrim($this->option('url'), '/') . '/api';

        if (!School::exists()) {
            $this->error('Belum ada sekolah. Jalankan php artisan migrate dulu.');
            return self::FAILURE;
        }

        $this->bersihkan();

        $pwSuper = Str::random(14);
        User::create([
            'name' => 'Super Uji 6c5',
            'email' => self::EMAIL_SUPER,
            'role' => 'super_admin',
            'password' => Hash::make($pwSuper),
        ]);

        $r = $this->api('POST', '/login', null, ['email' => self::EMAIL_SUPER, 'password' => $pwSuper]);
        $tokS = $r->json('token');

        if (!$tokS) {
            $this->error('Login super admin uji gagal, tes dihentikan.');
            $this->bersihkan();
            return self::FAILURE;
        }

        // ---- Sekolah dengan kunci pertama ----
        $this->line('--- Kunci sebelumnya ---');
        $r = $this->api('POST', '/super/schools', $tokS, [
            'name' => self::SEKOLAH,
            'admin_name' => 'Admin Uji',
            'admin_email' => 'admin-uji6c5@contoh.test',
        ]);
        $id = $r->json('data.id');
        $keyDev = Str::random(40);
        SchoolContext::run($id, fn() => Device::create(['name' => 'Perangkat Uji 6c5', 'api_key_hash' => hash('sha256', $keyDev)]));

        $kunci1 = School::find($id)?->card_key;

        $r = $this->api('GET', '/device/card-key', null, [], $keyDev);
        $this->cek('Sekolah baru: kunci ada, kunci sebelumnya kosong', $r->status() === 200 && $r->json('card_key') === $kunci1 && $r->json('previous_card_key') === null);

        // ---- Putar kunci ----
        $r = $this->api('POST', "/super/schools/{$id}/card-key/rotate", $tokS);
        $this->cek('Putar kunci (200)', $r->status() === 200);

        $r = $this->api('GET', '/device/card-key', null, [], $keyDev);
        $kunci2 = $r->json('card_key');
        $this->cek('Setelah putar: kunci baru, kunci lama jadi "sebelumnya"', $r->status() === 200 && $kunci2 !== $kunci1 && $r->json('previous_card_key') === $kunci1);

        $this->api('POST', "/super/schools/{$id}/card-key/rotate", $tokS);
        $r = $this->api('GET', '/device/card-key', null, [], $keyDev);
        $kunci3 = $r->json('card_key');
        $this->cek('Putar lagi: yang disimpan hanya satu kunci sebelumnya (kunci pertama hilang)', $kunci3 !== $kunci2 && $r->json('previous_card_key') === $kunci2);

        // ---- Isi manual ----
        $this->line('--- Isi kunci manual ---');
        $r = $this->api('PUT', "/super/schools/{$id}/card-key", $tokS, ['card_key' => strtolower(self::KUNCI_MANUAL)]);
        $this->cek('Isi kunci manual (200)', $r->status() === 200);

        $r = $this->api('GET', '/device/card-key', null, [], $keyDev);
        $this->cek('Kunci manual aktif (huruf besar), kunci sebelumnya terisi', $r->json('card_key') === self::KUNCI_MANUAL && $r->json('previous_card_key') === $kunci3);

        $this->api('PUT', "/super/schools/{$id}/card-key", $tokS, ['card_key' => self::KUNCI_MANUAL]);
        $r = $this->api('GET', '/device/card-key', null, [], $keyDev);
        $this->cek('Mengisi kunci yang sama tidak mengubah kunci sebelumnya', $r->json('card_key') === self::KUNCI_MANUAL && $r->json('previous_card_key') === $kunci3);

        $this->api('PUT', "/super/schools/{$id}/card-key", $tokS, ['card_key' => $kunci3]);
        $r = $this->api('GET', '/device/card-key', null, [], $keyDev);
        $this->cek('Kembali ke kunci sebelumnya: keduanya bertukar', $r->json('card_key') === $kunci3 && $r->json('previous_card_key') === self::KUNCI_MANUAL);

        // ---- Penyimpanan dan kebocoran ----
        $this->line('--- Penyimpanan ---');
        $mentah = DB::table('schools')->where('id', $id)->value('card_key_previous');
        $this->cek('Kunci sebelumnya tersimpan terenkripsi', is_string($mentah) && $mentah !== self::KUNCI_MANUAL && !preg_match('/^[0-9A-F]{12}$/', $mentah));

        $r = $this->api('GET', '/super/schools', $tokS);
        $this->cek('Daftar sekolah tidak memuat kunci apa pun', $r->status() === 200 && !str_contains($r->body(), 'previous') && !str_contains($r->body(), '"card_key"'));

        $r = $this->api('GET', '/device/card-key');
        $this->cek('Tanpa device key tetap ditolak (401)', $r->status() === 401);

        // ---- Sekolah tanpa kunci ----
        $this->line('--- Sekolah yang belum punya kunci ---');
        $kosong = School::create(['name' => self::SEKOLAH_KOSONG, 'is_active' => true]);
        $keyKosong = Str::random(40);
        SchoolContext::run($kosong->id, fn() => Device::create(['name' => 'Perangkat Kosong 6c5', 'api_key_hash' => hash('sha256', $keyKosong)]));

        $r = $this->api('GET', '/device/card-key', null, [], $keyKosong);
        $this->cek('Belum ada kunci (404, NO_CARD_KEY)', $r->status() === 404 && $r->json('code') === 'NO_CARD_KEY');

        $r = $this->api('PUT', "/super/schools/{$kosong->id}/card-key", $tokS, ['card_key' => self::KUNCI_MANUAL]);
        $this->cek('Pengisian pertama (200)', $r->status() === 200);

        $r = $this->api('GET', '/device/card-key', null, [], $keyKosong);
        $this->cek('Kunci pertama aktif, tanpa kunci sebelumnya', $r->status() === 200 && $r->json('card_key') === self::KUNCI_MANUAL && $r->json('previous_card_key') === null);

        $r = $this->api('GET', '/device/card-key', null, [], $keyDev);
        $this->cek('Sekolah lain tidak terpengaruh (kunci berbeda)', $r->json('card_key') === $kunci3);

        $this->bersihkan();

        $this->newLine();
        $this->info("Lulus: {$this->lulus}   Gagal: {$this->gagal}");

        return $this->gagal === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function api(string $method, string $path, ?string $token = null, array $data = [], ?string $deviceKey = null)
    {
        $req = Http::acceptJson();

        if ($token) {
            $req = $req->withToken($token);
        }
        if ($deviceKey) {
            $req = $req->withHeaders(['X-Device-Key' => $deviceKey]);
        }

        $options = $method === 'GET' ? ['query' => $data] : ['json' => $data];

        $res = $req->send($method, $this->base . $path, $options);

        // Batas login 10 per menit: tunggu sesuai Retry-After, lalu ulangi sekali
        if ($res->status() === 429) {
            $tunggu = max(1, min((int) $res->header('Retry-After'), 65));
            $this->warn("Batas percobaan tercapai, menunggu {$tunggu} detik...");
            sleep($tunggu + 1);
            $res = $req->send($method, $this->base . $path, $options);
        }

        return $res;
    }

    private function cek(string $nama, bool $ok): void
    {
        $ok ? $this->lulus++ : $this->gagal++;
        $this->line(($ok ? '<info>LULUS</info> ' : '<error>GAGAL</error> ') . $nama);
    }

    /** Hapus semua data uji, termasuk sisa dari tes yang terhenti. */
    private function bersihkan(): void
    {
        $users = User::withoutGlobalScopes()
            ->where(function ($q) {
                $q->where('email', self::EMAIL_SUPER)->orWhere('email', 'admin-uji6c5@contoh.test');
            })
            ->get();

        foreach ($users as $user) {
            $user->tokens()->delete();
            $user->delete();
        }

        $sekolah = School::whereIn('name', [self::SEKOLAH, self::SEKOLAH_KOSONG])->get();

        foreach ($sekolah as $s) {
            DB::table('users')->where('school_id', $s->id)->delete();
            Device::withoutGlobalScopes()->where('school_id', $s->id)->delete();
            DB::table('school_settings')->where('school_id', $s->id)->delete();
            $s->delete();
        }
    }
}
