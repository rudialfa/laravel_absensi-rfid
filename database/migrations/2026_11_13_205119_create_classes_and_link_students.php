<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->timestamps();
        });

        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('class_id')->nullable()->after('class')
                ->constrained('classes')->nullOnDelete();
        });

        // Konversi data lama: tiap nama kelas unik (tanpa membedakan huruf besar kecil) jadi satu kelas
        $names = DB::table('students')
            ->whereNotNull('class')
            ->pluck('class')
            ->map(fn($n) => trim((string) $n))
            ->filter(fn($n) => $n !== '')
            ->unique(fn($n) => mb_strtolower($n));

        foreach ($names as $name) {
            $id = DB::table('classes')->insertGetId([
                'name'       => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('students')
                ->whereRaw('LOWER(TRIM(class)) = ?', [mb_strtolower($name)])
                ->update(['class_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign(['class_id']);
            $table->dropColumn('class_id');
        });

        Schema::dropIfExists('classes');
    }
};
