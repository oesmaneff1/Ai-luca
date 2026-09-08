<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LlmService
 *
 * Bertanggung jawab mengirim teks perintah ke LLM API dan
 * mengembalikan structured intent (JSON).
 *
 * ─────────────────────────────────────────────────────────────────
 * ARSITEKTUR:
 *   - callApi()   → Entry point. Cek env untuk pilih driver.
 *   - mockCall()  → Driver dummy untuk development/testing.
 *   - openAiCall()→ Driver nyata OpenAI (siap pakai, tinggal set .env).
 * ─────────────────────────────────────────────────────────────────
 *
 * FORMAT RESPONSE STANDAR (selalu dikembalikan):
 * {
 *   "intent"    : "control" | "query" | "unknown",
 *   "device"    : "relay_1" | "ac_ruang_tamu" | null,
 *   "action"    : "ON" | "OFF" | "SET_TEMP" | null,
 *   "parameters": {"suhu": 24} | null,
 *   "ai_reply"  : "Pesan konfirmasi untuk user",
 *   "raw"       : { ...full response dari LLM API... }
 * }
 */
class LlmService
{
    /**
     * System prompt dinamis dengan LUCA persona, waktu terkini (Asia/Jakarta), dan memori percakapan.
     */
    private function getSystemPrompt(array $history = []): string
    {
        $waktuSekarang = now()->timezone('Asia/Jakarta')->translatedFormat('l, d F Y - H:i');

        return <<<PROMPT
Kamu adalah LUCA, asisten Smart Home cerdas. Selain mengeksekusi perintah kontrol rumah, kamu juga memiliki wawasan luas untuk menjawab pertanyaan umum, mencari informasi akademis/jurnal, dan melakukan obrolan santai secara natural. Jangan kaku.

WAKTU SAAT INI: {$waktuSekarang} (WIB)
LOKASI: Indonesia

[ATURAN BAHASA & TAG]
1. Awali setiap "ai_reply" dengan tag [ID] jika berbicara bahasa Indonesia, atau [EN] jika bahasa Inggris.
   Contoh: "[ID] Siap, lampu ruang tamu sudah dinyalakan." atau "[EN] Sure, turning on the lights."
2. Bersikaplah ramah, pintar, luwes, dan natural. Jangan kaku seperti robot.

[KEMAMPUAN UTAMA]
1. KONTROL SMART HOME: Jika perintah pengguna ingin mengontrol atau memeriksa perangkat rumah (lampu, relay, kipas, AC, pintu, sensor gas/suhu, 7segment display), set intent ke "control" atau "read".
   - Perangkat: "relay_1" (lampu ruang tamu/utama), "relay_2" (lampu kamar), "relay_3" (lampu dapur), "7segment" (layar angka 0-9), "sensor_gas" (kualitas udara), "sensor_suhu", "ac_ruang_tamu", "kunci_pintu_depan".
   - Aksi: "ON", "OFF", angka 0-9 (untuk 7segment), "READ" (untuk sensor).
2. TANYA JAWAB UMUM & AKADEMIK: Jika pengguna bertanya tentang pengetahuan umum, sejarah, teknologi, sains, jurnal ilmiah, referensi riset, artikel, atau ngobrol santai, jawab dengan lengkap, berwawasan luas, dan cerdas. Set intent ke "chat".
   - DILARANG memaksakan topik kembali ke urusan lampu/kelistrikan jika pengguna sedang membahas topik akademis atau umum. Jawab tuntas pertanyaan pengguna.

[FORMAT OUTPUT WAJIB JSON]
Format output HANYA berupa JSON valid tanpa teks pengantar di luar JSON:
{
  "intent": "control" | "read" | "chat",
  "device": "nama_device atau null",
  "action": "aksi atau null",
  "parameters": null,
  "ai_reply": "[ID] Jawaban natural dan lengkap kamu di sini"
}
PROMPT;
    }

