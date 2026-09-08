<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Model: CommandLog
 *
 * Merepresentasikan satu siklus penuh perintah Smart Home:
 *   User Input → LLM Parsing → ESP32 Execution → Response Logged
 *
 * @property int $id
 * @property string $perintah_teks Teks perintah asli dari pengguna
 * @property string $input_source 'text' | 'voice'
 * @property string|null $device Nama device (dari LLM)
 * @property string|null $action Aksi yang dieksekusi (dari LLM)
 * @property array|null $parameters Parameter JSON tambahan
 * @property array|null $llm_raw_response Raw JSON dari LLM
 * @property string $status Status pipeline saat ini
 * @property string|null $response_esp Response text dari ESP32
 * @property int|null $esp_http_code HTTP status code ESP32
 * @property string|null $esp_endpoint URL endpoint yang ditembak
 * @property int|null $latency_ms Latensi request ke ESP32 (ms)
 * @property int|null $user_id ID pengguna
 * @property string|null $user_ip IP address pengguna
 * @property string|null $session_id Session grouping
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $executed_at Waktu ESP32 selesai eksekusi
 *
 * @method static Builder forDevice(string $device)
 * @method static Builder forAction(string $action)
 * @method static Builder successful()
 * @method static Builder failed()
 * @method static Builder recentFirst()
 */
class CommandLog extends Model
{
    use HasFactory;

    /**
     * Nama tabel di database.
     */
    protected $table = 'command_logs';

    /**
     * Kolom yang boleh diisi secara massal (mass assignment).
     */
    protected $fillable = [
        'perintah_teks',
        'input_source',
        'device',
        'action',
        'parameters',
        'llm_raw_response',
        'status',
        'response_esp',
        'audio_url',
        'tts_status',
        'tts_duration_ms',
        'esp_http_code',
        'esp_endpoint',
        'latency_ms',
        'user_id',
        'user_ip',
        'session_id',
        'executed_at',
    ];

    /**
     * Cast otomatis tipe data kolom.
     * JSON akan otomatis di-decode menjadi array PHP.
     */
    protected $casts = [
        'parameters' => 'array',
        'llm_raw_response' => 'array',
        'esp_http_code' => 'integer',
        'latency_ms' => 'integer',
        'tts_duration_ms' => 'integer',
        'executed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ══════════════════════════════════════════════════════════════════════
    // KONSTANTA STATUS
    // ══════════════════════════════════════════════════════════════════════

    const STATUS_PENDING = 'pending';   // Baru masuk, belum diproses LLM

    const STATUS_PARSED = 'parsed';   // LLM sudah parse, belum ke ESP32

    const STATUS_SENT = 'sent';     // Sudah dikirim ke ESP32

    const STATUS_SUCCESS = 'success';  // ESP32 berhasil eksekusi

    const STATUS_FAILED = 'failed';   // Gagal di salah satu tahap

    const TTS_PENDING = 'pending';
    const TTS_PROCESSING = 'processing';
    const TTS_DONE = 'done';
    const TTS_FAILED = 'failed';

    const SOURCE_TEXT = 'text';

    const SOURCE_VOICE = 'voice';

    // ══════════════════════════════════════════════════════════════════════
    // RELATIONSHIPS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Pengguna yang mengirim perintah ini.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ══════════════════════════════════════════════════════════════════════
    // QUERY SCOPES
    // Mempermudah filter data dengan sintaks yang bersih & readable.
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Filter berdasarkan device tertentu.
     * Contoh: CommandLog::forDevice('lampu_kamar')->get()
     */
    public function scopeForDevice(Builder $query, string $device): Builder
    {
        return $query->where('device', $device);
    }

    /**
     * Filter berdasarkan action tertentu.
     * Contoh: CommandLog::forAction('on')->get()
     */
    public function scopeForAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    /**
     * Hanya tampilkan perintah yang berhasil dieksekusi.
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    /**
     * Hanya tampilkan perintah yang gagal.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Urut dari yang terbaru.
     */
    public function scopeRecentFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at');
    }

