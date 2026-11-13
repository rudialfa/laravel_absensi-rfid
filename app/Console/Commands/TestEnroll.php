<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\EnrollController;
use App\Models\Attendance;
use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TestEnroll extends Command
{
    protected $signature = 'enroll:test {--url=http://127.0.0.1:8000}';
    protected $description = 'Uji mode pendaftaran kartu (HANYA project uji coba)';

    private int $lulus = 0;
    private int $gagal = 0;
    private string $base = '';
    private string $deviceKey = '';

    private const KARTU_BARU = 'ABCD1234'; // tidak terdaftar
    private const KARTU_LAMA = 'ABCD5678'; // dibuat sementara untuk tes

    public function handle(): int
    {
        if (!app()->environment('local')) {
            $this->error('Hanya boleh dijalankan di environment local.');
            return self::FAILURE;
        }

        $this->base = rtrim($this->option('url'), '/') . '/api';

        $pwAdmin = $this->secret('Password admin (admin@contoh.test)');
        $pwWali  = $this->secret('Password wali (wali@contoh.test)');
        $this->deviceKey = (string) $this->secret('Device key');

        $this->bersihkan();

        $tokenAdmin = $this->api('POST', '/login', null, ['email' => 'admin@contoh.test', 'password' => $pwAdmin])->json('token');
        $tokenWali  = $this->api('POST', '/login', null, ['email' => 'wali@contoh.test', 'password' => $pwWali])->json('token');

        if (!$tokenAdmin || !$tokenWali) {
            $this->error('Login gagal. Cek password (ketik manual, jangan paste).');
            return self::FAILURE;
        }

        $r = $this->tap(self::KARTU_BARU);
        if ($r->status() === 401) {
            $this->error('Device key salah. Tes dihentikan.');
            return self::FAILURE;
        }

        $this->line('--- Akses ---');
        $r = $this->api('POST', '/enroll/start');
        $this->cek('Start tanpa token (401)', $r->status() === 401);

        $r = $this->api('POST', '/enroll/start', $tokenWali);
        $this->cek('Wali tidak bisa start (403)', $r->status() === 403);

        $r = $this->api('GET', '/enroll/status', $tokenWali);
        $this->cek('Wali tidak bisa lihat status (403)', $r->status() === 403);

        $this->line('--- Mode mati ---');
        $r = $this->api('GET', '/enroll/status', $tokenAdmin);
        $this->cek('Status awal: tidak aktif, belum ada kartu', $r->status() === 200 && $r->json('active') === false && $r->json('captured') === null);

        $r = $this->tap(self::KARTU_BARU);
        $this->cek('Tap saat mode mati: perilaku normal (404 CARD_NOT_FOUND)', $r->status() === 404 && $r->json('code') === 'CARD_NOT_FOUND');

        $this->line('--- Mode aktif ---');
        $r = $this->api('POST', '/enroll/start', $tokenAdmin);
        $this->cek('Start (200, aktif, 60 detik)', $r->status() === 200 && $r->json('active') === true && $r->json('expires_in') === 60);

        $r = $this->api('GET', '/enroll/status', $tokenAdmin);
        $this->cek('Status aktif (sisa waktu 1 sampai 60 detik)', $r->json('active') === true && $r->json('expires_in') >= 1 && $r->json('expires_in') <= 60);

        $r = $this->tap('xyz');
        $this->cek('UID ngawur ditolak (422), mode tetap aktif', $r->status() === 422 && Cache::has(EnrollController::KEY_ACTIVE));

        $this->line('--- Tangkap kartu baru ---');
        $jumlahAbsen = Attendance::count();

        $r = $this->tap(self::KARTU_BARU);
        $this->cek('Tap kartu baru (200, ENROLL_CAPTURED)', $r->status() === 200 && $r->json('code') === 'ENROLL_CAPTURED');

        $this->cek('Tidak ada absensi tercatat', Attendance::count() === $jumlahAbsen);

        $r = $this->api('GET', '/enroll/status', $tokenAdmin);
        $this->cek(
            'Status: mode mati, kartu tertangkap, belum terdaftar',
            $r->json('active') === false && $r->json('captured.uid') === self::KARTU_BARU && $r->json('captured.registered') === false
        );

        $r = $this->tap(self::KARTU_BARU);
        $this->cek('Tap lagi: mode sudah mati sendiri (404)', $r->status() === 404);

        $this->line('--- Tangkap kartu yang sudah terdaftar ---');
        Student::create(['name' => 'Murid Lama', 'class' => 'X-L', 'rfid_uid' => self::KARTU_LAMA]);
        $jumlahAbsen = Attendance::count();

        $this->api('POST', '/enroll/start', $tokenAdmin);
        $r = $this->tap(self::KARTU_LAMA);
        $this->cek('Tap kartu terdaftar (ENROLL_CAPTURED)', $r->status() === 200 && $r->json('code') === 'ENROLL_CAPTURED');

        $r = $this->api('GET', '/enroll/status', $tokenAdmin);
        $this->cek(
            'Status: terdaftar, pemilik Murid Lama',
            $r->json('captured.registered') === true && $r->json('captured.student') === 'Murid Lama'
        );

        $this->cek('Tidak ada absensi tercatat', Attendance::count() === $jumlahAbsen);

        $this->line('--- Start ulang dan batal ---');
        $r = $this->api('POST', '/enroll/start', $tokenAdmin);
        $r = $this->api('GET', '/enroll/status', $tokenAdmin);
        $this->cek('Start ulang menghapus hasil lama', $r->json('active') === true && $r->json('captured') === null);

        $r = $this->api('POST', '/enroll/cancel', $tokenAdmin);
        $s = $this->api('GET', '/enroll/status', $tokenAdmin);
        $this->cek('Batal (200, mode mati)', $r->status() === 200 && $s->json('active') === false);

        $r = $this->tap(self::KARTU_BARU);
        $this->cek('Tap setelah batal: perilaku normal (404)', $r->status() === 404 && $r->json('code') === 'CARD_NOT_FOUND');

        $this->bersihkan();

        $this->newLine();
        $this->info("Lulus: {$this->lulus}   Gagal: {$this->gagal}");

        return $this->gagal === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function bersihkan(): void
    {
        Cache::forget(EnrollController::KEY_ACTIVE);
        Cache::forget(EnrollController::KEY_CAPTURED);
        Student::where('rfid_uid', self::KARTU_LAMA)->delete();
        Student::where('rfid_uid', self::KARTU_BARU)->delete();
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

    private function tap(string $uid)
    {
        return Http::acceptJson()
            ->withHeaders(['X-Device-Key' => $this->deviceKey])
            ->post($this->base . '/device/tap', ['uid' => $uid]);
    }

    private function cek(string $nama, bool $ok): void
    {
        $ok ? $this->lulus++ : $this->gagal++;
        $this->line(($ok ? '<info>LULUS</info> ' : '<error>GAGAL</error> ') . $nama);
    }
}
