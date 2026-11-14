<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\EnrollController;
use App\Jobs\SendTeacherAttendanceNotification;
use App\Models\Attendance;
use App\Models\DeviceToken;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherAttendance;
use App\Models\User;
use App\Services\CardToken;
use App\Services\PushSender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TestTeacherAttendance extends Command
{
    protected $signature = 'tattendance:test {--url=http://127.0.0.1:8000}';
    protected $description = 'Uji absen guru lewat /tap (HANYA project uji coba, matikan queue:work dulu)';

    private int $lulus = 0;
    private int $gagal = 0;
    private string $base = '';
    private string $deviceKey = '';

    private const T1 = 'C4C40001'; // guru utama
    private const T2 = 'C4C40002'; // guru tanpa absensi
    private const T3 = 'C4C40003'; // guru nonaktif
    private const T4 = 'C4C40004'; // guru bertoken
    private const T5 = 'C4C40005'; // tidak pernah terdaftar
    private const S1 = 'C4C40006'; // murid

    private const UIDS = [self::T1, self::T2, self::T3, self::T4, self::T5, self::S1];

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
        $loginWali  = $this->login('wali@contoh.test', (string) $pwWali);
        $tokenWali  = $loginWali->json('token');
        $waliId     = $loginWali->json('user.id');

        if (!$tokenAdmin || !$tokenWali) {
            $this->error('Login gagal. Cek password (ketik manual, jangan paste).');
            return self::FAILURE;
        }

        if ($this->tap('xyz')->status() === 401) {
            $this->error('Device key salah. Tes dihentikan.');
            return self::FAILURE;
        }

        $awal = [
            'late_time'          => Setting::get('late_time', '07:15'),
            'checkout_start'     => Setting::get('checkout_start', '12:00'),
            'require_card_token' => Setting::get('require_card_token', '0'),
        ];
        $this->setelan('late_time', '23:59');
        $this->setelan('checkout_start', '23:59');
        $this->setelan('require_card_token', '0');

        // ---- Data uji ----
        $t1 = Teacher::create(['name' => 'Guru Uji 4c', 'rfid_uid' => self::T1, 'is_active' => true]);
        $t2 = Teacher::create(['name' => 'Guru Uji 4c B', 'rfid_uid' => self::T2, 'is_active' => true]);
        $t3 = Teacher::create(['name' => 'Guru Uji 4c Nonaktif', 'rfid_uid' => self::T3, 'is_active' => false]);
        $token4 = bin2hex(random_bytes(16));
        $t4 = Teacher::create([
            'name' => 'Guru Uji 4c Token',
            'rfid_uid' => self::T4,
            'is_active' => true,
            'card_token_hash' => hash('sha256', $token4),
        ]);
        Student::create(['name' => 'Murid Uji 4c', 'class' => 'X-T', 'rfid_uid' => self::S1]);

        // ---- Akses ----
        $this->line('--- Endpoint kehadiran guru ---');
        $r = $this->api('GET', '/teacher-attendances');
        $this->cek('Tanpa token (401)', $r->status() === 401);

        $r = $this->api('GET', '/teacher-attendances', $tokenWali);
        $this->cek('Wali ditolak (403)', $r->status() === 403);

        $r = $this->api('GET', '/teacher-attendances', $tokenAdmin);
        $this->cek('Admin bisa membuka (200)', $r->status() === 200 && $r->json('success') === true && is_array($r->json('data')));

        $r = $this->api('GET', '/teacher-attendances', $tokenAdmin, ['date' => 'ngawur']);
        $this->cek('Tanggal ngawur (422)', $r->status() === 422);

        $r = $this->api('GET', '/teacher-attendances', $tokenAdmin, ['date' => '2000-01-01']);
        $this->cek('Tanggal tanpa data (200, kosong)', $r->status() === 200 && count($r->json('data')) === 0);

        // ---- Kartu tidak terdaftar ----
        $this->line('--- Kartu tidak terdaftar ---');
        $r = $this->tap(self::T5);
        $this->cek('Kartu tidak terdaftar (404, CARD_NOT_FOUND)', $r->status() === 404 && $r->json('code') === 'CARD_NOT_FOUND');

        // ---- Masuk guru ----
        $this->line('--- Masuk guru ---');
        $a0 = Attendance::count();
        $j0 = $this->jobCount();

        $r = $this->tap(self::T1);
        $this->cek(
            'Guru masuk (200, OK, role teacher, tanpa status, jam benar)',
            $r->status() === 200 && $r->json('code') === 'OK' && $r->json('role') === 'teacher'
                && $r->json('student') === 'Guru Uji 4c' && !array_key_exists('status', $r->json())
                && preg_match('/^\d{2}:\d{2}$/', (string) $r->json('time')) === 1
        );

        $row = TeacherAttendance::where('teacher_id', $t1->id)->first();
        $this->cek(
            'Absensi guru tercatat (1 baris, device terisi, absensi murid tidak berubah)',
            TeacherAttendance::where('teacher_id', $t1->id)->count() === 1
                && $row && $row->device_id !== null && Attendance::count() === $a0
        );

        $this->cek('Notifikasi masuk masuk antrean (job bertambah 1)', $this->tungguJob($j0 + 1));
        $j1 = $this->jobCount();

        $r = $this->tap(self::T1);
        $this->cek('Tap ulang sebelum jam pulang (200, ALREADY)', $r->status() === 200 && $r->json('code') === 'ALREADY' && $r->json('role') === 'teacher');

        usleep(1000000);
        $this->cek('ALREADY tidak membuat notifikasi baru', $this->jobCount() === $j1);

        // ---- Pulang guru ----
        $this->line('--- Pulang guru ---');
        $this->setelan('checkout_start', '00:00');
        $j2 = $this->jobCount();

        $r = $this->tap(self::T1);
        $this->cek(
            'Guru pulang (200, CHECKOUT_OK, jam benar)',
            $r->status() === 200 && $r->json('code') === 'CHECKOUT_OK' && $r->json('role') === 'teacher'
                && preg_match('/^\d{2}:\d{2}$/', (string) $r->json('time')) === 1
        );

        $this->cek('Jam pulang tercatat di database', TeacherAttendance::where('teacher_id', $t1->id)->first()->checked_out_at !== null);
        $this->cek('Notifikasi pulang masuk antrean (job bertambah 1)', $this->tungguJob($j2 + 1));
        $j3 = $this->jobCount();

        $r = $this->tap(self::T1);
        $this->cek('Tap ulang setelah pulang (200, ALREADY_OUT)', $r->status() === 200 && $r->json('code') === 'ALREADY_OUT');

        usleep(1000000);
        $this->cek('ALREADY_OUT tidak membuat notifikasi baru', $this->jobCount() === $j3);

        // ---- Pulang tanpa masuk ----
        $this->line('--- Pulang tanpa masuk ---');
        $r = $this->tap(self::T2);
        $this->cek(
            'Belum masuk saat jam pulang (200, BELUM_MASUK, tidak tercatat)',
            $r->status() === 200 && $r->json('success') === false && $r->json('code') === 'BELUM_MASUK'
                && TeacherAttendance::where('teacher_id', $t2->id)->count() === 0
        );

        // ---- Guru nonaktif ----
        $this->line('--- Guru nonaktif ---');
        $r = $this->tap(self::T3);
        $this->cek(
            'Kartu guru nonaktif (404, CARD_NOT_FOUND, tidak tercatat)',
            $r->status() === 404 && $r->json('code') === 'CARD_NOT_FOUND'
                && TeacherAttendance::where('teacher_id', $t3->id)->count() === 0
        );

        // ---- Token kartu guru ----
        $this->line('--- Token kartu guru ---');
        $this->setelan('checkout_start', '23:59');

        $r = $this->tap(self::T4);
        $this->cek(
            'Kartu bertoken tanpa token (403, TOKEN_INVALID, tidak tercatat)',
            $r->status() === 403 && $r->json('code') === 'TOKEN_INVALID'
                && TeacherAttendance::where('teacher_id', $t4->id)->count() === 0
        );

        $r = $this->tap(self::T4, bin2hex(random_bytes(16)));
        $this->cek('Token salah (403, TOKEN_INVALID)', $r->status() === 403 && $r->json('code') === 'TOKEN_INVALID');

        $r = $this->tap(self::T4, $token4);
        $this->cek(
            'Token benar (200, OK, tercatat)',
            $r->status() === 200 && $r->json('code') === 'OK' && TeacherAttendance::where('teacher_id', $t4->id)->count() === 1
        );

        $this->setelan('require_card_token', '1');
        $r = $this->tap(self::T2);
        $this->cek(
            'require_card_token hidup: guru tanpa token (403, TOKEN_REQUIRED, tidak tercatat)',
            $r->status() === 403 && $r->json('code') === 'TOKEN_REQUIRED'
                && TeacherAttendance::where('teacher_id', $t2->id)->count() === 0
        );

        $r = $this->tap(self::T4, $token4);
        $this->cek('Guru bertoken tetap diterima saat require hidup (200, ALREADY)', $r->status() === 200 && $r->json('code') === 'ALREADY');
        $this->setelan('require_card_token', '0');

        // ---- Mode scan ----
        $this->line('--- Mode scan ---');
        $this->api('POST', '/enroll/start', $tokenAdmin);
        $r = $this->tap(self::T2);
        $this->cek(
            'Tap kartu guru di mode daftar: ENROLL_CAPTURED, tanpa token baru, tidak tercatat absen',
            $r->status() === 200 && $r->json('code') === 'ENROLL_CAPTURED' && !array_key_exists('write_token', $r->json())
                && TeacherAttendance::where('teacher_id', $t2->id)->count() === 0
        );
        $this->api('POST', '/enroll/cancel', $tokenAdmin);

        // ---- Murid tetap berjalan ----
        $this->line('--- Murid tetap berjalan ---');
        $a1 = Attendance::count();
        $g1 = TeacherAttendance::count();
        $r = $this->tap(self::S1);
        $this->cek('Murid masuk (200, OK, hadir)', $r->status() === 200 && $r->json('code') === 'OK' && $r->json('status') === 'hadir' && $r->json('student') === 'Murid Uji 4c');
        $this->cek('Absensi murid +1, absensi guru tidak berubah', Attendance::count() === $a1 + 1 && TeacherAttendance::count() === $g1);

        // ---- Admin melihat kehadiran guru ----
        $this->line('--- Admin melihat kehadiran guru ---');
        $r = $this->api('GET', '/teacher-attendances', $tokenAdmin);
        $list = collect($r->json('data'));
        $e1 = $list->firstWhere('teacher.id', $t1->id);
        $e4 = $list->firstWhere('teacher.id', $t4->id);
        $this->cek(
            'Daftar memuat guru 1 (masuk dan pulang) dan guru 4 (hanya masuk)',
            $r->status() === 200 && $e1 && $e1['checked_in_at'] && $e1['checked_out_at']
                && $e4 && $e4['checked_in_at'] && $e4['checked_out_at'] === null
                && $list->firstWhere('teacher.id', $t2->id) === null
        );

        // ---- Notifikasi: pengirim palsu memeriksa penerima dan isi ----
        $this->line('--- Penerima dan isi notifikasi ---');
        $guruUser = User::create([
            'name' => 'Guru Uji 4c',
            'email' => 'uji4c-guru@contoh.test',
            'role' => 'teacher',
            'password' => Hash::make(Str::random(16)),
        ]);
        $t1->update(['user_id' => $guruUser->id]);

        DeviceToken::create(['user_id' => $adminId, 'fcm_token' => 'uji4c-token-admin']);
        DeviceToken::create(['user_id' => $waliId, 'fcm_token' => 'uji4c-token-wali']);
        DeviceToken::create(['user_id' => $guruUser->id, 'fcm_token' => 'uji4c-token-guru']);

        $att1 = TeacherAttendance::where('teacher_id', $t1->id)->first();
        $att4 = TeacherAttendance::where('teacher_id', $t4->id)->first();

        $fake = $this->fakeSender();
        (new SendTeacherAttendanceNotification($att1->id, 'masuk'))->handle($fake);
        $c = $fake->calls;
        $this->cek(
            'Notifikasi masuk: satu kiriman, hanya ke admin (bukan wali atau guru)',
            count($c) === 1 && in_array('uji4c-token-admin', $c[0]['tokens'], true)
                && !in_array('uji4c-token-wali', $c[0]['tokens'], true)
                && !in_array('uji4c-token-guru', $c[0]['tokens'], true)
        );

        $this->cek(
            'Isi pesan masuk memuat nama guru, kata masuk, dan jam',
            count($c) === 1 && $c[0]['title'] === 'Absensi Guru'
                && str_contains($c[0]['body'], 'Guru Uji 4c') && str_contains($c[0]['body'], 'sudah masuk')
                && str_contains($c[0]['body'], $att1->checked_in_at->format('H:i'))
        );

        $fake = $this->fakeSender();
        (new SendTeacherAttendanceNotification($att1->id, 'pulang'))->handle($fake);
        $c = $fake->calls;
        $this->cek(
            'Isi pesan pulang memuat nama guru, kata pulang, dan jam',
            count($c) === 1 && str_contains($c[0]['body'], 'Guru Uji 4c') && str_contains($c[0]['body'], 'sudah pulang')
                && str_contains($c[0]['body'], $att1->checked_out_at->format('H:i'))
        );

        $fake = $this->fakeSender();
        (new SendTeacherAttendanceNotification($att4->id, 'pulang'))->handle($fake);
        $this->cek('Notifikasi pulang untuk guru yang belum pulang tidak dikirim', count($fake->calls) === 0);

        $fake = $this->fakeSender();
        (new SendTeacherAttendanceNotification(999999999, 'masuk'))->handle($fake);
        $this->cek('Notifikasi untuk absensi yang tidak ada tidak dikirim', count($fake->calls) === 0);

        $this->selesai($awal);

        $this->newLine();
        $this->info("Lulus: {$this->lulus}   Gagal: {$this->gagal}");

        return $this->gagal === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function selesai(array $awal): void
    {
        foreach ($awal as $key => $value) {
            $this->setelan($key, (string) $value);
        }
        $this->bersihkan();
    }

    private function bersihkan(): void
    {
        Cache::forget(EnrollController::KEY_ACTIVE);
        Cache::forget(EnrollController::KEY_CAPTURED);
        CardToken::discard();

        DeviceToken::where('fcm_token', 'like', 'uji4c-%')->delete();

        // Absensi guru ikut terhapus otomatis (cascade)
        Teacher::whereIn('rfid_uid', self::UIDS)->delete();

        User::where('email', 'like', 'uji4c-%@contoh.test')->get()->each(function (User $u) {
            $u->tokens()->delete();
            $u->delete();
        });

        // Absensi murid ikut terhapus otomatis (cascade)
        Student::whereIn('rfid_uid', self::UIDS)->delete();
    }

    private function setelan(string $key, string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    private function jobCount(): int
    {
        return DB::table('jobs')->where('payload', 'like', '%SendTeacherAttendanceNotification%')->count();
    }

    private function tungguJob(int $target): bool
    {
        for ($i = 0; $i < 10; $i++) {
            if ($this->jobCount() >= $target) {
                return true;
            }
            usleep(300000);
        }

        return false;
    }

    private function fakeSender(): object
    {
        return new class implements PushSender {
            public array $calls = [];

            public function send(array $tokens, string $title, string $body): void
            {
                $this->calls[] = compact('tokens', 'title', 'body');
            }
        };
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

    private function tap(string $uid, ?string $token = null)
    {
        $body = ['uid' => $uid];
        if ($token !== null) {
            $body['token'] = $token;
        }

        return Http::acceptJson()
            ->withHeaders(['X-Device-Key' => $this->deviceKey])
            ->post($this->base . '/device/tap', $body);
    }

    private function cek(string $nama, bool $ok): void
    {
        $ok ? $this->lulus++ : $this->gagal++;
        $this->line(($ok ? '<info>LULUS</info> ' : '<error>GAGAL</error> ') . $nama);
    }
}
