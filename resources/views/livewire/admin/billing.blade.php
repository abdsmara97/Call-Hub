<div>
    <div class="mx-auto max-w-6xl px-4 py-6">
        @include('partials.admin-nav')

        <x-panel-heading title="Billing"
                         description="Seats are the core subscription; the emergency alert system is the paid upgrade. Changes are made through Stripe and reflected here." />

        @unless ($enforced)
            <div class="mb-4 flex items-start gap-2 rounded-lg border border-line bg-info-tint px-3 py-2 text-sm text-info">
                <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0" />
                <span>
                    Billing is not enforced on this install. Every feature, including the
                    emergency system, is available without a subscription.
                </span>
            </div>
        @endunless

        <div class="grid gap-4 lg:grid-cols-2">
            {{-- ----------------------------------------------------------- seats --}}
            <div class="panel p-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-content">Seats</h2>
                    @if ($seatsSubscribed)
                        <span class="badge-brand">Active</span>
                    @elseif ($enforced)
                        <span class="badge">Not subscribed</span>
                    @endif
                </div>

                <p class="mt-2 text-3xl font-semibold tabular-nums">{{ number_format($seatsInUse) }}</p>
                <p class="text-xs text-content-muted">accounts in this workspace</p>

                @if ($enforced)
                    <div class="mt-4 flex gap-2">
                        @if ($seatsSubscribed)
                            <a href="{{ route('admin.billing.portal') }}" class="btn-secondary text-xs">
                                Manage in Stripe
                            </a>
                        @else
                            <a href="{{ route('admin.billing.checkout', 'seats') }}" class="btn-primary text-xs">
                                Subscribe
                            </a>
                        @endif
                    </div>
                @endif
            </div>

            {{-- ------------------------------------------------------- emergency --}}
            <div class="panel p-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-content">Emergency alert system</h2>
                    @if ($emergencyActive)
                        <span class="badge-brand">Active</span>
                    @else
                        <span class="badge">Locked</span>
                    @endif
                </div>

                <p class="mt-2 text-sm text-content-muted">
                    Escalating alerts with acknowledgment tracking, the permanent audit log,
                    and administrator broadcasts. Raising alerts requires this add-on;
                    the existing log always stays readable.
                </p>

                @if ($enforced)
                    <div class="mt-4 flex gap-2">
                        @if ($emergencySubscribed)
                            <a href="{{ route('admin.billing.portal') }}" class="btn-secondary text-xs">
                                Manage in Stripe
                            </a>
                        @else
                            <a href="{{ route('admin.billing.checkout', 'emergency') }}" class="btn-primary text-xs">
                                Add to plan
                            </a>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
