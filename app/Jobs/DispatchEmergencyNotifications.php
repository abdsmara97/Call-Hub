<?php

namespace App\Jobs;

use App\Models\Emergency;
use App\Models\EmergencyRecipient;
use App\Notifications\EmergencyRaised;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Fans browser push out to every recipient. Queued because a company-wide
 * broadcast is several hundred HTTP calls to push services, and the person who
 * raised the emergency must not wait on any of them.
 */
class DispatchEmergencyNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public int $emergencyId,
        public bool $isEscalation = false,
    ) {
        $this->onQueue('emergency');
    }

    public function handle(): void
    {
        $emergency = Emergency::with('sender')->find($this->emergencyId);

        if (! $emergency || $emergency->isResolved()) {
            return;
        }

        EmergencyRecipient::query()
            ->where('emergency_id', $emergency->getKey())
            // An escalation only chases the people who have not responded.
            ->when($this->isEscalation, fn ($q) => $q->pending())
            ->with('user')
            ->chunkById(200, function ($recipients) use ($emergency) {
                $users = $recipients->pluck('user')->filter();

                // Do Not Disturb is deliberately ignored here. That override is
                // the whole reason emergency messaging exists.
                Notification::send($users, new EmergencyRaised($emergency, $this->isEscalation));

                EmergencyRecipient::query()
                    ->whereIn('id', $recipients->pluck('id'))
                    ->update([
                        'notified_at' => now(),
                        'last_alerted_at' => now(),
                        'alert_count' => DB::raw('alert_count + 1'),
                        'updated_at' => now(),
                    ]);
            });
    }
}
