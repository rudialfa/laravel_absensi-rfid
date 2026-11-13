<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Student;
use App\Services\CardToken;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        $query = Student::query()->orderBy('name');

        if ($request->filled('q')) {
            $q = $request->query('q');
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                    ->orWhere('class', 'like', "%{$q}%")
                    ->orWhere('rfid_uid', strtoupper($q));
            });
        }

        $active = $request->query('active');
        if ($active === '1' || $active === '0') {
            $query->where('is_active', $active === '1');
        }

        return response()->json([
            'success' => true,
            'data'    => $query->get()->map(fn(Student $s) => $this->data($s)),
        ]);
    }

    public function show(Student $student)
    {
        return response()->json([
            'success' => true,
            'data'    => $this->data($student),
        ]);
    }

    public function store(Request $request)
    {
        $input = $request->all();
        if (isset($input['rfid_uid']) && is_string($input['rfid_uid'])) {
            $input['rfid_uid'] = strtoupper(trim($input['rfid_uid']));
        }

        $v = Validator::make($input, [
            'name'     => ['required', 'string', 'max:100'],
            'class'    => ['required', 'string', 'max:20'],
            'rfid_uid' => ['required', 'string', 'regex:/^[0-9A-F]{8}$/'],
        ]);

        if ($v->fails()) {
            return response()->json([
                'success' => false,
                'code'    => 'VALIDATION',
                'message' => 'Data tidak valid (nama, kelas, atau UID 8 karakter hex)',
                'errors'  => $v->errors(),
            ], 422);
        }

        if (Student::where('rfid_uid', $input['rfid_uid'])->exists()) {
            return response()->json([
                'success' => false,
                'code'    => 'UID_TAKEN',
                'message' => 'Kartu ini sudah dipakai murid lain',
            ], 422);
        }

        // Token terkonfirmasi dari hasil scan (kalau ada)
        $hash = CardToken::peekConfirmedHash($input['rfid_uid']);

        if ($hash === null && Setting::get('require_card_token', '0') === '1') {
            return response()->json([
                'success' => false,
                'code'    => 'TOKEN_NOT_READY',
                'message' => 'Kartu belum diberi token. Scan kartu dulu sampai token tercatat.',
            ], 422);
        }

        $student = Student::create([
            'name'            => $input['name'],
            'class'           => $input['class'],
            'rfid_uid'        => $input['rfid_uid'],
            'card_token_hash' => $hash,
            'is_active'       => true,
        ]);

        if ($hash !== null) {
            CardToken::discard();
            Cache::forget(EnrollController::KEY_CAPTURED);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->data($student),
        ], 201);
    }

    public function update(Request $request, Student $student)
    {
        // Sengaja hanya field ini. rfid_uid diubah lewat replaceCard().
        $v = Validator::make($request->all(), [
            'name'      => ['sometimes', 'required', 'string', 'max:100'],
            'class'     => ['sometimes', 'required', 'string', 'max:20'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);

        if ($v->fails()) {
            return response()->json([
                'success' => false,
                'code'    => 'VALIDATION',
                'message' => 'Data tidak valid',
                'errors'  => $v->errors(),
            ], 422);
        }

        $student->fill($v->validated())->save();

        return response()->json([
            'success' => true,
            'data'    => $this->data($student->fresh()),
        ]);
    }

    /**
     * Ganti kartu hilang. UID baru WAJIB berasal dari hasil scan (mode daftar),
     * tidak bisa dikirim lewat body. Token lama dihapus, token kartu baru dipasang
     * kalau sudah terkonfirmasi.
     */
    public function replaceCard(Student $student)
    {
        $captured = Cache::get(EnrollController::KEY_CAPTURED);

        if (!is_array($captured) || empty($captured['uid'])) {
            return response()->json([
                'success' => false,
                'code'    => 'NO_SCAN',
                'message' => 'Belum ada kartu baru yang di-scan. Tekan "Scan kartu" lalu tempelkan kartu.',
            ], 422);
        }

        $newUid = $captured['uid'];

        if ($newUid === $student->rfid_uid) {
            return response()->json([
                'success' => false,
                'code'    => 'SAME_CARD',
                'message' => 'Itu kartu milik murid ini sendiri',
            ], 422);
        }

        // Cek ulang ke database (bukan percaya hasil scan), karena data bisa berubah sejak scan
        if (Student::where('rfid_uid', $newUid)->exists()) {
            return response()->json([
                'success' => false,
                'code'    => 'UID_TAKEN',
                'message' => 'Kartu ini sudah dipakai murid lain',
            ], 422);
        }

        $newHash = CardToken::peekConfirmedHash($newUid);

        if ($newHash === null && Setting::get('require_card_token', '0') === '1') {
            return response()->json([
                'success' => false,
                'code'    => 'TOKEN_NOT_READY',
                'message' => 'Kartu belum diberi token. Scan kartu dulu sampai token tercatat.',
            ], 422);
        }

        $oldUid = $student->rfid_uid;

        try {
            $student->rfid_uid        = $newUid;
            $student->card_token_hash = $newHash; // token kartu lama tidak berlaku untuk kartu baru
            $student->save();
        } catch (QueryException $e) {
            // Dua admin mengganti ke UID yang sama bersamaan: salah satu kalah di UNIQUE
            if ($e->getCode() === '23000') {
                return response()->json([
                    'success' => false,
                    'code'    => 'UID_TAKEN',
                    'message' => 'Kartu ini sudah dipakai murid lain',
                ], 422);
            }
            throw $e;
        }

        // Hasil scan dan token hanya boleh dipakai sekali
        Cache::forget(EnrollController::KEY_CAPTURED);
        CardToken::discard();

        return response()->json([
            'success' => true,
            'data'    => $this->data($student->fresh()),
            'old_uid' => $oldUid,
        ]);
    }

    private function data(Student $s): array
    {
        return [
            'id'        => $s->id,
            'name'      => $s->name,
            'class'     => $s->class,
            'rfid_uid'  => $s->rfid_uid,
            'is_active' => (bool) $s->is_active,
            'has_token' => $s->card_token_hash !== null,
        ];
    }
}
