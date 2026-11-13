<?php

namespace App\Console\Commands;

use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestStudents extends Command
{
    protected $signature = 'students:test {--url=http://127.0.0.1:8000}';
    protected $description = 'Uji CRUD murid (HANYA project uji coba)';

    private int $lulus = 0;
    private int $gagal = 0;
    private string $base = '';

    private const UID = 'ABCD1234';

    public function handle(): int
    {
        if (!app()->environment('local')) {
            $this->error('Hanya boleh dijalankan di environment local.');
            return self::FAILURE;
        }

        $this->base = rtrim($this->option('url'), '/') . '/api';

        $pwAdmin = $this->secret('Password admin (admin@contoh.test)');
        $pwWali  = $this->secret('Password wali (wali@contoh.test)');

        Student::where('rfid_uid', self::UID)->delete();

        $tokenAdmin = $this->api('POST', '/login', null, ['email' => 'admin@contoh.test', 'password' => $pwAdmin])->json('token');
        $tokenWali  = $this->api('POST', '/login', null, ['email' => 'wali@contoh.test', 'password' => $pwWali])->json('token');

        if (!$tokenAdmin || !$tokenWali) {
            $this->error('Login gagal. Cek password (ketik manual, jangan paste).');
            return self::FAILURE;
        }

        $this->line('--- Akses ---');
        $r = $this->api('GET', '/students');
        $this->cek('Tanpa token (401)', $r->status() === 401);

        $r = $this->api('GET', '/students', $tokenWali);
        $this->cek('Wali ditolak (403)', $r->status() === 403);

        $r = $this->api('POST', '/students', $tokenWali, ['name' => 'X', 'class' => 'X-A', 'rfid_uid' => self::UID]);
        $this->cek('Wali tidak bisa tambah murid (403)', $r->status() === 403 && !Student::where('rfid_uid', self::UID)->exists());

        $this->line('--- Tambah ---');
        $r = $this->api('POST', '/students', $tokenAdmin, ['name' => 'Murid Uji', 'class' => 'X-T', 'rfid_uid' => 'abcd1234']);
        $id = $r->json('data.id');
        $this->cek('Tambah murid (201, UID jadi huruf besar)', $r->status() === 201 && $r->json('data.rfid_uid') === self::UID && $r->json('data.is_active') === true);

        $r = $this->api('POST', '/students', $tokenAdmin, ['name' => 'Duplikat', 'class' => 'X-T', 'rfid_uid' => self::UID]);
        $this->cek('UID sudah dipakai (422, UID_TAKEN)', $r->status() === 422 && $r->json('code') === 'UID_TAKEN');

        $r = $this->api('POST', '/students', $tokenAdmin, ['name' => 'Ngawur', 'class' => 'X-T', 'rfid_uid' => 'xyz']);
        $this->cek('UID ngawur (422, VALIDATION)', $r->status() === 422 && $r->json('code') === 'VALIDATION');

        $r = $this->api('POST', '/students', $tokenAdmin, ['class' => 'X-T', 'rfid_uid' => '11112222']);
        $this->cek('Tanpa nama (422)', $r->status() === 422 && !Student::where('rfid_uid', '11112222')->exists());

        $this->line('--- Baca ---');
        $r = $this->api('GET', '/students', $tokenAdmin, ['q' => 'Murid Uji']);
        $this->cek('Cari nama (1 hasil)', $r->status() === 200 && count($r->json('data')) === 1);

        $r = $this->api('GET', "/students/{$id}", $tokenAdmin);
        $this->cek('Detail murid (200)', $r->status() === 200 && $r->json('data.name') === 'Murid Uji');

        $r = $this->api('GET', '/students/999999', $tokenAdmin);
        $this->cek('Murid tidak ada (404)', $r->status() === 404);

        $this->line('--- Ubah ---');
        $r = $this->api('PUT', "/students/{$id}", $tokenAdmin, ['name' => 'Murid Uji 2', 'class' => 'X-U']);
        $this->cek('Ubah nama dan kelas (200)', $r->status() === 200 && $r->json('data.name') === 'Murid Uji 2' && $r->json('data.class') === 'X-U');

        $r = $this->api('PUT', "/students/{$id}", $tokenAdmin, ['rfid_uid' => '99998888']);
        $this->cek('UID tidak bisa diubah lewat PUT', $r->status() === 200 && Student::find($id)->rfid_uid === self::UID);

        $r = $this->api('PUT', "/students/{$id}", $tokenAdmin, ['name' => '']);
        $this->cek('Nama kosong ditolak (422)', $r->status() === 422);

        $this->line('--- Nonaktifkan ---');
        $r = $this->api('PUT', "/students/{$id}", $tokenAdmin, ['is_active' => false]);
        $this->cek('Nonaktifkan (200, is_active false)', $r->status() === 200 && Student::find($id)->is_active === false);

        $r = $this->api('GET', '/students', $tokenAdmin, ['active' => '1', 'q' => 'Murid Uji']);
        $this->cek('Filter aktif tidak memuat murid nonaktif', count($r->json('data')) === 0);

        $r = $this->api('GET', '/students', $tokenAdmin, ['active' => '0', 'q' => 'Murid Uji']);
        $this->cek('Filter nonaktif memuat murid itu', count($r->json('data')) === 1);

        $r = $this->api('PUT', "/students/{$id}", $tokenAdmin, ['is_active' => true]);
        $this->cek('Aktifkan lagi (200)', $r->status() === 200 && Student::find($id)->is_active === true);

        Student::where('rfid_uid', self::UID)->delete();

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
}
