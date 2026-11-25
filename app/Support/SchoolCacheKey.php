<?php

namespace App\Support;

/**
 * Kunci Cache per sekolah (mode daftar kartu, token kartu tertunda).
 *
 * Sekolah pertama memakai kunci lama apa adanya (mis. "enroll:active"), jadi
 * perintah tes lama dan data yang sedang berjalan tetap cocok. Sekolah lain
 * mendapat akhiran id-nya (mis. "enroll:active:2").
 */
class SchoolCacheKey
{
    public static function for(string $base): string
    {
        $id = SchoolContext::idForNewRecord();

        if ($id === null || $id === SchoolContext::defaultId()) {
            return $base;
        }

        return $base . ':' . $id;
    }
}
