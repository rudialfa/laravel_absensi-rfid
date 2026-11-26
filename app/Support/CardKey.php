<?php

namespace App\Support;

/** Kunci kartu MIFARE: 6 byte, ditulis sebagai 12 karakter heksadesimal huruf besar. */
class CardKey
{
    public static function generate(): string
    {
        do {
            $key = strtoupper(bin2hex(random_bytes(6)));
        } while (self::problem($key) !== null);

        return $key;
    }

    /** Pesan kesalahan kalau kunci tidak boleh dipakai, null kalau boleh. */
    public static function problem(string $key): ?string
    {
        if (!preg_match('/^[0-9A-Fa-f]{12}$/', $key)) {
            return 'Kunci kartu harus 12 karakter heksadesimal (0-9, A-F).';
        }

        $key = strtoupper($key);

        if ($key === 'FFFFFFFFFFFF' || $key === '000000000000') {
            return 'Kunci kartu tidak boleh FFFFFFFFFFFF atau 000000000000.';
        }

        return null;
    }
}
