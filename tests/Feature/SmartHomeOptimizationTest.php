<?php

namespace Tests\Feature;

use App\Jobs\GenerateSpeechJob;
use App\Models\CommandLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SmartHomeOptimizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. Verifikasi response teks kembali segera dengan audio_url null, dan GenerateSpeechJob di-dispatch.
     */
    public function test_process_command_returns_fast_with_null_audio_url_and_dispatches_speech_job(): void
    {
        Queue::fake();
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'intent' => 'control',
                                        'device' => 'relay_1',
                                        'action' => 'ON',
                                        'parameters' => null,
                                        'ai_reply' => '[ID] Siap, lampu ruang tamu dinyalakan.',
                                    ]),
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'http://10.143.163.40/*' => Http::response(['status' => 'ok'], 200),
        ]);

        config()->set('smarthome.gemini_api_key', 'AIzaSyFakeKeyValidFormat1234567890');

        $response = $this->postJson('/api/command', [
            'text_command' => 'nyalakan lampu ruang tamu',
            'input_source' => 'text',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'success' => true,
                'ai_reply' => 'Siap, lampu ruang tamu dinyalakan.',
                'audio_url' => null,
                'intent' => 'control',
                'device' => 'relay_1',
                'action' => 'ON',
            ]);

        $this->assertNotNull($response->json('log_id'));

        // GenerateSpeechJob harus di-dispatch setelah response
        Queue::assertPushed(GenerateSpeechJob::class, function ($job) use ($response) {
            return $job->logId === $response->json('log_id');
        });
    }

    /**
     * 2. Keamanan: Jika GEMINI_API_KEY kosong atau invalid, sistem fallback ke fallbackParser() tanpa crash.
     */
    public function test_empty_or_invalid_gemini_api_key_falls_back_gracefully(): void
    {
        Queue::fake();

        // Kasus A: API Key Kosong
        config()->set('smarthome.gemini_api_key', '');

        $responseEmpty = $this->postJson('/api/command', [
            'text_command' => 'nyalakan lampu',
            'input_source' => 'text',
        ]);

        $responseEmpty->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'success' => true,
                'device' => 'relay_1',
                'action' => 'ON',
            ]);

        // Kasus B: API Key Invalid / Ditolak Google API (HTTP 400 API_KEY_INVALID)
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 400,
                    'message' => 'API key not valid. Please pass a valid API key. (API_KEY_INVALID)',
                    'status' => 'INVALID_ARGUMENT',
                ],
            ], 400),
        ]);

        config()->set('smarthome.gemini_api_key', 'AIzaSyInvalidKeyNotReal9999999999999');

        $responseInvalid = $this->postJson('/api/command', [
            'text_command' => 'matikan lampu',
            'input_source' => 'text',
        ]);

        $responseInvalid->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'success' => true,
                'device' => 'relay_1',
                'action' => 'OFF',
            ]);
    }

    /**
     * 3. Keamanan: API key tidak pernah dibocorkan ke Log::info atau Log::warning.
     */
    public function test_api_key_is_never_logged(): void
    {
        Queue::fake();

        $secretKey = 'AIzaSySecretSuperPrivateToken987654321';
        config()->set('smarthome.gemini_api_key', $secretKey);

        $loggedMessages = [];
        Log::listen(function ($message) use (&$loggedMessages) {
            $loggedMessages[] = is_string($message->message) ? $message->message : json_encode($message);
        });

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'error' => ['message' => "Error with request key={$secretKey}"],
            ], 500),
        ]);

        $this->postJson('/api/command', [
            'text_command' => 'siapa kamu?',
            'input_source' => 'text',
        ]);

        // Periksa semua pesan log yang tertangkap
        foreach ($loggedMessages as $logText) {
            $this->assertStringNotContainsString(
                $secretKey,
                $logText,
                'FATAL: API Key ditemukan di dalam log sistem!'
            );
        }
    }

    /**
     * 4. Node 2 Sensor: Perintah biasa TIDAK memanggil sensor Node 2.
     */
    public function test_sensor_fetch_is_skipped_for_unrelated_commands(): void
    {
        Queue::fake();
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [['text' => json_encode(['intent' => 'chat', 'ai_reply' => '[ID] Halo!'])]],
                        ],
                    ],
                ],
            ], 200),
        ]);

        config()->set('smarthome.gemini_api_key', 'AIzaSyFakeKeyValidFormat1234567890');

        $this->postJson('/api/command', [
            'text_command' => 'halo apa kabar LUCA',
            'input_source' => 'text',
        ]);

        // Pastikan endpoint sensor Node 2 TIDAK pernah di-request
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), '/api/sensor');
        });
    }

    /**
     * 5. Node 2 Sensor: Perintah dengan kata kunci keamanan MEMANGGIL sensor Node 2.
     */
    public function test_sensor_fetch_is_executed_for_security_commands(): void
    {
        Queue::fake();
        Http::fake([
            'http://10.143.163.40/api/sensor' => Http::response(['distance_cm' => 25], 200),
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [['text' => json_encode(['intent' => 'chat', 'ai_reply' => '[ID] Kondisi aman.'])]],
                        ],
                    ],
                ],
            ], 200),
        ]);

        config()->set('smarthome.gemini_api_key', 'AIzaSyFakeKeyValidFormat1234567890');

        $this->postJson('/api/command', [
            'text_command' => 'apakah kondisi rumah aman sekarang?',
            'input_source' => 'text',
        ]);

        // Pastikan endpoint sensor Node 2 DITEMBAK
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/sensor');
        });
    }

    /**
     * 6. Endpoint Polling Audio: GET /api/command/{id}/audio.
     */
    public function test_audio_status_polling_endpoint(): void
    {
        $log = CommandLog::createPending('tes audio polling');
        $log->markTtsDone('http://localhost:8000/audio/reply_test.mp3', 250);

        $response = $this->getJson("/api/command/{$log->id}/audio");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'log_id' => $log->id,
                'tts_status' => 'done',
                'audio_url' => 'http://localhost:8000/audio/reply_test.mp3',
                'tts_duration_ms' => 250,
            ]);

        // Test not found
        $response404 = $this->getJson('/api/command/999999/audio');
        $response404->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    /**
     * 7. GenerateSpeechJob memperbarui CommandLog setelah selesai.
     */
    public function test_generate_speech_job_updates_command_log(): void
    {
        $log = CommandLog::createPending('tes job');

        // Test jika teks kosong, status jadi failed
        $job = new GenerateSpeechJob($log->id, '');
        $job->handle();

        $log->refresh();
        $this->assertEquals(CommandLog::TTS_FAILED, $log->tts_status);
    }

    /**
     * 8. AudioReadyEvent memvalidasi payload dan channels untuk Reverb / WebSocket.
     */
    public function test_audio_ready_event_payload_and_channels(): void
    {
        $event = new \App\Events\AudioReadyEvent(123, 'http://localhost:8000/audio/reply_test.mp3', 'esp1_device');

        $this->assertEquals('AudioReady', $event->broadcastAs());
        $payload = $event->broadcastWith();
        $this->assertEquals(123, $payload['log_id']);
        $this->assertEquals('http://localhost:8000/audio/reply_test.mp3', $payload['audio_url']);
        $this->assertEquals('esp1_device', $payload['device_identifier']);

        $channels = $event->broadcastOn();
        $channelNames = array_map(fn ($c) => $c->name, $channels);
        $this->assertContains('smarthome.global', $channelNames);
        $this->assertContains('smarthome.esp1_device', $channelNames);
    }

    /**
     * 9. Validasi: Jika Node 2 mengembalikan status 'ignored', ai_reply dikoreksi jujur, executed=false,
     *    dan koreksi tercatat di Cache history serta TTS job payload (bukan klaim sukses Gemini).
     */
    public function test_node2_ignored_status_corrects_ai_reply_history_and_tts_payload(): void
    {
        Queue::fake();
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'intent' => 'control',
                                        'device' => 'relay_1',
                                        'action' => 'ON',
                                        'parameters' => null,
                                        'ai_reply' => '[ID] Siap, lampu ruang tamu dinyalakan.',
                                    ]),
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'http://10.143.163.40/*' => Http::response([
                'status' => 'ignored',
                'message' => 'Perangkat tidak dikenali atau belum didukung',
            ], 200),
        ]);

        config()->set('smarthome.gemini_api_key', 'AIzaSyFakeKeyValidFormat1234567890');

        $response = $this->postJson('/api/command', [
            'text_command' => 'nyalakan lampu ruang tamu',
            'input_source' => 'text',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'success' => true,
                'executed' => false,
                'device' => 'relay_1',
                'action' => 'ON',
            ]);

        $aiReply = $response->json('ai_reply');
        $this->assertStringNotContainsString('Siap, lampu ruang tamu dinyalakan', $aiReply);
        $this->assertStringContainsString('belum saya kenali atau belum terhubung', $aiReply);

        // Pastikan GenerateSpeechJob menerima ai_reply yang sudah dikoreksi, bukan klaim palsu
        Queue::assertPushed(GenerateSpeechJob::class, function ($job) {
            return ! str_contains($job->text, 'Siap, lampu ruang tamu dinyalakan')
                && str_contains($job->text, 'belum saya kenali atau belum terhubung');
        });

        // Pastikan cache history juga mencatat pesan koreksi yang jujur
        $cacheKey = 'chat_history_127.0.0.1';
        $history = Cache::get($cacheKey, []);
        $this->assertNotEmpty($history);
        $lastAiMessage = end($history);
        $this->assertStringContainsString('belum saya kenali atau belum terhubung', $lastAiMessage['text'] ?? '');
    }

    /**
     * 10. Validasi: Jika Node 2 offline/timeout (exception), ai_reply dikoreksi jujur, executed=false.
     */
    public function test_node2_offline_exception_corrects_ai_reply_honestly(): void
    {
        Queue::fake();
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'intent' => 'control',
                                        'device' => 'relay_1',
                                        'action' => 'ON',
                                        'parameters' => null,
                                        'ai_reply' => '[ID] Siap, lampu ruang tamu dinyalakan.',
                                    ]),
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'http://10.143.163.40/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection timed out after 5000ms'),
        ]);

        config()->set('smarthome.gemini_api_key', 'AIzaSyFakeKeyValidFormat1234567890');

        $response = $this->postJson('/api/command', [
            'text_command' => 'nyalakan lampu ruang tamu',
            'input_source' => 'text',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'success' => true,
                'executed' => false,
                'device' => 'relay_1',
                'action' => 'ON',
            ]);

        $aiReply = $response->json('ai_reply');
        $this->assertStringNotContainsString('Siap, lampu ruang tamu dinyalakan', $aiReply);
        $this->assertStringContainsString('tidak dapat dihubungi atau sedang offline', $aiReply);

        Queue::assertPushed(GenerateSpeechJob::class, function ($job) {
            return str_contains($job->text, 'tidak dapat dihubungi atau sedang offline');
        });
    }

    /**
     * 11. Validasi: Jika Node 2 sukses ('success'), ai_reply asli dipertahankan dan executed=true.
     */
    public function test_node2_success_status_preserves_original_ai_reply_and_sets_executed_true(): void
    {
        Queue::fake();
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'intent' => 'control',
                                        'device' => 'relay_1',
                                        'action' => 'ON',
                                        'parameters' => null,
                                        'ai_reply' => '[ID] Siap, lampu ruang tamu dinyalakan.',
                                    ]),
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'http://10.143.163.40/*' => Http::response([
                'status' => 'success',
                'device' => 'relay_1',
                'state' => 'ON',
            ], 200),
        ]);

        config()->set('smarthome.gemini_api_key', 'AIzaSyFakeKeyValidFormat1234567890');

        $response = $this->postJson('/api/command', [
            'text_command' => 'nyalakan lampu ruang tamu',
            'input_source' => 'text',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'success' => true,
                'executed' => true,
                'ai_reply' => 'Siap, lampu ruang tamu dinyalakan.',
                'device' => 'relay_1',
                'action' => 'ON',
            ]);
    }

    /**
     * 12. Validasi: Chat biasa memiliki executed=true.
     */
    public function test_chat_command_has_executed_true(): void
    {
        Queue::fake();
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'intent' => 'chat',
                                        'device' => null,
                                        'action' => null,
                                        'parameters' => null,
                                        'ai_reply' => '[ID] Halo! Ada yang bisa saya bantu?',
                                    ]),
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        config()->set('smarthome.gemini_api_key', 'AIzaSyFakeKeyValidFormat1234567890');

        $response = $this->postJson('/api/command', [
            'text_command' => 'halo apa kabar',
            'input_source' => 'text',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'success' => true,
                'executed' => true,
                'ai_reply' => 'Halo! Ada yang bisa saya bantu?',
            ]);
    }
}
