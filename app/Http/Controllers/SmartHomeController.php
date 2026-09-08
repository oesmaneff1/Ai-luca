<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProcessCommandRequest;
use App\Jobs\GenerateSpeechJob;
use App\Models\CommandLog;
use App\Services\Esp32Service;
use App\Services\LlmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SmartHomeController
 *
 * "Otak Tengah" Asisten AI Smart Home (LUCA).
 * Mengorkestrasi input user/ESP32, memori percakapan (Chat History),
 * integrasi Google Gemini API, kontrol perangkat rumah pintar, dan Edge-TTS asinkron.
 */
class SmartHomeController extends Controller
{
    public function __construct(
        private readonly ?LlmService $llm = null,
        private readonly ?Esp32Service $esp32 = null,
    ) {}

    // ══════════════════════════════════════════════════════════════════
    // ENDPOINT UTAMA: PROCESS COMMAND & CHAT
    // ══════════════════════════════════════════════════════════════════

    /**
     * Proses perintah/percakapan dari Web Frontend atau ESP32.
     *
     * POST /api/command
     * POST /api/chat
     * Body: { "text_command": "...", "input_source": "text" } atau { "message": "..." }
     */
    public function processCommand(ProcessCommandRequest $request): JsonResponse
    {
        $requestStartTime = microtime(true);

        $command = $request->getCommand();
        if (empty($command)) {
            $command = trim((string) ($request->input('message') ?? $request->input('text_command') ?? ''));
        }

        $inputSource = $request->getCommandSource();
        $hasSessionCookie = $request->cookies->has(config('session.cookie', 'laravel_session'));
        $sessionId = ($hasSessionCookie && $request->hasSession()) ? $request->session()->getId() : null;
        $clientIp = $request->ip();
        $customSessionId = $request->input('session_id');

        // Gunakan custom session_id, atau browser session ID jika ada cookie, atau IP client (ESP32/API)
        $identifier = $customSessionId ?? $sessionId ?? $clientIp;
        $cacheKey = 'chat_history_'.$identifier;

        // ── STEP 1: Buat log pending di database ───────────────────────
        $log = CommandLog::createPending(
            perintah: $command,
            source: $inputSource,
            userId: auth()->id(),
            userIp: $clientIp,
            sessionId: $sessionId,
        );

        Log::info('[SmartHome] Menerima input', [
            'log_id' => $log->id,
            'command' => $command,
            'source' => $inputSource,
            'cacheKey' => $cacheKey,
        ]);

        try {
            // ── STEP 2: Ambil Chat History (Memori Percakapan) ──────────
            // Ambil dari Cache Laravel, fallback ke Session jika Cache kosong
            $history = Cache::get($cacheKey, []);
            if (empty($history) && $request->hasSession()) {
                $history = $request->session()->get('chat_history', []);
            }
            if (! is_array($history)) {
                $history = [];
            }

            // Batasi maksimal 10 pesan terakhir (5 pasang percakapan user & model)
            if (count($history) > 10) {
                $history = array_slice($history, -10);
            }

            // ── STEP 2.5: Mengambil Konteks Sensor dari Node 2 (Non-Blocking / Selektif) ──
            $sensorStart = microtime(true);
            $sensorContext = '';

            if ($this->isSensorContextNeeded($command)) {
                $ipNode2 = config('smarthome.esp32_node2_ip', env('ESP32_NODE2_IP', '10.143.163.40'));
                $node2Timeout = (float) config('smarthome.esp32_node2_timeout', 1.5);

                try {
                    // Gunakan Http::pool agar request terisolasi dan non-blocking
                    $poolResponses = Http::pool(fn ($pool) => [
                        $pool->as('sensor')->timeout($node2Timeout)->get("http://{$ipNode2}/api/sensor"),
                    ]);

                    $responseNode2 = $poolResponses['sensor'] ?? null;

                    if ($responseNode2 instanceof \Illuminate\Http\Client\Response && $responseNode2->successful()) {
                        $jarak = $responseNode2->json('distance_cm');

                        if ($jarak !== null) {
                            if ($jarak < 10) {
                                $sensorContext = " (Konteks Sistem dari Sensor: Ada objek/orang sangat dekat dengan jarak {$jarak} cm. Sistem keamanan / LED Peringatan fisik saat ini sedang menyala otomatis.)";
                            } else {
                                $sensorContext = " (Konteks Sistem dari Sensor: Jarak objek terdekat adalah {$jarak} cm, kondisi ruangan aman.)";
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $sensorContext = ' (Konteks Sistem: Gagal membaca sensor karena Node 2 sedang offline.)';
                }
            }
            $sensorDurationMs = (int) round((microtime(true) - $sensorStart) * 1000);

            // Gabungkan pesan asli dari user dengan data sensor terbaru (jika ada)
            $commandWithContext = $command.$sensorContext;

            // ── STEP 3: Panggil Google Gemini API dengan Multi-turn History ──
            $geminiStart = microtime(true);
            $intent = $this->callGeminiApi($commandWithContext, $history);
            $geminiDurationMs = (int) round((microtime(true) - $geminiStart) * 1000);

            // ── STEP 4: Normalisasi Device & Eksekusi Hardware Node 2 ──
            $normalized = $this->normalizeDeviceAndAction($intent);
            $intent['device'] = $normalized['device'];
            $intent['action'] = $normalized['action'];

            $rawReply = $intent['ai_reply'] ?? '[ID] Baik, perintah diproses.';
            $esp2Start = microtime(true);
            $espResult = null;
            $isControl = ($intent['intent'] ?? '') === 'control';
            $hasDeviceAndAction = ! empty($intent['device']) && ! empty($intent['action']);
            $executed = ! $isControl; // Perintah non-kontrol (chat, info, dsb) otomatis dianggap terlaksana

            if ($isControl && $hasDeviceAndAction) {
                $deviceLabel = $this->getHumanReadableDeviceName($intent['device']);
                try {
                    $ipNode2 = config('smarthome.esp32_node2_ip', env('ESP32_NODE2_IP', '10.143.163.40'));
                    $urlControl = "http://{$ipNode2}/api/control";

                    // Tembak perintah ke Node 2
                    $responseNode2 = Http::timeout(5)->post($urlControl, [
                        'device' => $intent['device'],
                        'action' => $intent['action'],
                    ]);

                    $espResult = $responseNode2->json();
                    $espStatus = $responseNode2->status();
                    $node2Status = is_array($espResult) ? strtolower((string) ($espResult['status'] ?? '')) : '';

                    if ($responseNode2->successful() && in_array($node2Status, ['success', 'ok'], true)) {
                        $executed = true;
                        Log::info('[SmartHome] Node 2 berhasil dieksekusi: '.$intent['action']);
                        $log->markAsExecuted($responseNode2->body(), $espStatus, (int) round((microtime(true) - $esp2Start) * 1000));
                    } else {
                        $executed = false;
                        $log->markAsExecuted($responseNode2->body(), $espStatus, (int) round((microtime(true) - $esp2Start) * 1000));

                        // Koreksi ai_reply jika status ignored atau gagal
                        if ($node2Status === 'ignored') {
                            $rawReply = "[ID] Maaf, perangkat {$deviceLabel} belum saya kenali atau belum terhubung di sistem, jadi perintahnya belum bisa saya jalankan.";
                        } else {
                            $rawReply = "[ID] Maaf, perangkat {$deviceLabel} gagal merespons perintah kontrol saat ini, jadi perintah belum bisa saya jalankan.";
                        }
                        Log::warning("[SmartHome] Eksekusi Node 2 tidak berhasil (status: {$node2Status}). Mengoreksi ai_reply.");
                    }
                } catch (Throwable $e) {
                    $executed = false;
                    $deviceLabel = $this->getHumanReadableDeviceName($intent['device']);
                    $rawReply = "[ID] Maaf, perangkat {$deviceLabel} tidak dapat dihubungi atau sedang offline, jadi perintah belum bisa saya jalankan.";
                    Log::warning('[SmartHome] Gagal mengirim perintah ke Node 2: '.$this->sanitizeLogMessage($e->getMessage()));
                    $log->markAsExecuted('Node 2 unreachable: '.$this->sanitizeLogMessage($e->getMessage()), 500, (int) round((microtime(true) - $esp2Start) * 1000));
                }
            } elseif ($isControl && ! $hasDeviceAndAction) {
                $executed = false;
                $rawReply = '[ID] Maaf, saya belum memahami perangkat atau tindakan spesifik yang ingin Anda kendalikan.';
                $log->markAsExecuted('Perangkat atau tindakan tidak teridentifikasi', 400, 0);
            } else {
                $log->markAsExecuted('Handled naturally by LUCA', 200, 0);
            }
            $esp2DurationMs = (int) round((microtime(true) - $esp2Start) * 1000);

            // Bersihkan tag bahasa [ID]/[EN] dari teks balasan akhir
            $cleanReply = trim(preg_replace('/^\[(ID|EN)\]\s*/i', '', $rawReply));
            $intent['ai_reply'] = $rawReply;

            // Catat log ter-parse yang sudah tervalidasi ke database
            $log->markAsParsed([
                'device' => $intent['device'],
                'action' => $intent['action'],
                'parameters' => $intent['parameters'] ?? null,
                'raw_response' => [
                    'ai_reply' => $rawReply,
                    'intent' => $intent['intent'],
                    'raw' => $intent['raw'] ?? null,
                    'executed' => $executed,
                ],
            ]);

            // ── STEP 5: Simpan Riwayat Percakapan (History) ke Session & Cache ──
            // Riwayat kini dijamin menyimpan respons yang jujur sesuai status eksekusi fisik
            $history[] = [
                'role' => 'user',
                'text' => $command,
            ];
            $history[] = [
                'role' => 'model',
                'text' => $rawReply,
            ];

            // Jaga array history tetap maksimal 10 pesan terakhir
            if (count($history) > 10) {
                $history = array_slice($history, -10);
            }

            // Simpan ke Cache Laravel selama 120 menit
            Cache::put($cacheKey, $history, now()->addMinutes(120));

            // Simpan juga ke Session Laravel jika request memiliki session
            if ($request->hasSession()) {
                $request->session()->put('chat_history', $history);
            }

            // ── STEP 6: Hitung Latensi Sebelum Response ──────────────────
            $totalBeforeResponseMs = (int) round((microtime(true) - $requestStartTime) * 1000);
            Log::info("[SmartHome] Latency breakdown - sensor: {$sensorDurationMs}ms, gemini: {$geminiDurationMs}ms, esp2: {$esp2DurationMs}ms, total_before_response: {$totalBeforeResponseMs}ms");

            $latencyMetrics = [
                'sensor' => $sensorDurationMs,
                'gemini' => $geminiDurationMs,
                'esp2' => $esp2DurationMs,
                'total_before_response' => $totalBeforeResponseMs,
            ];

            // ── STEP 7: Generate Audio Suara secara Asynchronous (Queue Job) ──
            // Menggunakan rawReply yang sudah dikoreksi agar suara TTS sesuai fakta eksekusi
            GenerateSpeechJob::dispatch($log->id, $rawReply, $identifier, $latencyMetrics);

            // ── STEP 8: Kirim Response JSON Lengkap Segera ───────────────
            return response()->json([
                'status' => 'success',
                'success' => true,
                'executed' => $executed,
                'ai_reply' => $cleanReply,
                'audio_url' => null, // Segera kirim tanpa menunggu TTS; client polling/event audio_url
                'intent' => $intent['intent'] ?? 'chat',
                'device' => $intent['device'] ?? null,
                'action' => $intent['action'] ?? null,
                'parameters' => $intent['parameters'] ?? null,
                'esp_status' => $espResult,
                'log_id' => $log->id,
            ], 200);

        } catch (Throwable $e) {
            $log->markAsFailed('System error: '.$this->sanitizeLogMessage($e->getMessage()));

            Log::error('[SmartHome] Unhandled error: '.$this->sanitizeLogMessage($e->getMessage()), [
                'trace' => $this->sanitizeLogMessage($e->getTraceAsString()),
            ]);

            $fallbackMsg = 'Maaf, terjadi sedikit kendala pada sistem. Silakan ulangi perintah Anda.';
            $totalBeforeResponseMs = (int) round((microtime(true) - $requestStartTime) * 1000);
            $fallbackMetrics = [
                'sensor' => $sensorDurationMs ?? 0,
                'gemini' => $geminiDurationMs ?? 0,
                'esp2' => 0,
                'total_before_response' => $totalBeforeResponseMs,
            ];

            // Dispatch fallback audio asinkron
            GenerateSpeechJob::dispatch($log->id, '[ID] '.$fallbackMsg, $identifier, $fallbackMetrics);

            return response()->json([
                'status' => 'error',
                'success' => false,
                'executed' => false,
                'ai_reply' => $fallbackMsg,
                'audio_url' => null,
                'log_id' => $log->id,
            ], 200);
        }
    }

    /**
     * GET /api/command/{id}/audio
     * Endpoint polling ringan untuk mendapatkan URL audio TTS setelah job selesai.
     */
    public function getAudioStatus(int $id): JsonResponse
    {
        $log = CommandLog::find($id);

        if (! $log) {
            return response()->json([
                'success' => false,
                'message' => 'Command log tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'log_id' => $log->id,
            'tts_status' => $log->tts_status ?? CommandLog::TTS_PENDING,
            'audio_url' => $log->audio_url,
            'tts_duration_ms' => $log->tts_duration_ms,
        ]);
    }

    /**
     * Deteksi apakah sebuah perintah membutuhkan konteks sensor dari Node 2.
     */
    private function isSensorContextNeeded(string $command): bool
    {
        $lower = mb_strtolower($command);
        $keywords = config('smarthome.sensor_keywords', [
            'aman', 'keamanan', 'sensor', 'jarak', 'deteksi', 'ada orang',
            'siapa di luar', 'kondisi rumah', 'cek ruangan', 'kamera',
            'peringatan', 'bahaya', 'maling', 'intruder', 'objek',
        ]);

        foreach ($keywords as $kw) {
            if (str_contains($lower, $kw)) {
                return true;
            }
        }

        return false;
    }

    // ══════════════════════════════════════════════════════════════════
    // GOOGLE GEMINI API: SYSTEM PROMPT & REQUEST ORCHESTRATION
    // ══════════════════════════════════════════════════════════════════

    /**
     * System Prompt fleksibel untuk persona LUCA.
     */
    private function getSystemPrompt(): string
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

    /**
     * Request HTTP ke Google Gemini API dengan melampirkan Chat History (Memori).
     */
    private function callGeminiApi(string $command, array $history = []): array
    {
        $apiKey = config('smarthome.gemini_api_key', env('GEMINI_API_KEY'));
        $preferredModel = config('smarthome.gemini_model', env('GEMINI_MODEL', 'gemini-3.1-flash-lite-preview'));
        $fallbackModel = config('smarthome.gemini_fallback_model', env('GEMINI_FALLBACK_MODEL', 'gemini-2.5-flash'));
        $timeout = (int) config('smarthome.gemini_timeout', 7);

        // Validasi API Key ketat: Jangan pernah biarkan crash jika kosong atau invalid format
        if (empty($apiKey) || ! is_string($apiKey) || strlen(trim($apiKey)) < 8 || str_contains($apiKey, 'your_api_key')) {
            Log::warning('[Gemini] API Key kosong atau invalid, langsung fallback ke fallback parser lokal.');

            return $this->fallbackParser($command);
        }

        // ── Susun array contents dengan seluruh history percakapan (Memori) ──
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

        // Lampirkan pesan user terbaru di akhir history
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $command]],
        ];

        // Maksimal 2 model kandidat (1 utama + 1 fallback)
        $candidateModels = array_slice(array_unique(array_filter([$preferredModel, $fallbackModel])), 0, 2);

        $systemInstruction = [
            'parts' => [
                ['text' => $this->getSystemPrompt()],
            ],
        ];

        $generationConfig = [
            'responseMimeType' => 'application/json',
            'temperature' => 0.7,
            'thinkingConfig' => [
                'thinkingLevel' => 'LOW',
            ],
        ];

        $response = null;
        $lastError = '';

        foreach ($candidateModels as $model) {
            // Gunakan header x-goog-api-key untuk mencegah API key bocor ke URL/log
            $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
            $modelStart = microtime(true);

            try {
                $res = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $apiKey,
                ])
                    ->timeout($timeout)
                    ->post($endpoint, [
                        'systemInstruction' => $systemInstruction,
                        'contents' => $contents,
                        'generationConfig' => $generationConfig,
                    ]);

                $modelDurationMs = (int) round((microtime(true) - $modelStart) * 1000);

                if ($res->successful()) {
                    $response = $res;
                    break;
                }

                $status = $res->status();
                $bodyClean = $this->sanitizeLogMessage($res->body());
                $lastError = "Model {$model} HTTP {$status} ({$modelDurationMs}ms): {$bodyClean}";
                Log::warning("[Gemini] Request gagal - {$lastError}");

                // Jika API key invalid (400 / 401 / 403), jangan teruskan retry yang membuang waktu
                if (in_array($status, [400, 401, 403]) && (str_contains($bodyClean, 'API_KEY_INVALID') || str_contains($bodyClean, 'PERMISSION_DENIED'))) {
                    Log::warning('[Gemini] API Key tidak valid menurut Google API. Langsung beralih ke fallback parser.');

                    return $this->fallbackParser($command);
                }
            } catch (Throwable $e) {
                $modelDurationMs = (int) round((microtime(true) - $modelStart) * 1000);
                $cleanMsg = $this->sanitizeLogMessage($e->getMessage());
                $lastError = "Model {$model} exception ({$modelDurationMs}ms): {$cleanMsg}";
                Log::warning("[Gemini] Exception pemanggilan model: {$lastError}");
            }
        }

