<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, BelongsToSchool;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role', // super_admin | admin | teacher | guardian
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /** super_admin milik sistem, bukan milik satu sekolah. */
    public function needsSchool(): bool
    {
        return $this->role !== 'super_admin';
    }

    /**
     * Tidak ditutup saat HTTP tanpa sekolah: login dan pencarian token Sanctum
     * terjadi sebelum sekolah diketahui. Begitu masuk, sekolah sudah ditetapkan
     * middleware Authenticate dan daftar pengguna ikut tersaring.
     */
    public function schoolScopeStrict(): bool
    {
        return false;
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'guardian_student');
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class);
    }
}
