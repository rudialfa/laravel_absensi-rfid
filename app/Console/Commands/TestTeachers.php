<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\EnrollController;
use App\Models\Attendance;
use App\Models\ClassRoom;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\CardToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class TestTeachers extends Command
{
    protected $signature = 'teachers:test {--url=http://127.0.0.1:8000}';
    protected $description = 'Uji guru, akun wali, dan hubungan kelas/murid (HANYA project uji coba)';

    private int $lulus = 0;
    private int $gagal = 0;
    private string $base = '';
    private string $deviceKey = '';

    private const T1 = 'FFFF0001'; // kartu guru 1
    private const S1 = 'FFFF0002'; // kartu murid 1
    private const T1_BARU = 'FFFF0003'; // kartu pengganti guru 1
    private const S2 = 'FFFF0004'; // kartu murid 2
    private const T2 = 'FFFF0005'; // kartu guru 2
    private const T3 = 'FFFF0006'; // tidak pernah terdaftar

    private const UIDS = [self::T1, self::S1, self::T1_BARU, self::S2, self::T2, self::T3];

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

        $loginAdmin = $this->login('admin@contoh.test', (string) $pwAdmin);
        $tokenAdmin = $loginAdmin->json('token');
        $adminId    = $loginAdmin->json('user.id');
        $tokenWali  = $this->login('wali@contoh.test', (string) $pwWali)->json('token');

        if (!$tokenAdmin || !$tokenWali) {
            $this->error('Login gagal. Cek password (ketik manual, jangan paste).');
            return self::FAILURE;
        }

        if ($this->tap('xyz')->status() === 401) {
            $this->error('Device key salah. Tes dihentikan.');
            return self::FAILURE;
        }

        $awalRequire = Setting::get('require_card_token', '0');
        Setting::updateOrCreate(['key' => 'require_card_token'], ['value' => '0']);

        // ---- Akses ----
        $this->line('--- Akses ---');
        $r = $this->api('GET', '/teachers');
        $this->cek('Tanpa token (401)', $r->status() === 401);

        $r = $this->api('GET', '/teachers', $tokenWali);
        $this->cek('Wali tidak bisa melihat guru (403)', $r->status() === 403);

        $r = $this->api('GET', '/guardians', $tokenWali);
        $this->cek('Wali tidak bisa melihat daftar wali (403)', $r->status() === 403);

        $r = $this->api('POST', '/guardians', $tokenWali, ['name' => 'X', 'email' => 'uji4b-wali@contoh.test']);
        $this->cek('Wali tidak bisa membuat akun wali (403)', $r->status() === 403 && !User::where('email', 'uji4b-wali@contoh.test')->exists());

        // ---- Data dasar ----
        $k1 = ClassRoom::create(['name' => 'Kelas Uji 4b A']);
        $k2 = ClassRoom::create(['name' => 'Kelas Uji 4b B']);

        // ---- Guru ----
        $this->line('--- Guru ---');
        $r = $this->api('POST', '/teachers', $tokenAdmin, [
            'name' => 'Guru Uji',
            'email' => 'uji4b-guru@contoh.test',
            'rfid_uid' => strtolower(self::T1),
            'class_ids' => [$k1->id],
        ]);
        $idGuru = $r->json('data.id');
        $pwGuru = $r->json('password');
        $this->cek(
            'Tambah guru (201, UID huruf besar, password 12 karakter, 1 kelas)',
            $r->status() === 201 && $r->json('data.rfid_uid') === self::T1
                && is_string($pwGuru) && strlen($pwGuru) === 12
                && count($r->json('data.classes')) === 1 && $r->json('data.has_token') === false
        );

        if (!$idGuru) {
            $this->error('Guru tidak terbuat, tes dihentikan.');
            $this->selesai($awalRequire);
            return self::FAILURE;
        }

        $userGuru = User::where('email', 'uji4b-guru@contoh.test')->first();
        $teacher  = Teacher::find($idGuru);
        $this->cek('Akun guru terbuat dengan role teacher dan terhubung', $userGuru && $userGuru->role === 'teacher' && $teacher->user_id === $userGuru->id);
        $this->cek('Password tersimpan sebagai hash, bukan teks asli', $userGuru && $userGuru->password !== $pwGuru);

        $r = $this->login('uji4b-guru@contoh.test', (string) $pwGuru);
        $tokGuru = $r->json('token');
        $this->cek('Guru bisa login (200, role teacher)', $r->status() === 200 && $tokGuru && $r->json('user.role') === 'teacher');

        $r = $this->api('GET', '/students', $tokGuru);
        $this->cek('Guru tidak bisa melihat murid (403)', $r->status() === 403);

        $r = $this->api('GET', '/teachers', $tokGuru);
        $this->cek('Guru tidak bisa mengelola guru (403)', $r->status() === 403);

        $r = $this->api('GET', '/me', $tokGuru);
        $this->cek('Guru bisa membuka /me (200)', $r->status() === 200 && $r->json('user.email') === 'uji4b-guru@contoh.test');

        // ---- Duplikat dan validasi ----
        $this->line('--- Duplikat dan validasi ---');
        $r = $this->api('POST', '/teachers', $tokenAdmin, ['name' => 'X', 'email' => 'UJI4B-GURU@contoh.test', 'rfid_uid' => self::T3]);
        $this->cek('Email sama beda huruf besar (422, EMAIL_TAKEN)', $r->status() === 422 && $r->json('code') === 'EMAIL_TAKEN');

        $r = $this->api('POST', '/teachers', $tokenAdmin, ['name' => 'X', 'email' => 'bukan-email', 'rfid_uid' => self::T3]);
        $this->cek('Email tidak valid (422, VALIDATION)', $r->status() === 422 && $r->json('code') === 'VALIDATION');

        $r = $this->api('POST', '/teachers', $tokenAdmin, ['name' => 'X', 'email' => 'uji4b-dup@contoh.test', 'rfid_uid' => self::T1]);
        $this->cek('UID sudah dipakai guru lain (422, UID_TAKEN, akun tidak terbuat)', $r->status() === 422 && $r->json('code') === 'UID_TAKEN' && !User::where('email', 'uji4b-dup@contoh.test')->exists());

        $r = $this->api('POST', '/teachers', $tokenAdmin, ['name' => 'X', 'email' => 'uji4b-cls@contoh.test', 'rfid_uid' => self::T3, 'class_ids' => [999999]]);
        $this->cek('Kelas tidak ada (422, akun tidak terbuat)', $r->status() === 422 && $r->json('code') === 'VALIDATION' && !User::where('email', 'uji4b-cls@contoh.test')->exists());

        // ---- Satu UID satu pemilik ----
        $this->line('--- Satu UID satu pemilik ---');
        $r = $this->api('POST', '/students', $tokenAdmin, ['name' => 'Murid Uji 4b 1', 'class_id' => $k1->id, 'rfid_uid' => self::S1]);
        $idS1 = $r->json('data.id');
        $this->cek('Tambah murid 1 (201)', $r->status() === 201 && $idS1);

        $r = $this->api('POST', '/teachers', $tokenAdmin, ['name' => 'X', 'email' => 'uji4b-x@contoh.test', 'rfid_uid' => self::S1]);
        $this->cek('Guru dengan UID milik murid ditolak (422, UID_TAKEN)', $r->status() === 422 && $r->json('code') === 'UID_TAKEN');

        $r = $this->api('POST', '/students', $tokenAdmin, ['name' => 'X', 'class_id' => $k1->id, 'rfid_uid' => self::T1]);
        $this->cek('Murid dengan UID milik guru ditolak (422, UID_TAKEN, tidak tersimpan)', $r->status() === 422 && $r->json('code') === 'UID_TAKEN' && Student::where('rfid_uid', self::T1)->count() === 0);

        // ---- Mode scan ----
        $this->line('--- Mode scan ---');
        $this->api('POST', '/enroll/start', $tokenAdmin);
        $r = $this->tap(self::T1);
        $this->cek('Tap kartu guru di mode daftar: ENROLL_CAPTURED tanpa write_token', $r->status() === 200 && $r->json('code') === 'ENROLL_CAPTURED' && !array_key_exists('write_token', $r->json()));

        $s = $this->api('GET', '/enroll/status', $tokenAdmin);
        $this->cek('Status: terdaftar sebagai guru, nama Guru Uji', $s->json('captured.registered') === true && $s->json('captured.owner_type') === 'teacher' && $s->json('captured.student') === 'Guru Uji');
        $this->api('POST', '/enroll/cancel', $tokenAdmin);

        // ---- Guru dan kelas ----
        $this->line('--- Guru dan kelas ---');
        $r = $this->api('GET', "/teachers/{$idGuru}", $tokenAdmin);
        $this->cek('Detail guru memuat 1 kelas', $r->status() === 200 && count($r->json('data.classes')) === 1);

        $r = $this->api('PUT', "/teachers/{$idGuru}", $tokenAdmin, ['name' => 'Guru Uji 2', 'class_ids' => [$k1->id, $k2->id]]);
        $this->cek(
            'Ubah nama dan 2 kelas (200, nama akun ikut berubah)',
            $r->status() === 200 && count($r->json('data.classes')) === 2
                && $r->json('data.name') === 'Guru Uji 2' && User::find($userGuru->id)->name === 'Guru Uji 2'
        );

        $r = $this->api('POST', '/teachers', $tokenAdmin, ['name' => 'Guru Uji B', 'email' => 'uji4b-guru2@contoh.test', 'rfid_uid' => self::T2, 'class_ids' => [$k1->id]]);
        $idGuru2 = $r->json('data.id');
        $this->cek('Guru kedua dibuat, kelas A kini punya 2 guru', $r->status() === 201 && $idGuru2 && $k1->teachers()->count() === 2);

        $r = $this->api('PUT', "/teachers/{$idGuru2}", $tokenAdmin, ['class_ids' => [$k2->id]]);
        $this->cek('Ganti kelas guru kedua (hanya kelas B)', $r->status() === 200 && count($r->json('data.classes')) === 1 && $r->json('data.classes.0.id') === $k2->id);

        $r = $this->api('PUT', "/teachers/{$idGuru2}", $tokenAdmin, ['class_ids' => []]);
        $this->cek('Kosongkan kelas guru kedua (0 kelas)', $r->status() === 200 && count($r->json('data.classes')) === 0);

        // ---- Nonaktifkan ----
        $this->line('--- Nonaktifkan guru ---');
        $r = $this->api('PUT', "/teachers/{$idGuru}", $tokenAdmin, ['is_active' => false]);
        $me = $this->api('GET', '/me', $tokGuru);
        $this->cek('Guru dinonaktifkan: token lama dicabut (401)', $r->status() === 200 && $r->json('data.is_active') === false && $me->status() === 401);

        $r = $this->login('uji4b-guru@contoh.test', (string) $pwGuru);
        $this->cek('Guru nonaktif tidak bisa login (403, ACCOUNT_DISABLED)', $r->status() === 403 && $r->json('code') === 'ACCOUNT_DISABLED');

        $this->api('PUT', "/teachers/{$idGuru}", $tokenAdmin, ['is_active' => true]);
        $r = $this->login('uji4b-guru@contoh.test', (string) $pwGuru);
        $this->cek('Guru diaktifkan lagi bisa login (200)', $r->status() === 200);

        // ---- Ganti kartu guru ----
        $this->line('--- Ganti kartu guru ---');
        $this->api('POST', '/enroll/start', $tokenAdmin);
        $this->tap(self::T1_BARU);
        $r = $this->api('POST', "/teachers/{$idGuru}/replace-card", $tokenAdmin);
        $this->cek(
            'Ganti kartu guru (200, UID berpindah, UID lama tercatat)',
            $r->status() === 200 && $r->json('data.rfid_uid') === self::T1_BARU
                && $r->json('old_uid') === self::T1 && Teacher::find($idGuru)->rfid_uid === self::T1_BARU
        );

        $this->api('POST', '/enroll/start', $tokenAdmin);
        $this->tap(self::S1);
        $r = $this->api('POST', "/teachers/{$idGuru}/replace-card", $tokenAdmin);
        $this->cek('Ganti ke kartu milik murid ditolak (422, UID_TAKEN)', $r->status() === 422 && $r->json('code') === 'UID_TAKEN' && Teacher::find($idGuru)->rfid_uid === self::T1_BARU);
        $this->api('POST', '/enroll/cancel', $tokenAdmin);

        $r = $this->api('POST', "/teachers/{$idGuru}/replace-card", $tokenAdmin);
        $this->cek('Tanpa scan (422, NO_SCAN)', $r->status() === 422 && $r->json('code') === 'NO_SCAN');

        $r = $this->api('POST', "/teachers/{$idGuru}/replace-card", $tokenWali);
        $this->cek('Wali tidak bisa ganti kartu guru (403)', $r->status() === 403);

        // ---- Wali ----
        $this->line('--- Akun wali ---');
        $r = $this->api('POST', '/students', $tokenAdmin, ['name' => 'Murid Uji 4b 2', 'class_id' => $k1->id, 'rfid_uid' => self::S2]);
        $idS2 = $r->json('data.id');
        $this->cek('Tambah murid 2 (201)', $r->status() === 201 && $idS2);

        foreach ([$idS1, $idS2] as $sid) {
            Attendance::create([
                'student_id'    => $sid,
                'date'          => now()->toDateString(),
                'checked_in_at' => now(),
                'status'        => 'hadir',
            ]);
        }

        $r = $this->api('POST', '/guardians', $tokenAdmin, ['name' => 'Wali Uji', 'email' => 'uji4b-wali@contoh.test', 'student_ids' => [$idS1]]);
        $idWali = $r->json('data.id');
        $pwWaliBaru = $r->json('password');
        $this->cek(
            'Tambah akun wali (201, password 12 karakter, 1 murid)',
            $r->status() === 201 && $idWali && is_string($pwWaliBaru) && strlen($pwWaliBaru) === 12 && count($r->json('data.students')) === 1
        );

        $r = $this->login('uji4b-wali@contoh.test', (string) $pwWaliBaru);
        $tokWali2 = $r->json('token');
        $a = $this->api('GET', '/attendances', $tokWali2);
        $this->cek('Wali baru hanya melihat anaknya (1 data, murid 1)', $r->status() === 200 && $a->status() === 200 && count($a->json('data')) === 1 && $a->json('data.0.student.id') === $idS1);

        $r = $this->api('PUT', "/guardians/{$idWali}", $tokenAdmin, ['student_ids' => [$idS1, $idS2]]);
        $a = $this->api('GET', '/attendances', $tokWali2);
        $this->cek('Hubungkan ke 2 murid: wali melihat 2 data', $r->status() === 200 && count($r->json('data.students')) === 2 && count($a->json('data')) === 2);

        $r = $this->api('PUT', "/guardians/{$idWali}", $tokenAdmin, ['student_ids' => [$idS2]]);
        $a = $this->api('GET', '/attendances', $tokWali2);
        $this->cek('Ganti ke murid 2 saja: wali melihat 1 data (murid 2)', $r->status() === 200 && count($a->json('data')) === 1 && $a->json('data.0.student.id') === $idS2);

        $r = $this->api('POST', '/guardians', $tokenAdmin, ['name' => 'X', 'email' => 'UJI4B-WALI@contoh.test']);
        $this->cek('Email wali sama (422, EMAIL_TAKEN)', $r->status() === 422 && $r->json('code') === 'EMAIL_TAKEN');

        $r = $this->api('GET', '/guardians', $tokenAdmin);
        $item = collect($r->json('data'))->firstWhere('email', 'uji4b-wali@contoh.test');
        $this->cek('Daftar wali memuat Wali Uji beserta muridnya', $r->status() === 200 && $item && count($item['students']) === 1);

        // ---- Reset password ----
        $this->line('--- Reset password ---');
        $r = $this->api('POST', "/users/{$idWali}/reset-password", $tokenAdmin);
        $pwBaru = $r->json('password');
        $me = $this->api('GET', '/me', $tokWali2);
        $this->cek('Reset password wali (200, password baru berbeda, sesi lama keluar)', $r->status() === 200 && is_string($pwBaru) && strlen($pwBaru) === 12 && $pwBaru !== $pwWaliBaru && $me->status() === 401);

        $r = $this->login('uji4b-wali@contoh.test', (string) $pwWaliBaru);
        $this->cek('Password lama tidak bisa login (401)', $r->status() === 401);

        $r = $this->login('uji4b-wali@contoh.test', (string) $pwBaru);
        $tokWaliBaru = $r->json('token');
        $this->cek('Password baru bisa login (200)', $r->status() === 200 && $tokWaliBaru);

        $r = $this->api('POST', "/users/{$adminId}/reset-password", $tokenAdmin);
        $this->cek('Akun admin tidak bisa direset (422, NOT_ALLOWED)', $r->status() === 422 && $r->json('code') === 'NOT_ALLOWED');

        $r = $this->api('POST', "/users/{$idWali}/reset-password", $tokenWali);
        $this->cek('Wali tidak bisa mereset password (403)', $r->status() === 403);

        $r = $this->api('POST', "/users/{$userGuru->id}/reset-password", $tokenAdmin);
        $this->cek('Reset password guru (200)', $r->status() === 200 && is_string($r->json('password')) && strlen($r->json('password')) === 12);

        // ---- Hapus wali ----
        $this->line('--- Hapus akun wali ---');

        // Dicek SEBELUM akun dihapus: untuk akun yang sudah tidak ada, Laravel menjawab 404
        // (pencocokan model terjadi sebelum pengecekan role)
        $r = $this->api('DELETE', "/guardians/{$idWali}", $tokenWali);
        $this->cek('Wali tidak bisa menghapus akun wali (403, akun tetap ada)', $r->status() === 403 && User::find($idWali) !== null);

        $r = $this->api('DELETE', "/guardians/{$userGuru->id}", $tokenAdmin);
        $this->cek('Akun guru tidak bisa dihapus lewat endpoint wali (404)', $r->status() === 404 && User::find($userGuru->id) !== null);

        $r = $this->api('DELETE', "/guardians/{$idWali}", $tokenAdmin);
        $this->cek(
            'Hapus akun wali (200, akun dan hubungan murid terhapus)',
            $r->status() === 200 && User::find($idWali) === null
                && DB::table('guardian_student')->where('user_id', $idWali)->count() === 0
        );

        $me = $this->api('GET', '/me', $tokWaliBaru);
        $this->cek('Token wali yang dihapus tidak berlaku (401)', $me->status() === 401);

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
        Cache::forget(EnrollController::KEY_ACTIVE);
        Cache::forget(EnrollController::KEY_CAPTURED);
        CardToken::discard();

        Teacher::whereIn('rfid_uid', self::UIDS)->delete();

        User::where('email', 'like', 'uji4b-%@contoh.test')->get()->each(function (User $u) {
            $u->tokens()->delete();
            $u->delete();
        });

        // Absensi ikut terhapus otomatis (cascade)
        Student::whereIn('rfid_uid', self::UIDS)->delete();
        ClassRoom::where('name', 'like', 'Kelas Uji 4b %')->delete();
    }

    private function login(string $email, string $password)
    {
        return $this->api('POST', '/login', null, ['email' => $email, 'password' => $password]);
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
