<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Setelan per sekolah. Satu baris per (sekolah, key); sekolah aktif
 * ditentukan otomatis oleh BelongsToSchool.
 */
class Setting extends Model
{
    use BelongsToSchool;

    protected $table = 'school_settings';

    public $timestamps = false;

    protected $guarded = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::where('key', $key)->value('value') ?? $default;
    }
}
