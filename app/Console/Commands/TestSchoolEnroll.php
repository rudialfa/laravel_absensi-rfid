<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\CardToken;
use App\Support\SchoolContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TestSchoolEnroll extends Command
{
    protected $signature = 'school2:test {--url=http://127.0.0.1:8000}';
    protected $description = 'Uji mode daftar kartu terpisah per sekolah (HANYA project uji coba)';

    private const SEKOLAH_B     = 'Sekolah Uji 6c2';
    private const EMAIL_ADMIN_B = 'admin-b-uji6c2@contoh.test';
    private const NAMA_DEV_A    = 'Perangkat A Uji 6c2';
    private const UID_BARU      = 'E1E1E1E1';

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

        $studentA = Student::where('school_id', $a->id)->first();
        if (!$studentA) {
            $this->error('Sekolah pertama belum punya murid. Jalankan StudentSeeder dulu.');
            return self::FAILURE;
        }

        $pwAdminA = $this->secret('Password admin sekolah pertama (admin@contoh.test)');

        $this->bersihkan();

        // ---- Siapkan sekolah B dan perangkat uji ----
        $pwB  = Str::random(14);
        $keyA = Str::random(40);
        $keyB = Str::random(40);

        $b = School::create(['name' => self::SEKOLAH_B, 'is_active' => true]);

        $adminB = new User([
            'name' => 'Admin B Uji',
            'email' => self::EMAIL_ADMIN_B,
            'role' => 'admin',
            'password' => Hash::make($pwB),
        ]);
        $adminB->school_id = $b->id;
        $adminB->save();

        SchoolContext::run($a->id, fn() => Device::create(['name' => self::NAMA_DEV_A, 'api_key_hash' => hash('sha256', $keyA)]));
        SchoolContext::run($b->id, fn() => Device::create(['name' => 'Perangkat B Uji 6c2', 'api_key_hash' => hash('sha256', $keyB)]));

        $r = $this->api('POST', '/login', null, ['email' => 'admin@contoh.test', 'password' => $pwAdminA]);
        $tokA = $r->json('token');
        $r = $this->api('POST', '/login', null, ['email' => self::EMAIL_ADMIN_B, 'password' => $pwB]);
        $tokB = $r->json('token');

        if (!$tokA || !$tokB) {
            $this->error('Login gagal, tes dihentikan. Cek password admin sekolah pertama.');
            $this->bersihkan();
            return self::FAILURE;
        }

        // ---- Mode daftar terpisah ----
        $this->line('--- Mode daftar terpisah ---');
        $r = $this->api('POST', '/enroll/start', $tokA);
        $this->cek('Admin A menyalakan mode daftar (200, aktif)', $r->status() === 200 && $r->json('active') === true);

        $r = $this->api('GET', '/enroll/status', $tokB);
        $this->cek('Status admin B: tidak aktif (A tidak mempengaruhi B)', $r->status() === 200 && $r->json('active') === false);

        $r = $this->api('POST', '/device/tap', null, ['uid' => self::UID_BARU], $keyB);
        $this->cek('Perangkat B tap kartu baru: perilaku normal (404, tidak mengambil mode A)', $r->status() === 404 && $r->json('code') === 'CARD_NOT_FOUND');

        $r = $this->api('GET', '/enroll/status', $tokA);
        $this->cek('Mode A masih aktif setelah tap dari B', $r->status() === 200 && $r->json('active') === true);

        // ---- Kartu baru di sekolah A ----
        $this->line('--- Kartu baru di sekolah A ---');
        $r = $this->api('POST', '/device/tap', null, ['uid' => self::UID_BARU], $keyA);
        $token = $r->json('write_token');
        $this->cek('Perangkat A tap kartu baru (200, ENROLL_CAPTURED, write_token 32 hex)', $r->status() === 200 && $r->json('code') === 'ENROLL_CAPTURED' && is_string($token) && strlen($token) === 32);

        $r = $this->api('GET', '/enroll/status', $tokA);
        $this->cek('Status A: UID tertangkap, belum terdaftar', $r->json('captured.uid') === self::UID_BARU && $r->json('captured.registered') === false && $r->json('captured.other_school') === false);
        $this->cek('Status A: token menunggu konfirmasi', $r->json('card_token') !== null);

        $r = $this->api('GET', '/enroll/status', $tokB);
        $this->cek('Status B: tidak melihat hasil scan A', $r->json('captured') === null);
        $this->cek('Status B: tidak melihat token A', $r->json('card_token') === null);

        // ---- Kartu sekolah lain di mode daftar sekolah B ----
        $this->line('--- Kartu milik sekolah lain ---');
        $r = $this->api('POST', '/enroll/start', $tokB);
        $this->cek('Admin B menyalakan mode daftar (200)', $r->status() === 200 && $r->json('active') === true);

        $r = $this->api('POST', '/device/tap', null, ['uid' => $studentA->rfid_uid], $keyB);
        $this->cek('Perangkat B tap kartu murid A (ENROLL_CAPTURED, tanpa write_token)', $r->status() === 200 && $r->json('code') === 'ENROLL_CAPTURED' && $r->json('write_token') === null);

        $r = $this->api('GET', '/enroll/status', $tokB);
        $this->cek(
            'Status B: terdaftar di sekolah lain, nama tidak bocor',
            $r->json('captured.registered') === true && $r->json('captured.other_school') === true && $r->json('captured.student') === null
        );

        $r = $this->api('GET', '/enroll/status', $tokA);
        $this->cek('Token A tetap utuh setelah B membuang tokennya sendiri', $r->json('card_token') !== null && $r->json('captured.uid') === self::UID_BARU);

        // ---- Batal terpisah ----
        $this->line('--- Batal terpisah ---');
        $r = $this->api('POST', '/enroll/cancel', $tokA);
        $this->cek('Admin A membatalkan (200)', $r->status() === 200);

        $r = $this->api('GET', '/enroll/status', $tokA);
        $this->cek('Status A bersih setelah batal', $r->json('active') === false && $r->json('captured') === null && $r->json('card_token') === null);

        $r = $this->api('GET', '/enroll/status', $tokB);
        $this->cek('Hasil scan B tidak ikut terhapus oleh batal A', $r->json('captured.uid') === $studentA->rfid_uid);

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

        return $req->send($method, $this->base . $path, $options);
    }

    private function cek(string $nama, bool $ok): void
    {
        $ok ? $this->lulus++ : $this->gagal++;
        $this->line(($ok ? '<info>LULUS</info> ' : '<error>GAGAL</error> ') . $nama);
    }

    /** Hapus semua data uji dan bersihkan status mode daftar di Cache. */
    private function bersihkan(): void
    {
        $a = School::orderBy('id')->first();
        $b = School::where('name', self::SEKOLAH_B)->first();

        foreach (array_filter([$a, $b]) as $school) {
            SchoolContext::run($school->id, function () {
                Cache::forget(\App\Support\SchoolCacheKey::for(\App\Http\Controllers\Api\EnrollController::KEY_ACTIVE));
                Cache::forget(\App\Support\SchoolCacheKey::for(\App\Http\Controllers\Api\EnrollController::KEY_CAPTURED));
                CardToken::discard();
            });
        }

        $users = User::withoutGlobalScopes()->where('email', self::EMAIL_ADMIN_B)->get();
        foreach ($users as $user) {
            $user->tokens()->delete();
            $user->delete();
        }

        Device::withoutGlobalScopes()->whereIn('name', [self::NAMA_DEV_A, 'Perangkat B Uji 6c2'])->delete();

        if ($b) {
            Device::withoutGlobalScopes()->where('school_id', $b->id)->delete();
            $b->delete();
        }
    }
}
