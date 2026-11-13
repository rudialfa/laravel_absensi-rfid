<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Token rahasia kartu (anti clone). Token mentah hanya dikirim sekali ke ESP32.
 * Server hanya menyimpan hash SHA-256.
 */
class CardToken
{
    public const KEY     = 'enroll:token';
    public const SECONDS = 300;

    /** Buat token baru untuk UID, kembalikan token mentah (hex 32 karakter). */
    public static function issue(string $uid): string
    {
        $plain = bin2hex(random_bytes(16));

        Cache::put(self::KEY, [
            'uid'       => $uid,
            'hash'      => self::hash($plain),
            'confirmed' => false,
        ], self::SECONDS);

        return $plain;
    }

    /** ESP32 melapor token sudah tertulis di kartu. */
    public static function confirm(string $uid): bool
    {
        $pending = Cache::get(self::KEY);

        if (!is_array($pending) || ($pending['uid'] ?? null) !== $uid) {
            return false;
        }

        $pending['confirmed'] = true;
        Cache::put(self::KEY, $pending, self::SECONDS);

        return true;
    }

    /** Hash token yang sudah terkonfirmasi untuk UID ini, atau null. */
    public static function peekConfirmedHash(string $uid): ?string
    {
        $pending = Cache::get(self::KEY);

        if (
            is_array($pending)
            && ($pending['uid'] ?? null) === $uid
            && ($pending['confirmed'] ?? false) === true
        ) {
            return $pending['hash'];
        }

        return null;
    }

    public static function discard(): void
    {
        Cache::forget(self::KEY);
    }

    /** Status untuk admin. Tidak pernah memuat token atau hash. */
    public static function publicStatus(): ?array
    {
        $pending = Cache::get(self::KEY);

        if (!is_array($pending)) {
            return null;
        }

        return ['confirmed' => (bool) ($pending['confirmed'] ?? false)];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', strtolower($plain));
    }

    public static function matches(?string $storedHash, ?string $token): bool
    {
        if ($storedHash === null || $token === null) {
            return false;
        }

        return hash_equals($storedHash, self::hash($token));
    }
}
