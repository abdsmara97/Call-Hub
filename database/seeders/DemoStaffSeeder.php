<?php

namespace Database\Seeders;

use App\Enums\RoomMemberRole;
use App\Enums\RoomType;
use App\Enums\UserStatus;
use App\Models\Administration;
use App\Models\Company;
use App\Models\Message;
use App\Models\Room;
use App\Models\User;
use App\Services\RoomProvisioner;
use App\Services\UserProvisioner;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Sample staff so the app is usable the moment it is installed. Every account
 * here uses the same well-known password and is pre-rotated, so you can sign in
 * as anyone without going through the temporary-password screen.
 */
class DemoStaffSeeder extends Seeder
{
    public const PASSWORD = 'password';

    /** [name, job title, company slug, administration slug] */
    private const STAFF = [
        ['Amina Haddad', 'Head of Engineering', 'oak-tree-technologies', 'engineering'],
        ['Daniel Okoye', 'Senior Backend Engineer', 'oak-tree-technologies', 'engineering'],
        ['Priya Raman', 'Frontend Engineer', 'oak-tree-technologies', 'engineering'],
        ['Tomas Novak', 'Platform Engineer', 'oak-tree-technologies', 'engineering'],
        ['Selin Aydin', 'QA Engineer', 'oak-tree-technologies', 'engineering'],
        ['Marcus Bell', 'Engineering Manager', 'oak-tree-technologies', 'engineering'],

        ['Hana Suzuki', 'Head of Product', 'oak-tree-technologies', 'product-design'],
        ['Owen Fitzgerald', 'Product Designer', 'oak-tree-technologies', 'product-design'],
        ['Lucia Moreno', 'UX Researcher', 'oak-tree-technologies', 'product-design'],
        ['Kwame Mensah', 'Product Manager', 'oak-tree-technologies', 'product-design'],

        ['Ivan Petrov', 'IT Support Lead', 'oak-tree-technologies', 'it-support'],
        ['Grace Lin', 'IT Support Technician', 'oak-tree-technologies', 'it-support'],
        ['Samuel Adeyemi', 'Systems Administrator', 'oak-tree-technologies', 'it-support'],

        ['Rosa Delgado', 'Fleet Operations Director', 'oak-tree-logistics', 'fleet-operations'],
        ['Peter Njoroge', 'Fleet Supervisor', 'oak-tree-logistics', 'fleet-operations'],
        ['Elif Demir', 'Route Planner', 'oak-tree-logistics', 'fleet-operations'],
        ['Jonas Weber', 'Driver Coordinator', 'oak-tree-logistics', 'fleet-operations'],

        ['Mei Chen', 'Warehouse Manager', 'oak-tree-logistics', 'warehouse'],
        ['Andre Silva', 'Inventory Lead', 'oak-tree-logistics', 'warehouse'],
        ['Fatima Zahra', 'Warehouse Operative', 'oak-tree-logistics', 'warehouse'],
        ['Liam Byrne', 'Forklift Operator', 'oak-tree-logistics', 'warehouse'],

        ['Nadia Rahman', 'Dispatch Lead', 'oak-tree-logistics', 'dispatch'],
        ['Carlos Vega', 'Dispatch Controller', 'oak-tree-logistics', 'dispatch'],
        ['Sofia Kowalski', 'Night Dispatcher', 'oak-tree-logistics', 'dispatch'],

        ['Henry Osei', 'Maintenance Manager', 'oak-tree-facilities', 'maintenance'],
        ['Julia Fernandes', 'Electrical Technician', 'oak-tree-facilities', 'maintenance'],
        ['Viktor Ilic', 'HVAC Technician', 'oak-tree-facilities', 'maintenance'],
        ['Aisha Bello', 'Facilities Coordinator', 'oak-tree-facilities', 'maintenance'],

        ['Robert Kane', 'Head of Security', 'oak-tree-facilities', 'security'],
        ['Yusuf Karim', 'Security Officer', 'oak-tree-facilities', 'security'],
    ];

