<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\ClassRoom;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestClassAttendance extends Command
{
    protected $signature = 'classatt:test {--url=http://127.0.0.1:8000}';
    protected $description = 'Uji daftar hadir kelas untuk guru (HANYA project uji coba)';

    private int $lulus = 0;
    private int $gagal = 0;
    private string $base = '';

    private const G1 = '4D4D0001'; // guru: kelas A
    private const G2 = '4D4D0002'; // guru: kelas A dan B
    private const G3 = '4D4D0003'; // guru: tanpa kelas
    private const S1 = '4D4D0011';
    private const S2 = '4D4D0012';
    private const S3 = '4D4D0013';
    private const S4 = '4D4D0014'; // nonaktif
    private const S5 = '4D4D0015'; // kelas B

    private const UIDS = [
        self::G1,
        self::G2,
        self::G3,
        self::S1,
        self::S2,
        self::S3,
        self::S4,
        self::S5,
    ];

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

        $tokenAdmin = $this->login('admin@contoh.test', (string) $pwAdmin)->json('token');
        $tokenWali  = $this->login('wali@contoh.test', (string) $pwWali)->json('token');

        if (!$tokenAdmin || !$tokenWali) {
            $this->error('Login gagal. Cek password (ketik manual, jangan paste).');
            return self::FAILURE;
        }

        $awalRequire = Setting::get('require_card_token', '0');
        Setting::updateOrCreate(['key' => 'require_card_token'], ['value' => '0']);

        // ---- Data uji ----
        $kA = ClassRoom::create(['name' => 'Kelas Uji 4d A']);
        $kB = ClassRoom::create(['name' => 'Kelas Uji 4d B']);
        $kC = ClassRoom::create(['name' => 'Kelas Uji 4d C']);

        $s1 = $this->murid('Anna 4d', $kA, self::S1);
        $s2 = $this->murid('Budi 4d', $kA, self::S2);
        $s3 = $this->murid('Citra 4d', $kA, self::S3);
        $s4 = $this->murid('Dodi 4d', $kA, self::S4, false);
        $s5 = $this->murid('Eka 4d', $kB, self::S5);

        $today = now()->toDateString();
        $this->absen($s1, $today, 7, 0, 'hadir', true);
        $this->absen($s2, $today, 7, 40, 'terlambat', false);
        $this->absen($s4, $today, 7, 5, 'hadir', false);
        $this->absen($s5, $today, 7, 10, 'hadir', false);

        $g = [];
        foreach ([[1, self::G1, [$kA->id]], [2, self::G2, [$kA->id, $kB->id]], [3, self::G3, []]] as [$n, $uid, $kelas]) {
            $r = $this->api('POST', '/teachers', $tokenAdmin, [
                'name' => "Guru Uji 4d {$n}",
                'email' => "uji4d-g{$n}@contoh.test",
                'rfid_uid' => $uid,
                'class_ids' => $kelas,
            ]);
            $pw = $r->json('password');
            $g[$n] = [
                'id'    => $r->json('data.id'),
                'token' => $pw ? $this->login("uji4d-g{$n}@contoh.test", $pw)->json('token') : null,
            ];
        }

        if (!$g[1]['token'] || !$g[2]['token'] || !$g[3]['token']) {
            $this->error('Guru uji gagal dibuat atau login. Tes dihentikan.');
            $this->selesai($awalRequire);
            return self::FAILURE;
        }

        // ---- Akses ----
        $this->line('--- Akses ---');
        $r = $this->api('GET', '/my-classes');
        $this->cek('my-classes tanpa token (401)', $r->status() === 401);

        $r = $this->api('GET', '/my-classes', $tokenWali);
        $this->cek('Wali ditolak di my-classes (403)', $r->status() === 403);

        $r = $this->api('GET', '/my-classes', $tokenAdmin);
        $this->cek('Admin ditolak di my-classes (403, khusus guru)', $r->status() === 403);

        $r = $this->api('GET', "/classes/{$kA->id}/attendances");
        $this->cek('Daftar hadir tanpa token (401)', $r->status() === 401);

        $r = $this->api('GET', "/classes/{$kA->id}/attendances", $tokenWali);
        $this->cek('Wali ditolak melihat daftar hadir kelas (403)', $r->status() === 403);

        // ---- Kelas milik guru ----
        $this->line('--- Kelas milik guru ---');
        $r = $this->api('GET', '/my-classes', $g[1]['token']);
        $this->cek(
            'Guru 1: 1 kelas (A), jumlah murid aktif 3',
            $r->status() === 200 && count($r->json('data')) === 1
                && $r->json('data.0.id') === $kA->id && $r->json('data.0.student_count') === 3
        );

        $r = $this->api('GET', '/my-classes', $g[2]['token']);
        $this->cek('Guru 2: 2 kelas (A dan B)', $r->status() === 200 && count($r->json('data')) === 2);

        $r = $this->api('GET', '/my-classes', $g[3]['token']);
        $this->cek('Guru 3: tanpa kelas (200, kosong)', $r->status() === 200 && $r->json('success') === true && count($r->json('data')) === 0);

        // ---- Daftar hadir ----
        $this->line('--- Daftar hadir kelas ---');
        $jumlahAbsen = Attendance::count();
        $r = $this->api('GET', "/classes/{$kA->id}/attendances", $g[1]['token']);
        $this->cek(
            'Guru 1 membuka kelas A (200, tanggal hari ini, nama kelas benar)',
            $r->status() === 200 && $r->json('date') === $today && $r->json('class.name') === 'Kelas Uji 4d A'
        );

        $this->cek(
            'Ringkasan: total 3, hadir 1, terlambat 1, belum hadir 1, sudah pulang 1',
            $r->json('summary.total') === 3 && $r->json('summary.hadir') === 1 && $r->json('summary.terlambat') === 1
                && $r->json('summary.belum_hadir') === 1 && $r->json('summary.sudah_pulang') === 1
        );

        $names = collect($r->json('data'))->pluck('student.name')->all();
        $this->cek('Murid urut nama, murid nonaktif tidak muncul', $names === ['Anna 4d', 'Budi 4d', 'Citra 4d']);

        $d = collect($r->json('data'));
        $e1 = $d->firstWhere('student.id', $s1->id);
        $this->cek('Anna: hadir 07:00, pulang 12:30', $e1 && $e1['status'] === 'hadir' && $e1['checked_in_at'] === '07:00' && $e1['checked_out_at'] === '12:30');

        $e2 = $d->firstWhere('student.id', $s2->id);
        $this->cek('Budi: terlambat 07:40, belum pulang', $e2 && $e2['status'] === 'terlambat' && $e2['checked_in_at'] === '07:40' && $e2['checked_out_at'] === null);

        $e3 = $d->firstWhere('student.id', $s3->id);
        $this->cek('Citra: belum hadir, jam kosong', $e3 && $e3['status'] === 'belum_hadir' && $e3['checked_in_at'] === null && $e3['checked_out_at'] === null);

        $this->cek('Membuka daftar hadir tidak mengubah data absensi', Attendance::count() === $jumlahAbsen);

        // ---- Pembatasan akses guru ----
        $this->line('--- Pembatasan akses guru ---');
        $r = $this->api('GET', "/classes/{$kB->id}/attendances", $g[1]['token']);
        $this->cek('Guru 1 membuka kelas B (403, FORBIDDEN_CLASS)', $r->status() === 403 && $r->json('code') === 'FORBIDDEN_CLASS');

        $r = $this->api('GET', "/classes/{$kA->id}/attendances", $g[3]['token']);
        $this->cek('Guru tanpa kelas membuka kelas A (403, FORBIDDEN_CLASS)', $r->status() === 403 && $r->json('code') === 'FORBIDDEN_CLASS');

        $r = $this->api('GET', "/classes/{$kA->id}/attendances", $g[2]['token']);
        $this->cek('Guru 2 membuka kelas A (200)', $r->status() === 200 && $r->json('summary.total') === 3);

        $r = $this->api('GET', "/classes/{$kB->id}/attendances", $g[2]['token']);
        $this->cek(
            'Guru 2 membuka kelas B (200, hanya Eka, hadir)',
            $r->status() === 200 && $r->json('summary.total') === 1 && $r->json('data.0.student.name') === 'Eka 4d' && $r->json('data.0.status') === 'hadir'
        );

        // ---- Admin ----
        $this->line('--- Admin ---');
        $r = $this->api('GET', "/classes/{$kB->id}/attendances", $tokenAdmin);
        $this->cek('Admin membuka kelas B (200)', $r->status() === 200 && $r->json('summary.total') === 1);

        $r = $this->api('GET', "/classes/{$kC->id}/attendances", $tokenAdmin);
        $this->cek('Admin membuka kelas kosong (200, total 0, data kosong)', $r->status() === 200 && $r->json('summary.total') === 0 && count($r->json('data')) === 0);

        $r = $this->api('GET', '/classes/999999/attendances', $tokenAdmin);
        $this->cek('Kelas tidak ada (404)', $r->status() === 404);

        // ---- Tanggal ----
        $this->line('--- Tanggal ---');
        $r = $this->api('GET', "/classes/{$kA->id}/attendances", $g[1]['token'], ['date' => 'ngawur']);
        $this->cek('Tanggal ngawur (422, VALIDATION)', $r->status() === 422 && $r->json('code') === 'VALIDATION');

        $r = $this->api('GET', "/classes/{$kA->id}/attendances", $g[1]['token'], ['date' => '2000-01-01']);
        $this->cek('Tanggal lama tanpa data (200, semua belum hadir)', $r->status() === 200 && $r->json('summary.total') === 3 && $r->json('summary.belum_hadir') === 3);

        $kemarin = now()->subDay()->toDateString();
        $this->absen($s3, $kemarin, 7, 0, 'hadir', false);
        $r = $this->api('GET', "/classes/{$kA->id}/attendances", $g[1]['token'], ['date' => $kemarin]);
        $this->cek(
            'Tanggal kemarin: hanya Citra yang hadir',
            $r->status() === 200 && $r->json('summary.hadir') === 1 && $r->json('summary.belum_hadir') === 2
                && collect($r->json('data'))->firstWhere('student.id', $s3->id)['status'] === 'hadir'
        );

        // ---- Akses mengikuti data terbaru ----
        $this->line('--- Akses mengikuti data terbaru ---');
        $this->api('PUT', "/teachers/{$g[1]['id']}", $tokenAdmin, ['class_ids' => []]);
        $r = $this->api('GET', "/classes/{$kA->id}/attendances", $g[1]['token']);
        $this->cek('Kelas dilepas dari guru: aksesnya langsung hilang (403)', $r->status() === 403 && $r->json('code') === 'FORBIDDEN_CLASS');

        $this->api('PUT', "/teachers/{$g[2]['id']}", $tokenAdmin, ['is_active' => false]);
        $r = $this->api('GET', "/classes/{$kA->id}/attendances", $g[2]['token']);
        $this->cek('Guru dinonaktifkan: token dicabut (401)', $r->status() === 401);

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
        Teacher::whereIn('rfid_uid', self::UIDS)->delete();

        User::where('email', 'like', 'uji4d-%@contoh.test')->get()->each(function (User $u) {
            $u->tokens()->delete();
            $u->delete();
        });

        // Absensi murid ikut terhapus otomatis (cascade)
        Student::whereIn('rfid_uid', self::UIDS)->delete();
        ClassRoom::where('name', 'like', 'Kelas Uji 4d %')->delete();
    }

    private function murid(string $nama, ClassRoom $kelas, string $uid, bool $aktif = true): Student
    {
        return Student::create([
            'name'      => $nama,
            'class'     => $kelas->name,
            'class_id'  => $kelas->id,
            'rfid_uid'  => $uid,
            'is_active' => $aktif,
        ]);
    }

    private function absen(Student $s, string $tanggal, int $jam, int $menit, string $status, bool $pulang): void
    {
        Attendance::create([
            'student_id'     => $s->id,
            'date'           => $tanggal,
            'checked_in_at'  => $tanggal . sprintf(' %02d:%02d:00', $jam, $menit),
            'checked_out_at' => $pulang ? $tanggal . ' 12:30:00' : null,
            'status'         => $status,
        ]);
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

    private function cek(string $nama, bool $ok): void
    {
        $ok ? $this->lulus++ : $this->gagal++;
        $this->line(($ok ? '<info>LULUS</info> ' : '<error>GAGAL</error> ') . $nama);
    }
}