    /**
     * Filter berdasarkan input source (teks atau suara).
     */
    public function scopeFromVoice(Builder $query): Builder
    {
        return $query->where('input_source', self::SOURCE_VOICE);
    }

    /**
     * Filter perintah dalam rentang waktu tertentu.
     */
    public function scopeInDateRange(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('created_at', [
            Carbon::parse($from)->startOfDay(),
            Carbon::parse($to)->endOfDay(),
        ]);
    }

    /**
     * Filter by user session.
     */
    public function scopeInSession(Builder $query, string $sessionId): Builder
    {
        return $query->where('session_id', $sessionId);
    }

    // ══════════════════════════════════════════════════════════════════════
    // ACCESSOR (Getter)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Cek apakah perintah ini berhasil dieksekusi.
     */
    public function getIsSuccessfulAttribute(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * Cek apakah perintah ini gagal.
     */
    public function getIsFailedAttribute(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Label status yang human-readable dalam Bahasa Indonesia.
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => '⏳ Menunggu',
            self::STATUS_PARSED => '🧠 Dianalisis AI',
            self::STATUS_SENT => '📡 Dikirim ke Perangkat',
            self::STATUS_SUCCESS => '✅ Berhasil',
            self::STATUS_FAILED => '❌ Gagal',
            default => '❓ Tidak Diketahui',
        };
    }

    /**
     * Warna badge status untuk Tailwind CSS.
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'yellow',
            self::STATUS_PARSED => 'blue',
            self::STATUS_SENT => 'indigo',
            self::STATUS_SUCCESS => 'green',
            self::STATUS_FAILED => 'red',
            default => 'gray',
        };
    }

    /**
     * Nama device yang diformat dengan lebih rapi.
     * Contoh: "lampu_kamar" → "Lampu Kamar"
     */
    public function getDeviceLabelAttribute(): string
    {
        return ucwords(str_replace('_', ' ', $this->device ?? 'Tidak Diketahui'));
    }

    /**
     * Label action yang lebih deskriptif.
     */
    public function getActionLabelAttribute(): string
    {
        return match ($this->action) {
            'on' => '🟢 Nyalakan',
            'off' => '🔴 Matikan',
            'toggle' => '🔄 Toggle',
            'set_temperature' => '🌡️ Atur Suhu',
            'set_brightness' => '💡 Atur Kecerahan',
            'lock' => '🔒 Kunci',
            'unlock' => '🔓 Buka Kunci',
            'status' => '📊 Cek Status',
            default => ucfirst($this->action ?? '-'),
        };
    }

    /**
     * Format latensi ESP32 dalam teks yang readable.
     * Contoh: 145 → "145ms", null → "N/A"
     */
    public function getLatencyLabelAttribute(): string
    {
        if (is_null($this->latency_ms)) {
            return 'N/A';
        }

        return $this->latency_ms >= 1000
            ? round($this->latency_ms / 1000, 2).'s'
            : $this->latency_ms.'ms';
    }

    /**
     * Sumber input yang human-readable.
     */
    public function getSourceLabelAttribute(): string
    {
        return $this->input_source === self::SOURCE_VOICE ? '🎤 Suara' : '⌨️ Teks';
    }

    // ══════════════════════════════════════════════════════════════════════
    // MUTATORS (Setter)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Otomatis lowercase device & action agar konsisten.
     */
    public function setDeviceAttribute(?string $value): void
    {
        $this->attributes['device'] = $value ? strtolower(trim($value)) : null;
    }

    public function setActionAttribute(?string $value): void
    {
        $this->attributes['action'] = $value ? strtolower(trim($value)) : null;
    }

    // ══════════════════════════════════════════════════════════════════════
    // BUSINESS LOGIC METHODS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Update status pipeline ke tahap berikutnya.
     * Digunakan setelah LLM berhasil parsing intent.
     *
     * @param  array  $intentData  Data dari LLM: ['device', 'action', 'parameters']
     */
    public function markAsParsed(array $intentData): void
    {
        $this->update([
            'device' => $intentData['device'] ?? null,
            'action' => $intentData['action'] ?? null,
            'parameters' => $intentData['parameters'] ?? null,
            'llm_raw_response' => $intentData['raw_response'] ?? null,
            'status' => self::STATUS_PARSED,
        ]);
    }

