<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassRoom;
use App\Models\Student;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ClassController extends Controller
{
    public function index()
    {
        $data = ClassRoom::withCount('students')
            ->orderBy('name')
            ->get()
            ->map(fn(ClassRoom $c) => $this->data($c));

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function show(ClassRoom $classRoom)
    {
        $classRoom->loadCount('students');

        $students = $classRoom->students()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn(Student $s) => [
                'id'        => $s->id,
                'name'      => $s->name,
                'is_active' => (bool) $s->is_active,
            ]);

        return response()->json([
            'success' => true,
            'data'    => $this->data($classRoom) + ['students' => $students],
        ]);
    }

    public function store(Request $request)
    {
        $v = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:50'],
        ]);

        if ($v->fails()) {
            return $this->validation($v);
        }

        $name = ClassRoom::normalize($request->input('name'));

        if (ClassRoom::findByName($name)) {
            return $this->exists();
        }

        try {
            $classRoom = ClassRoom::create(['name' => $name]);
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                return $this->exists();
            }
            throw $e;
        }

        $classRoom->loadCount('students');

        return response()->json([
            'success' => true,
            'data'    => $this->data($classRoom),
        ], 201);
    }

    public function update(Request $request, ClassRoom $classRoom)
    {
        $v = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:50'],
        ]);

        if ($v->fails()) {
            return $this->validation($v);
        }

        $name = ClassRoom::normalize($request->input('name'));

        $other = ClassRoom::findByName($name);
        if ($other && $other->id !== $classRoom->id) {
            return $this->exists();
        }

        try {
            DB::transaction(function () use ($classRoom, $name) {
                $classRoom->update(['name' => $name]);
                // Kolom teks lama di murid dijaga sinkron
                Student::where('class_id', $classRoom->id)->update(['class' => $name]);
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                return $this->exists();
            }
            throw $e;
        }

        $classRoom->refresh()->loadCount('students');

        return response()->json([
            'success' => true,
            'data'    => $this->data($classRoom),
        ]);
    }

    public function destroy(ClassRoom $classRoom)
    {
        if ($classRoom->students()->exists()) {
            return response()->json([
                'success' => false,
                'code'    => 'CLASS_NOT_EMPTY',
                'message' => 'Kelas masih punya murid. Pindahkan muridnya dulu.',
            ], 422);
        }

        $classRoom->delete();

        return response()->json(['success' => true]);
    }

    private function data(ClassRoom $c): array
    {
        return [
            'id'            => $c->id,
            'name'          => $c->name,
            'student_count' => (int) ($c->students_count ?? $c->students()->count()),
        ];
    }

    private function validation($validator)
    {
        return response()->json([
            'success' => false,
            'code'    => 'VALIDATION',
            'message' => 'Nama kelas wajib diisi (maksimal 50 karakter)',
            'errors'  => $validator->errors(),
        ], 422);
    }

    private function exists()
    {
        return response()->json([
            'success' => false,
            'code'    => 'CLASS_EXISTS',
            'message' => 'Nama kelas sudah dipakai',
        ], 422);
    }
}
