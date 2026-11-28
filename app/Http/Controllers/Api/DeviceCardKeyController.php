<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\SchoolContext;

/**
 * GET /api/device/card-key (X-Device-Key)
 * ESP32 mengambil kunci kartu sekolahnya sendiri lalu menyimpannya di NVS.
 * Kunci tidak pernah lewat HP admin.
 */
class DeviceCardKeyController extends Controller
{
    public function __invoke()
    {
        $school = School::find(SchoolContext::id());
        $key = $school?->card_key;

        if (!$key) {
            return response()->json([
                'success' => false,
                'code'    => 'NO_CARD_KEY',
                'message' => 'Kunci kartu sekolah belum diatur',
            ], 404);
        }

        return response()
            ->json([
                'success'           => true,
                'card_key'          => $key,
                // Kunci sebelum putaran terakhir (null kalau belum pernah diganti)
                'previous_card_key' => $school->card_key_previous,
            ])
            ->header('Cache-Control', 'no-store');
    }
}
