<?php

use App\Http\Controllers\SmartHomeController;
use Illuminate\Support\Facades\Route;

/**
 * routes/api.php
 *
 * API Routes untuk Smart Home AI System.
 * Semua route di sini otomatis mendapat prefix /api/
 *
 * Base URL: http://your-domain.test/api/
 *
 * Middleware 'api' sudah handle: throttle, stateful session, dsb.
 */

// ── Health Check ─────────────────────────────────────────────────────
Route::get('/ping', fn () => response()->json([
    'status' => 'ok',
    'service' => 'SmartHome AI API',
    'timestamp' => now()->toIso8601String(),
]));

// ── Direct ESP32 Chat Endpoint ───────────────────────────────────────
/**
 * POST /api/chat
 * Endpoint utama yang dipanggil oleh mikrokontroler ESP32
 * Body: { "message": "nyalakan lampu" }
 * Response: { "ai_reply": "Baik, lampu sekarang dinyalakan.", ... }
 */
Route::post('/chat', [SmartHomeController::class, 'processCommand'])
    ->name('smarthome.chat.process');

// ── Smart Home Commands ───────────────────────────────────────────────
Route::prefix('command')->group(function () {

    /**
     * POST /api/command
     * Proses perintah teks → LLM → ESP32 → simpan log
     *
     * Body: { "text_command": "nyalakan lampu", "input_source": "text" }
     */
    Route::post('/', [SmartHomeController::class, 'processCommand'])
        ->name('smarthome.command.process');

    /**
     * GET /api/command/history
     * Ambil 20 riwayat perintah terbaru
     */
    Route::get('/history', [SmartHomeController::class, 'history'])
        ->name('smarthome.command.history');

    /**
     * GET /api/command/stats
     * Statistik ringkasan untuk widget dashboard
     */
    Route::get('/stats', [SmartHomeController::class, 'stats'])
        ->name('smarthome.command.stats');

    /**
     * GET /api/command/{id}/audio
     * Polling status audio TTS untuk command tertentu
     */
    Route::get('/{id}/audio', [SmartHomeController::class, 'getAudioStatus'])
        ->name('smarthome.command.audio');
});

// ── ESP32 Status ──────────────────────────────────────────────────────
Route::get('/esp32/status', [SmartHomeController::class, 'esp32Status'])
    ->name('smarthome.esp32.status');
