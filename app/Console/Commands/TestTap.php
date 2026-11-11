<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Setting;
use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestTap extends Command
{
    protected $signature = 'tap:test {key : Device key} {--url=http://127.0.0.1:8000}';
    protected $description = 'Uji semua skenario endpoint /api/device/tap (HANYA project uji coba)';

    private int $lulus = 0;
    private int $gagal = 0;

    public function handle(): int
    {
        if (!app()->environment('local')) {
            $this->error('Hanya boleh dijalankan di environment local.');
            return self::FAILURE;
        }

        $key = $this->argument('key');
        $url = rtrim($this->option('url'), '/');

        $budi = 'F15E2F8E';
        $siti = 'A1B2C3D4';

        // Simpan setelan awal
        $awalLate = Setting::get('late_time', '07:15');
        $awalOut  = Setting::get('checkout_start', '12:00');

        $this->info('Menyiapkan data...');
        Attendance::truncate();
        Student::whereIn('rfid_uid', [$budi, $siti])->update(['is_active' => true]);

        $this->line('--- Autentikasi & validasi ---');
        $this->cek('Kunci salah', $this->tap($url, 'SALAH', $budi), 401, 'UNAUTHORIZED');
        $this->cek('Tanpa kunci', $this->tap($url, null, $budi), 401, 'UNAUTHORIZED');
        $this->cek('UID ngawur', $this->tap($url, $key, 'xyz'), 422, 'INVALID_UID');
        $this->cek('Kartu tidak terdaftar', $this->tap($url, $key, '00000000'), 404, 'CARD_NOT_FOUND');

        $this->line('--- Masuk ---');
        $this->setelan('late_time', '23:59');
        $this->setelan('checkout_start', '23:59');
        $this->cek('Masuk (hadir)', $r = $this->tap($url, $key, $budi), 200, 'OK');
        $this->cekField('  status = hadir', $r, 'status', 'hadir');
        $this->cek('Tap ulang sebelum jam pulang', $this->tap($url, $key, $budi), 200, 'ALREADY');

        $this->line('--- Terlambat ---');
        $this->setelan('late_time', '00:00');
        $this->cek('Masuk (terlambat)', $r = $this->tap($url, $key, $siti), 200, 'OK');
        $this->cekField('  status = terlambat', $r, 'status', 'terlambat');

        $this->line('--- Pulang ---');
        $this->setelan('checkout_start', '00:00');
        $this->cek('Pulang', $this->tap($url, $key, $budi), 200, 'CHECKOUT_OK');
        $this->cek('Tap ulang setelah pulang', $this->tap($url, $key, $budi), 200, 'ALREADY_OUT');

        $this->line('--- Pulang tanpa masuk ---');
        Attendance::truncate();
        $this->cek('Belum masuk', $this->tap($url, $key, $budi), 200, 'BELUM_MASUK');

        $this->line('--- Siswa nonaktif ---');
        Student::where('rfid_uid', $siti)->update(['is_active' => false]);
        $this->cek('Kartu nonaktif', $this->tap($url, $key, $siti), 404, 'CARD_NOT_FOUND');

        // Pulihkan
        Student::where('rfid_uid', $siti)->update(['is_active' => true]);
        $this->setelan('late_time', $awalLate);
        $this->setelan('checkout_start', $awalOut);
        Attendance::truncate();

        $this->newLine();
        $this->info("Lulus: {$this->lulus}   Gagal: {$this->gagal}");

        return $this->gagal === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function setelan(string $key, string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    private function tap(string $url, ?string $key, string $uid)
    {
        $req = Http::acceptJson();
        if ($key !== null) {
            $req = $req->withHeaders(['X-Device-Key' => $key]);
        }
        return $req->post($url . '/api/device/tap', ['uid' => $uid]);
    }

    private function cek(string $nama, $res, int $http, string $code): void
    {
        $ok = $res->status() === $http && $res->json('code') === $code;
        $this->hasil($ok, "{$nama} (HTTP {$http}, {$code})", $ok ? null : "dapat HTTP {$res->status()}, code " . ($res->json('code') ?? '-'));
    }

    private function cekField(string $nama, $res, string $field, string $expected): void
    {
        $ok = $res->json($field) === $expected;
        $this->hasil($ok, $nama, $ok ? null : "dapat " . ($res->json($field) ?? '-'));
    }

    private function hasil(bool $ok, string $nama, ?string $info): void
    {
        $ok ? $this->lulus++ : $this->gagal++;
        $this->line(($ok ? '<info>LULUS</info> ' : '<error>GAGAL</error> ') . $nama . ($info ? " [{$info}]" : ''));
    }
}