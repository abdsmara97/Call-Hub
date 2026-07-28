<?php

namespace App\Jobs;

use App\Events\EmergencyEscalated;
use App\Models\Emergency;
use App\Notifications\EmergencyUnacknowledged;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Re-alerts anyone who has not acknowledged, tells the sender it has not landed,
 * and re-queues itself until everyone responds, the emergency is resolved, or
 * the escalation budget runs out.
 *
 * A per-minute scheduled sweep (see routes/console.php) is the backstop in case
 * a delayed job is ever lost — losing an escalation must not be silent.
 */
class EscalateEmergency implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $emergencyId)
    {
        $this->onQueue('emergency');
    }

    public function handle(): void
    {
        $emergency = Emergency::with('sender')->find($this->emergencyId);

        if (! $emergency || $emergency->isResolved()) {
            return;
        }

        $pending = $emergency->recipients()->pending()->pluck('user_id');

        if ($pending->isEmpty()) {
            return;
        }

        if (! $emergency->hasEscalationsRemaining()) {
            // Budget spent. Tell the sender who never responded and stop; an
            // endless loop of alerts would train people to ignore them.
            $emergency->sender->notify(
                new EmergencyUnacknowledged($emergency, $pending->count(), exhausted: true)
            );

            return;
        }

        $emergency->forceFill([
            'escalation_count' => $emergency->escalation_count + 1,
            'last_escalated_at' => now(),
        ])->save();

        broadcast(new EmergencyEscalated($emergency, $pending->all()));

        DispatchEmergencyNotifications::dispatch($emergency->getKey(), isEscalation: true);

        $emergency->sender->notify(
            new EmergencyUnacknowledged($emergency, $pending->count(), exhausted: false)
        );

        self::dispatch($emergency->getKey())
            ->delay(now()->addMinutes($emergency->escalation_interval_minutes));
    }
}
