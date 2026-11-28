<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sekolah sendiri tidak diberi filter sekolah: dibaca oleh middleware
 * dan oleh super admin.
 */
class School extends Model
{
    /** Setelan awal untuk sekolah baru (sama dengan nilai bawaan SettingController). */
    public const DEFAULT_SETTINGS = [
        'late_time'          => '07:15',
        'checkout_start'     => '12:00',
        'cooldown_seconds'   => '10',
        'lcd_idle'           => 'Tempel kartu',
        'require_card_token' => '0',
    ];

    protected $guarded = [];

    /** Kunci kartu tidak pernah ikut keluar lewat toArray atau JSON. */
    protected $hidden = ['card_key', 'card_key_previous'];

    protected $casts = [
        'is_active'           => 'boolean',
        'card_key'            => 'encrypted',
        'card_key_previous'   => 'encrypted',
        'card_key_rotated_at' => 'datetime',
    ];
}
