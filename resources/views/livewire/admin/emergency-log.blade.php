<div class="mx-auto max-w-6xl px-4 py-6">
    @includeIf('partials.admin-nav')

    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <x-panel-heading
            class="!mb-0"
            title="Emergency log"
            description="Every emergency ever sent, who received it, and when each person acknowledged. Permanent and read-only." />

        <a href="{{ route('admin.emergency-log.export', ['from' => $from ?: null, 'to' => $to ?: null]) }}"
           class="btn-secondary">
            <x-icon name="download" class="h-4 w-4" />
            Export CSV
        </a>
    </div>

    {{-- ----------------------------------------------------------- filters --}}
    <div class="panel mb-4 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <label for="log-search" class="label">Search</label>
            <input id="log-search" type="search" wire:model.live.debounce.300ms="search"
                   class="field" placeholder="Message text or sender">
        </div>
        <div>
            <label for="log-from" class="label">From</label>
            <input id="log-from" type="date" wire:model.live="from" class="field">
        </div>
        <div>
            <label for="log-to" class="label">To</label>
            <input id="log-to" type="date" wire:model.live="to" class="field">
        </div>
        <div>
            <label for="log-status" class="label">Status</label>
            <select id="log-status" wire:model.live="status" class="field">
                <option value="all">All</option>
                <option value="open">Open</option>
                <option value="unacknowledged">Awaiting acknowledgement</option>
                <option value="resolved">Resolved</option>
            </select>
        </div>
    </div>

    {{-- ------------------------------------------------------------- table --}}
    <div class="panel overflow-hidden">
        <table class="w-full text-sm">
            <caption class="sr-only">Emergency messages, most recent first</caption>
            <thead class="border-b border-line bg-surface-sunken text-left">
                <tr class="text-2xs uppercase tracking-wide text-content-muted">
                    <th scope="col" class="px-4 py-2 font-semibold">Sent</th>
                    <th scope="col" class="px-4 py-2 font-semibold">Sender</th>
                    <th scope="col" class="px-4 py-2 font-semibold">Scope</th>
                    <th scope="col" class="px-4 py-2 font-semibold">Message</th>
                    <th scope="col" class="px-4 py-2 font-semibold">Acknowledged</th>
                    <th scope="col" class="px-4 py-2 font-semibold">Status</th>
                    <th scope="col" class="px-4 py-2"><span class="sr-only">Details</span></th>
                </tr>
            </thead>

            <tbody class="divide-y divide-line">
                @forelse ($emergencies as $emergency)
                    @php
                        $total = $emergency->recipients_count;
                        $acked = $emergency->acknowledged_count;
                        $complete = $total > 0 && $acked === $total;
                    @endphp

                    <tr class="align-top">
                        <td class="whitespace-nowrap px-4 py-3 text-xs text-content-muted">
                            <time datetime="{{ $emergency->created_at->toIso8601String() }}">
                                {{ $emergency->created_at->format('d M Y H:i') }}
                            </time>
                        </td>
                        <td class="px-4 py-3">{{ $emergency->sender?->name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="badge-neutral">{{ $emergency->scope->label() }}</span>
                            <span class="mt-1 block text-2xs text-content-subtle">{{ $emergency->targetLabel() }}</span>
                        </td>
                        <td class="max-w-sm px-4 py-3">
                            <p class="line-clamp-2 break-words">{{ $emergency->body }}</p>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span class="{{ $complete ? 'text-brand-text' : 'text-emergency-text' }} font-semibold tabular-nums">
                                {{ $acked }} / {{ $total }}
                            </span>
                            @if ($emergency->escalation_count > 0)
                                <span class="mt-1 block text-2xs text-content-subtle">
                                    Re-alerted &times;{{ $emergency->escalation_count }}
                                </span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            @if ($emergency->resolved_at)
                                <span class="badge bg-brand-tint-strong text-brand-text">
                                    <x-icon name="check" class="h-3 w-3" /> Resolved
                                </span>
                            @else
                                <span class="badge-emergency">
                                    <x-icon name="clock" class="h-3 w-3" /> Open
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button type="button" wire:click="toggle({{ $emergency->id }})"
                                    class="btn-ghost !px-2 !py-1 !text-xs"
                                    aria-expanded="{{ $expanded === $emergency->id ? 'true' : 'false' }}">
                                {{ $expanded === $emergency->id ? 'Hide' : 'Details' }}
                            </button>
                        </td>
                    </tr>

                    @if ($expanded === $emergency->id && $detail)
                        <tr>
                            <td colspan="7" class="bg-surface-sunken px-4 py-4">
                                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-content-muted">
                                    Recipients and acknowledgement times
                                </h3>

                                <div class="overflow-x-auto">
                                    <table class="w-full text-xs">
                                        <caption class="sr-only">Per-recipient acknowledgement detail</caption>
                                        <thead class="text-left text-content-muted">
                                            <tr>
                                                <th scope="col" class="py-1 pr-4 font-semibold">Recipient</th>
                                                <th scope="col" class="py-1 pr-4 font-semibold">Administration</th>
                                                <th scope="col" class="py-1 pr-4 font-semibold">Notified</th>
                                                <th scope="col" class="py-1 pr-4 font-semibold">Acknowledged</th>
                                                <th scope="col" class="py-1 pr-4 font-semibold">Took</th>
                                                <th scope="col" class="py-1 font-semibold">Alerts</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-line">
                                            @foreach ($detail->recipients as $recipient)
                                                <tr>
                                                    <td class="py-1.5 pr-4">{{ $recipient->user?->name }}</td>
                                                    <td class="py-1.5 pr-4 text-content-muted">
                                                        {{ $recipient->user?->administration?->name }}
                                                    </td>
                                                    <td class="py-1.5 pr-4 text-content-muted">
                                                        {{ $recipient->notified_at?->format('d M H:i:s') ?? '—' }}
                                                    </td>
                                                    <td class="py-1.5 pr-4">
                                                        @if ($recipient->acknowledged_at)
                                                            <span class="text-brand-text">
                                                                {{ $recipient->acknowledged_at->format('d M H:i:s') }}
                                                            </span>
                                                        @else
                                                            <span class="font-semibold text-emergency-text">Never</span>
                                                        @endif
                                                    </td>
                                                    <td class="py-1.5 pr-4 tabular-nums text-content-muted">
                                                        @php $seconds = $recipient->responseSeconds(); @endphp
                                                        {{ $seconds === null ? '—' : ($seconds < 60 ? $seconds.'s' : round($seconds / 60).'m') }}
                                                    </td>
                                                    <td class="py-1.5 tabular-nums text-content-muted">{{ $recipient->alert_count }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-sm text-content-muted">
                            No emergencies match these filters.
                            @if ($from || $to || $search || $status !== 'all')
                                <button type="button" wire:click="clearFilters" class="font-medium text-brand-text hover:underline">
                                    Clear filters
                                </button>
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $emergencies->links() }}</div>
</div>