    // ──────────────────────────────────────────────────────────────────
    // ENTRY POINT
    // ──────────────────────────────────────────────────────────────────

    /**
     * Kirim perintah ke LLM dan dapatkan intent terstruktur.
     *
     * @param  string  $command  Teks perintah dari pengguna
     * @param  array  $history  Riwayat percakapan sebelumnya (memori)
     * @return array Parsed intent + ai_reply + raw response
     */
    public function callApi(string $command, array $history = []): array
    {
        $driver = config('smarthome.llm_driver', 'mock');

        Log::info('[LLM] Memproses perintah', [
            'driver' => $driver,
            'command' => $command,
            'history_count' => count($history),
        ]);

        return match ($driver) {
            'gemini' => $this->geminiCall($command, $history),
            'openai' => $this->openAiCall($command, $history),
            'mock' => $this->mockCall($command, $history),
            default => throw new \InvalidArgumentException("LLM driver [{$driver}] tidak dikenal."),
        };
    }

    // ──────────────────────────────────────────────────────────────────
    // DRIVER: MOCK (Dummy untuk Development)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Mock LLM — mengembalikan intent dummy berdasarkan keyword matching.
     * Tidak membutuhkan API key, cocok untuk dev & demo.
     *
     * Logika: cek keyword dalam perintah → map ke intent yang sesuai.
     */
    private function mockCall(string $command, array $history = []): array
    {
        // Simulasi delay API (~200-500ms) agar terasa realistis
        usleep(rand(200_000, 500_000));

        $lower = mb_strtolower($command);

        // ── Mapping keyword → intent ───────────────────────────────
        $intent = $this->matchMockIntent($lower, $command);

        $rawMock = [
            'model' => 'mock-llm-v1',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode($intent),
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => strlen($command),
                'completion_tokens' => 50,
                'total_tokens' => strlen($command) + 50,
            ],
        ];

