<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\EnrollController;
use App\Models\Attendance;
use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TestReplaceCard extends Command
{
    protected $signature = 'replace:test {--url=http://127.0.0.1:8000}';
    protected $description = 'Uji ganti kartu hilang (HANYA project uji coba)';

    private int $lulus = 0;
    private int $gagal = 0;
    private string $base = '';
    private string $deviceKey = '';

    private const UID_A   = 'AAAA0001'; // kartu lama murid A
    private const UID_B   = 'BBBB0002'; // kartu murid B
    private const UID_NEW = 'CCCC0003'; // kartu pengganti

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

        if ($this->tap('xyz')->status() === 401) {
            $this->error('Device key salah. Tes dihentikan.');
            return self::FAILURE;
        }

        // ---- Data uji ----
        $a = Student::create(['name' => 'Murid A', 'class' => 'X-A', 'rfid_uid' => self::UID_A]);
        $b = Student::create(['name' => 'Murid B', 'class' => 'X-B', 'rfid_uid' => self::UID_B]);

        // Absensi A sudah lengkap (masuk dan pulang), supaya tap kartu baru selalu menjawab ALREADY_OUT
        Attendance::create([
            'student_id'     => $a->id,
            'date'           => now()->toDateString(),
            'checked_in_at'  => now(),
            'checked_out_at' => now(),
            'status'         => 'hadir',
        ]);

        $this->line('--- Akses ---');
        $r = $this->api('POST', "/students/{$a->id}/replace-card");
        $this->cek('Tanpa token (401)', $r->status() === 401);

        $r = $this->api('POST', "/students/{$a->id}/replace-card", $tokenWali);
        $this->cek('Wali ditolak (403)', $r->status() === 403);

        $r = $this->api('POST', '/students/999999/replace-card', $tokenAdmin);
        $this->cek('Murid tidak ada (404)', $r->status() === 404);

        $this->line('--- Belum scan ---');
        $r = $this->api('POST', "/students/{$a->id}/replace-card", $tokenAdmin);
        $this->cek('Tanpa scan (422, NO_SCAN)', $r->status() === 422 && $r->json('code') === 'NO_SCAN');

        $this->api('POST', '/enroll/start', $tokenAdmin);
        $r = $this->api('POST', "/students/{$a->id}/replace-card", $tokenAdmin);
        $this->cek('Mode aktif tapi belum tap (422, NO_SCAN)', $r->status() === 422 && $r->json('code') === 'NO_SCAN');
        $this->api('POST', '/enroll/cancel', $tokenAdmin);

        $this->line('--- Ganti kartu ---');
        $this->scan($tokenAdmin, self::UID_NEW);
        $r = $this->api('POST', "/students/{$a->id}/replace-card", $tokenAdmin);
        $this->cek(
            'Ganti kartu berhasil (200, UID berpindah, UID lama tercatat)',
            $r->status() === 200
                && $r->json('data.rfid_uid') === self::UID_NEW
                && $r->json('old_uid') === self::UID_A
                && Student::find($a->id)->rfid_uid === self::UID_NEW
        );

        $s = $this->api('GET', '/enroll/status', $tokenAdmin);
        $this->cek('Hasil scan dihapus setelah dipakai', $s->json('captured') === null);

        $r = $this->api('POST', "/students/{$a->id}/replace-card", $tokenAdmin);
        $this->cek('Pakai ulang hasil scan ditolak (422, NO_SCAN)', $r->status() === 422 && $r->json('code') === 'NO_SCAN');

        $r = $this->tap(self::UID_A);
        $this->cek('Kartu lama tidak berlaku (404, CARD_NOT_FOUND)', $r->status() === 404 && $r->json('code') === 'CARD_NOT_FOUND');

        $r = $this->tap(self::UID_NEW);
        $this->cek('Kartu baru dikenali (200, ALREADY_OUT)', $r->status() === 200 && $r->json('code') === 'ALREADY_OUT');

        $this->cek('Riwayat absensi murid A tetap utuh (1 data)', Attendance::where('student_id', $a->id)->count() === 1);

        $this->line('--- Penolakan ---');
        $this->scan($tokenAdmin, self::UID_B);
        $r = $this->api('POST', "/students/{$a->id}/replace-card", $tokenAdmin);
        $this->cek('Kartu milik murid lain (422, UID_TAKEN)', $r->status() === 422 && $r->json('code') === 'UID_TAKEN');

        $this->cek(
            'UID murid A dan B tidak berubah',
            Student::find($a->id)->rfid_uid === self::UID_NEW && Student::find($b->id)->rfid_uid === self::UID_B
        );

        $this->scan($tokenAdmin, self::UID_NEW);
        $r = $this->api('POST', "/students/{$a->id}/replace-card", $tokenAdmin);
        $this->cek('Kartu milik murid itu sendiri (422, SAME_CARD)', $r->status() === 422 && $r->json('code') === 'SAME_CARD');

        $this->bersihkan();

        $this->newLine();
        $this->info("Lulus: {$this->lulus}   Gagal: {$this->gagal}");

        return $this->gagal === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function scan(string $tokenAdmin, string $uid): void
    {
        $this->api('POST', '/enroll/start', $tokenAdmin);
        $this->tap($uid);
    }

    private function bersihkan(): void
    {
        Cache::forget(EnrollController::KEY_ACTIVE);
        Cache::forget(EnrollController::KEY_CAPTURED);
        // Absensi ikut terhapus otomatis (cascade)
        Student::whereIn('rfid_uid', [self::UID_A, self::UID_B, self::UID_NEW])->delete();
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
