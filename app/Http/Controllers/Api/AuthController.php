<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $v = Validator::make($request->all(), [
            'email'    => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        if ($v->fails()) {
            return response()->json([
                'success' => false,
                'code'    => 'VALIDATION',
                'message' => 'Email dan password wajib diisi',
            ], 422);
        }

        $user = User::where('email', strtolower(trim($request->input('email'))))->first();

        if (!$user || !Hash::check($request->input('password'), $user->password)) {
            return response()->json([
                'success' => false,
                'code'    => 'INVALID_CREDENTIALS',
                'message' => 'Email atau password salah',
            ], 401);
        }

        // Guru yang dinonaktifkan tidak boleh masuk
        if ($user->role === 'teacher') {
            $teacher = $user->teacher;
            if ($teacher && !$teacher->is_active) {
                return response()->json([
                    'success' => false,
                    'code'    => 'ACCOUNT_DISABLED',
                    'message' => 'Akun dinonaktifkan. Hubungi admin.',
                ], 403);
            }
        }

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'success' => true,
            'token'   => $token,
            'user'    => $this->userData($user),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'success' => true,
            'user'    => $this->userData($request->user()),
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        // Hapus token FCM HP ini supaya tidak menerima notifikasi setelah logout
        if ($request->filled('fcm_token')) {
            DeviceToken::where('user_id', $user->id)
                ->where('fcm_token', $request->input('fcm_token'))
                ->delete();
        }

        $user->currentAccessToken()->delete();

        return response()->json(['success' => true]);
    }

    private function userData(User $user): array
    {
        return [
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
            'role'  => $user->role,
        ];
    }
}
