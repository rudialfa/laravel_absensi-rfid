<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class GuardianController extends Controller
{
    // Sengaja tidak memakai aturan "email" bawaan (ada advisory CRLF di Laravel 10)
    private const EMAIL_REGEX = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/';

    public function index()
    {
        $guardians = User::where('role', 'guardian')
            ->with('students:id,name')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $guardians->map(fn(User $u) => $this->data($u)),
        ]);
    }

    public function store(Request $request)
    {
        $input = $request->all();
        if (isset($input['email']) && is_string($input['email'])) {
            $input['email'] = strtolower(trim($input['email']));
        }

        $v = Validator::make($input, [
            'name'          => ['required', 'string', 'max:100'],
            'email'         => ['required', 'string', 'max:255', 'regex:' . self::EMAIL_REGEX],
            'student_ids'   => ['sometimes', 'array'],
            'student_ids.*' => ['integer', 'exists:students,id'],
        ]);

        if ($v->fails()) {
            return $this->validation($v);
        }

        if (User::where('email', $input['email'])->exists()) {
            return $this->fail('EMAIL_TAKEN', 'Email sudah dipakai', 422);
        }

        $plain = Str::random(12);

        try {
            $user = DB::transaction(function () use ($input, $plain) {
                $user = User::create([
                    'name'     => $input['name'],
                    'email'    => $input['email'],
                    'role'     => 'guardian',
                    'password' => Hash::make($plain),
                ]);

                $user->students()->sync($input['student_ids'] ?? []);

                return $user;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                return $this->fail('EMAIL_TAKEN', 'Email sudah dipakai', 422);
            }
            throw $e;
        }

        $user->load('students:id,name');

        return response()->json([
            'success'  => true,
            'data'     => $this->data($user),
            'password' => $plain, // hanya tampil sekali
        ], 201);
    }

    public function update(Request $request, User $guardian)
    {
        if ($guardian->role !== 'guardian') {
            return $this->fail('NOT_FOUND', 'Wali tidak ditemukan', 404);
        }

        $v = Validator::make($request->all(), [
            'name'          => ['sometimes', 'required', 'string', 'max:100'],
            'student_ids'   => ['sometimes', 'array'],
            'student_ids.*' => ['integer', 'exists:students,id'],
        ]);

        if ($v->fails()) {
            return $this->validation($v);
        }

        $data = $v->validated();

        DB::transaction(function () use ($guardian, $data) {
            if (isset($data['name'])) {
                $guardian->update(['name' => $data['name']]);
            }

            if (array_key_exists('student_ids', $data)) {
                $guardian->students()->sync($data['student_ids']);
            }
        });

        $guardian->load('students:id,name');

        return response()->json([
            'success' => true,
            'data'    => $this->data($guardian),
        ]);
    }

    public function destroy(User $guardian)
    {
        if ($guardian->role !== 'guardian') {
            return $this->fail('NOT_FOUND', 'Wali tidak ditemukan', 404);
        }

        DB::transaction(function () use ($guardian) {
            $guardian->tokens()->delete();
            // device_tokens dan guardian_student ikut terhapus (cascade)
            $guardian->delete();
        });

        return response()->json(['success' => true]);
    }

    /** Reset password akun wali atau guru. Akun admin tidak boleh direset dari sini. */
    public function resetPassword(User $user)
    {
        if (!in_array($user->role, ['guardian', 'teacher'], true)) {
            return $this->fail('NOT_ALLOWED', 'Akun ini tidak bisa direset dari sini', 422);
        }

        $plain = Str::random(12);

        DB::transaction(function () use ($user, $plain) {
            $user->update(['password' => Hash::make($plain)]);
            $user->tokens()->delete(); // semua sesi lama keluar
        });

        return response()->json([
            'success'  => true,
            'password' => $plain, // hanya tampil sekali
        ]);
    }

    private function data(User $u): array
    {
        return [
            'id'       => $u->id,
            'name'     => $u->name,
            'email'    => $u->email,
            'students' => $u->students->map(fn($s) => ['id' => $s->id, 'name' => $s->name])->values(),
        ];
    }

    private function validation($validator)
    {
        return response()->json([
            'success' => false,
            'code'    => 'VALIDATION',
            'message' => 'Data tidak valid',
            'errors'  => $validator->errors(),
        ], 422);
    }

    private function fail(string $code, string $message, int $http)
    {
        return response()->json([
            'success' => false,
            'code'    => $code,
            'message' => $message,
        ], $http);
    }
}
