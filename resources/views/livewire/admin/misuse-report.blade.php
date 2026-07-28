<div>
    <div class="mx-auto max-w-6xl px-4 py-6">
        @include('partials.admin-nav')

        <x-panel-heading title="Emergency misuse report" />

        <p class="mb-5 max-w-3xl text-sm text-content-muted">
            An emergency bypasses Do Not Disturb, keeps alerting until it is acknowledged, and interrupts whatever
            someone was doing. That only stays acceptable while it is rare. Anyone who raised more than
            <strong class="font-semibold text-content">{{ $threshold }}</strong>
            {{ \Illuminate\Support\Str::plural('emergency', $threshold) }} since
            {{ $since->format('j M Y, H:i') }} is listed here so you can have a conversation — not so the system can
            punish them. A low acknowledgement rate on their alerts is the clearest sign that colleagues have started
            tuning them out.
        </p>

        <div class="panel overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-left text-sm">
                    <caption class="sr-only">
                        Senders who exceeded the weekly emergency threshold of {{ $threshold }} in the last 7 days.
                    </caption>

                    <thead class="bg-surface-sunken text-2xs uppercase tracking-wide text-content-muted">
                        <tr>
                            <th scope="col" class="px-4 py-2 font-semibold">Sender</th>
                            <th scope="col" class="px-4 py-2 font-semibold">Organisation</th>
                            <th scope="col" class="px-4 py-2 text-right font-semibold">This week</th>
                            <th scope="col" class="px-4 py-2 text-right font-semibold">All time</th>
                            <th scope="col" class="px-4 py-2 font-semibold">Last sent</th>
                            <th scope="col" class="px-4 py-2 font-semibold">Acknowledged</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($rows as $row)
                            <tr wire:key="misuse-{{ $row['user']->id }}" class="border-t border-line align-top">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <x-avatar :user="$row['user']" size="sm" />
                                        <div class="min-w-0">
                                            {{-- Straight into the register, pre-filtered to this person. --}}
                                            <a href="{{ route('admin.users', ['search' => $row['user']->name]) }}"
                                               class="truncate font-medium text-brand-text underline underline-offset-2">
                                                {{ $row['user']->name }}
                                            </a>
                                            <p class="truncate text-xs text-content-muted">{{ $row['user']->email }}</p>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-4 py-3">
                                    <p class="truncate text-content">{{ $row['user']->company?->name ?? '—' }}</p>
                                    <p class="truncate text-xs text-content-muted">{{ $row['user']->administration?->name ?? '—' }}</p>
                                </td>

                                <td class="px-4 py-3 text-right">
                                    <span class="badge-emergency">
                                        <x-icon name="alert" class="h-3 w-3" />
                                        {{ $row['week_count'] }}
                                    </span>
                                </td>

                                <td class="px-4 py-3 text-right font-mono text-content-muted">
                                    {{ number_format($row['all_time_count']) }}
                                </td>

                                <td class="px-4 py-3 text-content-muted">
                                    @if ($row['last_sent_at'])
                                        <span title="{{ $row['last_sent_at']->format('j M Y, H:i') }}">
                                            {{ $row['last_sent_at']->diffForHumans() }}
                                        </span>
                                    @else
                                        —
                                    @endif
                                </td>

                                <td class="px-4 py-3">
                                    @if ($row['acknowledgement_rate'] === null)
                                        <span class="text-xs text-content-subtle">No recipients recorded</span>
                                    @else
                                        <p class="font-medium text-content">{{ $row['acknowledgement_rate'] }}%</p>
                                        <p class="text-xs text-content-muted">
                                            {{ number_format($row['acknowledged_recipients']) }} of
                                            {{ number_format($row['total_recipients']) }} recipients
                                        </p>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr class="border-t border-line">
                                <td colspan="6" class="px-4 py-12 text-center">
                                    <x-icon name="check-circle" class="mx-auto h-8 w-8 text-content-subtle" />
                                    <p class="mt-2 text-sm font-medium text-content">
                                        No one has exceeded the threshold in the last 7 days.
                                    </p>
                                    <p class="mt-1 text-xs text-content-muted">
                                        The threshold is {{ $threshold }} per rolling week and can be changed in
                                        <a href="{{ route('admin.settings') }}" class="underline">hub settings</a>.
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
