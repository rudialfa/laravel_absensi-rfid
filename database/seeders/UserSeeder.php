<?php

namespace Database\Seeders;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $passAdmin = Str::random(12);
        $passWali  = Str::random(12);

        $admin = User::updateOrCreate(
            ['email' => 'admin@contoh.test'],
            ['name' => 'Admin Sekolah', 'role' => 'admin', 'password' => Hash::make($passAdmin)]
        );

        $wali = User::updateOrCreate(
            ['email' => 'wali@contoh.test'],
            ['name' => 'Wali Contoh', 'role' => 'guardian', 'password' => Hash::make($passWali)]
        );

        // Hubungkan wali ke siswa pertama yang ada
        if ($student = Student::first()) {
            $wali->students()->syncWithoutDetaching([$student->id]);
        }

        $this->command->warn("Password admin : {$passAdmin}");
        $this->command->warn("Password wali  : {$passWali}");
        $this->command->warn('Catat sekarang, tidak ditampilkan lagi. Jangan kirim ke chat.');
    }
}