    public function run(UserProvisioner $provisioner, RoomProvisioner $rooms): void
    {
        $companies = Company::query()->get()->keyBy('slug');
        $administrations = Administration::query()->get()
            ->keyBy(fn (Administration $a) => $a->company_id.':'.$a->slug);

        $admin = $this->makeUser(
            $provisioner,
            'Abdelraouf Hussien',
            'pm@oaktreetech.com',
            'Platform Administrator',
            $companies['oak-tree-technologies'],
            $administrations[$companies['oak-tree-technologies']->id.':it-support'],
            Permissions::ROLE_ADMIN,
        );

        // The platform operator: the one account that can create workspaces.
        // forceFill because is_super_admin is deliberately not mass-assignable.
        $admin->forceFill(['is_super_admin' => true])->save();

        $staff = collect([$admin]);

        foreach (self::STAFF as $index => [$name, $title, $companySlug, $administrationSlug]) {
            $company = $companies[$companySlug];
            $administration = $administrations[$company->id.':'.$administrationSlug];

            $user = $this->makeUser(
                $provisioner,
                $name,
                Str::slug($name, '.').'@oaktreetech.com',
                $title,
                $company,
                $administration,
                Permissions::ROLE_EMPLOYEE,
            );

            // One suspended account so the admin console has something to show.
            if ($index === count(self::STAFF) - 1) {
                $user->forceFill(['status' => UserStatus::Suspended->value])->save();
            }

            $staff->push($user);
        }

        $this->seedRooms($rooms, $staff);
    }

    private function makeUser(
        UserProvisioner $provisioner,
        string $name,
        string $email,
        string $title,
        Company $company,
        Administration $administration,
        string $role,
    ): User {
        if ($existing = User::query()->where('email', $email)->first()) {
            return $existing;
        }

        [$user] = $provisioner->create([
            'name' => $name,
            'email' => $email,
            'phone' => '+1 555 0'.str_pad((string) random_int(100, 999), 3, '0').' '.random_int(1000, 9999),
            'company_id' => $company->getKey(),
            'administration_id' => $administration->getKey(),
            'job_title' => $title,
            'role' => $role,
        ], self::PASSWORD);

        // Demo accounts skip the forced rotation so you can sign straight in.
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }

    private function seedRooms(RoomProvisioner $rooms, \Illuminate\Support\Collection $staff): void
    {
        $admin = $staff->first();

        $announcements = Room::query()->where('slug', 'announcements')->first()
            ?? $rooms->createRoom($admin, 'Announcements', RoomType::Public, 'Company-wide notices');

        $shiftHandover = Room::query()->where('slug', 'shift-handover')->first()
            ?? $rooms->createRoom($admin, 'Shift Handover', RoomType::Public, 'End-of-shift notes across sites');

        $incidentRoom = Room::query()->where('slug', 'incident-response')->first()
            ?? $rooms->createRoom($admin, 'Incident Response', RoomType::Private, 'Coordination during live incidents');

        foreach ($staff as $user) {
            $rooms->addMember($announcements, $user);
            $rooms->addMember($shiftHandover, $user);
        }

        // The private room gets a deliberately narrow membership.
        foreach ($staff->take(6) as $index => $user) {
            $rooms->addMember(
                $incidentRoom,
                $user,
                $index === 0 ? RoomMemberRole::Moderator : RoomMemberRole::Member,
            );
        }

        $this->seedMessages($announcements, $staff);
        $this->seedMessages($shiftHandover, $staff);
    }

    private function seedMessages(Room $room, \Illuminate\Support\Collection $staff): void
    {
        if ($room->messages()->exists()) {
            return;
        }

        $lines = [
            'Morning everyone — the new access badges are ready for collection at reception.',
            'Reminder: the quarterly safety briefing is on Thursday at 10:00.',
            'The east loading bay will be closed for resurfacing until Friday.',
            'Welcome to the two new starters joining Dispatch this week.',
            'Network maintenance is scheduled for Saturday night; expect a short outage.',
        ];

        $members = $room->members()->get();

        foreach ($lines as $offset => $line) {
            $author = $members->random();

            $message = Message::create([
                'room_id' => $room->getKey(),
                'user_id' => $author->getKey(),
                'body' => $line,
            ]);

            $message->forceFill([
                'created_at' => now()->subHours(count($lines) - $offset),
                'updated_at' => now()->subHours(count($lines) - $offset),
            ])->save();
        }

        $room->forceFill(['last_message_at' => now()])->save();
    }
}
