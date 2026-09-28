<?php

namespace App\Services;

use App\Enums\EmergencyScope;
use App\Events\EmergencyAcknowledged;
use App\Events\EmergencyResolved;
use App\Events\EmergencySent;
use App\Exceptions\EmergencyRateLimited;
use App\Jobs\DispatchEmergencyNotifications;
use App\Jobs\EscalateEmergency;
use App\Models\Administration;
use App\Models\Company;
use App\Models\Emergency;
use App\Models\EmergencyRecipient;
use App\Models\Room;
use App\Models\User;
use App\Support\HubSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Everything an emergency does, in one place: who it reaches, how it is
 * recorded, when it is chased, and when it stops.
 */
class EmergencyService
{
    public function __construct(
        private readonly HubSettings $settings,
        private readonly MessageService $messages,
        private readonly RoomProvisioner $rooms,
    ) {}

    /**
     * Raise an emergency inside a room or DM. Every member except the sender
     * becomes a recipient who must acknowledge.
     */
    public function raiseInRoom(User $sender, Room $room, string $body): Emergency
    {
        $this->assertWithinRateLimit($sender);

        $recipientIds = $room->memberships()
            ->where('user_id', '!=', $sender->getKey())
            ->pluck('user_id');

        $emergency = DB::transaction(function () use ($sender, $room, $body, $recipientIds) {
            $emergency = $this->record($sender, $body, [
                'scope' => ($room->isDm() ? EmergencyScope::Dm : EmergencyScope::Room)->value,
                'room_id' => $room->getKey(),
            ]);

            $this->attachRecipients($emergency, $recipientIds);

            // The emergency also appears as a message so it sits in the
            // conversation's history rather than only in an overlay.
            $this->messages->send($sender, $room, $body, emergency: $emergency);

            return $emergency;
        });

        $this->dispatchDelivery($emergency, $recipientIds->all());

        return $emergency;
    }

    /**
     * Administrator broadcast to a company, an administration, or everyone.
     * Posts into each targeted system room so it lands in history too.
     */
    public function broadcastTo(
        User $sender,
        EmergencyScope $scope,
        string $body,
        ?Company $company = null,
        ?Administration $administration = null,
    ): Emergency {
        if (! $scope->isBroadcast()) {
            throw new \InvalidArgumentException('Use raiseInRoom() for room-scoped emergencies.');
        }

        $recipientIds = $this->broadcastAudience($scope, $sender, $company, $administration);

        $emergency = DB::transaction(function () use ($sender, $body, $scope, $company, $administration, $recipientIds) {
            $emergency = $this->record($sender, $body, [
                'scope' => $scope->value,
                'company_id' => $company?->getKey(),
                'administration_id' => $administration?->getKey(),
            ]);

            $this->attachRecipients($emergency, $recipientIds);
            $this->postIntoSystemRooms($emergency, $sender, $body, $scope, $company, $administration);

            return $emergency;
        });

        $this->dispatchDelivery($emergency, $recipientIds->all());

        return $emergency;
    }

