<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Support\HubSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Senders who leaned on the emergency flag more than the agreed weekly budget.
 *
 * This is an attention list, not a punishment list: if everything is an
 * emergency then nothing is, and the alert stops waking anyone up.
 */
#[Layout('layouts.app')]
class MisuseReport extends Component
{
    public function mount(): void
    {
        $this->authorize('manage', User::class);
    }

    public function render()
    {
        $threshold = app(HubSettings::class)->misuseThresholdPerWeek();
        $since = now()->subDays(7);

        // One grouped pass over the emergencies table: counts for the rolling
        // week, counts for all time, and the most recent send, per sender.
        $totals = DB::table('emergencies')
            ->selectRaw('sender_id')
            ->selectRaw('count(*) as all_time_count')
            ->selectRaw('sum(case when created_at >= ? then 1 else 0 end) as week_count', [$since->toDateTimeString()])
            ->selectRaw('max(created_at) as last_sent_at')
            ->groupBy('sender_id')
            ->havingRaw('sum(case when created_at >= ? then 1 else 0 end) > ?', [$since->toDateTimeString(), $threshold])
            ->get();

        $senderIds = $totals->pluck('sender_id')->map(fn ($id) => (int) $id)->all();

        // Second pass for acknowledgement rates, joined rather than looped so the
        // page stays two queries wide no matter how long the list gets.
        $acknowledgement = $senderIds === []
            ? collect()
            : DB::table('emergency_recipients')
                ->join('emergencies', 'emergencies.id', '=', 'emergency_recipients.emergency_id')
                ->whereIn('emergencies.sender_id', $senderIds)
                ->groupBy('emergencies.sender_id')
                ->selectRaw('emergencies.sender_id as sender_id')
                ->selectRaw('count(*) as total_recipients')
                ->selectRaw('sum(case when emergency_recipients.acknowledged_at is not null then 1 else 0 end) as acknowledged_recipients')
                ->get()
                ->keyBy('sender_id');

        $users = $senderIds === []
            ? collect()
            : User::query()
                ->with(['company:id,name', 'administration:id,name'])
                ->whereIn('id', $senderIds)
                ->get()
                ->keyBy('id');

        $rows = $totals
            ->map(function ($row) use ($users, $acknowledgement) {
                $user = $users->get((int) $row->sender_id);

                if (! $user) {
                    return null;
                }

                $ack = $acknowledgement->get((int) $row->sender_id);
                $totalRecipients = (int) ($ack->total_recipients ?? 0);
                $acknowledged = (int) ($ack->acknowledged_recipients ?? 0);

                return [
                    'user' => $user,
                    'week_count' => (int) $row->week_count,
                    'all_time_count' => (int) $row->all_time_count,
                    'last_sent_at' => $row->last_sent_at ? Carbon::parse($row->last_sent_at) : null,
                    'total_recipients' => $totalRecipients,
                    'acknowledged_recipients' => $acknowledged,
                    'acknowledgement_rate' => $totalRecipients > 0
                        ? (int) round($acknowledged / $totalRecipients * 100)
                        : null,
                ];
            })
            ->filter()
            ->sortByDesc('week_count')
            ->values();

        return view('livewire.admin.misuse-report', [
            'rows' => $rows,
            'threshold' => $threshold,
            'since' => $since,
        ]);
    }
}
