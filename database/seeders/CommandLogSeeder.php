<?php

namespace Database\Seeders;

use App\Models\CommandLog;
use Illuminate\Database\Seeder;

/**
 * Seeder: CommandLogSeeder
 *
 * Mengisi tabel command_logs dengan data dummy realistis
 * untuk keperluan development & demo dashboard.
 */
class CommandLogSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🏠 Seeding Smart Home Command Logs...');

        // 50 perintah sukses — untuk melihat history yang bagus di dashboard
        CommandLog::factory()
            ->count(50)
            ->successful()
            ->create();

        $this->command->line('  ✅ Created 50 successful commands');

        // 10 perintah gagal — untuk testing error handling
        CommandLog::factory()
            ->count(10)
            ->failed()
            ->create();

        $this->command->line('  ❌ Created 10 failed commands');

        // 5 perintah dari suara
        CommandLog::factory()
            ->count(5)
            ->successful()
            ->fromVoice()
            ->create();

        $this->command->line('  🎤 Created 5 voice commands');

        // 5 perintah masih pending (belum diproses)
        CommandLog::factory()
            ->count(5)
            ->create(['status' => CommandLog::STATUS_PENDING]);

        $this->command->line('  ⏳ Created 5 pending commands');

        $total = CommandLog::count();
        $this->command->info("✅ Done! Total: {$total} command logs seeded.");
    }
}
