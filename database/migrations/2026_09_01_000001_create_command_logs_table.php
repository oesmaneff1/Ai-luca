<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: create_command_logs_table
 *
 * Menyimpan seluruh riwayat perintah pengguna ke Smart Home.
 * Setiap baris merepresentasikan SATU siklus penuh:
 *   Pengguna input → LLM parsing → ESP32 executed → Response tercatat
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('command_logs', function (Blueprint $table) {

            // ── Primary Key ─────────────────────────────────────────────
            $table->id();

            // ── Input Pengguna ───────────────────────────────────────────
            // Perintah dalam bentuk teks (dari ketikan ATAU hasil transkripsi suara)
            // Contoh: "nyalakan lampu kamar tidur"
            $table->text('perintah_teks');

            // Sumber input: 'text' | 'voice'
            $table->enum('input_source', ['text', 'voice'])->default('text');

            // ── Hasil Parsing LLM (AI Intent) ───────────────────────────
            // Nama device yang dituju berdasarkan analisis LLM
            // Contoh: "lampu_kamar", "ac_ruang_tamu", "kunci_pintu"
            $table->string('device', 100)->nullable();

            // Aksi yang harus dieksekusi pada device
            // Contoh: "on", "off", "set_temperature", "lock", "unlock"
            $table->string('action', 100)->nullable();

            // Parameter tambahan dari LLM (misal: {"suhu": 24, "brightness": 80})
            // Disimpan sebagai JSON untuk fleksibilitas
            $table->json('parameters')->nullable();

            // Raw JSON response dari LLM (untuk debugging & audit trail)
            $table->json('llm_raw_response')->nullable();

            // ── Status & Eksekusi ESP32 ──────────────────────────────────
            // Status pemrosesan keseluruhan pipeline
            // 'pending'  → Baru masuk, belum diproses LLM
            // 'parsed'   → LLM sudah parse intent, belum kirim ke ESP32
            // 'sent'     → Sudah dikirim ke ESP32, menunggu response
            // 'success'  → ESP32 berhasil mengeksekusi perintah
            // 'failed'   → Gagal di salah satu tahap
            $table->enum('status', ['pending', 'parsed', 'sent', 'success', 'failed'])
                ->default('pending');

            // HTTP response body dari ESP32 setelah eksekusi
            // Contoh: {"status":"ok","device":"lampu_kamar","state":"on"}
            $table->text('response_esp')->nullable();

            // HTTP status code dari ESP32 (200, 400, 500, dsb.)
            $table->unsignedSmallInteger('esp_http_code')->nullable();

            // URL endpoint ESP32 yang ditembak
            // Contoh: "http://192.168.1.101/api/relay/toggle"
            $table->string('esp_endpoint', 255)->nullable();

            // Latensi total request ke ESP32 dalam milidetik
            $table->unsignedInteger('latency_ms')->nullable();

            // ── Konteks Pengguna ─────────────────────────────────────────
            // User ID jika sistem multi-user (nullable untuk guest)
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // IP address pengguna yang mengirim perintah
            $table->ipAddress('user_ip')->nullable();

            // Session ID untuk grouping perintah dalam satu sesi
            $table->string('session_id', 100)->nullable();

            // ── Timestamps ───────────────────────────────────────────────
            $table->timestamps(); // created_at & updated_at

            // Kapan perintah selesai dieksekusi ESP32 (bisa berbeda dari created_at)
            $table->timestamp('executed_at')->nullable();

            // ── Indexes untuk Query Performance ──────────────────────────
            $table->index('device');
            $table->index('action');
            $table->index('status');
            $table->index('created_at');
            $table->index(['device', 'action']); // Composite: filter by device+action
            $table->index(['user_id', 'created_at']); // History per user
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('command_logs');
    }
};
