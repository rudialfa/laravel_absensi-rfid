<?php

namespace App\Services;

use App\Models\Student;
use App\Models\Teacher;

/**
 * Satu UID hanya boleh dimiliki satu orang di seluruh sistem, baik murid maupun
 * guru, di sekolah mana pun.
 */
class CardOwner
{
    /**
     * Pemilik kartu di sekolah aktif. Kartu milik sekolah lain tidak ditemukan
     * di sini, supaya data sekolah lain tidak terlihat.
     *
     * @return array{type: string, model: Student|Teacher}|null
     */
    public static function find(string $uid): ?array
    {
        if ($student = Student::where('rfid_uid', $uid)->first()) {
            return ['type' => 'student', 'model' => $student];
        }

        if ($teacher = Teacher::where('rfid_uid', $uid)->first()) {
            return ['type' => 'teacher', 'model' => $teacher];
        }

        return null;
    }

    /** Sudah dipakai di sekolah mana pun? (tidak disaring per sekolah) */
    public static function exists(string $uid): bool
    {
        return Student::withoutGlobalScopes()->where('rfid_uid', $uid)->exists()
            || Teacher::withoutGlobalScopes()->where('rfid_uid', $uid)->exists();
    }
}
