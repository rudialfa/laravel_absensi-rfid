<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DeviceTokenController extends Controller
{
    public function store(Request $request)
    {
        $v = Validator::make($request->all(), [
            'fcm_token' => ['required', 'string', 'max:255'],
        ]);

        if ($v->fails()) {
            return response()->json([
                'success' => false,
                'code'    => 'VALIDATION',
                'message' => 'fcm_token wajib diisi',
            ], 422);
        }

        // Satu token = satu HP. Kalau HP yang sama login sebagai user lain,
        // token berpindah ke user yang sedang login.
        DeviceToken::updateOrCreate(
            ['fcm_token' => $request->input('fcm_token')],
            ['user_id' => $request->user()->id]
        );

        return response()->json(['success' => true]);
    }
}
