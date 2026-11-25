<?php

namespace App\Http\Middleware;

use App\Models\Device;
use App\Models\School;
use App\Support\SchoolContext;
use Closure;
use Illuminate\Http\Request;

class DeviceKey
{
    public function handle(Request $request, Closure $next)
    {
        $key = $request->header('X-Device-Key');

        // Tanpa filter sekolah: sekolahnya baru diketahui dari perangkat ini
        $device = $key
            ? Device::withoutGlobalScopes()->where('api_key_hash', hash('sha256', $key))->first()
            : null;

        if (!$device) {
            return response()->json([
                'success' => false,
                'code' => 'UNAUTHORIZED',
                'message' => 'Kunci perangkat salah',
            ], 401);
        }

        $school = $device->school_id ? School::find($device->school_id) : null;

        if (!$school || !$school->is_active) {
            return response()->json([
                'success' => false,
                'code' => 'SCHOOL_DISABLED',
                'message' => 'Sekolah tidak aktif',
            ], 403);
        }

        $request->attributes->set('device', $device);
        SchoolContext::set($school->id);

        try {
            return $next($request);
        } finally {
            SchoolContext::clear();
        }
    }
}
