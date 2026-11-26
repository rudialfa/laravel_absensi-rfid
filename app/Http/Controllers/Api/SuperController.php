<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Setting;
use App\Models\User;
use App\Support\CardKey;
use App\Support\SchoolContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Pengelola sistem (super_admin): sekolah, admin sekolah, kunci kartu. */
class SuperController extends Controller
{
    // GET /api/super/schools
    public function index()
    {
        return response()->json([
            'success' => true,
            'data'    => School::orderBy('id')->get()->map(fn(School $s) => $this->summary($s))->values(),
        ]);
    }

    // POST /api/super/schools  {name, admin_name, admin_email}
    public function store(Request $request)
    {
        $v = Validator::make($request->all(), [
            'name'        => ['required', 'string', 'max:100'],
            'admin_name'  => ['required', 'string', 'max:100'],
            'admin_email' => ['required', 'email', 'max:255'],
        ]);

        if ($v->fails()) {
            return $this->validation($v);
        }

        $name  = trim($request->input('name'));
        $email = strtolower(trim($request->input('admin_email')));

        if ($this->nameTaken($name)) {
            return $this->fail('SCHOOL_EXISTS', 'Nama sekolah sudah dipakai', 422);
        }
        if ($this->emailTaken($email)) {
            return $this->fail('EMAIL_TAKEN', 'Email sudah dipakai', 422);
        }

        try {
            [$school, $admin, $plain] = DB::transaction(function () use ($request, $name, $email) {
                $school = School::create([
                    'name'                => $name,
                    'is_active'           => true,
                    'card_key'            => CardKey::generate(),
                    'card_key_rotated_at' => now(),
                ]);

                // Setelan awal: sekolah baru langsung punya jam, cooldown, dan teks LCD
                SchoolContext::run($school->id, function () {
                    foreach (School::DEFAULT_SETTINGS as $key => $value) {
                        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
                    }
                });

                [$admin, $plain] = $this->makeAdmin($school, trim($request->input('admin_name')), $email);

                return [$school, $admin, $plain];
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                return $this->fail('CONFLICT', 'Nama sekolah atau email sudah dipakai', 422);
            }
            throw $e;
        }

        return response()->json([
            'success' => true,
            'data'    => $this->summary($school),
            'admin'   => [
                'id'       => $admin->id,
                'name'     => $admin->name,
                'email'    => $admin->email,
                'password' => $plain, // hanya tampil sekali
            ],
        ], 201);
    }

    // PUT /api/super/schools/{school}  {name?, is_active?}
    public function update(Request $request, School $school)
    {
        $v = Validator::make($request->all(), [
            'name'      => ['sometimes', 'required', 'string', 'max:100'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);

        if ($v->fails()) {
            return $this->validation($v);
        }

        $data = $v->validated();

        if (empty($data)) {
            return $this->fail('VALIDATION', 'Tidak ada data yang dikirim', 422);
        }

        if (array_key_exists('name', $data)) {
            $data['name'] = trim($data['name']);

            if ($this->nameTaken($data['name'], $school->id)) {
                return $this->fail('SCHOOL_EXISTS', 'Nama sekolah sudah dipakai', 422);
            }
        }

        $school->update($data);

        return response()->json(['success' => true, 'data' => $this->summary($school->fresh())]);
    }

    // GET /api/super/schools/{school}/admins
    public function admins(School $school)
    {
        $admins = User::where('school_id', $school->id)
            ->where('role', 'admin')
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'created_at']);

        return response()->json([
            'success' => true,
            'data'    => $admins->map(fn(User $u) => [
                'id'         => $u->id,
                'name'       => $u->name,
                'email'      => $u->email,
                'created_at' => $u->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    // POST /api/super/schools/{school}/admins  {name, email}
    public function storeAdmin(Request $request, School $school)
    {
        $v = Validator::make($request->all(), [
            'name'  => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        if ($v->fails()) {
            return $this->validation($v);
        }

        $email = strtolower(trim($request->input('email')));

        if ($this->emailTaken($email)) {
            return $this->fail('EMAIL_TAKEN', 'Email sudah dipakai', 422);
        }

        try {
            [$admin, $plain] = $this->makeAdmin($school, trim($request->input('name')), $email);
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                return $this->fail('EMAIL_TAKEN', 'Email sudah dipakai', 422);
            }
            throw $e;
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'id'       => $admin->id,
                'name'     => $admin->name,
                'email'    => $admin->email,
                'password' => $plain, // hanya tampil sekali
            ],
        ], 201);
    }

    // POST /api/super/admins/{user}/reset-password
    public function resetAdminPassword(User $user)
    {
        if ($user->role !== 'admin') {
            return $this->fail('NOT_ALLOWED', 'Hanya akun admin sekolah yang bisa direset dari sini', 422);
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

    // POST /api/super/schools/{school}/card-key/rotate
    // Kunci baru acak. Kartu yang sudah didaftarkan dengan kunci lama harus didaftarkan ulang.
    public function rotateCardKey(School $school)
    {
        $school->update([
            'card_key'            => CardKey::generate(),
            'card_key_rotated_at' => now(),
        ]);

        return response()->json(['success' => true, 'data' => $this->summary($school->fresh())]);
    }

    // PUT /api/super/schools/{school}/card-key  {card_key}
    // Mengisi kunci yang sudah dipakai kartu lama, supaya kartu itu tetap terbaca.
    public function setCardKey(Request $request, School $school)
    {
        $key = (string) $request->input('card_key', '');

        if ($problem = CardKey::problem($key)) {
            return $this->fail('VALIDATION', $problem, 422);
        }

        $school->update([
            'card_key'            => strtoupper($key),
            'card_key_rotated_at' => now(),
        ]);

        return response()->json(['success' => true, 'data' => $this->summary($school->fresh())]);
    }

    /** Kunci tidak pernah ikut: hanya penanda has_card_key. */
    private function summary(School $s): array
    {
        $count = fn(string $table) => DB::table($table)->where('school_id', $s->id)->count();

        return [
            'id'           => $s->id,
            'name'         => $s->name,
            'is_active'    => (bool) $s->is_active,
            'students'     => $count('students'),
            'teachers'     => $count('teachers'),
            'devices'      => $count('devices'),
            'admins'       => DB::table('users')->where('school_id', $s->id)->where('role', 'admin')->count(),
            'has_card_key' => $s->getRawOriginal('card_key') !== null,
            'created_at'   => $s->created_at?->toIso8601String(),
        ];
    }

    /** @return array{0: User, 1: string} */
    private function makeAdmin(School $school, string $name, string $email): array
    {
        $plain = Str::random(12);

        $admin = new User([
            'name'     => $name,
            'email'    => $email,
            'password' => Hash::make($plain),
            'role'     => 'admin',
        ]);
        $admin->school_id = $school->id;
        $admin->save();

        return [$admin, $plain];
    }

    private function nameTaken(string $name, ?int $exceptId = null): bool
    {
        return School::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($exceptId, fn($q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }

    private function emailTaken(string $email): bool
    {
        return User::withoutGlobalScopes()->where('email', $email)->exists();
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
