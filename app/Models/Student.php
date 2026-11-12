<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];

    public function attendances(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(Attendance::class);
}

    public function guardians(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'guardian_student');
}
}
