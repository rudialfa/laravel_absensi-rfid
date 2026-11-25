<?php

namespace App\Support;

use Illuminate\Validation\DatabasePresenceVerifier;

/**
 * Aturan "exists:students,id" dan sejenisnya hanya menemukan data sekolah aktif.
 * Tanpa ini, admin sekolah A bisa menautkan wali ke murid sekolah B.
 */
class SchoolPresenceVerifier extends DatabasePresenceVerifier
{
    private const TABLES = ['students', 'teachers', 'classes', 'devices'];

    public function getCount($collection, $column, $value, $excludeId = null, $idColumn = null, array $extra = [])
    {
        return parent::getCount(
            $collection,
            $column,
            $value,
            $excludeId,
            $idColumn,
            $this->withSchool($collection, $extra)
        );
    }

    public function getMultiCount($collection, $column, array $values, array $extra = [])
    {
        return parent::getMultiCount($collection, $column, $values, $this->withSchool($collection, $extra));
    }

    private function withSchool(string $collection, array $extra): array
    {
        $id = SchoolContext::id();

        if ($id !== null && in_array($collection, self::TABLES, true)) {
            $extra['school_id'] = $id;
        }

        return $extra;
    }
}
