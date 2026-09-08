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
        Schema::table('command_logs', function (Blueprint $table) {
            $table->string('audio_url')->nullable()->after('response_esp');
            $table->enum('tts_status', ['pending', 'processing', 'done', 'failed'])
                ->default('pending')
                ->after('audio_url');
            $table->unsignedInteger('tts_duration_ms')->nullable()->after('tts_status');

            $table->index('tts_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('command_logs', function (Blueprint $table) {
            $table->dropIndex(['tts_status']);
            $table->dropColumn(['audio_url', 'tts_status', 'tts_duration_ms']);
        });
    }
};
