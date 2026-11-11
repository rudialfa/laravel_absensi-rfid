<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;

class DeviceConfigController extends Controller
{
    public function __invoke()
    {
        return response()->json([
            'late_time'        => Setting::get('late_time', '07:15'),
            'checkout_start'   => Setting::get('checkout_start', '12:00'),
            'cooldown_seconds' => (int) Setting::get('cooldown_seconds', '10'),
            'lcd_idle'         => Setting::get('lcd_idle', 'Tempel kartu'),
        ]);
    }
}
