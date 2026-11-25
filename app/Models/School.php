<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sekolah sendiri tidak diberi filter sekolah: dibaca oleh middleware
 * dan oleh super admin.
 */
class School extends Model
{
    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];
}
