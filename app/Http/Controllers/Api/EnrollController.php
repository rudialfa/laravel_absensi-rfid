<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CardToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class EnrollController extends Controller
{
    public const KEY_ACTIVE       = 'enroll:active';
    public const KEY_CAPTURED     = 'enroll:captured';
    public const SECONDS          = 60;   // lama mode daftar aktif
    public const CAPTURED_SECONDS = 300;  // hasil scan berlaku sampai admin menyimpan murid

    public function start()
    {
        Cache::forget(self::KEY_CAPTURED);
        CardToken::discard();
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
            'card_token' => CardToken::publicStatus(),
        ]);
    }

    public function cancel()
    {
        Cache::forget(self::KEY_ACTIVE);
        Cache::forget(self::KEY_CAPTURED);
        CardToken::discard();

        return response()->json(['success' => true, 'active' => false]);
    }

    /**
     * Dipanggil ESP32 (X-Device-Key) setelah token selesai ditulis ke kartu.
     * ok=false berarti penulisan gagal, token dibuang.
     */
    public function confirm(Request $request)
    {
        $v = Validator::make($request->all(), [
            'uid' => ['required', 'string', 'regex:/^[0-9A-Fa-f]{8,20}$/'],
        ]);

        if ($v->fails()) {
            return response()->json([
                'success' => false,
                'code'    => 'INVALID_UID',
                'message' => 'UID tidak valid',
            ], 422);
        }

        if (!$request->boolean('ok', true)) {
            CardToken::discard();

            return response()->json([
                'success' => true,
                'code'    => 'ENROLL_ABORTED',
                'message' => 'Pendaftaran token dibatalkan',
            ]);
        }

        if (!CardToken::confirm(strtoupper($request->input('uid')))) {
            return response()->json([
                'success' => false,
                'code'    => 'NO_PENDING',
                'message' => 'Tidak ada pendaftaran token yang menunggu untuk kartu ini',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'code'    => 'ENROLL_CONFIRMED',
            'message' => 'Token kartu tercatat',
        ]);
    }
}
