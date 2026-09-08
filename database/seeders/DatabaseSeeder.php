<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Buat user admin untuk Smart Home
        User::factory()->create([
            'name' => 'Admin Smart Home',
            'email' => 'admin@smarthome.test',
        ]);

        // Seed riwayat perintah dummy
        $this->call(CommandLogSeeder::class);
    }
}
