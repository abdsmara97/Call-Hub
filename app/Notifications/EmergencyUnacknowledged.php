<?php

namespace App\Notifications;

use App\Models\Emergency;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Tells the sender their emergency has not been seen by everyone. Sent on each
 * escalation, and once more when the escalation budget is exhausted.
 */
class EmergencyUnacknowledged extends Notification
{
    use Queueable;

    public function __construct(
        public Emergency $emergency,
        public int $pendingCount,
        public bool $exhausted = false,
    ) {
        $this->onQueue('emergency');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class, 'database'];
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        $body = $this->exhausted
            ? "{$this->pendingCount} recipient(s) never acknowledged. Re-alerts have stopped — reach them another way."
            : "{$this->pendingCount} recipient(s) have still not acknowledged. They have been re-alerted.";

        return (new WebPushMessage)
            ->title($this->exhausted ? 'Emergency unacknowledged' : 'Emergency still pending')
            ->body($body)
            ->tag('emergency-pending-'.$this->emergency->getKey())
            ->data([
                'emergency_id' => $this->emergency->getKey(),
                'url' => route('hub'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'emergency_unacknowledged',
            'emergency_id' => $this->emergency->getKey(),
            'pending_count' => $this->pendingCount,
            'exhausted' => $this->exhausted,
        ];
    }
}
