<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    protected $guarded = [];

    protected $hidden = ['card_token_hash'];

    protected $casts = ['is_active' => 'boolean'];

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'guardian_student');
    }
}
