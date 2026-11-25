<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tabel yang dimiliki satu sekolah */
    private const TABLES = [
        'users',
        'students',
        'teachers',
        'classes',
        'devices',
        'attendances',
        'teacher_attendances',
    ];

    public function up(): void
    {
        $schoolId = DB::table('schools')->orderBy('id')->value('id');

        // Nullable di database: super_admin tidak punya sekolah. Untuk peran lain,
        // model (BelongsToSchool) yang menolak data tanpa sekolah.
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('school_id')->nullable()
                    ->constrained('schools')->restrictOnDelete();
            });
        }

        // Data lama pindah ke sekolah pertama (belum ada super_admin saat ini)
        foreach (['users', 'students', 'teachers', 'classes', 'devices'] as $name) {
            DB::table($name)->update(['school_id' => $schoolId]);
        }

        DB::table('attendances')->update([
            'school_id' => DB::raw('(select school_id from students where students.id = attendances.student_id)'),
        ]);

        DB::table('teacher_attendances')->update([
            'school_id' => DB::raw('(select school_id from teachers where teachers.id = teacher_attendances.teacher_id)'),
        ]);

        // Nama kelas unik per sekolah, bukan di seluruh sistem
        Schema::table('classes', function (Blueprint $table) {
            $table->dropUnique('classes_name_unique');
            $table->unique(['school_id', 'name']);
        });
    }

    public function down(): void
    {
        // MySQL memakai indeks (school_id, name) untuk foreign key, jadi
        // foreign key dilepas dulu sebelum indeks itu diganti.
        Schema::table('classes', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->dropUnique(['school_id', 'name']);
            $table->unique('name');
            $table->dropColumn('school_id');
        });

        foreach (array_reverse(self::TABLES) as $name) {
            if ($name === 'classes') {
                continue;
            }

            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('school_id');
            });
        }
    }
};
