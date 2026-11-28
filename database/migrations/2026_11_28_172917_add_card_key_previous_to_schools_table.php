<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            // Kunci kartu sebelum diputar atau diganti. Terenkripsi (cast di model School).
            // ESP32 memakainya supaya kartu lama tetap terbaca sampai putaran berikutnya.
            $table->text('card_key_previous')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('card_key_previous');
        });
    }
};
