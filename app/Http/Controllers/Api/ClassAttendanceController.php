<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\ClassRoom;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ClassAttendanceController extends Controller
{
    /** Kelas yang diampu guru yang sedang login. */
    public function myClasses(Request $request)
    {
        $teacher = $request->user()->teacher;

        if (!$teacher || !$teacher->is_active) {
            return $this->fail('FORBIDDEN', 'Akun guru tidak aktif', 403);
        }

        $classes = $teacher->classes()
            ->withCount(['students as active_students_count' => fn($q) => $q->where('is_active', true)])
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $classes->map(fn(ClassRoom $c) => [
                'id'            => $c->id,
                'name'          => $c->name,
                'student_count' => (int) $c->active_students_count,
            ])->values(),
        ]);
    }

    /** Daftar hadir satu kelas pada satu tanggal. Guru hanya untuk kelas yang dia ampu. */
    public function show(Request $request, ClassRoom $classRoom)
    {
        $user = $request->user();

        // Hak akses dicek lebih dulu, dan selalu dari data terbaru di database
        if ($user->role === 'teacher') {
            $teacher = $user->teacher;

            $allowed = $teacher
                && $teacher->is_active
                && $teacher->classes()->where('classes.id', $classRoom->id)->exists();

            if (!$allowed) {
                return $this->fail('FORBIDDEN_CLASS', 'Anda tidak mengampu kelas ini', 403);
            }
        }

        $v = Validator::make($request->query(), [
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if ($v->fails()) {
            return $this->fail('VALIDATION', 'Format tanggal harus YYYY-MM-DD', 422);
        }

        $date = $request->query('date') ?: now()->toDateString();

        $students = $classRoom->students()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $records = Attendance::whereIn('student_id', $students->pluck('id'))
            ->where('date', $date)
            ->get()
            ->keyBy('student_id');

        $rows = $students->map(function ($s) use ($records) {
            $a = $records->get($s->id);

            return [
                'student'        => ['id' => $s->id, 'name' => $s->name],
                'status'         => $a ? $a->status : 'belum_hadir',
                'checked_in_at'  => $a?->checked_in_at->format('H:i'),
                'checked_out_at' => $a?->checked_out_at?->format('H:i'),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'date'    => $date,
            'class'   => ['id' => $classRoom->id, 'name' => $classRoom->name],
            'summary' => [
                'total'        => $rows->count(),
                'hadir'        => $rows->where('status', 'hadir')->count(),
                'terlambat'    => $rows->where('status', 'terlambat')->count(),
                'belum_hadir'  => $rows->where('status', 'belum_hadir')->count(),
                'sudah_pulang' => $rows->whereNotNull('checked_out_at')->count(),
            ],
            'data'    => $rows,
        ]);
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
