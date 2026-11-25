<?php

namespace App\Support;

use App\Models\School;
use Closure;

/**
 * Sekolah yang sedang aktif untuk proses ini.
 *
 * - Request aplikasi: diisi middleware Authenticate (dari akun pengguna).
 * - Request ESP32: diisi middleware DeviceKey (dari perangkat).
 * - Konsol, job antrean, seeder: tidak diisi. Data tidak disaring, dan data baru
 *   masuk ke sekolah pertama (supaya perintah tes lama tetap jalan).
 * - Request HTTP tanpa sekolah: data bersekolah ditutup (hasil kosong), bukan terbuka.
 */
class SchoolContext
{
    private static ?int $id = null;

    private static ?int $default = null;

    public static function set(?int $id): void
    {
        self::$id = $id;
    }

    public static function id(): ?int
    {
        return self::$id;
    }

    public static function clear(): void
    {
        self::$id = null;
    }

    /** Jalankan blok untuk sekolah tertentu, lalu kembalikan konteks semula. */
    public static function run(?int $id, Closure $callback): mixed
    {
        $previous = self::$id;
        self::$id = $id;

        try {
            return $callback();
        } finally {
            self::$id = $previous;
        }
    }

    /** True kalau ini request HTTP yang belum punya sekolah. */
    public static function failClosed(): bool
    {
        return self::$id === null && !app()->runningInConsole();
    }

    /** school_id untuk data baru, atau null kalau tidak boleh dibuat. */
    public static function idForNewRecord(): ?int
    {
        if (self::$id !== null) {
            return self::$id;
        }

        return app()->runningInConsole() ? self::defaultId() : null;
    }

    public static function defaultId(): ?int
    {
        return self::$default ??= School::orderBy('id')->value('id');
    }
}
