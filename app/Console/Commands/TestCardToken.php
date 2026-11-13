<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\EnrollController;
use App\Models\Attendance;
use App\Models\Setting;
use App\Models\Student;
use App\Services\CardToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TestCardToken extends Command
{
    protected $signature = 'token:test {--url=http://127.0.0.1:8000}';
    protected $description = 'Uji token kartu anti clone (HANYA project uji coba)';

    private int $lulus = 0;
    private int $gagal = 0;
    private string $base = '';
    private string $deviceKey = '';

    private const UID_LAMA   = 'DDDD0001'; // kartu lama, tanpa token
    private const UID_BARU   = 'DDDD0002'; // didaftarkan dengan token
    private const UID_LAIN   = 'DDDD0009'; // tidak ada pendaftaran yang menunggu
    private const UID_TANPA  = 'DDDD0003'; // murid tanpa token
    private const UID_GANTI1 = 'DDDD0004'; // kartu pengganti bertoken
    private const UID_GANTI2 = 'DDDD0005'; // kartu pengganti tanpa konfirmasi token
    private const UID_ABORT  = 'DDDD0006'; // pendaftaran dibatalkan

    private const SEMUA = [
        self::UID_LAMA,
        self::UID_BARU,
        self::UID_LAIN,
        self::UID_TANPA,
        self::UID_GANTI1,
        self::UID_GANTI2,
        self::UID_ABORT,
    ];

    public function handle(): int
    {
        if (!app()->environment('local')) {
            $this->error('Hanya boleh dijalankan di environment local.');
            return self::FAILURE;
        }

        $this->base = rtrim($this->option('url'), '/') . '/api';

        $pwAdmin = $this->secret('Password admin (admin@contoh.test)');
        $this->deviceKey = (string) $this->secret('Device key');

        $this->bersihkan();
        $awalRequire = Setting::get('require_card_token', '0');
        $this->setelan('require_card_token', '0');

        $tokenAdmin = $this->api('POST', '/login', null, ['email' => 'admin@contoh.test', 'password' => $pwAdmin])->json('token');
        if (!$tokenAdmin) {
            $this->error('Login gagal. Cek password (ketik manual, jangan paste).');
            return self::FAILURE;
        }

        if ($this->tap('xyz')->status() === 401) {
            $this->error('Device key salah. Tes dihentikan.');
            return self::FAILURE;
        }

        // ---- 1. Kartu lama ----
        $this->line('--- Kartu lama (tanpa token), require mati ---');
        $this->murid(self::UID_LAMA, 'Murid Lama');
        $r = $this->tap(self::UID_LAMA);
        $this->cek('Kartu lama tanpa token diterima (200, ALREADY_OUT)', $r->status() === 200 && $r->json('code') === 'ALREADY_OUT');

        // ---- 2. Pendaftaran token ----
        $this->line('--- Pendaftaran token ---');
        $this->api('POST', '/enroll/start', $tokenAdmin);
        $r = $this->tap(self::UID_BARU);
        $token1 = $r->json('write_token');
        $this->cek(
            'Kartu baru di mode daftar mendapat write_token (32 hex)',
            $r->status() === 200 && $r->json('code') === 'ENROLL_CAPTURED'
                && is_string($token1) && preg_match('/^[0-9a-f]{32}$/', $token1) === 1
        );

        $s = $this->api('GET', '/enroll/status', $tokenAdmin);
        $this->cek(
            'Status admin: menunggu konfirmasi, token tidak bocor',
            $s->json('card_token.confirmed') === false && is_string($token1) && !str_contains($s->body(), $token1)
        );

        $r = $this->konfirmasi(self::UID_LAIN);
        $this->cek('Konfirmasi UID lain ditolak (404, NO_PENDING)', $r->status() === 404 && $r->json('code') === 'NO_PENDING');

        $r = $this->konfirmasi(self::UID_BARU, false);
        $this->cek('Konfirmasi tanpa device key (401)', $r->status() === 401);

        $r = $this->konfirmasi(self::UID_BARU);
        $this->cek('Konfirmasi benar (200, ENROLL_CONFIRMED)', $r->status() === 200 && $r->json('code') === 'ENROLL_CONFIRMED');

        $s = $this->api('GET', '/enroll/status', $tokenAdmin);
        $this->cek('Status admin: token terkonfirmasi', $s->json('card_token.confirmed') === true);

        $r = $this->api('POST', '/students', $tokenAdmin, ['name' => 'Murid Baru', 'class' => 'X-T', 'rfid_uid' => self::UID_BARU]);
        $baru = Student::where('rfid_uid', self::UID_BARU)->first();
        $this->cek('Tambah murid dari hasil scan (201, has_token true)', $r->status() === 201 && $r->json('data.has_token') === true && $baru !== null);

        if (!$baru) {
            $this->error('Murid baru tidak terbuat, tes dihentikan.');
            $this->selesai($awalRequire);
            return self::FAILURE;
        }
        $this->absenLengkap($baru);

        $this->cek('Yang tersimpan adalah hash token, bukan token mentah', $baru->card_token_hash === hash('sha256', $token1) && $baru->card_token_hash !== $token1);

        $s = $this->api('GET', '/enroll/status', $tokenAdmin);
        $this->cek('Token tertunda dibersihkan setelah dipakai', $s->json('card_token') === null);

        // ---- 3. Verifikasi di /tap ----
        $this->line('--- Verifikasi token di /tap ---');
        $r = $this->tap(self::UID_BARU, $token1);
        $this->cek('UID + token benar (200, ALREADY_OUT)', $r->status() === 200 && $r->json('code') === 'ALREADY_OUT');

        $r = $this->tap(self::UID_BARU);
        $this->cek('Kartu clone, hanya UID (403, TOKEN_INVALID)', $r->status() === 403 && $r->json('code') === 'TOKEN_INVALID');

        $r = $this->tap(self::UID_BARU, bin2hex(random_bytes(16)));
        $this->cek('Token salah (403, TOKEN_INVALID)', $r->status() === 403 && $r->json('code') === 'TOKEN_INVALID');

        $r = $this->tap(self::UID_BARU, 'xyz');
        $this->cek('Token ngawur (403, TOKEN_INVALID)', $r->status() === 403 && $r->json('code') === 'TOKEN_INVALID');

        $r = $this->tap(self::UID_BARU, strtoupper($token1));
        $this->cek('Token huruf besar tetap diterima (200)', $r->status() === 200 && $r->json('code') === 'ALREADY_OUT');

        $this->cek('Tap ditolak tidak mengubah absensi', Attendance::where('student_id', $baru->id)->count() === 1);

        // ---- 4. require_card_token ----
        $this->line('--- Setelan require_card_token ---');
        $this->setelan('require_card_token', '1');

        $r = $this->tap(self::UID_LAMA);
        $this->cek('Kartu lama saat require hidup (403, TOKEN_REQUIRED)', $r->status() === 403 && $r->json('code') === 'TOKEN_REQUIRED');

        $r = $this->tap(self::UID_BARU, $token1);
        $this->cek('Kartu bertoken tetap diterima (200)', $r->status() === 200 && $r->json('code') === 'ALREADY_OUT');

        $r = $this->api('POST', '/students', $tokenAdmin, ['name' => 'Tanpa Token', 'class' => 'X-T', 'rfid_uid' => self::UID_TANPA]);
        $this->cek(
            'Tambah murid tanpa token saat require hidup (422, TOKEN_NOT_READY)',
            $r->status() === 422 && $r->json('code') === 'TOKEN_NOT_READY' && !Student::where('rfid_uid', self::UID_TANPA)->exists()
        );

        $this->setelan('require_card_token', '0');
        $r = $this->api('POST', '/students', $tokenAdmin, ['name' => 'Tanpa Token', 'class' => 'X-T', 'rfid_uid' => self::UID_TANPA]);
        $this->cek('Tambah murid tanpa token saat require mati (201, has_token false)', $r->status() === 201 && $r->json('data.has_token') === false);

        // ---- 5. Kartu terdaftar tidak diberi token ----
        $this->line('--- Kartu yang sudah terdaftar ---');
        $this->api('POST', '/enroll/start', $tokenAdmin);
        $r = $this->tap(self::UID_BARU);
        $this->api('POST', '/enroll/cancel', $tokenAdmin);
        $this->cek(
            'Kartu terdaftar tidak diberi write_token, token lama utuh',
            $r->json('code') === 'ENROLL_CAPTURED' && !array_key_exists('write_token', $r->json())
                && Student::find($baru->id)->card_token_hash === hash('sha256', $token1)
        );

        // ---- 6. Ganti kartu ----
        $this->line('--- Ganti kartu ---');
        $token2 = $this->scan($tokenAdmin, self::UID_GANTI1);
        $this->konfirmasi(self::UID_GANTI1);
        $r = $this->api('POST', "/students/{$baru->id}/replace-card", $tokenAdmin);
        $this->cek(
            'Ganti kartu dengan token terkonfirmasi (200, token baru terpasang)',
            $r->status() === 200 && $r->json('data.has_token') === true
                && is_string($token2) && Student::find($baru->id)->card_token_hash === hash('sha256', $token2)
        );

        $r = $this->tap(self::UID_BARU, $token1);
        $this->cek('Kartu lama tidak berlaku (404, CARD_NOT_FOUND)', $r->status() === 404 && $r->json('code') === 'CARD_NOT_FOUND');

        $r = $this->tap(self::UID_GANTI1, $token2);
        $this->cek('Kartu baru + token baru diterima (200)', $r->status() === 200 && $r->json('code') === 'ALREADY_OUT');

        $r = $this->tap(self::UID_GANTI1, $token1);
        $this->cek('Kartu baru + token lama ditolak (403, TOKEN_INVALID)', $r->status() === 403 && $r->json('code') === 'TOKEN_INVALID');

        $this->scan($tokenAdmin, self::UID_GANTI2); // token tidak dikonfirmasi
        $r = $this->api('POST', "/students/{$baru->id}/replace-card", $tokenAdmin);
        $this->cek(
            'Ganti ke kartu tanpa token terkonfirmasi (token lama dihapus)',
            $r->status() === 200 && $r->json('data.has_token') === false && Student::find($baru->id)->card_token_hash === null
        );

        $r = $this->tap(self::UID_GANTI2);
        $this->cek('Kartu tanpa token diterima saat require mati (200)', $r->status() === 200 && $r->json('code') === 'ALREADY_OUT');

        // ---- 7. Batal ----
        $this->line('--- Pendaftaran dibatalkan ---');
        $this->scan($tokenAdmin, self::UID_ABORT);
        $r = $this->konfirmasi(self::UID_ABORT, true, false);
        $s = $this->api('GET', '/enroll/status', $tokenAdmin);
        $this->cek('Batal dari ESP32 (200, ENROLL_ABORTED)', $r->status() === 200 && $r->json('code') === 'ENROLL_ABORTED');
        $this->cek('Token tertunda dibuang setelah batal', $s->json('card_token') === null);

        $this->selesai($awalRequire);

        $this->newLine();
        $this->info("Lulus: {$this->lulus}   Gagal: {$this->gagal}");

        return $this->gagal === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function selesai(string $awalRequire): void
    {
        $this->setelan('require_card_token', $awalRequire);
        $this->bersihkan();
    }

    private function bersihkan(): void
    {
        Cache::forget(EnrollController::KEY_ACTIVE);
        Cache::forget(EnrollController::KEY_CAPTURED);
        CardToken::discard();
        // Absensi ikut terhapus otomatis (cascade)
        Student::whereIn('rfid_uid', self::SEMUA)->delete();
    }

    private function setelan(string $key, string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    private function murid(string $uid, string $nama): Student
    {
        $s = Student::create(['name' => $nama, 'class' => 'X-T', 'rfid_uid' => $uid]);
        $this->absenLengkap($s);

        return $s;
    }

    // Absensi sudah lengkap supaya tap yang lolos selalu menjawab ALREADY_OUT (tanpa notifikasi)
    private function absenLengkap(Student $s): void
    {
        Attendance::create([
            'student_id'     => $s->id,
            'date'           => now()->toDateString(),
            'checked_in_at'  => now(),
            'checked_out_at' => now(),
            'status'         => 'hadir',
        ]);
    }

    private function scan(string $tokenAdmin, string $uid): ?string
    {
        $this->api('POST', '/enroll/start', $tokenAdmin);

        return $this->tap($uid)->json('write_token');
    }

    private function api(string $method, string $path, ?string $token = null, array $data = [])
    {
        $req = Http::acceptJson();
        if ($token) {
            $req = $req->withToken($token);
        }

        $options = $method === 'GET' ? ['query' => $data] : ['json' => $data];

        return $req->send($method, $this->base . $path, $options);
    }

    private function tap(string $uid, ?string $token = null)
    {
        $body = ['uid' => $uid];
        if ($token !== null) {
            $body['token'] = $token;
        }

        return Http::acceptJson()
            ->withHeaders(['X-Device-Key' => $this->deviceKey])
            ->post($this->base . '/device/tap', $body);
    }

    private function konfirmasi(string $uid, bool $denganKunci = true, bool $ok = true)
    {
        $req = Http::acceptJson();
        if ($denganKunci) {
            $req = $req->withHeaders(['X-Device-Key' => $this->deviceKey]);
        }

        return $req->post($this->base . '/device/enroll-confirm', ['uid' => $uid, 'ok' => $ok]);
    }

    private function cek(string $nama, bool $ok): void
    {
        $ok ? $this->lulus++ : $this->gagal++;
        $this->line(($ok ? '<info>LULUS</info> ' : '<error>GAGAL</error> ') . $nama);
    }
}
