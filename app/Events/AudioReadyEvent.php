<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AudioReadyEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public int $logId,
        public string $audioUrl,
        public ?string $deviceIdentifier = null,
    ) {}

    /**
     * Nama event yang dibroadcast ke client WebSocket.
     */
    public function broadcastAs(): string
    {
        return 'AudioReady';
    }

    /**
     * Payload yang dikirimkan ke client WebSocket.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'log_id' => $this->logId,
            'audio_url' => $this->audioUrl,
            'device_identifier' => $this->deviceIdentifier,
        ];
    }

    /**
     * Channel tempat event dibroadcast.
     * Menggunakan channel smarthome.{identifier} agar bisa difilter per device / ESP32.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new Channel('smarthome.global'),
        ];

        if (! empty($this->deviceIdentifier)) {
            $channels[] = new Channel('smarthome.'.$this->deviceIdentifier);
        }

        return $channels;
    }
}
