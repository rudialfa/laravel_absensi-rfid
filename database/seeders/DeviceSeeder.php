<?php

namespace Database\Seeders;

use App\Models\Device;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DeviceSeeder extends Seeder
{
    public function run(): void
    {
        $plain = Str::random(40);

        Device::create([
            'name' => 'Gerbang Utama',
            'api_key_hash' => hash('sha256', $plain),
        ]);

        $this->command->warn("DEVICE KEY (simpan sekarang, tidak bisa dilihat lagi): {$plain}");
    }
}
