<?php

namespace App\Notifications;

use App\Models\Emergency;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Browser push for a recipient. Sent regardless of Do Not Disturb — that
 * override is the product guarantee behind emergency messaging.
 */
class EmergencyRaised extends Notification
{
    use Queueable;

    public function __construct(
        public Emergency $emergency,
        public bool $isEscalation = false,
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
        $title = $this->isEscalation
            ? 'STILL UNACKNOWLEDGED — Emergency'
            : 'EMERGENCY — '.$this->emergency->sender->name;

        return (new WebPushMessage)
            ->title($title)
            ->icon('/images/emergency-icon.png')
            ->badge('/images/emergency-badge.png')
            ->body(str($this->emergency->body)->limit(180)->toString())
            ->tag('emergency-'.$this->emergency->getKey())
            // renotify + requireInteraction: the notification must not be
            // silently coalesced away or auto-dismissed.
            ->options(['TTL' => 3600])
            ->data([
                'emergency_id' => $this->emergency->getKey(),
                'room_id' => $this->emergency->room_id,
                'url' => $this->emergency->room_id
                    ? route('rooms.show', $this->emergency->room_id)
                    : route('hub'),
                'requireInteraction' => true,
                'renotify' => true,
            ]);
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'emergency',
            'emergency_id' => $this->emergency->getKey(),
            'room_id' => $this->emergency->room_id,
            'sender_name' => $this->emergency->sender->name,
            'body' => $this->emergency->body,
            'is_escalation' => $this->isEscalation,
        ];
    }
}
