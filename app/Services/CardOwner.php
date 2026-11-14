<?php

namespace App\Services;

use App\Models\Student;
use App\Models\Teacher;

/**
 * Satu UID hanya boleh dimiliki satu orang, baik murid maupun guru.
 */
class CardOwner
{
    /** @return array{type: string, model: Student|Teacher}|null */
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

    public static function exists(string $uid): bool
    {
        return Student::where('rfid_uid', $uid)->exists()
            || Teacher::where('rfid_uid', $uid)->exists();
    }
}
