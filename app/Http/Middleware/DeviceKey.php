<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;

class DeviceKey
{
    public function handle(Request $request, Closure $next)
    {
        $key = $request->header('X-Device-Key');

        $device = $key
            ? Device::where('api_key_hash', hash('sha256', $key))->first()
            : null;

        if (!$device) {
            return response()->json([
                'success' => false,
                'code' => 'UNAUTHORIZED',
                'message' => 'Kunci perangkat salah',
            ], 401);
        }

        $request->attributes->set('device', $device);

        return $next($request);
    }
}
