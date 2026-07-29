<?php

namespace App\Livewire;

use App\Enums\Availability;
use App\Enums\MessageNotificationLevel;
use App\Models\DndWindow;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Self-service profile. Deliberately narrow: an employee may change their photo,
 * phone, status message, availability, password and Do Not Disturb schedule and
 * nothing else. Name, email, company, administration, job title and role are
 * owned by an administrator (UserPolicy::update) and are never read from input
 * here — not even hidden fields exist for them.
 */
#[Layout('layouts.app')]
class ProfileSettings extends Component
{
    use WithFileUploads;

    // -------------------------------------------------------------- photo
    public $photo = null;

    public string $photoStatus = '';

    // ---------------------------------------------------- contact & status
    public ?string $phone = null;

    public ?string $status_message = null;

    public string $availability = '';

    public string $contactStatus = '';

    // ------------------------------------------------------- notifications
    public string $message_notifications = MessageNotificationLevel::All->value;

    public string $notificationStatus = '';

    // ----------------------------------------------------------- password
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $passwordStatus = '';

    // ---------------------------------------------------------------- dnd
    public ?int $dnd_day = 1;

    public string $dnd_starts_at = '';

    public string $dnd_ends_at = '';

    public string $dndStatus = '';

    public function mount(): void
    {
        $user = $this->user();

        $this->phone = $user->phone;
        $this->status_message = $user->status_message;
        $this->availability = ($user->availability ?? Availability::Available)->value;
        $this->message_notifications = $user->message_notifications->value;
    }

    /**
     * The setting saves itself — making someone hunt for a Save button after
     * picking one radio is how preferences end up not stuck.
     *
     * Validated rather than trusted: a string property accepts whatever the
     * client sends, so this cannot go straight into the model the way the old
     * bool-typed toggle safely could.
     */
    public function updatedMessageNotifications(string $value): void
    {
        $level = MessageNotificationLevel::tryFrom($value);

        if ($level === null) {
            // Put the property back to what is actually stored.
            $this->message_notifications = $this->user()->message_notifications->value;
            $this->notificationStatus = '';

            return;
        }

        $this->user()->forceFill(['message_notifications' => $level->value])->save();

        $this->notificationStatus = match ($level) {
            MessageNotificationLevel::All => 'You will be notified about every message.',
            MessageNotificationLevel::Mentions => 'You will only be notified when someone mentions you.',
            MessageNotificationLevel::None => 'Message notifications are off. Emergencies will still reach you.',
        };
    }

    private function user(): User
    {
        return Auth::user();
    }

    // ------------------------------------------------------------------ photo

    /** Validate as soon as a file lands so the preview is always safe to render. */
    public function updatedPhoto(): void
    {
        $this->photoStatus = '';

        $this->validateOnly('photo', $this->photoRules(), attributes: ['photo' => 'photo']);
    }

    /** @return array<string, array<int, string>> */
    private function photoRules(): array
    {
        return ['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']];
    }

    public function savePhoto(): void
    {
        $this->validate($this->photoRules(), attributes: ['photo' => 'photo']);

        $user = $this->user();
        $disk = config('hub.avatar_disk');
        $previous = $user->avatar_path;

        // store() writes a random, unguessable filename — the original client
        // filename never reaches the disk.
        $path = $this->photo->store('/', $disk);

        $user->forceFill(['avatar_path' => $path])->save();

        if ($previous && $previous !== $path) {
            Storage::disk($disk)->delete($previous);
        }

        $this->reset('photo');
        $this->photoStatus = 'Your profile photo has been updated.';
    }

    public function removePhoto(): void
    {
        $user = $this->user();
        $disk = config('hub.avatar_disk');

        if ($user->avatar_path) {
            Storage::disk($disk)->delete($user->avatar_path);
        }

        $user->forceFill(['avatar_path' => null])->save();

        $this->reset('photo');
        $this->photoStatus = 'Your profile photo has been removed. Your initials will be shown instead.';
    }

    public function cancelPhoto(): void
    {
        $this->reset('photo');
        $this->resetValidation('photo');
        $this->photoStatus = '';
    }

    // -------------------------------------------------------- contact & status

    public function saveContact(): void
    {
        $this->validate([
            'phone' => ['nullable', 'string', 'max:40'],
            'status_message' => ['nullable', 'string', 'max:120'],
            'availability' => ['required', Rule::enum(Availability::class)],
        ], attributes: [
            'status_message' => 'status message',
        ]);

        // Explicit, whitelisted assignment. Nothing else on the model is touched.
        $this->user()->forceFill([
            'phone' => filled($this->phone) ? trim($this->phone) : null,
            'status_message' => filled($this->status_message) ? trim($this->status_message) : null,
            'availability' => Availability::from($this->availability),
        ])->save();

        $this->contactStatus = 'Your contact details and availability have been saved.';
    }

    // --------------------------------------------------------------- password

    public function updatePassword(): void
    {
        $this->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()->min(12)],
        ], attributes: [
            'current_password' => 'current password',
            'password' => 'new password',
        ]);

        $user = $this->user();

        $user->forceFill([
            'password' => $this->password,
            'must_change_password' => false,
        ])->save();

        // Every other browser signed in as this account is cut loose. This must
        // run after the save — the guard verifies the plaintext against the
        // stored hash before rotating it.
        Auth::logoutOtherDevices($this->password);
        session()->regenerate();

        $this->reset(['current_password', 'password', 'password_confirmation']);
        $this->passwordStatus = 'Your password has been changed. Any other signed-in device has been signed out.';
    }

    // -------------------------------------------------------------------- dnd

    public function addDndWindow(): void
    {
        $this->validate([
            'dnd_day' => ['required', 'integer', 'between:0,6'],
            'dnd_starts_at' => ['required', 'date_format:H:i'],
            'dnd_ends_at' => ['required', 'date_format:H:i', 'different:dnd_starts_at'],
        ], messages: [
            'dnd_ends_at.different' => 'The end time must differ from the start time. For a window that runs past midnight, set an end time earlier than the start time.',
        ], attributes: [
            'dnd_day' => 'day',
            'dnd_starts_at' => 'start time',
            'dnd_ends_at' => 'end time',
        ]);

        DndWindow::create([
            'user_id' => $this->user()->getKey(),
            'day_of_week' => (int) $this->dnd_day,
            // Stored with seconds so string comparison against H:i:s in
            // User::isWithinDndWindow behaves on every driver.
            'starts_at' => $this->dnd_starts_at.':00',
            'ends_at' => $this->dnd_ends_at.':00',
        ]);

        $this->reset(['dnd_starts_at', 'dnd_ends_at']);
        $this->dndStatus = 'Quiet period added.';
    }

    public function deleteDndWindow(int $windowId): void
    {
        $deleted = DndWindow::query()
            ->whereKey($windowId)
            ->where('user_id', $this->user()->getKey())
            ->delete();

        $this->dndStatus = $deleted ? 'Quiet period removed.' : 'That quiet period no longer exists.';
    }

    // ----------------------------------------------------------------- render

    public function render()
    {
        $user = $this->user()->loadMissing(['company', 'administration']);

        return view('livewire.profile-settings', [
            'user' => $user,
            'availabilities' => Availability::cases(),
            'dndWindows' => DndWindow::query()
                ->where('user_id', $user->getKey())
                ->orderBy('day_of_week')
                ->orderBy('starts_at')
                ->get(),
            'roleNames' => $user->getRoleNames(),
        ]);
    }
}
