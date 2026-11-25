<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('value')->nullable();

            $table->unique(['school_id', 'key']);
        });

        // Setelan lama menjadi setelan sekolah pertama
        $schoolId = DB::table('schools')->orderBy('id')->value('id');

        foreach (DB::table('settings')->get() as $row) {
            DB::table('school_settings')->insert([
                'school_id' => $schoolId,
                'key'       => $row->key,
                'value'     => $row->value,
            ]);
        }

        Schema::dropIfExists('settings');
    }

    public function down(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('value')->nullable();
        });

        // Hanya setelan sekolah pertama yang kembali ke tabel lama
        $schoolId = DB::table('schools')->orderBy('id')->value('id');

        foreach (DB::table('school_settings')->where('school_id', $schoolId)->get() as $row) {
            DB::table('settings')->insert(['key' => $row->key, 'value' => $row->value]);
        }

        Schema::dropIfExists('school_settings');
    }
};
