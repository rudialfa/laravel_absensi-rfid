<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\DeviceToken;
use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestApi extends Command
{
    protected $signature = 'api:test {--url=http://127.0.0.1:8000}';
    protected $description = 'Uji login, role, device token, dan attendances (HANYA project uji coba)';

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

        $pwAdmin = $this->secret('Password admin (admin@contoh.test)');
        $pwWali  = $this->secret('Password wali (wali@contoh.test)');

        // ---- Siapkan data ----
        Attendance::truncate();
        DeviceToken::truncate();

        $anak = Student::whereHas('guardians', fn($q) => $q->where('email', 'wali@contoh.test'))->first();
        if (!$anak) {
            $this->error('Wali contoh belum terhubung ke siswa. Jalankan UserSeeder dulu.');
            return self::FAILURE;
        }
        $lain = Student::firstOrCreate(
            ['rfid_uid' => '99999999'],
            ['name' => 'Siswa Lain', 'class' => 'X-Z']
        );

        foreach ([$anak, $lain] as $s) {
            Attendance::create([
                'student_id'    => $s->id,
                'date'          => now()->toDateString(),
                'checked_in_at' => now(),
                'status'        => 'hadir',
            ]);
        }

        // ---- Login ----
        $this->line('--- Login ---');
        $r = $this->api('POST', '/login', null, ['email' => 'admin@contoh.test', 'password' => 'salah-total']);
        $this->cek('Password salah (401, INVALID_CREDENTIALS)', $r->status() === 401 && $r->json('code') === 'INVALID_CREDENTIALS');

        $r = $this->api('POST', '/login', null, ['email' => 'admin@contoh.test']);
        $this->cek('Tanpa password (422)', $r->status() === 422);

        $r = $this->api('POST', '/login', null, ['email' => 'admin@contoh.test', 'password' => $pwAdmin]);
        $tokenAdmin = $r->json('token');
        $this->cek('Login admin (200, token, role admin)', $r->status() === 200 && $tokenAdmin && $r->json('user.role') === 'admin');

        $r = $this->api('POST', '/login', null, ['email' => 'wali@contoh.test', 'password' => $pwWali]);
        $tokenWali = $r->json('token');
        $this->cek('Login wali (200, token, role guardian)', $r->status() === 200 && $tokenWali && $r->json('user.role') === 'guardian');

        if (!$tokenAdmin || !$tokenWali) {
            $this->error('Login gagal, tes dihentikan. Cek password atau jalankan ulang UserSeeder.');
            $this->bersihkan($lain);
            return self::FAILURE;
        }

        // ---- Akses dan role ----
        $this->line('--- Akses dan role ---');
        $r = $this->api('GET', '/me', $tokenWali);
        $this->cek('/me wali (email benar)', $r->status() === 200 && $r->json('user.email') === 'wali@contoh.test');

        $r = $this->api('GET', '/attendances');
        $this->cek('Attendances tanpa token (401)', $r->status() === 401);

        $r = $this->api('GET', '/attendances', $tokenAdmin);
        $this->cek('Admin melihat semua (2 data)', $r->status() === 200 && count($r->json('data')) === 2);

        $r = $this->api('GET', '/attendances', $tokenWali);
        $this->cek(
            'Wali hanya melihat anaknya (1 data, bukan Siswa Lain)',
            $r->status() === 200 && count($r->json('data')) === 1 && $r->json('data.0.student.name') === $anak->name
        );

        $r = $this->api('GET', '/attendances', $tokenWali, ['date' => 'ngawur']);
        $this->cek('Tanggal ngawur (422)', $r->status() === 422);

        $r = $this->api('GET', '/attendances', $tokenWali, ['date' => '2000-01-01']);
        $this->cek('Tanggal tanpa data (200, kosong)', $r->status() === 200 && count($r->json('data')) === 0);

        $r = $this->api('GET', '/admin/ping', $tokenWali);
        $this->cek('Wali ke endpoint admin (403, FORBIDDEN)', $r->status() === 403 && $r->json('code') === 'FORBIDDEN');

        $r = $this->api('GET', '/admin/ping', $tokenAdmin);
        $this->cek('Admin ke endpoint admin (200)', $r->status() === 200);

        // ---- Token FCM ----
        $this->line('--- Token FCM ---');
        $r = $this->api('POST', '/device-tokens', $tokenWali, ['fcm_token' => 'token-uji-hp-1']);
        $milik = DeviceToken::where('fcm_token', 'token-uji-hp-1')->first();
        $this->cek('Wali simpan token (200, milik wali)', $r->status() === 200 && $milik && $milik->user->email === 'wali@contoh.test');

        $r = $this->api('POST', '/device-tokens', $tokenAdmin, ['fcm_token' => 'token-uji-hp-1']);
        $milik = DeviceToken::where('fcm_token', 'token-uji-hp-1')->first();
        $this->cek(
            'Token yang sama dipakai admin (pindah milik, tidak ganda)',
            $r->status() === 200 && DeviceToken::count() === 1 && $milik->user->email === 'admin@contoh.test'
        );

        $r = $this->api('POST', '/device-tokens', $tokenAdmin, []);
        $this->cek('Token kosong (422)', $r->status() === 422);

        // ---- Logout ----
        $this->line('--- Logout ---');
        $r = $this->api('POST', '/device-tokens', $tokenWali, ['fcm_token' => 'token-uji-hp-2']);
        $r = $this->api('POST', '/logout', $tokenWali, ['fcm_token' => 'token-uji-hp-2']);
        $this->cek('Logout wali (200, token FCM dihapus)', $r->status() === 200 && !DeviceToken::where('fcm_token', 'token-uji-hp-2')->exists());

        $r = $this->api('GET', '/me', $tokenWali);
        $this->cek('Token wali setelah logout (401)', $r->status() === 401);

        $this->bersihkan($lain);

        $this->newLine();
        $this->info("Lulus: {$this->lulus}   Gagal: {$this->gagal}");

        return $this->gagal === 0 ? self::SUCCESS : self::FAILURE;
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

    private function cek(string $nama, bool $ok): void
    {
        $ok ? $this->lulus++ : $this->gagal++;
        $this->line(($ok ? '<info>LULUS</info> ' : '<error>GAGAL</error> ') . $nama);
    }

    private function bersihkan(Student $lain): void
    {
        Attendance::truncate();
        DeviceToken::truncate();
        $lain->delete();
    }
}