        return array_merge($intent, ['raw' => $rawMock]);
    }

    /**
     * Logika keyword matching untuk mock LLM.
     * Dipisah agar mudah di-test secara unit.
     */
    private function matchMockIntent(string $lower, string $original = ''): array
    {
        // ── 7-SEGMENT DISPLAY ─────────────────────────────────────
        if (str_contains($lower, '7segment') || str_contains($lower, '7-segment') || str_contains($lower, 'layar') || str_contains($lower, 'angka')) {
            preg_match('/[0-9]/', $lower, $numMatch);
            $digit = $numMatch[0] ?? '8';

            return [
                'intent' => 'control',
                'device' => '7segment',
                'action' => $digit,
                'parameters' => null,
                'ai_reply' => "Siap Bos, angka {$digit} udah gue tampilin di layar nih!",
            ];
        }
        // ── LAMPU ─────────────────────────────────────────────────
        if (str_contains($lower, 'lampu ruang tamu') || str_contains($lower, 'relay 1') || str_contains($lower, 'relay_1')) {
            $action = $this->detectOnOff($lower);

            return [
                'intent' => 'control',
                'device' => 'relay_1',
                'action' => $action,
                'parameters' => null,
                'ai_reply' => "Lampu ruang tamu di{$this->actionLabel($action)}.",
            ];
        }

        if (str_contains($lower, 'lampu kamar') || str_contains($lower, 'relay 2') || str_contains($lower, 'relay_2')) {
            $action = $this->detectOnOff($lower);

            return [
                'intent' => 'control',
                'device' => 'relay_2',
                'action' => $action,
                'parameters' => null,
                'ai_reply' => "Lampu kamar tidur di{$this->actionLabel($action)}.",
            ];
        }

        if (str_contains($lower, 'lampu dapur') || str_contains($lower, 'relay 3') || str_contains($lower, 'relay_3')) {
            $action = $this->detectOnOff($lower);

            return [
                'intent' => 'control',
                'device' => 'relay_3',
                'action' => $action,
                'parameters' => null,
                'ai_reply' => "Lampu dapur di{$this->actionLabel($action)}.",
            ];
        }

        // Kata "lampu" generik → relay_1 sebagai default
        if (str_contains($lower, 'lampu') || str_contains($lower, 'cahaya') || str_contains($lower, 'listrik')) {
            $action = $this->detectOnOff($lower);

            return [
                'intent' => 'control',
                'device' => 'relay_1',
                'action' => $action,
                'parameters' => null,
                'ai_reply' => "Baik, lampu di{$this->actionLabel($action)}.",
            ];
        }

        // ── AC / PENDINGIN ────────────────────────────────────────
        if (str_contains($lower, 'ac') || str_contains($lower, 'pendingin') || str_contains($lower, 'air conditioner')) {
            // Cek apakah ada angka suhu (misal: "atur AC 24 derajat")
            preg_match('/(\d{2})\s*(?:derajat|°|celsius|c\b)/i', $lower, $suhuMatch);

            if (! empty($suhuMatch[1])) {
                $suhu = (int) $suhuMatch[1];

                return [
                    'intent' => 'control',
                    'device' => 'ac_ruang_tamu',
                    'action' => 'SET_TEMP',
                    'parameters' => ['suhu' => $suhu],
                    'ai_reply' => "Suhu AC diatur ke {$suhu}°C.",
                ];
            }

            $action = $this->detectOnOff($lower);

            return [
                'intent' => 'control',
                'device' => 'ac_ruang_tamu',
                'action' => $action,
                'parameters' => null,
                'ai_reply' => "AC di{$this->actionLabel($action)}.",
            ];
        }

        // ── KUNCI PINTU ───────────────────────────────────────────
        if (str_contains($lower, 'pintu') || str_contains($lower, 'kunci') || str_contains($lower, 'gembok')) {
            $isLock = str_contains($lower, 'kunci') && ! str_contains($lower, 'buka');
            $isUnlock = str_contains($lower, 'buka') || str_contains($lower, 'unlock');
            $action = $isUnlock ? 'UNLOCK' : 'LOCK';
            $reply = $isUnlock ? 'dibuka' : 'dikunci';

            return [
                'intent' => 'control',
                'device' => 'kunci_pintu_depan',
                'action' => $action,
                'parameters' => null,
                'ai_reply' => "Pintu depan {$reply}.",
            ];
        }

        // ── KIPAS ─────────────────────────────────────────────────
        if (str_contains($lower, 'kipas') || str_contains($lower, 'fan')) {
            $action = $this->detectOnOff($lower);

            return [
                'intent' => 'control',
                'device' => 'kipas_dapur',
                'action' => $action,
                'parameters' => null,
                'ai_reply' => "Kipas dapur di{$this->actionLabel($action)}.",
            ];
        }

        // ── CHAT SANTAI / SAPAAN ─────────────────────────────────
        $greetingWords = ['hai', 'halo', 'hello', 'hei', 'hey', 'hi ', 'hi!', 'hi,', "hi\n", 'selamat pagi', 'selamat siang', 'selamat malam', 'selamat sore', 'morning', 'good morning', 'good evening', 'good night'];
        foreach ($greetingWords as $g) {
            if (str_contains($lower, $g)) {
                $greetings = [
                    '[ID] Halo, Bos! Saya LUCA, asisten smart home Anda. Ada yang bisa saya bantu hari ini?',
                    '[ID] Hai! Senang mendengar dari Anda. Mau nyalakan lampu, cek sensor, atau sekadar ngobrol?',
                    '[ID] Halo Bos! Saya siap. Mau kontrol perangkat atau ada yang ingin ditanyakan?',
                    '[EN] Hey there, Boss! I\'m LUCA, your smart home assistant. How can I help you today?',
                ];

                return [
                    'intent' => 'chat',
                    'device' => null,
                    'action' => null,
                    'parameters' => null,
                    'ai_reply' => $greetings[array_rand($greetings)],
                ];
            }
        }

        // ── NAMA / IDENTITAS ─────────────────────────────────────
        if (str_contains($lower, 'siapa') || str_contains($lower, 'nama') || str_contains($lower, 'who are you') || str_contains($lower, 'your name')) {
            return [
                'intent' => 'chat',
                'device' => null,
                'action' => null,
                'parameters' => null,
                'ai_reply' => '[ID] Nama saya LUCA — Light, Utility, Control Assistant. Saya dirancang untuk membantu Anda mengontrol perangkat rumah pintar dan menjawab berbagai pertanyaan. Ada yang bisa saya bantu, Bos?',
            ];
        }

        // ── KABAR / HOW ARE YOU ───────────────────────────────────
        if (str_contains($lower, 'kabar') || str_contains($lower, 'apa kabar') || str_contains($lower, 'how are you') || str_contains($lower, 'how r u')) {
            return [
                'intent' => 'chat',
                'device' => null,
                'action' => null,
                'parameters' => null,
                'ai_reply' => '[ID] Saya selalu baik-baik saja, siap melayani 24 jam! Bagaimana dengan Anda, Bos? Ada yang bisa saya bantu?',
            ];
        }

        // ── TERIMA KASIH ─────────────────────────────────────────
        if (str_contains($lower, 'terima kasih') || str_contains($lower, 'makasih') || str_contains($lower, 'thanks') || str_contains($lower, 'thank you') || str_contains($lower, 'thx')) {
            $replies = [
                '[ID] Sama-sama, Bos! Kalau butuh apa-apa lagi, saya siap.',
                '[ID] Dengan senang hati! Ada lagi yang bisa saya bantu?',
                '[EN] You\'re welcome, Boss! Let me know if you need anything else.',
            ];

            return [
                'intent' => 'chat',
                'device' => null,
                'action' => null,
                'parameters' => null,
                'ai_reply' => $replies[array_rand($replies)],
            ];
        }

        // ── GURAUAN / BERCANDA ───────────────────────────────────
        if (str_contains($lower, 'lucu') || str_contains($lower, 'joke') || str_contains($lower, 'jokes') || str_contains($lower, 'bercanda') || str_contains($lower, 'humor') || str_contains($lower, 'lawak')) {
            return [
                'intent' => 'chat',
                'device' => null,
                'action' => null,
                'parameters' => null,
                'ai_reply' => '[ID] Oke nih: Kenapa lampu tidak pernah sedih? Karena selalu bisa "dinyalakan" kembali! 😄 Kalau mau serius juga bisa, Bos!',
            ];
        }

        // ── PERTANYAAN UMUM / WAWASAN ────────────────────────────
        // Script / Program ESP32
        if (str_contains($lower, 'script') || str_contains($lower, 'program') || str_contains($lower, 'kode') || str_contains($lower, 'code') || str_contains($lower, 'arduino') || str_contains($lower, 'esp32')) {
            return [
                'intent' => 'chat',
                'device' => null,
                'action' => null,
                'parameters' => null,
                'ai_reply' => '[ID] Tentu Bos! Untuk membuat program ESP32 LED dengan button, caranya: definisikan pin LED dan BUTTON, di setup() gunakan pinMode(LED, OUTPUT) dan pinMode(BUTTON, INPUT_PULLUP), lalu di loop() baca digitalRead(BUTTON) dan tulis digitalWrite(LED, state). Mau saya jelaskan lebih detail?',
            ];
        }

        // LED / hardware
        if (str_contains($lower, 'led') || str_contains($lower, 'button') || str_contains($lower, 'relay') || str_contains($lower, 'sensor')) {
            return [
                'intent' => 'chat',
                'device' => null,
                'action' => null,
                'parameters' => null,
                'ai_reply' => '[ID] Soal hardware elektronik itu keahlian saya, Bos! Untuk LED+Button di ESP32: LED ke pin GPIO dengan resistor 220 ohm, button ke GND dengan INPUT_PULLUP. Logikanya terbalik — LOW berarti ditekan. Ada yang ingin ditanyakan lebih lanjut?',
            ];
        }

        // Terhubung / connected / gemini
        if (str_contains($lower, 'terhubung') || str_contains($lower, 'gemini') || str_contains($lower, 'connected') || str_contains($lower, 'online') || str_contains($lower, 'offline') || str_contains($lower, 'api')) {
            return [
                'intent' => 'chat',
                'device' => null,
                'action' => null,
                'parameters' => null,
                'ai_reply' => '[ID] Saya LUCA, asisten smart home Anda — siap melayani! Saya terhubung ke server Laravel dan bisa mengontrol semua perangkat di rumah Anda. Mau coba nyalakan lampu atau cek sensor?',
            ];
        }

        // Pertanyaan menggunakan kata tanya
        if (str_contains($lower, 'apa itu') || str_contains($lower, 'apakah') || str_contains($lower, 'bagaimana') || str_contains($lower, 'kenapa') || str_contains($lower, 'mengapa') || str_contains($lower, 'what is') || str_contains($lower, 'how to') || str_contains($lower, 'why')) {
            $topicReplies = [
                '[ID] Pertanyaan bagus, Bos! Kalau pertanyaannya soal smart home, elektronik, atau IoT — saya siap bantu. Untuk topik lainnya, coba tanyakan yang lebih spesifik ya!',
                '[ID] Saya dengar pertanyaan Anda, Bos. Mau tahu tentang apa? Perangkat rumah, sensor, atau cara kerja sistem smart home ini?',
                '[ID] Good question, Boss! Coba tanyakan yang lebih spesifik — misalnya cara kerja relay, fungsi sensor gas, atau cara kontrol lampu lewat suara.',
            ];

            return [
                'intent' => 'chat',
                'device' => null,
                'action' => null,
                'parameters' => null,
                'ai_reply' => $topicReplies[array_rand($topicReplies)],
            ];
        }

        // ── PERNYATAAN / CURHATAN ─────────────────────────────────
        if (str_contains($lower, 'bosen') || str_contains($lower, 'bosan') || str_contains($lower, 'capek') || str_contains($lower, 'lelah') || str_contains($lower, 'tired') || str_contains($lower, 'boring')) {
            return [
                'intent' => 'chat',
                'device' => null,
                'action' => null,
                'parameters' => null,
                'ai_reply' => '[ID] Santai dulu, Bos! Istirahat sejenak itu penting. Kalau mau saya bantu atur lampu atau suhu ruangan biar lebih nyaman, bilang saja!',
            ];
        }

        // ── PENCARIAN / JURNAL / RISET / AKADEMIK ────────────────
        if (str_contains($lower, 'jurnal') || str_contains($lower, 'artikel') || str_contains($lower, 'penelitian') || str_contains($lower, 'paper') || str_contains($lower, 'referensi') || str_contains($lower, 'cari') || str_contains($lower, 'carikan')) {
            return [
                'intent' => 'chat',
                'device' => null,
                'action' => null,
                'parameters' => null,
                'ai_reply' => '[ID] Tentu Bos! Untuk jurnal ilmiah tentang AI atau teknologi, Anda bisa mengakses Google Scholar (scholar.google.com), IEEE Xplore, ScienceDirect, atau arXiv.org. Ada topik spesifik seperti Machine Learning atau IoT yang ingin dicari?',
            ];
        }

        // ── READ SENSOR GAS / UDARA ─────────────────────────────
        if (str_contains($lower, 'gas') || str_contains($lower, 'udara') || str_contains($lower, 'sensor')) {
            return [
                'intent' => 'read',
                'device' => 'sensor_gas',
                'action' => 'READ',
                'parameters' => null,
                'ai_reply' => '[ID] Siap, saya cek kondisi udara sekarang...',
            ];
        }

        // ── QUERY STATUS ──────────────────────────────────────────
        if (str_contains($lower, 'status') || str_contains($lower, 'kondisi') || str_contains($lower, 'cek')) {
            return [
                'intent' => 'read',
                'device' => null,
                'action' => 'STATUS',
                'parameters' => null,
                'ai_reply' => '[ID] Mengecek status seluruh perangkat...',
            ];
        }

        // ── FALLBACK CERDAS: Pesan pendek tidak dikenal → sapaan
        if (mb_strlen(trim($original)) < 20) {
            $casualReplies = [
                '[ID] Halo, Bos! Ada yang bisa saya bantu?',
                '[ID] Saya di sini, Bos! Mau kontrol perangkat atau ada yang ingin ditanyakan?',
                '[EN] Hey Boss! I\'m ready. What can I do for you?',
            ];

            return [
                'intent' => 'chat',
                'device' => null,
                'action' => null,
                'parameters' => null,
                'ai_reply' => $casualReplies[array_rand($casualReplies)],
            ];
        }

        // Pesan panjang tidak dikenal → tetap ramah dan helpful
        $generalReplies = [
            '[ID] Sip, Bos! Minta tolong lebih spesifik ya — misalnya: "nyalakan lampu", "cek sensor gas", atau tanya soal ESP32.',
            '[ID] Oke Bos, saya perlu perintah yang lebih jelas. Contoh: "matikan lampu dapur", "tampilkan angka 5", atau "apa kabar".',
            '[ID] Hmm, saya kurang paham maksudnya. Coba ulangi dengan kalimat yang lebih singkat — misalnya nama perangkat yang mau dikontrol!',
        ];

        return [
            'intent' => 'chat',
            'device' => null,
            'action' => null,
            'parameters' => null,
            'ai_reply' => $generalReplies[array_rand($generalReplies)],
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // DRIVER: GEMINI (Google Generative AI)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Panggil Google Gemini API (gemini-1.5-flash).
     *
     * Konfigurasi di .env:
     *   LLM_DRIVER=gemini
     *   GEMINI_API_KEY=AIzaSy...
     *   GEMINI_MODEL=gemini-1.5-flash
     *
     * @throws \RuntimeException Jika API gagal atau response bukan JSON valid
     */
    private function geminiCall(string $command, array $history = []): array
    {
        $apiKey = config('smarthome.gemini_api_key', env('GEMINI_API_KEY'));
        $preferredModel = config('smarthome.gemini_model', 'gemini-3.1-flash-lite-preview');
        $fallbackModel = config('smarthome.gemini_fallback_model', 'gemini-2.5-flash');
        $timeout = (int) config('smarthome.gemini_timeout', 7);

        if (empty($apiKey) || ! is_string($apiKey) || strlen(trim($apiKey)) < 8) {
            Log::warning('[LLM] GEMINI_API_KEY kosong atau invalid di .env, beralih ke mock driver.');

            return $this->mockCall($command, $history);
        }

        // ── Circuit Breaker: Skip Gemini jika baru saja gagal ────────
        $circuitKey = 'gemini_circuit_open';
        if (Cache::get($circuitKey, false)) {
            Log::info('[LLM] Circuit breaker aktif: skip Gemini, langsung mock driver.');

            return $this->mockCall($command, $history);
        }

        $startTime = microtime(true);
        // Maksimal 2 model kandidat (1 utama + 1 fallback)
        $candidateModels = array_slice(array_unique(array_filter([
            $preferredModel,
            $fallbackModel,
        ])), 0, 2);

        $response = null;
        $lastError = '';
        $triedCount = 0;

        // Susun multi-turn contents
        $contents = [];
        foreach ($history as $msg) {
            $role = ($msg['role'] ?? 'user') === 'user' ? 'user' : 'model';
            $text = $msg['text'] ?? ($role === 'user' ? ($msg['user'] ?? '') : ($msg['ai'] ?? ''));
            if (! empty(trim((string) $text))) {
                $contents[] = [
                    'role' => $role,
                    'parts' => [['text' => (string) $text]],
                ];
            }
        }
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $command]],
        ];

        foreach ($candidateModels as $model) {
            $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
            $modelStart = microtime(true);

            try {
                $res = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $apiKey,
                ])
                    ->timeout($timeout)
                    ->post($endpoint, [
                        'systemInstruction' => [
                            'parts' => [
                                ['text' => $this->getSystemPrompt()],
                            ],
                        ],
                        'contents' => $contents,
                        'generationConfig' => [
                            'responseMimeType' => 'application/json',
                            'temperature' => 0.7,
                            'thinkingConfig' => [
                                'thinkingLevel' => 'LOW',
                            ],
                        ],
                    ]);

                $modelDurationMs = (int) round((microtime(true) - $modelStart) * 1000);

                if ($res->successful()) {
                    Cache::forget($circuitKey);
                    $response = $res;
                    break;
                } else {
                    $cleanBody = $this->sanitizeLog($res->body(), $apiKey);
                    $lastError = "Gemini ({$model}) status {$res->status()} ({$modelDurationMs}ms): {$cleanBody}";
                    $triedCount++;
                }
            } catch (\Exception $e) {
                $cleanMsg = $this->sanitizeLog($e->getMessage(), $apiKey);
                $lastError = "Gemini ({$model}) exception: {$cleanMsg}";
                $triedCount++;
            }
        }

        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        if (! $response) {
            if ($triedCount >= count($candidateModels)) {
                Cache::put($circuitKey, true, 180);
                Log::warning('[LLM] Circuit breaker DIAKTIFKAN selama 3 menit karena Gemini tidak dapat dijangkau.');
            }
            Log::warning('[LLM] Gemini API gagal/timeout, otomatis beralih ke mock driver. Detail: '.$lastError);

            return $this->mockCall($command, $history);
        }

        $responseData = $response->json();
        $geminiText = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (empty($geminiText)) {
            throw new \RuntimeException('Gemini API mengembalikan respons kosong.');
        }

        // Clean markdown code blocks if any
        $cleanJson = preg_replace('/^```(?:json)?\s*/m', '', $geminiText);
        $cleanJson = preg_replace('/\s*```$/m', '', $cleanJson);
        $cleanJson = trim($cleanJson);
        $aiIntent = json_decode($cleanJson, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($aiIntent)) {
            // Coba sanitize literal unescaped newlines/tabs di dalam string JSON
            $sanitized = preg_replace_callback('/"ai_reply"\s*:\s*"(.*?)"\s*([,}])/s', function ($matches) {
                $escaped = str_replace(["\r", "\n", "\t"], ['\r', '\n', '\t'], $matches[1]);

                return '"ai_reply": "'.$escaped.'"'.$matches[2];
            }, $cleanJson);

            $aiIntent = json_decode($sanitized, true);

            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($aiIntent)) {
                // Fallback regex jika masih gagal
                preg_match('/"intent"\s*:\s*"([^"]+)"/', $geminiText, $intentMatch);
                preg_match('/"device"\s*:\s*(?:"([^"]+)"|null)/', $geminiText, $deviceMatch);
                preg_match('/"action"\s*:\s*(?:"([^"]+)"|null)/', $geminiText, $actionMatch);
                preg_match('/"ai_reply"\s*:\s*"(.*)"\s*\}?\s*$/s', $cleanJson, $replyMatch);

                if (! empty($replyMatch[1])) {
                    $aiIntent = [
                        'intent' => $intentMatch[1] ?? 'chat',
                        'device' => (! empty($deviceMatch[1]) && $deviceMatch[1] !== 'null') ? $deviceMatch[1] : null,
                        'action' => (! empty($actionMatch[1]) && $actionMatch[1] !== 'null') ? $actionMatch[1] : null,
                        'ai_reply' => stripcslashes(trim($replyMatch[1], "\"' \t\n\r\0\x0B")),
                    ];
                } else {
                    throw new \RuntimeException('Gagal memparse JSON dari Gemini API: '.$geminiText);
                }
            }
        }

        Log::info('[LLM] Gemini response', [
            'latency_ms' => $latencyMs,
            'intent' => $aiIntent,
        ]);

        // Ensure standard keys exist
        return [
            'intent' => $aiIntent['intent'] ?? 'control',
            'device' => $aiIntent['device'] ?? null,
            'action' => $aiIntent['action'] ?? null,
            'parameters' => $aiIntent['parameters'] ?? null,
            'ai_reply' => $aiIntent['ai_reply'] ?? 'Perintah diproses.',
            'raw' => $responseData,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // DRIVER: OPENAI (Siap Pakai — Tinggal Set .env)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Panggil OpenAI ChatCompletion API.
     *
     * Konfigurasi di .env:
     *   LLM_DRIVER=openai
     *   OPENAI_API_KEY=sk-...
     *   OPENAI_MODEL=gpt-4o-mini   (default)
     *
     * @throws \RuntimeException Jika API gagal atau response bukan JSON valid
     */
    private function openAiCall(string $command, array $history = []): array
    {
        $apiKey = config('smarthome.openai_api_key');
        $model = config('smarthome.openai_model', 'gpt-4o-mini');

        if (empty($apiKey)) {
            throw new \RuntimeException('OPENAI_API_KEY belum dikonfigurasi di .env');
        }

        $startTime = microtime(true);

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])
                ->timeout(15)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.1, // Rendah agar output konsisten & deterministik
                    'messages' => [
                        ['role' => 'system', 'content' => $this->getSystemPrompt()],
                        ['role' => 'user',   'content' => $command],
                    ],
                ]);

            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->failed()) {
                throw new \RuntimeException(
                    "OpenAI API error {$response->status()}: ".$response->body()
                );
            }

            $raw = $response->json();
            $content = $raw['choices'][0]['message']['content'] ?? null;

            if (empty($content)) {
                throw new \RuntimeException('OpenAI mengembalikan response kosong.');
            }

            // Parse JSON dari LLM (bersihkan markdown code block jika ada)
            $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
            $content = preg_replace('/\s*```$/m', '', $content);
            $intent = json_decode(trim($content), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('LLM mengembalikan response bukan JSON valid: '.$content);
            }

            Log::info('[LLM] OpenAI response', [
                'latency_ms' => $latencyMs,
                'tokens' => $raw['usage'] ?? [],
                'intent' => $intent,
            ]);

            return array_merge($intent, ['raw' => $raw]);

        } catch (RequestException $e) {
            throw new \RuntimeException('Koneksi ke OpenAI gagal: '.$e->getMessage());
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────────────

    /**
     * Deteksi apakah perintah bermakna ON atau OFF.
     * Default: ON jika tidak ada kata "matikan"/"off".
     */
    private function detectOnOff(string $lower): string
    {
        $offKeywords = ['matikan', 'padamkan', 'nonaktifkan', 'off', 'mati', 'padam'];

        foreach ($offKeywords as $kw) {
            if (str_contains($lower, $kw)) {
                return 'OFF';
            }
        }

        return 'ON';
    }

    /**
     * Label aksi untuk kalimat konfirmasi Bahasa Indonesia.
     */
    private function actionLabel(string $action): string
    {
        return match (strtoupper($action)) {
            'ON' => 'nyalakan',
            'OFF' => 'matikan',
            default => strtolower($action),
        };
    }

    /**
     * Sanitasi log agar tidak membocorkan API key.
     */
    private function sanitizeLog(string $message, ?string $apiKey = null): string
    {
        if (! empty($apiKey)) {
            $message = str_replace($apiKey, '[REDACTED_API_KEY]', $message);
        }
        $message = preg_replace('/key=([a-zA-Z0-9_\-]+)/i', 'key=[REDACTED]', $message);
        $message = preg_replace('/AIza[0-9A-Za-z-_]{35}/', '[REDACTED_API_KEY]', $message);

        return mb_substr($message, 0, 300);
    }
}
