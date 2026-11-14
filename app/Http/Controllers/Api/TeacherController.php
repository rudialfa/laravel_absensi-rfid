<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Teacher;
use App\Models\User;
use App\Services\CardOwner;
use App\Services\CardToken;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class TeacherController extends Controller
{
    // Sengaja tidak memakai aturan "email" bawaan (ada advisory CRLF di Laravel 10)
    private const EMAIL_REGEX = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/';

    public function index()
    {
        $teachers = Teacher::with(['user', 'classes'])->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data'    => $teachers->map(fn(Teacher $t) => $this->data($t)),
        ]);
    }

    public function show(Teacher $teacher)
    {
        $teacher->load(['user', 'classes']);

        return response()->json([
            'success' => true,
            'data'    => $this->data($teacher),
        ]);
    }

    public function store(Request $request)
    {
        $input = $request->all();
        if (isset($input['rfid_uid']) && is_string($input['rfid_uid'])) {
            $input['rfid_uid'] = strtoupper(trim($input['rfid_uid']));
        }
        if (isset($input['email']) && is_string($input['email'])) {
            $input['email'] = strtolower(trim($input['email']));
        }

        $v = Validator::make($input, [
            'name'        => ['required', 'string', 'max:100'],
            'email'       => ['required', 'string', 'max:255', 'regex:' . self::EMAIL_REGEX],
            'rfid_uid'    => ['required', 'string', 'regex:/^[0-9A-F]{8}$/'],
            'class_ids'   => ['sometimes', 'array'],
            'class_ids.*' => ['integer', 'exists:classes,id'],
        ]);

        if ($v->fails()) {
            return $this->validation($v);
        }

        if (User::where('email', $input['email'])->exists()) {
            return $this->fail('EMAIL_TAKEN', 'Email sudah dipakai', 422);
        }

        if (CardOwner::exists($input['rfid_uid'])) {
            return $this->fail('UID_TAKEN', 'Kartu ini sudah dipakai orang lain', 422);
        }

        $hash = CardToken::peekConfirmedHash($input['rfid_uid']);

        if ($hash === null && Setting::get('require_card_token', '0') === '1') {
            return $this->fail('TOKEN_NOT_READY', 'Kartu belum diberi token. Scan kartu dulu sampai token tercatat.', 422);
        }

        $plain = Str::random(12);

        try {
            $teacher = DB::transaction(function () use ($input, $plain, $hash) {
                $user = User::create([
                    'name'     => $input['name'],
                    'email'    => $input['email'],
                    'role'     => 'teacher',
                    'password' => Hash::make($plain),
                ]);

                $teacher = Teacher::create([
                    'user_id'         => $user->id,
                    'name'            => $input['name'],
                    'rfid_uid'        => $input['rfid_uid'],
                    'card_token_hash' => $hash,
                    'is_active'       => true,
                ]);

                $teacher->classes()->sync($input['class_ids'] ?? []);

                return $teacher;
            });
        } catch (QueryException $e) {
            // Dua admin mendaftarkan email atau UID yang sama bersamaan
            if ($e->getCode() === '23000') {
                return $this->fail('CONFLICT', 'Email atau kartu sudah dipakai', 422);
            }
            throw $e;
        }

        if ($hash !== null) {
            CardToken::discard();
            Cache::forget(EnrollController::KEY_CAPTURED);
        }

        $teacher->load(['user', 'classes']);

        return response()->json([
            'success'  => true,
            'data'     => $this->data($teacher),
            'password' => $plain, // hanya tampil sekali
        ], 201);
    }

    public function update(Request $request, Teacher $teacher)
    {
        // rfid_uid diubah lewat replaceCard()
        $v = Validator::make($request->all(), [
            'name'        => ['sometimes', 'required', 'string', 'max:100'],
            'is_active'   => ['sometimes', 'required', 'boolean'],
            'class_ids'   => ['sometimes', 'array'],
            'class_ids.*' => ['integer', 'exists:classes,id'],
        ]);

        if ($v->fails()) {
            return $this->validation($v);
        }

        $data = $v->validated();

        DB::transaction(function () use ($teacher, $data) {
            $teacher->fill(Arr::only($data, ['name', 'is_active']))->save();

            if (isset($data['name']) && $teacher->user) {
                $teacher->user->update(['name' => $data['name']]);
            }

            if (array_key_exists('class_ids', $data)) {
                $teacher->classes()->sync($data['class_ids']);
            }

            // Guru dinonaktifkan: semua token login dicabut
            if (array_key_exists('is_active', $data) && !$data['is_active'] && $teacher->user) {
                $teacher->user->tokens()->delete();
            }
        });

        $teacher->load(['user', 'classes']);

        return response()->json([
            'success' => true,
            'data'    => $this->data($teacher),
        ]);
    }

    /**
     * Ganti kartu guru. UID baru WAJIB berasal dari hasil scan (mode daftar).
     */
    public function replaceCard(Teacher $teacher)
    {
        $captured = Cache::get(EnrollController::KEY_CAPTURED);

        if (!is_array($captured) || empty($captured['uid'])) {
            return $this->fail('NO_SCAN', 'Belum ada kartu baru yang di-scan. Tekan "Scan kartu" lalu tempelkan kartu.', 422);
        }

        $newUid = $captured['uid'];

        if ($newUid === $teacher->rfid_uid) {
            return $this->fail('SAME_CARD', 'Itu kartu milik guru ini sendiri', 422);
        }

        if (CardOwner::exists($newUid)) {
            return $this->fail('UID_TAKEN', 'Kartu ini sudah dipakai orang lain', 422);
        }

        $newHash = CardToken::peekConfirmedHash($newUid);

        if ($newHash === null && Setting::get('require_card_token', '0') === '1') {
            return $this->fail('TOKEN_NOT_READY', 'Kartu belum diberi token. Scan kartu dulu sampai token tercatat.', 422);
        }

        $oldUid = $teacher->rfid_uid;

        try {
            $teacher->rfid_uid        = $newUid;
            $teacher->card_token_hash = $newHash;
            $teacher->save();
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                return $this->fail('UID_TAKEN', 'Kartu ini sudah dipakai orang lain', 422);
            }
            throw $e;
        }

        Cache::forget(EnrollController::KEY_CAPTURED);
        CardToken::discard();

        $teacher->load(['user', 'classes']);

        return response()->json([
            'success' => true,
            'data'    => $this->data($teacher),
            'old_uid' => $oldUid,
        ]);
    }

    private function data(Teacher $t): array
    {
        return [
            'id'        => $t->id,
            'user_id'   => $t->user_id,
            'name'      => $t->name,
            'email'     => $t->user?->email,
            'rfid_uid'  => $t->rfid_uid,
            'is_active' => (bool) $t->is_active,
            'has_token' => $t->card_token_hash !== null,
            'classes'   => $t->classes->map(fn($c) => ['id' => $c->id, 'name' => $c->name])->values(),
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
