<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;

class ClassRoom extends Model
{
    protected $table = 'classes';

    protected $guarded = [];

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'class_id');
    }

    public static function normalize(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', $name));
    }

    /** Cari kelas dari nama, tanpa membedakan huruf besar kecil. */
    public static function findByName(string $name): ?self
    {
        return static::whereRaw('LOWER(name) = ?', [mb_strtolower(static::normalize($name))])->first();
    }

    public static function findOrCreateByName(string $name): self
    {
        $name = static::normalize($name);

        if ($found = static::findByName($name)) {
            return $found;
        }

        try {
            return static::create(['name' => $name]);
        } catch (QueryException $e) {
            // Dua permintaan membuat kelas yang sama bersamaan: yang kalah mengambil yang sudah ada
            if ($e->getCode() === '23000' && ($found = static::findByName($name))) {
                return $found;
            }
            throw $e;
        }
    }
}
