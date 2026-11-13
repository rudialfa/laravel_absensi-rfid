<?php

namespace App\Console\Commands;

use App\Models\ClassRoom;
use App\Models\Setting;
use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestClasses extends Command
{
    protected $signature = 'classes:test {--url=http://127.0.0.1:8000}';
    protected $description = 'Uji kelas dan hubungan murid-kelas (HANYA project uji coba)';

    private int $lulus = 0;
    private int $gagal = 0;
    private string $base = '';

    private const A  = 'Kelas Uji A';
    private const B  = 'Kelas Uji B';
    private const B2 = 'Kelas Uji B2';
    private const C  = 'Kelas Uji C';
    private const D  = 'Kelas Uji D';

    private const UIDS = ['EEEE0001', 'EEEE0002', 'EEEE0003', 'EEEE0004'];

    public function handle(): int
    {
        if (!app()->environment('local')) {
            $this->error('Hanya boleh dijalankan di environment local.');
            return self::FAILURE;
        }

        $this->base = rtrim($this->option('url'), '/') . '/api';

        $pwAdmin = $this->secret('Password admin (admin@contoh.test)');
        $pwWali  = $this->secret('Password wali (wali@contoh.test)');

        $this->bersihkan();

        $tokenAdmin = $this->api('POST', '/login', null, ['email' => 'admin@contoh.test', 'password' => $pwAdmin])->json('token');
        $tokenWali  = $this->api('POST', '/login', null, ['email' => 'wali@contoh.test', 'password' => $pwWali])->json('token');

        if (!$tokenAdmin || !$tokenWali) {
            $this->error('Login gagal. Cek password (ketik manual, jangan paste).');
            return self::FAILURE;
        }

        $awalRequire = Setting::get('require_card_token', '0');
        Setting::updateOrCreate(['key' => 'require_card_token'], ['value' => '0']);

        // ---- Akses ----
        $this->line('--- Akses ---');
        $r = $this->api('GET', '/classes');
        $this->cek('Tanpa token (401)', $r->status() === 401);

        $r = $this->api('GET', '/classes', $tokenWali);
        $this->cek('Wali ditolak melihat kelas (403)', $r->status() === 403);

        $r = $this->api('POST', '/classes', $tokenWali, ['name' => 'Kelas Uji Wali']);
        $this->cek('Wali tidak bisa membuat kelas (403)', $r->status() === 403 && !ClassRoom::where('name', 'Kelas Uji Wali')->exists());

        // ---- Kelas ----
        $this->line('--- Kelas ---');
        $r = $this->api('POST', '/classes', $tokenAdmin, ['name' => self::A]);
        $idA = $r->json('data.id');
        $this->cek('Tambah kelas (201)', $r->status() === 201 && $r->json('data.name') === self::A && $idA);

        if (!$idA) {
            $this->error('Kelas tidak terbuat, tes dihentikan.');
            $this->selesai($awalRequire);
            return self::FAILURE;
        }

        $r = $this->api('POST', '/classes', $tokenAdmin, ['name' => strtolower(self::A)]);
        $this->cek('Nama sama beda huruf besar kecil (422, CLASS_EXISTS)', $r->status() === 422 && $r->json('code') === 'CLASS_EXISTS');

        $r = $this->api('POST', '/classes', $tokenAdmin, ['name' => '']);
        $this->cek('Nama kosong (422, VALIDATION)', $r->status() === 422 && $r->json('code') === 'VALIDATION');

        $r = $this->api('GET', '/classes', $tokenAdmin);
        $item = collect($r->json('data'))->firstWhere('name', self::A);
        $this->cek('Daftar kelas memuat kelas baru (0 murid)', $r->status() === 200 && $item !== null && $item['student_count'] === 0);

        $r = $this->api('GET', "/classes/{$idA}", $tokenAdmin);
        $this->cek('Detail kelas (200)', $r->status() === 200 && $r->json('data.name') === self::A);

        $r = $this->api('GET', '/classes/999999', $tokenAdmin);
        $this->cek('Kelas tidak ada (404)', $r->status() === 404);

        $r = $this->api('POST', '/classes', $tokenAdmin, ['name' => self::D]);
        $idD = $r->json('data.id');
        $this->cek('Tambah kelas kedua (201)', $r->status() === 201 && $idD);

        $r = $this->api('PUT', "/classes/{$idA}", $tokenAdmin, ['name' => self::B]);
        $this->cek('Ganti nama kelas (200)', $r->status() === 200 && $r->json('data.name') === self::B);

        $r = $this->api('PUT', "/classes/{$idA}", $tokenAdmin, ['name' => self::D]);
        $this->cek('Ganti ke nama kelas lain (422, CLASS_EXISTS)', $r->status() === 422 && $r->json('code') === 'CLASS_EXISTS');

        // ---- Murid dan kelas ----
        $this->line('--- Murid dan kelas ---');
        $r = $this->api('POST', '/students', $tokenAdmin, ['name' => 'Murid Uji 1', 'class_id' => $idA, 'rfid_uid' => 'EEEE0001']);
        $id1 = $r->json('data.id');
        $this->cek('Murid dengan class_id (201, kelas benar)', $r->status() === 201 && $r->json('data.class_id') === $idA && $r->json('data.class') === self::B);

        $r = $this->api('POST', '/students', $tokenAdmin, ['name' => 'Murid Uji 2', 'class' => 'kelas uji b', 'rfid_uid' => 'EEEE0002']);
        $id2 = $r->json('data.id');
        $jumlahB = ClassRoom::whereRaw('LOWER(name) = ?', ['kelas uji b'])->count();
        $this->cek('Murid dengan nama kelas beda huruf: kelas yang sama dipakai (tidak ganda)', $r->status() === 201 && $r->json('data.class_id') === $idA && $jumlahB === 1);

        $r = $this->api('POST', '/students', $tokenAdmin, ['name' => 'Murid Uji 3', 'class' => self::C, 'rfid_uid' => 'EEEE0003']);
        $idC = $r->json('data.class_id');
        $this->cek('Murid dengan nama kelas baru: kelas dibuat otomatis (201)', $r->status() === 201 && $idC && $idC !== $idA && ClassRoom::where('name', self::C)->exists());

        $r = $this->api('POST', '/students', $tokenAdmin, ['name' => 'Murid Uji 4', 'class_id' => 999999, 'rfid_uid' => 'EEEE0004']);
        $this->cek('class_id tidak ada (422, tidak tersimpan)', $r->status() === 422 && $r->json('code') === 'VALIDATION' && !Student::where('rfid_uid', 'EEEE0004')->exists());

        $r = $this->api('POST', '/students', $tokenAdmin, ['name' => 'Murid Uji 4', 'rfid_uid' => 'EEEE0004']);
        $this->cek('Tanpa kelas sama sekali (422, tidak tersimpan)', $r->status() === 422 && $r->json('code') === 'VALIDATION' && !Student::where('rfid_uid', 'EEEE0004')->exists());

        $r = $this->api('GET', "/classes/{$idA}", $tokenAdmin);
        $this->cek('Jumlah murid kelas = 2', $r->status() === 200 && $r->json('data.student_count') === 2 && count($r->json('data.students')) === 2);

        // ---- Ganti nama kelas ----
        $this->line('--- Ganti nama kelas ---');
        $r = $this->api('PUT', "/classes/{$idA}", $tokenAdmin, ['name' => self::B2]);
        $this->cek('Ganti nama kelas yang berisi murid (200)', $r->status() === 200 && $r->json('data.name') === self::B2);

        $r = $this->api('GET', "/students/{$id1}", $tokenAdmin);
        $this->cek(
            'Nama kelas di data murid ikut berubah (sinkron)',
            Student::find($id1)->class === self::B2 && $r->json('data.class') === self::B2
        );

        // ---- Pindah kelas ----
        $this->line('--- Pindah kelas ---');
        $r = $this->api('PUT', "/students/{$id1}", $tokenAdmin, ['class_id' => $idD]);
        $this->cek('Pindah kelas lewat class_id (200)', $r->status() === 200 && $r->json('data.class_id') === $idD && $r->json('data.class') === self::D);

        $r = $this->api('PUT', "/students/{$id2}", $tokenAdmin, ['class' => self::C]);
        $this->cek('Pindah kelas lewat nama (200)', $r->status() === 200 && $r->json('data.class_id') === $idC);

        // ---- Hapus ----
        $this->line('--- Hapus kelas ---');
        $r = $this->api('DELETE', "/classes/{$idA}", $tokenWali);
        $this->cek('Wali tidak bisa menghapus kelas (403)', $r->status() === 403 && ClassRoom::find($idA) !== null);

        $r = $this->api('DELETE', "/classes/{$idC}", $tokenAdmin);
        $this->cek('Kelas berisi murid tidak bisa dihapus (422, CLASS_NOT_EMPTY)', $r->status() === 422 && $r->json('code') === 'CLASS_NOT_EMPTY' && ClassRoom::find($idC) !== null);

        $r = $this->api('DELETE', "/classes/{$idA}", $tokenAdmin);
        $this->cek('Kelas kosong terhapus (200)', $r->status() === 200 && ClassRoom::find($idA) === null);

        $this->selesai($awalRequire);

        $this->newLine();
        $this->info("Lulus: {$this->lulus}   Gagal: {$this->gagal}");

        return $this->gagal === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function selesai(string $awalRequire): void
    {
        Setting::updateOrCreate(['key' => 'require_card_token'], ['value' => $awalRequire]);
        $this->bersihkan();
    }

    private function bersihkan(): void
    {
        // Murid dulu, supaya kelas bisa dihapus
        Student::whereIn('rfid_uid', self::UIDS)->delete();
        ClassRoom::where('name', 'like', 'Kelas Uji %')->delete();
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