        if (! $response) {
            Log::warning('[Gemini] Semua model kandidat Gemini gagal dihubungi. Menggunakan fallback parser lokal.');

            return $this->fallbackParser($command);
        }

        $responseData = $response->json();
        $geminiText = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (empty($geminiText)) {
            return $this->fallbackParser($command);
        }

        // Bersihkan blok kode markdown jika ada
        $cleanJson = preg_replace('/^```(?:json)?\s*/mi', '', $geminiText);
        $cleanJson = preg_replace('/\s*```$/mi', '', $cleanJson);
        $cleanJson = trim($cleanJson);

        $parsed = json_decode($cleanJson, true);

        // Fallback jika decode JSON gagal
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($parsed)) {
            preg_match('/"intent"\s*:\s*"([^"]+)"/', $geminiText, $mIntent);
            preg_match('/"device"\s*:\s*(?:"([^"]+)"|null)/', $geminiText, $mDevice);
            preg_match('/"action"\s*:\s*(?:"([^"]+)"|null)/', $geminiText, $mAction);
            preg_match('/"ai_reply"\s*:\s*"(.*?)"(?:\s*,\s*"|\s*\})/s', $geminiText, $mReply);

            $parsed = [
                'intent' => $mIntent[1] ?? 'chat',
                'device' => (! empty($mDevice[1]) && $mDevice[1] !== 'null') ? $mDevice[1] : null,
                'action' => (! empty($mAction[1]) && $mAction[1] !== 'null') ? $mAction[1] : null,
                'parameters' => null,
                'ai_reply' => ! empty($mReply[1]) ? stripcslashes($mReply[1]) : '[ID] Perintah telah saya terima.',
            ];
        }

        return [
            'intent' => $parsed['intent'] ?? 'chat',
            'device' => $parsed['device'] ?? null,
            'action' => $parsed['action'] ?? null,
            'parameters' => $parsed['parameters'] ?? null,
            'ai_reply' => $parsed['ai_reply'] ?? '[ID] Perintah telah diproses.',
            'raw' => $responseData,
        ];
    }

    /**
     * Sanitasi pesan log agar tidak pernah membocorkan string API Key ke log sistem.
     */
    private function sanitizeLogMessage(string $msg): string
    {
        $apiKey = (string) config('smarthome.gemini_api_key', env('GEMINI_API_KEY'));
        if (! empty($apiKey)) {
            $msg = str_replace($apiKey, '[REDACTED_API_KEY]', $msg);
        }

        $msg = preg_replace('/key=([a-zA-Z0-9_\-]+)/i', 'key=[REDACTED]', $msg);
        $msg = preg_replace('/AIza[0-9A-Za-z-_]{35}/', '[REDACTED_API_KEY]', $msg);

        return mb_substr($msg, 0, 300);
    }

    /**
     * Normalisasi nama device dan aksi agar sesuai dengan format hardware ESP32.
     */
    private function normalizeDeviceAndAction(array $intent): array
    {
        $device = $intent['device'] ?? null;
        $action = $intent['action'] ?? null;

        if (! $device && ! $action) {
            return ['device' => null, 'action' => null];
        }

        $lowerDev = mb_strtolower((string) $device);
        $lowerAct = mb_strtolower((string) $action);

        // Mapping Device
        if (str_contains($lowerDev, 'ruang tamu') || str_contains($lowerDev, 'utama') || str_contains($lowerDev, 'relay 1') || str_contains($lowerDev, 'relay_1')) {
            $device = 'relay_1';
        } elseif (str_contains($lowerDev, 'kamar') || str_contains($lowerDev, 'relay 2') || str_contains($lowerDev, 'relay_2')) {
            $device = 'relay_2';
        } elseif (str_contains($lowerDev, 'dapur') || str_contains($lowerDev, 'relay 3') || str_contains($lowerDev, 'relay_3')) {
            $device = 'relay_3';
        } elseif (str_contains($lowerDev, '7segment') || str_contains($lowerDev, 'layar') || str_contains($lowerDev, 'display') || str_contains($lowerDev, 'angka')) {
            $device = '7segment';
        } elseif (str_contains($lowerDev, 'lampu') || str_contains($lowerDev, 'light')) {
            $device = 'relay_1';
        } elseif (str_contains($lowerDev, 'gas') || str_contains($lowerDev, 'udara')) {
            $device = 'sensor_gas';
        }

        // Mapping Action
        if (in_array($lowerAct, ['on', 'turn_on', 'nyalakan', 'hidupkan', 'aktifkan', '1'])) {
            $action = 'ON';
        } elseif (in_array($lowerAct, ['off', 'turn_off', 'matikan', 'padamkan', 'nonaktifkan', '0'])) {
            $action = 'OFF';
        } elseif (in_array($lowerAct, ['read', 'baca', 'cek', 'status'])) {
            $action = 'READ';
        }

        return ['device' => $device, 'action' => $action];
    }

    /**
     * Dapatkan label nama perangkat yang ramah manusia untuk pesan koreksi.
     */
    private function getHumanReadableDeviceName(?string $device): string
    {
        if (empty($device)) {
            return 'tersebut';
        }

        return match (strtolower(trim($device))) {
            'relay_1' => 'lampu utama',
            'relay_2' => 'lampu kamar',
            'relay_3' => 'lampu dapur',
            '7segment' => 'layar display',
            'sensor_gas' => 'sensor gas',
            default => str_replace('_', ' ', $device),
        };
    }

    /**
     * Fallback parser lokal jika Gemini API tidak dapat dihubungi atau API key invalid.
     */
    private function fallbackParser(string $command): array
    {
        $lower = mb_strtolower($command);

        if (str_contains($lower, 'lampu') || str_contains($lower, 'relay')) {
            $isOn = ! (str_contains($lower, 'mati') || str_contains($lower, 'off') || str_contains($lower, 'padam'));

            return [
                'intent' => 'control',
                'device' => 'relay_1',
                'action' => $isOn ? 'ON' : 'OFF',
                'parameters' => null,
                'ai_reply' => $isOn ? '[ID] Baik, lampu sudah dinyalakan.' : '[ID] Baik, lampu sudah dimatikan.',
            ];
        }

        if (str_contains($lower, 'gas') || str_contains($lower, 'udara')) {
            return [
                'intent' => 'read',
                'device' => 'sensor_gas',
                'action' => 'READ',
                'parameters' => null,
                'ai_reply' => '[ID] Sedang mengecek kondisi sensor udara.',
            ];
        }

        if (str_contains($lower, 'jurnal') || str_contains($lower, 'artikel') || str_contains($lower, 'riset') || str_contains($lower, 'paper')) {
            return [
                'intent' => 'chat',
                'device' => null,
                'action' => null,
                'parameters' => null,
                'ai_reply' => '[ID] Tentu! Untuk referensi jurnal akademik seputar AI dan teknologi, Anda dapat menjelajahi Google Scholar, IEEE Xplore, ScienceDirect, atau arXiv.org. Ada topik spesifik yang ingin Anda teliti?',
            ];
        }

        return [
            'intent' => 'chat',
            'device' => null,
            'action' => null,
            'parameters' => null,
            'ai_reply' => '[ID] Halo! Saya LUCA, asisten Smart Home Anda. Siap membantu kontrol rumah pintar maupun menjawab pertanyaan umum Anda.',
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    // TEXT-TO-SPEECH: EDGE-TTS (UTILITY / FALLBACK JIKA DIPERLUKAN)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Generate speech synchronously (dipertahankan sebagai utilitas jika dibutuhkan langsung).
     * Pipeline utama menggunakan GenerateSpeechJob asinkron.
     */
    public function generateSpeech(string $text): ?string
    {
        if (empty(trim($text))) {
            return null;
        }

        $txtPath = null;

        try {
            $rawText = $text;

            $voice = 'id-ID-GadisNeural';
            $pitch = '--pitch=+10Hz';
            $cleanText = $rawText;

            if (str_starts_with($rawText, '[EN]')) {
                $voice = 'en-US-JennyNeural';
                $pitch = '';
                $cleanText = trim(str_replace('[EN]', '', $rawText));
            } elseif (str_starts_with($rawText, '[ID]')) {
                $voice = 'id-ID-GadisNeural';
                $pitch = '--pitch=+10Hz';
                $cleanText = trim(str_replace('[ID]', '', $rawText));
            } else {
                $cleanText = trim(str_replace(['[ID]', '[EN]'], '', $rawText));
            }

            $cleanText = preg_replace('/[*#_`]/', '', $cleanText);
            $cleanText = trim($cleanText);

            if (empty($cleanText)) {
                return null;
            }

            $audioDir = public_path('audio');
            if (! is_dir($audioDir)) {
                mkdir($audioDir, 0755, true);
            }

            $uniqueId = time().'_'.uniqid();
            $txtFileName = 'input_'.$uniqueId.'.txt';
            $mp3FileName = 'reply_'.$uniqueId.'.mp3';

            $txtPath = $audioDir.DIRECTORY_SEPARATOR.$txtFileName;
            $audioPath = $audioDir.DIRECTORY_SEPARATOR.$mp3FileName;

            file_put_contents($txtPath, $cleanText);

            @set_time_limit(60);
            $pitchArg = ! empty($pitch) ? ' '.$pitch : '';
            $command = 'edge-tts --voice '.$voice.$pitchArg.' -f '.escapeshellarg($txtPath).' --write-media '.escapeshellarg($audioPath);
            shell_exec($command);

            if (file_exists($txtPath)) {
                @unlink($txtPath);
            }

            if (file_exists($audioPath) && filesize($audioPath) > 0) {
                return url('audio/'.$mp3FileName);
            }

            return null;
        } catch (Throwable $e) {
            Log::error('[Edge-TTS] Gagal generate audio: '.$this->sanitizeLogMessage($e->getMessage()));

            return null;
        } finally {
            if ($txtPath && file_exists($txtPath)) {
                @unlink($txtPath);
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // ENDPOINT PENDUKUNG (HISTORY, STATS, ESP32 STATUS, RESET)
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET /api/command/history
     * Riwayat perintah terbaru untuk sidebar frontend.
     */
    public function history(): JsonResponse
    {
        $logs = CommandLog::recentFirst()
            ->select([
                'id', 'perintah_teks', 'device', 'action',
                'status', 'input_source', 'latency_ms', 'audio_url', 'tts_status', 'created_at',
            ])
            ->limit(20)
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'perintah' => $log->perintah_teks,
                'device' => $log->device_label,
                'action' => $log->action_label,
                'status' => $log->status,
                'status_label' => $log->status_label,
                'status_color' => $log->status_color,
                'source' => $log->source_label,
                'latency' => $log->latency_label,
                'audio_url' => $log->audio_url,
                'tts_status' => $log->tts_status,
                'waktu' => $log->created_at->diffForHumans(),
            ]);

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }

    /**
     * GET /api/command/stats
     * Statistik ringkasan untuk widget dashboard.
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => CommandLog::getDashboardStats(),
        ]);
    }

    /**
     * GET /api/esp32/status
     * Cek apakah ESP32 online.
     */
    public function esp32Status(): JsonResponse
    {
        $online = $this->esp32 ? $this->esp32->isOnline() : false;
        $baseUrl = $this->esp32 ? $this->esp32->getBaseUrl() : config('smarthome.esp32_base_url');

        return response()->json([
            'success' => true,
            'online' => $online,
            'base_url' => $baseUrl,
            'message' => $online ? '✅ ESP32 terhubung' : '❌ ESP32 tidak dapat dihubungi',
        ]);
    }

    /**
     * POST /api/command/clear-history
     * Hapus memori percakapan (Cache & Session).
     */
    public function clearHistory(Request $request): JsonResponse
    {
        $hasSessionCookie = $request->cookies->has(config('session.cookie', 'laravel_session'));
        $sessionId = ($hasSessionCookie && $request->hasSession()) ? $request->session()->getId() : null;
        $clientIp = $request->ip();
        $cacheKey = 'chat_history_'.($request->input('session_id') ?? $sessionId ?? $clientIp);

        Cache::forget($cacheKey);

        if ($request->hasSession()) {
            $request->session()->forget('chat_history');
        }

        return response()->json([
            'success' => true,
            'message' => 'Memori percakapan berhasil direset.',
        ]);
    }
}
