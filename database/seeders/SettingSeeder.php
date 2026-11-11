<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'late_time'        => '07:15',
            'checkout_start'   => '12:00',
            'cooldown_seconds' => '10',
            'lcd_idle'         => 'Tempel kartu',
        ];

        foreach ($defaults as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