    /**
     * Update status setelah request dikirim ke ESP32.
     *
     * @param  string  $endpoint  URL ESP32 yang ditembak
     */
    public function markAsSent(string $endpoint): void
    {
        $this->update([
            'esp_endpoint' => $endpoint,
            'status' => self::STATUS_SENT,
        ]);
    }

    /**
     * Update status setelah menerima response dari ESP32.
     *
     * @param  string  $response  HTTP response body dari ESP32
     * @param  int  $httpCode  HTTP status code
     * @param  int  $latencyMs  Latensi dalam milidetik
     */
    public function markAsExecuted(string $response, int $httpCode, int $latencyMs): void
    {
        $isSuccess = $httpCode >= 200 && $httpCode < 300;

        $this->update([
            'response_esp' => $response,
            'esp_http_code' => $httpCode,
            'latency_ms' => $latencyMs,
            'status' => $isSuccess ? self::STATUS_SUCCESS : self::STATUS_FAILED,
            'executed_at' => now(),
        ]);
    }

    /**
     * Tandai perintah sebagai gagal dengan alasan tertentu.
     *
     * @param  string  $reason  Alasan kegagalan (disimpan ke response_esp)
     */
    public function markAsFailed(string $reason): void
    {
        $this->update([
            'response_esp' => $reason,
            'status' => self::STATUS_FAILED,
        ]);
    }

    /**
     * Tandai proses TTS sedang berjalan.
     */
    public function markTtsProcessing(): void
    {
        $this->update([
            'tts_status' => self::TTS_PROCESSING,
        ]);
    }

    /**
     * Tandai proses TTS berhasil selesai beserta URL dan durasi eksekusinya.
     */
    public function markTtsDone(string $audioUrl, ?int $durationMs = null): void
    {
        $this->update([
            'audio_url' => $audioUrl,
            'tts_status' => self::TTS_DONE,
            'tts_duration_ms' => $durationMs,
        ]);
    }

    /**
     * Tandai proses TTS gagal.
     */
    public function markTtsFailed(?string $reason = null): void
    {
        $this->update([
            'tts_status' => self::TTS_FAILED,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // STATIC HELPERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Buat log baru dengan status 'pending' dari sebuah perintah teks.
     * Entry point utama ketika perintah baru masuk ke sistem.
     *
     * @param  string  $perintah  Teks perintah
     * @param  string  $source  'text' atau 'voice'
     * @param  int|null  $userId  ID user (null = guest)
     * @param  string|null  $userIp  IP Address pengguna
     * @param  string|null  $sessionId  Session ID
     */
    public static function createPending(
        string $perintah,
        string $source = self::SOURCE_TEXT,
        ?int $userId = null,
        ?string $userIp = null,
        ?string $sessionId = null
    ): static {
        return static::create([
            'perintah_teks' => $perintah,
            'input_source' => $source,
            'status' => self::STATUS_PENDING,
            'user_id' => $userId,
            'user_ip' => $userIp,
            'session_id' => $sessionId,
        ]);
    }

    /**
     * Statistik ringkasan untuk dashboard analytics.
     * Mengembalikan array dengan data agregat.
     */
    public static function getDashboardStats(): array
    {
        return [
            'total' => static::count(),
            'success' => static::successful()->count(),
            'failed' => static::failed()->count(),
            'today' => static::whereDate('created_at', today())->count(),
            'avg_latency_ms' => (int) static::successful()->whereNotNull('latency_ms')->avg('latency_ms'),
            'voice_commands' => static::fromVoice()->count(),
            'top_device' => static::selectRaw('device, COUNT(*) as total')
                ->whereNotNull('device')
                ->groupBy('device')
                ->orderByDesc('total')
                ->value('device'),
        ];
    }
}
