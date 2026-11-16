<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Device;
use App\Models\Setting;
use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class TestSettings extends Command
{
    protected $signature = 'settings:test {--url=http://127.0.0.1:8000}';
    protected $description = 'Uji setelan dan perangkat untuk admin (HANYA project uji coba)';

    private int $lulus = 0;
    private int $gagal = 0;
    private string $base = '';
    private string $deviceKey = '';

    private const UID_MURID = '4E4E0001';

    private const KEYS = [
        'late_time'          => '07:15',
        'checkout_start'     => '12:00',
        'cooldown_seconds'   => '10',
        'lcd_idle'           => 'Tempel kartu',
        'require_card_token' => '0',
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
        $this->deviceKey = (string) $this->secret('Device key');

        $this->bersihkan();

        $tokenAdmin = $this->login('admin@contoh.test', (string) $pwAdmin)->json('token');
        $tokenWali  = $this->login('wali@contoh.test', (string) $pwWali)->json('token');

        if (!$tokenAdmin || !$tokenWali) {
            $this->error('Login gagal. Cek password (ketik manual, jangan paste).');
            return self::FAILURE;
        }

        $real = Device::where('api_key_hash', hash('sha256', $this->deviceKey))->first();
        if (!$real || $this->config($this->deviceKey)->status() !== 200) {
            $this->error('Device key salah. Tes dihentikan.');
            return self::FAILURE;
        }

        $awal = $this->snapshot();

        // ================= SETELAN =================
        $this->line('--- Akses ---');
        $r = $this->api('GET', '/settings');
        $this->cek('Setelan tanpa token (401)', $r->status() === 401);

        $r = $this->api('GET', '/settings', $tokenWali);
        $this->cek('Wali ditolak membaca setelan (403)', $r->status() === 403);

        $r = $this->api('PUT', '/settings', $tokenWali, ['cooldown_seconds' => 77]);
        $this->cek('Wali tidak bisa mengubah setelan (403, tidak berubah)', $r->status() === 403 && Setting::get('cooldown_seconds', '10') === $awal['cooldown_seconds']);

        $r = $this->api('GET', '/devices', $tokenWali);
        $this->cek('Wali ditolak melihat perangkat (403)', $r->status() === 403);

        $r = $this->api('POST', '/devices', $tokenWali, ['name' => 'Uji 4e Wali']);
        $this->cek('Wali tidak bisa menambah perangkat (403, tidak terbuat)', $r->status() === 403 && !Device::where('name', 'Uji 4e Wali')->exists());

        $this->line('--- Baca setelan ---');
        $r = $this->api('GET', '/settings', $tokenAdmin);
        $d = $r->json('data') ?? [];
        $this->cek(
            'Admin membaca setelan (200, lima kunci, tipe benar)',
            $r->status() === 200 && count(array_intersect_key($d, self::KEYS)) === 5
                && is_int($d['cooldown_seconds'] ?? null) && is_bool($d['require_card_token'] ?? null)
        );

        $this->cek(
            'Nilai sama dengan isi database',
            ($d['late_time'] ?? null) === Setting::get('late_time', '07:15')
                && ($d['checkout_start'] ?? null) === Setting::get('checkout_start', '12:00')
                && ($d['cooldown_seconds'] ?? null) === (int) Setting::get('cooldown_seconds', '10')
                && ($d['lcd_idle'] ?? null) === Setting::get('lcd_idle', 'Tempel kartu')
        );

        $this->line('--- Ubah setelan ---');
        $r = $this->api('PUT', '/settings', $tokenAdmin, ['cooldown_seconds' => 20]);
        $this->cek(
            'Ubah sebagian (cooldown 20, jam tidak berubah)',
            $r->status() === 200 && $r->json('data.cooldown_seconds') === 20 && $r->json('data.late_time') === $awal['late_time']
        );

        $r = $this->api('PUT', '/settings', $tokenAdmin, [
            'late_time' => '07:30',
            'checkout_start' => '13:00',
            'cooldown_seconds' => 15,
            'lcd_idle' => 'Selamat datang',
            'require_card_token' => true,
        ]);
        $this->cek(
            'Ubah semua setelan sekaligus (200, tersimpan di database)',
            $r->status() === 200 && $r->json('data.late_time') === '07:30' && $r->json('data.checkout_start') === '13:00'
                && $r->json('data.cooldown_seconds') === 15 && $r->json('data.lcd_idle') === 'Selamat datang'
                && $r->json('data.require_card_token') === true && Setting::get('require_card_token') === '1'
        );

        $r = $this->api('PUT', '/settings', $tokenAdmin, ['require_card_token' => false]);
        $this->cek('require_card_token dimatikan (false, tersimpan 0)', $r->status() === 200 && $r->json('data.require_card_token') === false && Setting::get('require_card_token') === '0');

        $c = $this->config($this->deviceKey)->json();
        $this->cek(
            '/device/config perangkat langsung membaca nilai baru',
            ($c['late_time'] ?? null) === '07:30' && ($c['checkout_start'] ?? null) === '13:00'
                && ($c['cooldown_seconds'] ?? null) === 15 && ($c['lcd_idle'] ?? null) === 'Selamat datang'
        );

        $this->line('--- Setelan ditolak (tidak ada yang berubah) ---');
        $this->tolak('Jam tidak valid 25:99 (422)', $tokenAdmin, ['late_time' => '25:99'], 'VALIDATION');
        $this->tolak('Jam tanpa nol di depan 7:5 (422)', $tokenAdmin, ['checkout_start' => '7:5'], 'VALIDATION');
        $this->tolak('Cooldown 0 (422)', $tokenAdmin, ['cooldown_seconds' => 0], 'VALIDATION');
        $this->tolak('Cooldown 601 (422)', $tokenAdmin, ['cooldown_seconds' => 601], 'VALIDATION');
        $this->tolak('Cooldown bukan angka (422)', $tokenAdmin, ['cooldown_seconds' => 'abc'], 'VALIDATION');
        $this->tolak('Teks LCD 17 karakter (422)', $tokenAdmin, ['lcd_idle' => str_repeat('a', 17)], 'VALIDATION');
        $this->tolak('Teks LCD kosong (422)', $tokenAdmin, ['lcd_idle' => ''], 'VALIDATION');
        $this->tolak('require_card_token bukan benar atau salah (422)', $tokenAdmin, ['require_card_token' => 'ya'], 'VALIDATION');
        $this->tolak('Tanpa isi (422)', $tokenAdmin, [], 'VALIDATION');
        $this->tolak('Batas terlambat 14:00 lebih besar dari jam pulang 13:00 (422, SETTING_ORDER)', $tokenAdmin, ['late_time' => '14:00'], 'SETTING_ORDER');
        $this->tolak('Jam pulang 07:00 lebih kecil dari batas terlambat 07:30 (422, SETTING_ORDER)', $tokenAdmin, ['checkout_start' => '07:00'], 'SETTING_ORDER');
        $this->tolak('Satu salah satu benar: tidak ada yang tersimpan (422)', $tokenAdmin, ['cooldown_seconds' => 99, 'late_time' => 'xx'], 'VALIDATION');

        $r = $this->api('PUT', '/settings', $tokenAdmin, ['late_time' => '08:00', 'checkout_start' => '08:00']);
        $this->cek('Batas terlambat sama dengan jam pulang diperbolehkan (200)', $r->status() === 200 && $r->json('data.late_time') === '08:00' && $r->json('data.checkout_start') === '08:00');

        // ================= PERANGKAT =================
        $this->line('--- Perangkat ---');
        $r = $this->api('POST', '/devices', $tokenAdmin, ['name' => 'Uji 4e Satu']);
        $d1   = $r->json('data.id');
        $key1 = $r->json('device_key');
        $this->cek(
            'Tambah perangkat (201, key 40 karakter, tidak memuat hash)',
            $r->status() === 201 && $d1 && is_string($key1) && strlen($key1) === 40
                && !array_key_exists('api_key_hash', $r->json('data') ?? [])
        );

        if (!$d1 || !is_string($key1)) {
            $this->error('Perangkat uji tidak terbuat, tes dihentikan.');
            $this->selesai($awal);
            return self::FAILURE;
        }

        $row = Device::find($d1);
        $this->cek('Yang tersimpan adalah hash SHA-256, bukan key asli', $row && $row->api_key_hash === hash('sha256', $key1) && $row->api_key_hash !== $key1);

        $this->cek('Key baru langsung bisa dipakai perangkat (200)', $this->config($key1)->status() === 200);

        $r = $this->api('GET', '/devices', $tokenAdmin);
        $item = collect($r->json('data'))->firstWhere('id', $d1);
        $this->cek(
            'Daftar perangkat memuat perangkat baru, tanpa key dan hash',
            $r->status() === 200 && $item && $item['name'] === 'Uji 4e Satu'
                && !str_contains($r->body(), $key1) && !str_contains($r->body(), 'api_key_hash')
        );

        $jumlah = Device::count();
        $r = $this->api('POST', '/devices', $tokenAdmin, ['name' => '']);
        $this->cek('Nama perangkat kosong (422, tidak terbuat)', $r->status() === 422 && $r->json('code') === 'VALIDATION' && Device::count() === $jumlah);

        $r = $this->api('POST', '/devices', $tokenAdmin, ['name' => str_repeat('a', 101)]);
        $this->cek('Nama perangkat 101 karakter (422, tidak terbuat)', $r->status() === 422 && Device::count() === $jumlah);

        $r = $this->api('PUT', "/devices/{$d1}", $tokenAdmin, ['name' => 'Uji 4e Satu Baru']);
        $this->cek('Ganti nama perangkat (200, key tetap berlaku)', $r->status() === 200 && Device::find($d1)->name === 'Uji 4e Satu Baru' && $this->config($key1)->status() === 200);

        $r = $this->api('PUT', "/devices/{$d1}", $tokenAdmin, ['name' => '']);
        $this->cek('Ganti nama jadi kosong (422, nama tetap)', $r->status() === 422 && Device::find($d1)->name === 'Uji 4e Satu Baru');

        $r = $this->api('POST', "/devices/{$d1}/rotate-key", $tokenAdmin);
        $key2 = $r->json('device_key');
        $this->cek(
            'Ganti key (200, key baru 40 karakter, berbeda, hash baru tersimpan)',
            $r->status() === 200 && is_string($key2) && strlen($key2) === 40 && $key2 !== $key1
                && Device::find($d1)->api_key_hash === hash('sha256', $key2)
        );

        $this->cek('Key lama langsung mati (401)', $this->config($key1)->status() === 401);
        $this->cek('Key baru berlaku (200)', $this->config((string) $key2)->status() === 200);

        $r = $this->api('POST', "/devices/{$d1}/rotate-key", $tokenWali);
        $this->cek('Wali tidak bisa ganti key (403, key tetap)', $r->status() === 403 && $this->config((string) $key2)->status() === 200 && Device::find($d1)->api_key_hash === hash('sha256', (string) $key2));

        $r = $this->api('DELETE', "/devices/{$d1}", $tokenWali);
        $this->cek('Wali tidak bisa menghapus perangkat (403, tetap ada)', $r->status() === 403 && Device::find($d1) !== null);

        $r = $this->api('DELETE', "/devices/{$d1}", $tokenAdmin);
        $this->cek('Admin menghapus perangkat (200, hilang, key mati)', $r->status() === 200 && Device::find($d1) === null && $this->config((string) $key2)->status() === 401);

        $r = $this->api('DELETE', '/devices/999999999', $tokenAdmin);
        $this->cek('Hapus perangkat yang tidak ada (404)', $r->status() === 404);

        $r = $this->api('POST', '/devices/999999999/rotate-key', $tokenAdmin);
        $this->cek('Ganti key perangkat yang tidak ada (404)', $r->status() === 404);

        // ---- Riwayat absensi tetap utuh ----
        $r = $this->api('POST', '/devices', $tokenAdmin, ['name' => 'Uji 4e Absensi']);
        $d2 = $r->json('data.id');
        $murid = Student::create(['name' => 'Murid Uji 4e', 'class' => 'Uji 4e', 'rfid_uid' => self::UID_MURID]);
        $absen = $d2 ? Attendance::create([
            'student_id'    => $murid->id,
            'date'          => now()->toDateString(),
            'checked_in_at' => now(),
            'status'        => 'hadir',
            'device_id'     => $d2,
        ]) : null;

        $r = $d2 ? $this->api('DELETE', "/devices/{$d2}", $tokenAdmin) : null;
        $setelah = $absen ? Attendance::find($absen->id) : null;
        $this->cek(
            'Hapus perangkat: riwayat absensi tetap ada, device_id jadi kosong',
            $r && $r->status() === 200 && $setelah !== null && $setelah->device_id === null
        );

        $this->cek('Perangkat asli tetap berfungsi (200)', $this->config($this->deviceKey)->status() === 200);

        // ---- Perangkat terakhir tidak boleh dihapus ----
        $sisa = Device::all();
        if ($sisa->count() === 1 && $sisa->first()->id === $real->id) {
            $cadangan = (array) DB::table('devices')->where('id', $real->id)->first();

            $r = $this->api('DELETE', "/devices/{$real->id}", $tokenAdmin);

            // Pengaman: kalau ternyata terhapus, kembalikan barisnya
            $masihAda = Device::find($real->id) !== null;
            if (!$masihAda) {
                DB::table('devices')->insert($cadangan);
            }

            $this->cek(
                'Perangkat terakhir tidak bisa dihapus (422, LAST_DEVICE, key tetap berlaku)',
                $r->status() === 422 && $r->json('code') === 'LAST_DEVICE' && $masihAda
                    && $this->config($this->deviceKey)->status() === 200
            );
        } else {
            $this->warn('DILEWATI: tes perangkat terakhir hanya dijalankan kalau perangkat asli satu-satunya.');
        }

        $this->selesai($awal);

        $this->newLine();
        $this->info("Lulus: {$this->lulus}   Gagal: {$this->gagal}");

        return $this->gagal === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function selesai(array $awal): void
    {
        foreach ($awal as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        $this->bersihkan();
    }

    private function bersihkan(): void
    {
        Device::where('name', 'like', 'Uji 4e %')->delete();

        // Absensi ikut terhapus otomatis (cascade)
        Student::where('rfid_uid', self::UID_MURID)->delete();
    }

    private function snapshot(): array
    {
        $out = [];
        foreach (self::KEYS as $key => $default) {
            $out[$key] = (string) Setting::get($key, $default);
        }

        return $out;
    }

    private function tolak(string $nama, string $token, array $body, string $kode): void
    {
        $sebelum = $this->snapshot();
        $r = $this->api('PUT', '/settings', $token, $body);

        $this->cek($nama, $r->status() === 422 && $r->json('code') === $kode && $this->snapshot() === $sebelum);
    }

    private function config(string $key)
    {
        return Http::acceptJson()
            ->withHeaders(['X-Device-Key' => $key])
            ->get($this->base . '/device/config');
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
