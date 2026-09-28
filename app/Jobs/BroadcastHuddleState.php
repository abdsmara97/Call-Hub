<?php

namespace App\Jobs;

use App\Events\HuddleUpdated;
use App\Services\HuddleRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The trailing edge of a burst of huddle events.
 *
 * HuddleRegistry::announce() broadcasts the first change in any one-second
 * window immediately and queues this for everything after it. Fifty people
 * joining a company-wide huddle therefore costs one inline broadcast and one
 * job, rather than fifty broadcasts to three hundred subscribers each.
 *
 * Unique per room, so a burst collapses to a single trailing send no matter how
 * many events triggered it. It deliberately carries no payload: it re-reads the
 * registry when it runs, so what goes out is the state as it actually is by
 * then, not as it was when the burst started.
 */
class BroadcastHuddleState implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $roomId) {}

    public function uniqueId(): string
    {
        return "huddle-broadcast:{$this->roomId}";
    }

    /**
     * Short, because this job is only ever a couple of seconds behind the event
     * that queued it. A longer window would start suppressing genuinely new
     * changes rather than duplicates.
     */
    public function uniqueFor(): int
    {
        return 10;
    }

    public function handle(HuddleRegistry $registry): void
    {
        HuddleUpdated::dispatch($this->roomId, $registry->snapshot($this->roomId));
    }
}
