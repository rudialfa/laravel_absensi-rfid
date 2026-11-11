<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
                $table->id();
        $table->foreignId('student_id')->constrained()->cascadeOnDelete();
        $table->date('date');
        $table->dateTime('checked_in_at');
        $table->dateTime('checked_out_at')->nullable();
        $table->string('status'); // hadir | terlambat
        $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
        $table->timestamps();

        $table->unique(['student_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};