<?php

namespace App\Jobs;

use App\Events\AudioReadyEvent;
use App\Events\SpeechGeneratedEvent;
use App\Models\CommandLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateSpeechJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Jumlah percobaan job jika gagal.
     */
    public int $tries = 2;

    /**
     * Timeout job dalam detik.
     */
    public int $timeout = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $logId,
        public string $text,
        public ?string $deviceIdentifier = null,
        public array $latencyMetrics = [],
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $log = CommandLog::find($this->logId);
        if (! $log) {
            Log::warning("[Edge-TTS] CommandLog tidak ditemukan untuk ID: {$this->logId}");

            return;
        }

        if (empty(trim($this->text))) {
            $log->markTtsFailed('Teks kosong');

            return;
        }

        $log->markTtsProcessing();
        $startTime = microtime(true);
        $txtPath = null;

        try {
            $rawText = $this->text;

            // Setup default (Bahasa Indonesia)
            $voice = 'id-ID-GadisNeural';
            $pitch = '--pitch=+10Hz';
            $cleanText = $rawText;

            // Deteksi bahasa dari tag [EN] / [ID]
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

            // Bersihkan format markdown
            $cleanText = preg_replace('/[*#_`]/', '', $cleanText);
            $cleanText = trim($cleanText);

            if (empty($cleanText)) {
                $log->markTtsFailed('Teks setelah pembersihan kosong');

                return;
            }

            $audioDir = public_path('audio');
            if (! is_dir($audioDir)) {
                mkdir($audioDir, 0755, true);
            }

            // Simpan ke file teks sementara
            $uniqueId = time().'_'.uniqid();
            $txtFileName = 'input_'.$uniqueId.'.txt';
            $mp3FileName = 'reply_'.$uniqueId.'.mp3';

            $txtPath = $audioDir.DIRECTORY_SEPARATOR.$txtFileName;
            $audioPath = $audioDir.DIRECTORY_SEPARATOR.$mp3FileName;

            file_put_contents($txtPath, $cleanText);

            // Eksekusi edge-tts
            $pitchArg = ! empty($pitch) ? ' '.$pitch : '';
            $command = 'edge-tts --voice '.$voice.$pitchArg.' -f '.escapeshellarg($txtPath).' --write-media '.escapeshellarg($audioPath);
            shell_exec($command);

            // Bersihkan file txt
            if (file_exists($txtPath)) {
                @unlink($txtPath);
            }

            if (file_exists($audioPath) && filesize($audioPath) > 0) {
                $audioUrl = url('audio/'.$mp3FileName);
                $durationMs = (int) round((microtime(true) - $startTime) * 1000);

                // Update command log secara async
                $log->markTtsDone($audioUrl, $durationMs);

                // Broadcast event jika broadcaster aktif (Reverb)
                try {
                    AudioReadyEvent::dispatch($this->logId, $audioUrl, $this->deviceIdentifier);
                    SpeechGeneratedEvent::dispatch($this->logId, $audioUrl);
                } catch (Throwable $broadcastError) {
                    Log::debug('[Edge-TTS] Broadcast notification skipped: '.$broadcastError->getMessage());
                }

                // Waktu total dari proses TTS sampai event WebSocket selesai dibroadcast
                $ttsAsyncMs = (int) round((microtime(true) - $startTime) * 1000);

                $sensorMs = $this->latencyMetrics['sensor'] ?? 0;
                $geminiMs = $this->latencyMetrics['gemini'] ?? 0;
                $esp2Ms = $this->latencyMetrics['esp2'] ?? 0;
                $totalBeforeResponseMs = $this->latencyMetrics['total_before_response'] ?? 0;

                // Log latency breakdown lengkap:
                // [SmartHome] Latency breakdown - sensor: Xms, gemini: Xms, esp2: Xms, total_before_response: Xms, tts_async: Xms
                Log::info("[SmartHome] Latency breakdown - sensor: {$sensorMs}ms, gemini: {$geminiMs}ms, esp2: {$esp2Ms}ms, total_before_response: {$totalBeforeResponseMs}ms, tts_async: {$ttsAsyncMs}ms");
            } else {
                $log->markTtsFailed('Audio file generation resulted in empty output');
                Log::warning("[Edge-TTS] File audio tidak terbentuk atau 0 byte untuk log_id: {$this->logId}");
            }
        } catch (Throwable $e) {
            $log->markTtsFailed($e->getMessage());
            Log::error("[Edge-TTS] Exception generate audio untuk log_id {$this->logId}: ".$e->getMessage());
        } finally {
            if ($txtPath && file_exists($txtPath)) {
                @unlink($txtPath);
            }
        }
    }
}
