<?php

namespace Database\Factories;

use App\Models\CommandLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory: CommandLogFactory
 *
 * Menghasilkan data dummy yang realistis untuk testing & seeding.
 */
class CommandLogFactory extends Factory
{
    protected $model = CommandLog::class;

    /**
     * Device-action pairs yang realistis untuk Smart Home.
     */
    private array $scenarios = [
        ['device' => 'lampu_ruang_tamu',   'action' => 'on',               'endpoint' => '/api/relay/1/on'],
        ['device' => 'lampu_ruang_tamu',   'action' => 'off',              'endpoint' => '/api/relay/1/off'],
        ['device' => 'lampu_kamar_tidur',  'action' => 'on',               'endpoint' => '/api/relay/2/on'],
        ['device' => 'lampu_kamar_tidur',  'action' => 'off',              'endpoint' => '/api/relay/2/off'],
        ['device' => 'ac_ruang_tamu',      'action' => 'on',               'endpoint' => '/api/ac/on'],
        ['device' => 'ac_ruang_tamu',      'action' => 'off',              'endpoint' => '/api/ac/off'],
        ['device' => 'ac_ruang_tamu',      'action' => 'set_temperature',  'endpoint' => '/api/ac/temperature'],
        ['device' => 'kunci_pintu_depan',  'action' => 'lock',             'endpoint' => '/api/lock/front/lock'],
        ['device' => 'kunci_pintu_depan',  'action' => 'unlock',           'endpoint' => '/api/lock/front/unlock'],
        ['device' => 'kipas_dapur',        'action' => 'on',               'endpoint' => '/api/fan/kitchen/on'],
        ['device' => 'kipas_dapur',        'action' => 'off',              'endpoint' => '/api/fan/kitchen/off'],
        ['device' => 'tv_ruang_tamu',      'action' => 'on',               'endpoint' => '/api/tv/on'],
        ['device' => 'tv_ruang_tamu',      'action' => 'off',              'endpoint' => '/api/tv/off'],
        ['device' => 'pompa_air',          'action' => 'on',               'endpoint' => '/api/pump/on'],
        ['device' => 'pompa_air',          'action' => 'off',              'endpoint' => '/api/pump/off'],
    ];

    private array $perintahTemplates = [
        'on' => ['nyalakan %s', 'hidupkan %s', 'tolong nyalakan %s', 'aktifkan %s'],
        'off' => ['matikan %s', 'padamkan %s', 'tolong matikan %s', 'nonaktifkan %s'],
        'set_temperature' => ['atur suhu AC ke %d derajat', 'set AC %d derajat', 'dinginkan ruangan ke %d'],
        'lock' => ['kunci pintu', 'tolong kunci pintu', 'amankan pintu'],
        'unlock' => ['buka kunci pintu', 'tolong buka pintu'],
        'default' => ['%s %s'],
    ];

    public function definition(): array
    {
        $scenario = $this->faker->randomElement($this->scenarios);
        $status = $this->faker->randomElement(['success', 'success', 'success', 'failed', 'pending']);

        // Generate perintah teks yang realistis
        $perintah = $this->generatePerintah($scenario['device'], $scenario['action']);

        // Simulate LLM raw response
        $llmRaw = [
            'model' => 'gpt-4o-mini',
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'device' => $scenario['device'],
                        'action' => $scenario['action'],
                        'parameters' => $this->generateParameters($scenario['action']),
                    ]),
                ],
            ]],
            'usage' => [
                'prompt_tokens' => $this->faker->numberBetween(50, 200),
                'completion_tokens' => $this->faker->numberBetween(20, 80),
            ],
        ];

        // Simulate ESP32 response
        $espResponse = $status === 'success'
            ? json_encode([
                'status' => 'ok',
                'device' => $scenario['device'],
                'action' => $scenario['action'],
                'state' => $scenario['action'] === 'on' ? true : false,
                'message' => 'Command executed successfully',
            ])
            : json_encode([
                'status' => 'error',
                'message' => $this->faker->randomElement([
                    'Device not responding',
                    'Timeout',
                    'Invalid command',
                    'Device offline',
                ]),
            ]);

        $createdAt = $this->faker->dateTimeBetween('-30 days', 'now');

        return [
            'perintah_teks' => $perintah,
            'input_source' => $this->faker->randomElement(['text', 'text', 'voice']),
            'device' => $scenario['device'],
            'action' => $scenario['action'],
            'parameters' => $this->generateParameters($scenario['action']),
            'llm_raw_response' => $llmRaw,
            'status' => $status,
            'response_esp' => in_array($status, ['success', 'failed']) ? $espResponse : null,
            'esp_http_code' => match ($status) {
                'success' => 200,
                'failed' => $this->faker->randomElement([400, 408, 500, 503]),
                default => null,
            },
            'esp_endpoint' => in_array($status, ['sent', 'success', 'failed'])
                ? 'http://192.168.1.'.$this->faker->numberBetween(100, 120).$scenario['endpoint']
                : null,
            'latency_ms' => $status === 'success'
                ? $this->faker->numberBetween(50, 800)
                : null,
            'user_id' => null,
            'user_ip' => $this->faker->localIpv4(),
            'session_id' => $this->faker->uuid(),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'executed_at' => in_array($status, ['success', 'failed']) ? $createdAt : null,
        ];
    }

    /**
     * State: Hanya perintah yang berhasil.
     */
    public function successful(): static
    {
        return $this->state(fn () => [
            'status' => CommandLog::STATUS_SUCCESS,
            'esp_http_code' => 200,
            'latency_ms' => fake()->numberBetween(50, 500),
            'executed_at' => now(),
        ]);
    }

    /**
     * State: Hanya perintah yang gagal.
     */
    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => CommandLog::STATUS_FAILED,
            'esp_http_code' => fake()->randomElement([400, 500, 503]),
            'response_esp' => json_encode(['status' => 'error', 'message' => 'Device not responding']),
        ]);
    }

    /**
     * State: Perintah dari suara.
     */
    public function fromVoice(): static
    {
        return $this->state(fn () => ['input_source' => CommandLog::SOURCE_VOICE]);
    }

    /**
     * State: Device lampu.
     */
    public function forLampu(): static
    {
        return $this->state(function () {
            $devices = ['lampu_ruang_tamu', 'lampu_kamar_tidur', 'lampu_dapur', 'lampu_kamar_mandi'];
            $device = fake()->randomElement($devices);
            $action = fake()->randomElement(['on', 'off']);

            return [
                'device' => $device,
                'action' => $action,
                'perintah_teks' => $this->generatePerintah($device, $action),
            ];
        });
    }

    // ── Private Helpers ─────────────────────────────────────────────────

    private function generatePerintah(string $device, string $action): string
    {
        $deviceLabel = str_replace('_', ' ', $device);
        $templates = $this->perintahTemplates[$action] ?? $this->perintahTemplates['default'];
        $template = $this->faker->randomElement($templates);

        if (str_contains($template, '%d')) {
            return sprintf($template, $this->faker->numberBetween(18, 28));
        }
        if (str_contains($template, '%s') && substr_count($template, '%s') === 2) {
            return sprintf($template, $action, $deviceLabel);
        }

        return sprintf($template, $deviceLabel);
    }

    private function generateParameters(string $action): ?array
    {
        return match ($action) {
            'set_temperature' => ['suhu' => $this->faker->numberBetween(18, 28)],
            'set_brightness' => ['brightness' => $this->faker->numberBetween(10, 100)],
            'on' => $this->faker->boolean(30) ? ['timer_menit' => $this->faker->randomElement([30, 60, 120])] : null,
            default => null,
        };
    }
}
