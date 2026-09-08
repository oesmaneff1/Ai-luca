<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Esp32Service
 *
 * Bertanggung jawab mengirim perintah ke mikrokontroler ESP32
 * melalui HTTP request, dan menginterpretasikan response-nya.
 *
 * ─────────────────────────────────────────────────────────────────
 * ALUR EKSEKUSI:
 *   1. Terima intent (device, action, parameters) dari LlmService
 *   2. Build payload JSON
 *   3. POST ke endpoint ESP32 via Http Client
 *   4. Kembalikan hasil (sukses/gagal, latency, response body)
 * ─────────────────────────────────────────────────────────────────
 */
class Esp32Service
{
    /**
     * Base URL ESP32 (dari config/smarthome.php → .env).
     * Contoh: http://192.168.1.100
     */
    private string $baseUrl;

    /**
     * Timeout request ke ESP32 dalam detik.
     * ESP32 fisik biasanya merespons < 500ms di jaringan lokal.
     */
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('smarthome.esp32_base_url', 'http://10.143.163.111'), '/');
        $this->timeout = (int) config('smarthome.esp32_timeout', 5);
    }

    // ──────────────────────────────────────────────────────────────────
    // ENTRY POINT
    // ──────────────────────────────────────────────────────────────────

    /**
     * Eksekusi perintah ke ESP32.
     *
     * @param  string  $device  Nama device (misal: "relay_1")
     * @param  string  $action  Aksi (misal: "ON", "OFF", "SET_TEMP")
     * @param  array|null  $parameters  Parameter tambahan (misal: ['suhu' => 24])
     * @return array {
     *               bool   success       → Apakah request berhasil (HTTP 2xx)
     *               string endpoint      → URL lengkap yang ditembak
     *               string response_body → Raw response body dari ESP32
     *               int    http_code     → HTTP status code
     *               int    latency_ms    → Latensi total dalam milidetik
     *               string error_message → Pesan error jika gagal (kosong jika sukses)
     *               }
     */
    public function execute(string $device, string $action, ?array $parameters = null, string $intent = 'control'): array
    {
        $endpoint = $this->buildEndpoint();

        // Payload yang dikirim ke ESP32
        $payload = $this->buildPayload($device, $action, $parameters, $intent);

        Log::info('[ESP32] Mengirim perintah', [
            'endpoint' => $endpoint,
            'payload' => $payload,
        ]);

        $startTime = microtime(true);

        try {
            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->timeout($this->timeout)
                ->post($endpoint, $payload);

            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            $result = [
                'success' => $response->successful(),
                'endpoint' => $endpoint,
                'response_body' => $response->body(),
                'http_code' => $response->status(),
                'latency_ms' => $latencyMs,
                'error_message' => '',
            ];

            if ($response->successful()) {
                Log::info('[ESP32] Berhasil', [
                    'device' => $device,
                    'action' => $action,
                    'http_code' => $response->status(),
                    'latency_ms' => $latencyMs,
                ]);
            } else {
                Log::warning('[ESP32] HTTP Error', [
                    'http_code' => $response->status(),
                    'body' => $response->body(),
                ]);
                $result['error_message'] = "ESP32 merespons dengan HTTP {$response->status()}.";
            }

            return $result;

        } catch (ConnectionException $e) {
            // ESP32 tidak bisa dihubungi (mati, IP salah, timeout)
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            Log::error('[ESP32] Koneksi gagal', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'endpoint' => $endpoint,
                'response_body' => '',
                'http_code' => 0,
                'latency_ms' => $latencyMs,
                'error_message' => "Tidak dapat terhubung ke ESP32 ({$this->baseUrl}). Pastikan ESP32 menyala dan terhubung ke jaringan yang sama.",
            ];

        } catch (RequestException $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            Log::error('[ESP32] Request exception', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'endpoint' => $endpoint,
                'response_body' => '',
                'http_code' => 0,
                'latency_ms' => $latencyMs,
                'error_message' => 'Request ke ESP32 gagal: '.$e->getMessage(),
            ];
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────────────

    /**
     * Build URL endpoint ESP32.
     *
     * Desain: satu endpoint universal /api/execute untuk semua perintah.
     * ESP32 yang memutuskan apa yang dilakukan berdasarkan payload.
     * (Bisa diganti /api/{device}/{action} jika ESP32 pakai REST routing)
     */
    private function buildEndpoint(): string
    {
        $path = config('smarthome.esp32_api_path', '/api/execute');

        return $this->baseUrl.$path;
    }

    /**
     * Build payload JSON yang akan dikirim ke ESP32.
     *
     * Format yang diterima ESP32 (Arduino/ESP-IDF):
     * {
     *   "device"    : "relay_1",
     *   "action"    : "ON",
     *   "parameters": { "suhu": 24 },  // null jika tidak ada
     *   "timestamp" : 1725170000       // Unix timestamp untuk sync ESP32
     * }
     */
    private function buildPayload(string $device, string $action, ?array $parameters, string $intent = 'control'): array
    {
        return array_filter([
            'intent' => $intent,
            'device' => $device,
            'action' => strtoupper($action),
            'parameters' => $parameters,
            'timestamp' => now()->timestamp,
        ], fn ($value) => ! is_null($value));
    }

    /**
     * Cek apakah ESP32 online (ping ke base URL).
     * Berguna untuk health check sebelum mengirim perintah penting.
     */
    public function isOnline(): bool
    {
        try {
            $response = Http::timeout(3)->get($this->baseUrl.'/ping');

            return $response->successful();
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Getter URL ESP32 aktif (untuk ditampilkan di UI/log).
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}
