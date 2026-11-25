<?php

namespace App\Http\Middleware;

use App\Models\School;
use App\Support\SchoolContext;
use Closure;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Setelah pengguna terbukti sah, tetapkan sekolahnya. Dilakukan di sini
     * (bukan middleware terpisah) supaya sudah siap sebelum route model binding.
     */
    public function handle($request, Closure $next, ...$guards)
    {
        return parent::handle($request, function ($request) use ($next) {
            $user = $request->user();

            if ($user && $user->role !== 'super_admin') {
                $school = $user->school_id ? School::find($user->school_id) : null;

                if (!$school) {
                    return $this->deny('NO_SCHOOL', 'Akun belum terhubung ke sekolah');
                }

                if (!$school->is_active) {
                    return $this->deny('SCHOOL_DISABLED', 'Sekolah dinonaktifkan. Hubungi pengelola.');
                }

                SchoolContext::set($school->id);
            } else {
                SchoolContext::clear();
            }

            try {
                return $next($request);
            } finally {
                SchoolContext::clear();
            }
        }, ...$guards);
    }

    private function deny(string $code, string $message)
    {
        return response()->json([
            'success' => false,
            'code'    => $code,
            'message' => $message,
        ], 403);
    }

    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        return $request->expectsJson() ? null : route('login');
    }
}
