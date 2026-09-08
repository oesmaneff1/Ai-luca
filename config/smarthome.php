<?php

/**
 * config/smarthome.php
 *
 * Konfigurasi terpusat untuk sistem Smart Home AI.
 * Semua nilai diambil dari .env — JANGAN hardcode di sini.
 *
 * Cara penggunaan:
 *   config('smarthome.esp32_base_url')
 *   config('smarthome.llm_driver')
 */

return [

    // ── LLM (AI Language Model) ──────────────────────────────────────
    //
    // LLM_DRIVER=mock    → Gunakan mock/dummy (tanpa API key, untuk dev)
    // LLM_DRIVER=openai  → Panggil OpenAI API sungguhan

    'llm_driver' => env('LLM_DRIVER', 'gemini'),

    // Google Gemini API (digunakan jika LLM_DRIVER=gemini)
    'gemini_api_key' => env('GEMINI_API_KEY'),
    'gemini_model' => env('GEMINI_MODEL', 'gemini-3.1-flash-lite-preview'),
    'gemini_fallback_model' => env('GEMINI_FALLBACK_MODEL', 'gemini-3.6-flash'),
    'gemini_timeout' => (int) env('GEMINI_TIMEOUT', 7), // Timeout per model: 6-8 detik

    // Kata kunci perintah yang membutuhkan context data sensor dari Node 2
    'sensor_keywords' => [
        'aman', 'keamanan', 'sensor', 'jarak', 'deteksi', 'ada orang',
        'siapa di luar', 'kondisi rumah', 'cek ruangan', 'kamera',
        'peringatan', 'bahaya', 'maling', 'intruder', 'objek',
    ],

    // OpenAI (digunakan jika LLM_DRIVER=openai)
    'openai_api_key' => env('OPENAI_API_KEY'),
    'openai_model' => env('OPENAI_MODEL', 'gpt-4o-mini'),

    // ── ESP32 Mikrokontroler ─────────────────────────────────────────
    //
    // Base URL ESP32 di jaringan lokal.
    // Pastikan server Laravel dan ESP32 berada di subnet yang sama.
    // Contoh: http://192.168.1.100  (tanpa trailing slash)

    'esp32_base_url' => env('ESP32_BASE_URL', 'http://10.143.163.111'),

    // Path endpoint API di ESP32
    // Default: /api/execute (single universal endpoint)
    'esp32_api_path' => env('ESP32_API_PATH', '/api/execute'),

    // Node 2 (Sensor Jarak / Keamanan)
    'esp32_node2_ip' => env('ESP32_NODE2_IP', '10.143.163.40'),
    'esp32_node2_timeout' => env('ESP32_NODE2_TIMEOUT', 1.5),

    // Timeout request ke ESP32 (detik)
    // ESP32 di jaringan lokal biasanya respons < 500ms
    // Naikkan ke 10 jika ESP32 melakukan operasi yang lama (misal: motor/servo)
    'esp32_timeout' => env('ESP32_TIMEOUT', 5),

];
