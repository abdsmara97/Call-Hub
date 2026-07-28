<?php

namespace App\Console\Commands;

use App\Jobs\EscalateEmergency;
use App\Models\Emergency;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Backstop for the delayed escalation jobs. If a worker dies mid-flight or a
 * delayed job is lost, this picks the emergency back up on the next minute.
 * Losing an escalation quietly is the one failure mode this feature cannot have.
 */
class SweepEmergencyEscalations extends Command
{
    protected $signature = 'emergency:sweep';

    protected $description = 'Re-queue escalations for emergencies that are overdue for a re-alert';

    public function handle(): int
    {
        $overdue = Emergency::query()
            ->awaitingAcknowledgement()
            ->whereColumn('escalation_count', '<', 'max_escalations')
            ->where(function (Builder $query) {
                $query
                    ->where(function (Builder $q) {
                        // Escalated before, and the interval has elapsed again.
                        $q->whereNotNull('last_escalated_at')
                            ->whereRaw($this->intervalExpression('last_escalated_at'));
                    })
                    ->orWhere(function (Builder $q) {
                        // Never escalated, and the first interval has elapsed.
                        $q->whereNull('last_escalated_at')
                            ->whereRaw($this->intervalExpression('created_at'));
                    });
            })
            ->get();

        foreach ($overdue as $emergency) {
            EscalateEmergency::dispatch($emergency->getKey());
        }

        $this->info("Re-queued {$overdue->count()} overdue escalation(s).");

        return self::SUCCESS;
    }

    /** MySQL and SQLite spell date arithmetic differently. */
    private function intervalExpression(string $column): string
    {
        return match (DB::getDriverName()) {
            'sqlite' => "datetime({$column}, '+' || escalation_interval_minutes || ' minutes') <= datetime('now')",
            default => "DATE_ADD({$column}, INTERVAL escalation_interval_minutes MINUTE) <= UTC_TIMESTAMP()",
        };
    }
}
