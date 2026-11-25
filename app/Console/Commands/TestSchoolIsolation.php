<?php

namespace App\Console\Commands;

use App\Jobs\SendAttendanceNotification;
use App\Models\Attendance;
use App\Models\ClassRoom;
use App\Models\Device;
use App\Models\DeviceToken;
use App\Models\School;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\CardToken;
use App\Services\PushSender;
use App\Support\SchoolContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TestSchoolIsolation extends Command
{
    protected $signature = 'school:test {--url=http://127.0.0.1:8000}';
    protected $description = 'Uji isolasi data antar sekolah (HANYA project uji coba)';

    private const SEKOLAH_B = 'Sekolah Uji 6c';
    private const KELAS_B   = 'B-1-UJI6C';
    private const UID_B     = 'B1B1B1B1';
    private const EMAIL_ADMIN_B = 'admin-b-uji6c@contoh.test';
    private const EMAIL_SUPER   = 'super-uji6c@contoh.test';
    private const EMAIL_WALI    = 'wali-uji6c@contoh.test';

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

        // ---- Siapkan sekolah B (khusus uji) ----
        $pwB     = Str::random(14);
        $pwSuper = Str::random(14);
        $keyB    = Str::random(40);

        $b = School::create(['name' => self::SEKOLAH_B, 'is_active' => true]);

        $adminB = new User([
            'name' => 'Admin B Uji',
            'email' => self::EMAIL_ADMIN_B,
            'role' => 'admin',
            'password' => Hash::make($pwB),
        ]);
        $adminB->school_id = $b->id;
        $adminB->save();

        User::create([
            'name' => 'Super Uji',
            'email' => self::EMAIL_SUPER,
            'role' => 'super_admin',
            'password' => Hash::make($pwSuper),
        ]);

        [$classB, $studentB] = SchoolContext::run($b->id, function () use ($keyB) {
            $class = ClassRoom::create(['name' => self::KELAS_B]);
            $student = Student::create([
                'name' => 'Murid B Uji',
                'class' => self::KELAS_B,
                'class_id' => $class->id,
                'rfid_uid' => self::UID_B,
                'is_active' => true,
            ]);
            Device::create(['name' => 'Perangkat B Uji', 'api_key_hash' => hash('sha256', $keyB)]);
            // Jam sengaja ujung hari supaya tap uji selalu "hadir"
            Setting::updateOrCreate(['key' => 'late_time'], ['value' => '23:58']);
            Setting::updateOrCreate(['key' => 'checkout_start'], ['value' => '23:59']);

            return [$class, $student];
        });

        $this->line('--- Data baru masuk ke sekolah yang benar ---');
        $this->cek('Murid B tersimpan di sekolah B', $studentB->school_id === $b->id);
        $this->cek('Kelas B tersimpan di sekolah B', $classB->school_id === $b->id);
        $this->cek('Murid lama tetap di sekolah pertama', (int) $studentA->school_id === $a->id);

        // ---- Login ----
        $this->line('--- Login ---');
        $r = $this->api('POST', '/login', null, ['email' => 'admin@contoh.test', 'password' => $pwAdminA]);
        $tokA = $r->json('token');
        $this->cek('Login admin A (200, sekolah terisi)', $r->status() === 200 && $tokA && $r->json('user.school_id') === $a->id);

        $r = $this->api('POST', '/login', null, ['email' => self::EMAIL_ADMIN_B, 'password' => $pwB]);
        $tokB = $r->json('token');
        $this->cek('Login admin B (200, nama sekolah B)', $r->status() === 200 && $tokB && $r->json('user.school') === self::SEKOLAH_B);

        $r = $this->api('POST', '/login', null, ['email' => self::EMAIL_SUPER, 'password' => $pwSuper]);
        $tokS = $r->json('token');
        $this->cek('Login super admin (200, tanpa sekolah)', $r->status() === 200 && $tokS && $r->json('user.role') === 'super_admin' && $r->json('user.school_id') === null);

        if (!$tokA || !$tokB || !$tokS) {
            $this->error('Login gagal, tes dihentikan. Cek password admin A.');
            $this->bersihkan();
            return self::FAILURE;
        }

        // ---- Murid dan kelas ----
        $this->line('--- Murid dan kelas ---');
        $r = $this->api('GET', '/students', $tokA);
        $this->cek('Admin A tidak melihat murid B', $r->status() === 200 && !in_array('Murid B Uji', $this->kolom($r, 'name'), true));

        $r = $this->api('GET', '/students', $tokB);
        $this->cek('Admin B hanya melihat murid B (1 data)', $r->status() === 200 && $this->kolom($r, 'name') === ['Murid B Uji']);

        $r = $this->api('GET', "/students/{$studentB->id}", $tokA);
        $this->cek('Admin A buka murid B (404)', $r->status() === 404);

        $r = $this->api('PUT', "/students/{$studentB->id}", $tokA, ['name' => 'Diubah A']);
        $this->cek('Admin A ubah murid B (404, nama tetap)', $r->status() === 404 && Student::withoutGlobalScopes()->find($studentB->id)->name === 'Murid B Uji');

        $r = $this->api('GET', '/classes', $tokA);
        $this->cek('Admin A tidak melihat kelas B', $r->status() === 200 && !in_array(self::KELAS_B, $this->kolom($r, 'name'), true));

        $r = $this->api('POST', '/classes', $tokA, ['name' => self::KELAS_B]);
        $this->cek('Nama kelas yang sama boleh di sekolah lain (200/201)', in_array($r->status(), [200, 201], true));

        $r = $this->api('POST', '/students', $tokA, ['name' => 'Salah Kelas', 'class_id' => $classB->id, 'rfid_uid' => 'D0D0D0D0']);
        $this->cek('Murid A dengan kelas B ditolak (422, class_id)', $r->status() === 422 && array_key_exists('class_id', $r->json('errors') ?? []));

        $r = $this->api('POST', '/students', $tokA, ['name' => 'Kembar', 'class' => 'X-A', 'rfid_uid' => self::UID_B]);
        $this->cek('UID kartu sekolah B dipakai di A (422, tidak tersimpan)', $r->status() === 422 && !Student::withoutGlobalScopes()->where('name', 'Kembar')->exists());

        // ---- Wali dan guru ----
        $this->line('--- Wali dan guru ---');
        $r = $this->api('POST', '/guardians', $tokA, ['name' => 'Wali Uji', 'email' => self::EMAIL_WALI, 'student_ids' => [$studentB->id]]);
        $this->cek('Wali A ditautkan ke murid B (422, student_ids)', $r->status() === 422 && array_key_exists('student_ids.0', $r->json('errors') ?? []));

        $r = $this->api('POST', '/guardians', $tokA, ['name' => 'Wali Uji', 'email' => self::EMAIL_WALI, 'student_ids' => [$studentA->id]]);
        $this->cek('Wali A dengan murid A (201)', $r->status() === 201);

        $r = $this->api('GET', '/guardians', $tokB);
        $this->cek('Admin B tidak melihat wali sekolah A', $r->status() === 200 && !in_array(self::EMAIL_WALI, $this->kolom($r, 'email'), true));

        $r = $this->api('POST', '/guardians', $tokB, ['name' => 'Wali Kembar', 'email' => self::EMAIL_WALI]);
        $this->cek('Email wali sama di sekolah lain (422, EMAIL_TAKEN)', $r->status() === 422 && $r->json('code') === 'EMAIL_TAKEN');

        $r = $this->api('POST', '/teachers', $tokA, [
            'name' => 'Guru Uji 6c',
            'email' => 'guru-uji6c@contoh.test',
            'rfid_uid' => 'C0C0C0C0',
            'class_ids' => [$classB->id],
        ]);
        $this->cek('Guru A mengampu kelas B (422, class_ids)', $r->status() === 422 && array_key_exists('class_ids.0', $r->json('errors') ?? []));

        // ---- Perangkat dan setelan ----
        $this->line('--- Perangkat dan setelan ---');
        $r = $this->api('GET', '/devices', $tokA);
        $this->cek('Admin A tidak melihat perangkat B', $r->status() === 200 && !in_array('Perangkat B Uji', $this->kolom($r, 'name'), true));

        $r = $this->api('GET', '/devices', $tokB);
        $deviceB = $r->json('data.0.id');
        $this->cek('Admin B hanya melihat perangkatnya (1 data)', $r->status() === 200 && count($r->json('data')) === 1);

        $r = $this->api('DELETE', "/devices/{$deviceB}", $tokB);
        $this->cek('Perangkat terakhir sekolah B tidak bisa dihapus (422, LAST_DEVICE)', $r->status() === 422 && $r->json('code') === 'LAST_DEVICE');

        $r = $this->api('GET', '/settings', $tokB);
        $lateB = $r->json('data.late_time') ?? $r->json('late_time');
        $r = $this->api('GET', '/settings', $tokA);
        $lateA = $r->json('data.late_time') ?? $r->json('late_time');
        $this->cek('Setelan terpisah (B 23:58, A bukan 23:58)', $lateB === '23:58' && $lateA !== null && $lateA !== '23:58');

        $r = $this->api('GET', '/device/config', null, [], $keyB);
        $this->cek('Config perangkat B memakai setelan sekolah B', $r->status() === 200 && $r->json('late_time') === '23:58');

        // ---- Tap kartu ----
        $this->line('--- Tap kartu ---');
        $r = $this->api('POST', '/device/tap', null, ['uid' => $studentA->rfid_uid], $keyB);
        $this->cek('Perangkat B tap kartu murid A (404, CARD_NOT_FOUND)', $r->status() === 404 && $r->json('code') === 'CARD_NOT_FOUND');

        $r = $this->api('POST', '/device/tap', null, ['uid' => self::UID_B], $keyB);
        $this->cek('Perangkat B tap kartu murid B (200, OK)', $r->status() === 200 && $r->json('code') === 'OK');

        $attB = Attendance::withoutGlobalScopes()->where('student_id', $studentB->id)->first();
        $this->cek('Absensi baru memakai sekolah perangkat', $attB && (int) $attB->school_id === $b->id);

        $r = $this->api('GET', '/attendances', $tokA, ['date' => now()->toDateString()]);
        $namaA = collect($r->json('data') ?? [])->pluck('student.name')->all();
        $this->cek('Absensi: admin A tidak melihat murid B', $r->status() === 200 && !in_array('Murid B Uji', $namaA, true));

        $r = $this->api('GET', '/attendances', $tokB, ['date' => now()->toDateString()]);
        $namaB = collect($r->json('data') ?? [])->pluck('student.name')->all();
        $this->cek('Absensi: admin B melihat murid B', $r->status() === 200 && in_array('Murid B Uji', $namaB, true));

        // ---- Kartu dan notifikasi ----
        $this->line('--- Kartu dan notifikasi ---');
        $this->cek('Token tidak dibuat untuk UID yang sudah dipakai sekolah lain', CardToken::issue(self::UID_B) === '');

        $baru = CardToken::issue('E0E0E0E0');
        CardToken::discard();
        $this->cek('Token dibuat untuk UID baru (32 karakter)', strlen($baru) === 32);

        $adminA = User::withoutGlobalScopes()->where('email', 'admin@contoh.test')->first();
        DeviceToken::create(['user_id' => $adminA->id, 'fcm_token' => 'uji6c-a']);
        DeviceToken::create(['user_id' => $adminB->id, 'fcm_token' => 'uji6c-b']);

        $sender = new class implements PushSender {
            public array $tokens = [];

            public function send(array $tokens, string $title, string $body): void
            {
                $this->tokens = array_merge($this->tokens, $tokens);
            }
        };

        if ($attB) {
            (new SendAttendanceNotification($attB->id, 'masuk'))->handle($sender);
        }
        $this->cek(
            'Notifikasi murid B hanya ke admin B (bukan admin A)',
            in_array('uji6c-b', $sender->tokens, true) && !in_array('uji6c-a', $sender->tokens, true)
        );

        // ---- Sekolah dinonaktifkan ----
        $this->line('--- Sekolah dinonaktifkan ---');
        $b->update(['is_active' => false]);

        $r = $this->api('POST', '/device/tap', null, ['uid' => self::UID_B], $keyB);
        $this->cek('Perangkat sekolah nonaktif ditolak (403, SCHOOL_DISABLED)', $r->status() === 403 && $r->json('code') === 'SCHOOL_DISABLED');

        $r = $this->api('GET', '/me', $tokB);
        $this->cek('Token admin sekolah nonaktif ditolak (403, SCHOOL_DISABLED)', $r->status() === 403 && $r->json('code') === 'SCHOOL_DISABLED');

        $r = $this->api('POST', '/login', null, ['email' => self::EMAIL_ADMIN_B, 'password' => $pwB]);
        $this->cek('Login admin sekolah nonaktif ditolak (403)', $r->status() === 403 && $r->json('code') === 'SCHOOL_DISABLED');

        $b->update(['is_active' => true]);

        $r = $this->api('GET', '/me', $tokB);
        $this->cek('Sekolah aktif lagi: akses pulih (200)', $r->status() === 200);

        // ---- Super admin ----
        $this->line('--- Super admin ---');
        $r = $this->api('GET', '/me', $tokS);
        $this->cek('/me super admin (200, tanpa sekolah)', $r->status() === 200 && $r->json('user.role') === 'super_admin');

        $r = $this->api('GET', '/students', $tokS);
        $this->cek('Super admin ke data sekolah (403, FORBIDDEN)', $r->status() === 403 && $r->json('code') === 'FORBIDDEN');

        $this->bersihkan();

        $this->newLine();
        $this->info("Lulus: {$this->lulus}   Gagal: {$this->gagal}");

        return $this->gagal === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** Nilai satu kolom dari daftar "data" pada jawaban API. */
    private function kolom($response, string $key): array
    {
        return collect($response->json('data') ?? [])->pluck($key)->all();
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

    /** Hapus semua data uji, termasuk sisa dari tes yang terhenti. */
    private function bersihkan(): void
    {
        DeviceToken::whereIn('fcm_token', ['uji6c-a', 'uji6c-b'])->delete();

        $users = User::withoutGlobalScopes()
            ->whereIn('email', [self::EMAIL_ADMIN_B, self::EMAIL_SUPER, self::EMAIL_WALI])
            ->get();

        foreach ($users as $user) {
            $user->tokens()->delete();
            DeviceToken::where('user_id', $user->id)->delete();
            $user->delete();
        }

        Student::withoutGlobalScopes()->whereIn('name', ['Kembar', 'Salah Kelas'])->delete();
        ClassRoom::withoutGlobalScopes()->where('name', self::KELAS_B)->delete(); // juga kelas uji di sekolah A

        $b = School::where('name', self::SEKOLAH_B)->first();

        if ($b) {
            Attendance::withoutGlobalScopes()->where('school_id', $b->id)->delete();
            Student::withoutGlobalScopes()->where('school_id', $b->id)->delete();
            Device::withoutGlobalScopes()->where('school_id', $b->id)->delete();
            Setting::withoutGlobalScopes()->where('school_id', $b->id)->delete();
            $b->delete();
        }
    }
}
