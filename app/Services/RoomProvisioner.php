<?php

namespace App\Services;

use App\Enums\RoomMemberRole;
use App\Enums\RoomType;
use App\Models\Administration;
use App\Models\Company;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns the rooms that exist because of the org chart rather than because someone
 * created them: one per company, one per administration, plus DM rooms.
 */
class RoomProvisioner
{
    public function ensureCompanyRoom(Company $company): Room
    {
        return Room::firstOrCreate(
            [
                'company_id' => $company->getKey(),
                'administration_id' => null,
                'is_system' => true,
            ],
            [
                'name' => $company->name,
                'slug' => 'co-'.$company->slug,
                'topic' => 'Everyone at '.$company->name,
                'type' => RoomType::Private->value,
            ],
        );
    }

    public function ensureAdministrationRoom(Administration $administration): Room
    {
        $administration->loadMissing('company');

        return Room::firstOrCreate(
            [
                'administration_id' => $administration->getKey(),
                'is_system' => true,
            ],
            [
                'company_id' => $administration->company_id,
                'name' => $administration->name,
                'slug' => 'ad-'.$administration->company->slug.'-'.$administration->slug,
                'topic' => $administration->name.' — '.$administration->company->name,
                'type' => RoomType::Private->value,
            ],
        );
    }

    /**
     * Puts a user in the system rooms their org placement implies and pulls them
     * out of any they no longer belong to (e.g. after a department transfer).
     */
    public function syncSystemRoomsFor(User $user): void
    {
        $user->loadMissing(['company', 'administration']);

        $shouldBeIn = collect();

        if ($user->company) {
            $shouldBeIn->push($this->ensureCompanyRoom($user->company)->getKey());
        }

        if ($user->administration) {
            $shouldBeIn->push($this->ensureAdministrationRoom($user->administration)->getKey());
        }

        $currentSystemRoomIds = RoomMember::query()
            ->where('user_id', $user->getKey())
            ->whereIn('room_id', Room::query()->where('is_system', true)->select('id'))
            ->pluck('room_id');

        foreach ($shouldBeIn->diff($currentSystemRoomIds) as $roomId) {
            $this->addMember(Room::find($roomId), $user);
        }

        RoomMember::query()
            ->where('user_id', $user->getKey())
            ->whereIn('room_id', $currentSystemRoomIds->diff($shouldBeIn))
            ->delete();
    }

    public function addMember(Room $room, User $user, RoomMemberRole $role = RoomMemberRole::Member): RoomMember
    {
        return RoomMember::firstOrCreate(
            ['room_id' => $room->getKey(), 'user_id' => $user->getKey()],
            ['role' => $role->value, 'joined_at' => now()],
        );
    }

    public function removeMember(Room $room, User $user): void
    {
        RoomMember::query()
            ->where('room_id', $room->getKey())
            ->where('user_id', $user->getKey())
            ->delete();
    }

    public function createRoom(User $creator, string $name, RoomType $type, ?string $topic = null): Room
    {
        return DB::transaction(function () use ($creator, $name, $type, $topic) {
            $room = Room::create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'topic' => $topic,
                'type' => $type->value,
                'created_by' => $creator->getKey(),
            ]);

            // Whoever opens a room moderates it.
            $this->addMember($room, $creator, RoomMemberRole::Moderator);

            return $room;
        });
    }

    /**
     * DMs are rooms with exactly two members, created lazily on first contact.
     * Ordering the pair keeps the lookup stable regardless of who initiates.
     */
    public function findOrCreateDm(User $a, User $b): Room
    {
        if ($a->is($b)) {
            throw new \InvalidArgumentException('A direct message needs two different people.');
        }

        $existing = Room::query()
            ->conversations()
            ->whereHas('memberships', fn ($q) => $q->where('user_id', $a->getKey()))
            ->whereHas('memberships', fn ($q) => $q->where('user_id', $b->getKey()))
            ->withCount('memberships')
            ->having('memberships_count', '=', 2)
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($a, $b) {
            $ids = collect([$a->getKey(), $b->getKey()])->sort()->values();

            $room = Room::create([
                'name' => null,
                'slug' => 'dm-'.$ids->implode('-'),
                'type' => RoomType::Dm->value,
            ]);

            $this->addMember($room, $a);
            $this->addMember($room, $b);

            return $room;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'room';
        $slug = $base;
        $suffix = 2;

        while (Room::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
