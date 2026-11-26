<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            // Terenkripsi (cast "encrypted" di model School), tidak pernah dikirim ke admin sekolah
            $table->text('card_key')->nullable();
            $table->timestamp('card_key_rotated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['card_key', 'card_key_rotated_at']);
        });
    }
};
