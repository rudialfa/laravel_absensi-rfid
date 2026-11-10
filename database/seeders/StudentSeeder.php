<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Student;

class StudentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Student::updateOrCreate(['rfid_uid' => 'F15E2F8E'], ['name' => 'Budi', 'class' => 'X-A']);
        Student::updateOrCreate(['rfid_uid' => 'A1B2C3D4'], ['name' => 'Siti', 'class' => 'X-B']);
    }
}