    /** Records one person having seen it. Idempotent. */
    public function acknowledge(Emergency $emergency, User $user): void
    {
        $recipient = EmergencyRecipient::query()
            ->where('emergency_id', $emergency->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        if (! $recipient || $recipient->hasAcknowledged()) {
            return;
        }

        $recipient->forceFill(['acknowledged_at' => now()])->save();

        broadcast(new EmergencyAcknowledged($emergency, $user));

        // Nobody left to chase — close it out and stop the escalation loop.
        if ($emergency->fresh()->isFullyAcknowledged()) {
            $this->resolve($emergency, $user, automatic: true);
        }
    }

    public function resolve(Emergency $emergency, User $actor, bool $automatic = false): void
    {
        if ($emergency->isResolved()) {
            return;
        }

        $emergency->forceFill([
            'resolved_at' => now(),
            'resolved_by' => $automatic ? null : $actor->getKey(),
        ])->save();

        broadcast(new EmergencyResolved(
            $emergency,
            $emergency->recipients()->pluck('user_id')->all(),
        ));
    }

    /** @return Collection<int, EmergencyRecipient> */
    public function pendingRecipients(Emergency $emergency): Collection
    {
        return $emergency->recipients()->pending()->with('user')->get();
    }

    // ------------------------------------------------------------- internals

    /** @param  array<string, mixed>  $attributes */
    private function record(User $sender, string $body, array $attributes): Emergency
    {
        return Emergency::create(array_merge([
            // Inherited from the sender, not the request context, so the
            // audit row lands in the right tenant even from a job or test.
            'tenant_id' => $sender->tenant_id,
            'sender_id' => $sender->getKey(),
            'body' => trim($body),
            // Snapshotted so a later settings change cannot rewrite history.
            'escalation_interval_minutes' => $this->settings->escalationIntervalMinutes(),
            'max_escalations' => $this->settings->maxEscalations(),
        ], $attributes));
    }

    /** @param  Collection<int, int>  $userIds */
    private function attachRecipients(Emergency $emergency, Collection $userIds): void
    {
        $now = now();

        $rows = $userIds->unique()->values()->map(fn (int $id) => [
            'emergency_id' => $emergency->getKey(),
            'user_id' => $id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Chunked: a company-wide broadcast can be several hundred rows.
        foreach ($rows->chunk(500) as $chunk) {
            EmergencyRecipient::insert($chunk->all());
        }
    }

    /** @return Collection<int, int> */
    private function broadcastAudience(
        EmergencyScope $scope,
        User $sender,
        ?Company $company,
        ?Administration $administration,
    ): Collection {
        return User::query()
            ->active()
            ->when($scope === EmergencyScope::Company, fn ($q) => $q->where('company_id', $company?->getKey()))
            ->when($scope === EmergencyScope::Administration, fn ($q) => $q->where('administration_id', $administration?->getKey()))
            ->where('id', '!=', $sender->getKey())
            ->pluck('id');
    }

    private function postIntoSystemRooms(
        Emergency $emergency,
        User $sender,
        string $body,
        EmergencyScope $scope,
        ?Company $company,
        ?Administration $administration,
    ): void {
        $rooms = match ($scope) {
            EmergencyScope::Administration => $administration
                ? collect([$this->rooms->ensureAdministrationRoom($administration)])
                : collect(),
            EmergencyScope::Company => $company
                ? collect([$this->rooms->ensureCompanyRoom($company)])
                : collect(),
            EmergencyScope::All => Company::all()->map(fn (Company $c) => $this->rooms->ensureCompanyRoom($c)),
            default => collect(),
        };

        foreach ($rooms as $room) {
            // The sender must be in the room to post into it; broadcasts can
            // target rooms the admin does not belong to.
            $this->rooms->addMember($room, $sender);
            $this->messages->send($sender, $room, $body, emergency: $emergency);
        }
    }

    /** @param  list<int>  $recipientIds */
    private function dispatchDelivery(Emergency $emergency, array $recipientIds): void
    {
        broadcast(new EmergencySent($emergency, $recipientIds));

        // Push notifications and the escalation timer are queued, never inline.
        DispatchEmergencyNotifications::dispatch($emergency->getKey());

        if ($emergency->max_escalations > 0) {
            EscalateEmergency::dispatch($emergency->getKey())
                ->delay(now()->addMinutes($emergency->escalation_interval_minutes));
        }
    }

    // ----------------------------------------------------------- rate limits

    /**
     * Two limits: a short burst window and a daily ceiling. Administrators are
     * exempt because broadcast is already permission-gated.
     *
     * @throws EmergencyRateLimited
     */
    public function assertWithinRateLimit(User $sender): void
    {
        if ($sender->isAdmin()) {
            return;
        }

        $burstKey = 'emergency:burst:'.$sender->getKey();
        $dailyKey = 'emergency:daily:'.$sender->getKey();

        if (RateLimiter::tooManyAttempts($burstKey, $this->settings->rateLimitPerWindow())) {
            throw EmergencyRateLimited::forSeconds(RateLimiter::availableIn($burstKey));
        }

        if (RateLimiter::tooManyAttempts($dailyKey, $this->settings->rateLimitPerDay())) {
            throw EmergencyRateLimited::dailyCeiling($this->settings->rateLimitPerDay());
        }

        RateLimiter::hit($burstKey, $this->settings->rateLimitWindowMinutes() * 60);
        RateLimiter::hit($dailyKey, 86400);
    }
}
