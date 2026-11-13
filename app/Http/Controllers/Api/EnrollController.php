<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;

class EnrollController extends Controller
{
    public const KEY_ACTIVE   = 'enroll:active';
    public const KEY_CAPTURED = 'enroll:captured';
    public const SECONDS      = 60;

    public function start()
    {
        Cache::forget(self::KEY_CAPTURED);
        Cache::put(self::KEY_ACTIVE, now()->addSeconds(self::SECONDS)->timestamp, self::SECONDS);

        return response()->json([
            'success'    => true,
            'active'     => true,
            'expires_in' => self::SECONDS,
        ]);
    }

    public function status()
    {
        $until = Cache::get(self::KEY_ACTIVE);
        $active = $until !== null;

        return response()->json([
            'success'    => true,
            'active'     => $active,
            'expires_in' => $active ? max(0, $until - now()->timestamp) : 0,
            'captured'   => Cache::get(self::KEY_CAPTURED),
        ]);
    }

    public function cancel()
    {
        Cache::forget(self::KEY_ACTIVE);
        Cache::forget(self::KEY_CAPTURED);

        return response()->json(['success' => true, 'active' => false]);
    }
}
