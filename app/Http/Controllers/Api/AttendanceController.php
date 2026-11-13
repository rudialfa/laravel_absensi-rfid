<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $v = Validator::make($request->query(), [
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if ($v->fails()) {
            return response()->json([
                'success' => false,
                'code'    => 'VALIDATION',
                'message' => 'Format tanggal harus YYYY-MM-DD',
            ], 422);
        }

        $date = $request->query('date') ?: now()->toDateString();
        $user = $request->user();

        $query = Attendance::with('student:id,name,class')
            ->where('date', $date)
            ->orderBy('checked_in_at');

        // Wali hanya boleh melihat anaknya sendiri (dijaga di sini, di server)
        if ($user->role === 'guardian') {
            $query->whereIn('student_id', $user->students()->pluck('students.id'));
        }

        $data = $query->get()->map(fn(Attendance $a) => [
            'id'             => $a->id,
            'student'        => [
                'id'    => $a->student->id,
                'name'  => $a->student->name,
                'class' => $a->student->class,
            ],
            'date'           => $a->date->format('Y-m-d'),
            'checked_in_at'  => $a->checked_in_at->format('H:i'),
            'checked_out_at' => $a->checked_out_at?->format('H:i'),
            'status'         => $a->status,
        ]);

        return response()->json([
            'success' => true,
            'date'    => $date,
            'data'    => $data,
        ]);
    }
}
