<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\School;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TestSuperAdmin extends Command
{
    protected $signature = 'school3:test {--url=http://127.0.0.1:8000}';
    protected $description = 'Uji endpoint super admin dan kunci kartu per sekolah (HANYA project uji coba)';

    private const SEKOLAH     = 'Sekolah Uji 6c3';
    private const SEKOLAH_BARU = 'Sekolah Uji 6c3 Baru';
    private const SEKOLAH_KEDUA = 'Sekolah Uji 6c3 Kedua';
    private const EMAIL_SUPER  = 'super-uji6c3@contoh.test';
    private const EMAIL_ADMIN  = 'admin-uji6c3@contoh.test';
    private const EMAIL_ADMIN2 = 'admin2-uji6c3@contoh.test';

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

        $a = School::orderBy('id')->first();
        if (!$a) {
            $this->error('Belum ada sekolah. Jalankan php artisan migrate dulu.');
            return self::FAILURE;
        }

        $pwAdminA = $this->secret('Password admin sekolah pertama (admin@contoh.test)');

        $this->bersihkan();

        $pwSuper = Str::random(14);
        User::create([
            'name' => 'Super Uji 6c3',
            'email' => self::EMAIL_SUPER,
            'role' => 'super_admin',
            'password' => Hash::make($pwSuper),
        ]);

        $r = $this->api('POST', '/login', null, ['email' => 'admin@contoh.test', 'password' => $pwAdminA]);
        $tokA = $r->json('token');
        $r = $this->api('POST', '/login', null, ['email' => self::EMAIL_SUPER, 'password' => $pwSuper]);
        $tokS = $r->json('token');

        if (!$tokA || !$tokS) {
            $this->error('Login gagal, tes dihentikan. Cek password admin sekolah pertama.');
            $this->bersihkan();
            return self::FAILURE;
        }

        // ---- Akses ----
        $this->line('--- Akses ---');
        $r = $this->api('GET', '/super/schools');
        $this->cek('Tanpa token (401)', $r->status() === 401);

        $r = $this->api('GET', '/super/schools', $tokA);
        $this->cek('Admin sekolah ditolak (403, FORBIDDEN)', $r->status() === 403 && $r->json('code') === 'FORBIDDEN');

        $r = $this->api('POST', '/super/schools', $tokA, ['name' => 'Coba', 'admin_name' => 'x', 'admin_email' => 'x@contoh.test']);
        $this->cek('Admin sekolah tidak bisa membuat sekolah (403)', $r->status() === 403 && !School::where('name', 'Coba')->exists());

        // ---- Daftar sekolah ----
        $this->line('--- Daftar sekolah ---');
        $r = $this->api('GET', '/super/schools', $tokS);
        $pertama = collect($r->json('data') ?? [])->firstWhere('id', $a->id);
        $this->cek('Super admin melihat daftar (200, sekolah pertama ada)', $r->status() === 200 && $pertama && $pertama['name'] === $a->name);
        $this->cek('Daftar memuat jumlah murid, guru, perangkat, admin', $pertama && isset($pertama['students'], $pertama['teachers'], $pertama['devices'], $pertama['admins']));
        $this->cek('Kunci kartu tidak pernah ikut di daftar', $pertama && !array_key_exists('card_key', $pertama) && !str_contains($r->body(), '"card_key"'));

        // ---- Tambah sekolah ----
        $this->line('--- Tambah sekolah ---');
        $r = $this->api('POST', '/super/schools', $tokS, [
            'name' => self::SEKOLAH,
            'admin_name' => 'Admin Uji 6c3',
            'admin_email' => strtoupper(self::EMAIL_ADMIN),
        ]);
        $idB = $r->json('data.id');
        $pwAdmin = $r->json('admin.password');
        $this->cek('Sekolah dibuat (201, admin dan password 12 karakter)', $r->status() === 201 && $idB && is_string($pwAdmin) && strlen($pwAdmin) === 12);
        $this->cek('Email admin disimpan huruf kecil', $r->json('admin.email') === self::EMAIL_ADMIN);
        $this->cek('Kunci kartu ada tapi tidak ikut di jawaban', $r->json('data.has_card_key') === true && !str_contains($r->body(), '"card_key"'));

        $mentah = DB::table('schools')->where('id', $idB)->value('card_key');
        $asli = School::find($idB)?->card_key;
        $this->cek('Kunci kartu tersimpan terenkripsi (12 hex saat dibaca, bukan di database)', is_string($asli) && preg_match('/^[0-9A-F]{12}$/', $asli) === 1 && $mentah !== $asli);

        $this->cek('Setelan awal terisi (5 baris)', DB::table('school_settings')->where('school_id', $idB)->count() === 5);

        $r = $this->api('POST', '/super/schools', $tokS, ['name' => strtolower(self::SEKOLAH), 'admin_name' => 'x', 'admin_email' => 'lain-uji6c3@contoh.test']);
        $this->cek('Nama sekolah sama beda huruf (422, SCHOOL_EXISTS)', $r->status() === 422 && $r->json('code') === 'SCHOOL_EXISTS');

        $r = $this->api('POST', '/super/schools', $tokS, ['name' => self::SEKOLAH_KEDUA, 'admin_name' => 'x', 'admin_email' => 'admin@contoh.test']);
        $this->cek('Email admin sudah dipakai (422, EMAIL_TAKEN, sekolah tidak terbuat)', $r->status() === 422 && $r->json('code') === 'EMAIL_TAKEN' && !School::where('name', self::SEKOLAH_KEDUA)->exists());

        $r = $this->api('POST', '/super/schools', $tokS, ['name' => self::SEKOLAH_KEDUA, 'admin_name' => 'x', 'admin_email' => 'bukan-email']);
        $this->cek('Email tidak valid (422, VALIDATION)', $r->status() === 422 && $r->json('code') === 'VALIDATION');

        // ---- Admin sekolah baru ----
        $this->line('--- Admin sekolah baru ---');
        $r = $this->api('POST', '/login', null, ['email' => self::EMAIL_ADMIN, 'password' => $pwAdmin]);
        $tokAdm = $r->json('token');
        $this->cek('Admin baru bisa login (200, nama sekolah benar)', $r->status() === 200 && $tokAdm && $r->json('user.school') === self::SEKOLAH);

        $r = $this->api('GET', '/settings', $tokAdm);
        $this->cek('Setelan awal terbaca (07:15, cooldown 10)', $r->status() === 200 && $r->json('data.late_time') === '07:15' && $r->json('data.cooldown_seconds') === 10);

        $r = $this->api('GET', '/students', $tokAdm);
        $this->cek('Belum ada murid (data kosong)', $r->status() === 200 && ($r->json('data') ?? []) === []);

        $r = $this->api('POST', '/devices', $tokAdm, ['name' => 'Perangkat Uji 6c3']);
        $keyDev = $r->json('device_key');
        $this->cek('Admin baru menambah perangkat (201, key 40 karakter)', $r->status() === 201 && is_string($keyDev) && strlen($keyDev) === 40);

        $r = $this->api('GET', '/device/config', null, [], $keyDev);
        $this->cek('Perangkat baru memakai setelan awal (200, 07:15)', $r->status() === 200 && $r->json('late_time') === '07:15');

        // ---- Kunci kartu untuk ESP32 ----
        $this->line('--- Kunci kartu untuk ESP32 ---');
        $r = $this->api('GET', '/device/card-key', null, [], $keyDev);
        $kunci1 = $r->json('card_key');
        $this->cek('Perangkat mengambil kunci sekolahnya (200, sama dengan di database)', $r->status() === 200 && $kunci1 === $asli);
        $this->cek('Jawaban kunci tidak boleh disimpan (no-store)', str_contains(strtolower((string) $r->header('Cache-Control')), 'no-store'));

        $r = $this->api('GET', '/device/card-key');
        $this->cek('Tanpa device key (401)', $r->status() === 401);

        $r = $this->api('GET', '/device/card-key', $tokAdm);
        $this->cek('Token admin tidak bisa mengambil kunci (401)', $r->status() === 401);

        $r = $this->api('POST', '/super/schools/' . $idB . '/card-key/rotate', $tokS);
        $this->cek('Rotasi kunci (200, kunci tidak ikut di jawaban)', $r->status() === 200 && !str_contains($r->body(), '"card_key"'));

        $r = $this->api('GET', '/device/card-key', null, [], $keyDev);
        $kunci2 = $r->json('card_key');
        $this->cek('Kunci berganti setelah rotasi (12 hex, berbeda)', $kunci2 !== $kunci1 && preg_match('/^[0-9A-F]{12}$/', (string) $kunci2) === 1);
        $this->cek('Waktu rotasi tercatat', DB::table('schools')->where('id', $idB)->value('card_key_rotated_at') !== null);

        foreach (['FFFFFFFFFFFF', '000000000000', 'ZZZZZZZZZZZZ', '12AB'] as $buruk) {
            $r = $this->api('PUT', '/super/schools/' . $idB . '/card-key', $tokS, ['card_key' => $buruk]);
            $this->cek("Kunci '{$buruk}' ditolak (422)", $r->status() === 422);
        }

        $r = $this->api('GET', '/device/card-key', null, [], $keyDev);
        $this->cek('Kunci tidak berubah setelah penolakan', $r->json('card_key') === $kunci2);

        $r = $this->api('PUT', '/super/schools/' . $idB . '/card-key', $tokS, ['card_key' => '0a1b2c3d4e5f']);
        $this->cek('Kunci diisi manual huruf kecil (200)', $r->status() === 200);

        $r = $this->api('GET', '/device/card-key', null, [], $keyDev);
        $this->cek('Kunci tersimpan huruf besar', $r->json('card_key') === '0A1B2C3D4E5F');

        // ---- Nonaktifkan sekolah ----
        $this->line('--- Nonaktifkan sekolah ---');
        $r = $this->api('PUT', '/super/schools/' . $idB, $tokS, ['is_active' => false]);
        $this->cek('Sekolah dinonaktifkan (200, is_active false)', $r->status() === 200 && $r->json('data.is_active') === false);

        $r = $this->api('POST', '/login', null, ['email' => self::EMAIL_ADMIN, 'password' => $pwAdmin]);
        $this->cek('Admin sekolah nonaktif tidak bisa login (403, SCHOOL_DISABLED)', $r->status() === 403 && $r->json('code') === 'SCHOOL_DISABLED');

        $r = $this->api('GET', '/device/card-key', null, [], $keyDev);
        $this->cek('Perangkat sekolah nonaktif tidak mendapat kunci (403)', $r->status() === 403 && $r->json('code') === 'SCHOOL_DISABLED');

        $r = $this->api('PUT', '/super/schools/' . $idB, $tokS, ['is_active' => true]);
        $this->cek('Sekolah diaktifkan lagi (200)', $r->status() === 200 && $r->json('data.is_active') === true);

        // ---- Ganti nama ----
        $this->line('--- Ganti nama sekolah ---');
        $r = $this->api('PUT', '/super/schools/' . $idB, $tokS, ['name' => self::SEKOLAH_BARU]);
        $this->cek('Ganti nama (200)', $r->status() === 200 && $r->json('data.name') === self::SEKOLAH_BARU);

        $r = $this->api('PUT', '/super/schools/' . $idB, $tokS, ['name' => $a->name]);
        $this->cek('Nama milik sekolah lain ditolak (422, SCHOOL_EXISTS)', $r->status() === 422 && $r->json('code') === 'SCHOOL_EXISTS');

        $r = $this->api('PUT', '/super/schools/' . $idB, $tokS, []);
        $this->cek('Tanpa data (422)', $r->status() === 422);

        $r = $this->api('PUT', '/super/schools/999999', $tokS, ['name' => 'Tidak Ada']);
        $this->cek('Sekolah tidak ada (404)', $r->status() === 404);

        // ---- Admin sekolah ----
        $this->line('--- Admin sekolah ---');
        $r = $this->api('GET', '/super/schools/' . $idB . '/admins', $tokS);
        $this->cek('Daftar admin (1 admin, tanpa password)', $r->status() === 200 && count($r->json('data')) === 1 && !str_contains($r->body(), 'password'));

        $r = $this->api('POST', '/super/schools/' . $idB . '/admins', $tokS, ['name' => 'Admin Dua', 'email' => self::EMAIL_ADMIN2]);
        $admin2Id = $r->json('data.id');
        $pwAdmin2 = $r->json('data.password');
        $this->cek('Tambah admin kedua (201, password 12 karakter)', $r->status() === 201 && $admin2Id && is_string($pwAdmin2) && strlen($pwAdmin2) === 12);

        $r = $this->api('POST', '/super/schools/' . $idB . '/admins', $tokS, ['name' => 'Admin Tiga', 'email' => self::EMAIL_ADMIN2]);
        $this->cek('Email admin sama (422, EMAIL_TAKEN)', $r->status() === 422 && $r->json('code') === 'EMAIL_TAKEN');

        $r = $this->api('GET', '/super/schools/' . $idB . '/admins', $tokS);
        $this->cek('Daftar admin kini 2', count($r->json('data') ?? []) === 2);

        $r = $this->api('POST', '/login', null, ['email' => self::EMAIL_ADMIN2, 'password' => $pwAdmin2]);
        $tokAdm2 = $r->json('token');
        $this->cek('Admin kedua bisa login (200)', $r->status() === 200 && $tokAdm2);

        // ---- Reset password admin ----
        $this->line('--- Reset password admin ---');
        $r = $this->api('POST', "/super/admins/{$admin2Id}/reset-password", $tokS);
        $pwBaru = $r->json('password');
        $this->cek('Reset password (200, 12 karakter, berbeda)', $r->status() === 200 && is_string($pwBaru) && strlen($pwBaru) === 12 && $pwBaru !== $pwAdmin2);

        $r = $this->api('GET', '/me', $tokAdm2);
        $this->cek('Sesi lama keluar (401)', $r->status() === 401);

        $r = $this->api('POST', '/login', null, ['email' => self::EMAIL_ADMIN2, 'password' => $pwAdmin2]);
        $this->cek('Password lama tidak bisa login (401)', $r->status() === 401);

        $r = $this->api('POST', '/login', null, ['email' => self::EMAIL_ADMIN2, 'password' => $pwBaru]);
        $this->cek('Password baru bisa login (200)', $r->status() === 200);

        $wali = User::withoutGlobalScopes()->where('role', 'guardian')->first();
        if ($wali) {
            $r = $this->api('POST', "/super/admins/{$wali->id}/reset-password", $tokS);
            $this->cek('Akun wali tidak bisa direset dari sini (422, NOT_ALLOWED)', $r->status() === 422 && $r->json('code') === 'NOT_ALLOWED');
        }

        $superId = User::withoutGlobalScopes()->where('email', self::EMAIL_SUPER)->value('id');
        $r = $this->api('POST', "/super/admins/{$superId}/reset-password", $tokS);
        $this->cek('Akun super admin tidak bisa direset dari sini (422)', $r->status() === 422 && $r->json('code') === 'NOT_ALLOWED');

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
            ->whereIn('email', [self::EMAIL_SUPER, self::EMAIL_ADMIN, self::EMAIL_ADMIN2])
            ->get();

        foreach ($users as $user) {
            $user->tokens()->delete();
            $user->delete();
        }

        $sekolah = School::whereIn('name', [self::SEKOLAH, self::SEKOLAH_BARU, self::SEKOLAH_KEDUA])->get();

        foreach ($sekolah as $s) {
            $ids = DB::table('users')->where('school_id', $s->id)->pluck('id');
            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereIn('tokenable_id', $ids)
                ->delete();

            DB::table('users')->where('school_id', $s->id)->delete();
            Device::withoutGlobalScopes()->where('school_id', $s->id)->delete();
            DB::table('school_settings')->where('school_id', $s->id)->delete();
            $s->delete();
        }
    }
}
