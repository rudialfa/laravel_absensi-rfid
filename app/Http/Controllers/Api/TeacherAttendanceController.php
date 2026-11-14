<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TeacherAttendance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TeacherAttendanceController extends Controller
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

        $data = TeacherAttendance::with('teacher:id,name')
            ->where('date', $date)
            ->orderBy('checked_in_at')
            ->get()
            ->map(fn(TeacherAttendance $a) => [
                'id'             => $a->id,
                'teacher'        => ['id' => $a->teacher->id, 'name' => $a->teacher->name],
                'date'           => $a->date->format('Y-m-d'),
                'checked_in_at'  => $a->checked_in_at->format('H:i'),
                'checked_out_at' => $a->checked_out_at?->format('H:i'),
            ]);

        return response()->json([
            'success' => true,
            'date'    => $date,
            'data'    => $data,
        ]);
    }
}
