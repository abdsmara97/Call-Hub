<?php

namespace App\Livewire\Hub;

use App\Exceptions\EmergencyRateLimited;
use App\Models\Emergency;
use App\Models\Form;
use App\Models\Message;
use App\Models\PinnedMessage;
use App\Models\Poll;
use App\Models\Room;
use App\Models\SavedMessage;
use App\Services\EmergencyService;
use App\Services\FormService;
use App\Services\HuddleRegistry;
use App\Services\MessageService;
use App\Services\PollService;
use App\Services\RoomProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class Conversation extends Component
{
    use WithFileUploads;

    public int $roomId;

    public string $body = '';

    /** @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    public ?int $replyTo = null;

    public ?int $editing = null;

    public string $editBody = '';

    /** Emergency is a two-step action on purpose: arm, then confirm. */
    public bool $emergencyArmed = false;

    public int $limit = 40;

    public string $search = '';

    /** Poll composer. Closed until the user opens it from the composer bar. */
    public bool $pollOpen = false;

    public string $pollQuestion = '';

    /** @var array<int, string> */
    public array $pollOptions = ['', ''];

    public string $pollClosesAt = '';

    /** Form picker. Forms are written in the admin area; here you only choose one. */
    public bool $formPickerOpen = false;

    /** @var array<int, bool> */
    public array $openThreads = [];

    public function mount(int $roomId): void
    {
        $this->roomId = $roomId;

        Gate::authorize('view', $this->room);

        $this->markRead();
    }

    protected function getListeners(): array
    {
        $room = $this->roomId;

        return [
            "echo-private:room.{$room},.message.sent" => 'onIncomingMessage',
            "echo-private:room.{$room},.message.updated" => '$refresh',
            "echo-private:room.{$room},.message.deleted" => '$refresh',
            "echo-private:room.{$room},.read.updated" => '$refresh',
            "echo-private:room.{$room},.emergency.sent" => '$refresh',
            "echo-private:room.{$room},.emergency.acknowledged" => '$refresh',
            "echo-private:room.{$room},.emergency.resolved" => '$refresh',
            "echo-private:room.{$room},.poll.updated" => 'refreshTimeline',
            "echo-private:room.{$room},.form.updated" => 'refreshTimeline',
            "echo-private:room.{$room},.message.reacted" => 'refreshTimeline',
            'emergency-acknowledged' => '$refresh',
        ];
    }

    #[Computed]
    public function room(): Room
    {
        return Room::with(['members', 'company', 'administration'])->findOrFail($this->roomId);
    }

    #[Computed]
    public function membership()
    {
        return auth()->user()->membershipFor($this->room);
    }

    /**
     * The room's root messages, newest page first then reversed for display.
     *
     * Named `timeline` rather than `messages` on purpose: Livewire treats a
     * `messages()` method on a component as the custom validation-message hook,
     * so calling it `messages` made every validation failure in this component
     * blow up trying to array_merge an Eloquent collection.
     *
     * @return Collection<int, Message>
     */
    #[Computed]
    public function timeline(): Collection
    {
        return Message::query()
            ->where('room_id', $this->roomId)
            ->roots()
            ->when($this->search !== '', fn ($q) => $q->where('body', 'like', '%'.$this->escapeLike($this->search).'%'))
            ->with([
                'author',
                'attachments',
                'emergency.recipients.user',
                // Options and votes come along so the tally renders without a
                // query per poll per row. Same reasoning for a form's fields
                // and responses, which its card counts.
                'poll.options',
                'poll.votes',
                'formPosting.form.fields',
                'formPosting.form.responses',
                'reactions.user',
                'mentions',
                'replies.author',
                'replies.attachments',
                'replies.reactions.user',
                'replies.mentions',
            ])
            ->withCount('replies')
            ->latest('id')
            ->limit($this->limit)
            ->get()
            ->reverse()
            ->values();
    }

    /** Unresolved emergencies stay pinned above the message list. */
    #[Computed]
    public function pinnedEmergencies(): Collection
    {
        return Emergency::query()
            ->where('room_id', $this->roomId)
            ->unresolved()
            ->with(['sender', 'recipients.user'])
            ->latest('id')
            ->get();
    }

    #[Computed]
    public function pinnedMessages(): Collection
    {
        return PinnedMessage::query()
            ->where('room_id', $this->roomId)
            ->with(['message.author'])
            ->latest()
            ->get()
            ->filter(fn (PinnedMessage $p) => $p->message !== null);
    }

    /** @return array<int, int> user id => last read message id */
    #[Computed]
    public function readCursors(): array
    {
        return $this->room->memberships()
            ->whereNotNull('last_read_message_id')
            ->pluck('last_read_message_id', 'user_id')
            ->all();
    }

    /**
     * Everyone who has read up to a given message, by name.
     *
     * Read state is a per-member cursor, not a row per message per person, so
     * "has X seen this" is "is X's cursor at or past this id". The author is
     * excluded — writing something does not count as having seen it.
     *
     * Pure array work over data the component already loaded: no query, even
     * though this is called once per rendered row.
     *
     * @return list<string>
     */
    public function seenBy(int $messageId, int $authorId): array
    {
        $names = $this->memberNames;

        $seen = [];

        foreach ($this->readCursors as $userId => $cursor) {
            if ($userId === $authorId || $cursor < $messageId) {
                continue;
            }

            if (isset($names[$userId])) {
                $seen[] = $names[$userId];
            }
        }

        sort($seen);

        return $seen;
    }

    /** @return array<int, string> user id => name */
    #[Computed]
    public function memberNames(): array
    {
        return $this->room->members
            ->mapWithKeys(fn ($member) => [(int) $member->getKey() => (string) $member->name])
            ->all();
    }

    /**
     * Who the composer will offer after an '@'.
     *
     * Exactly the room's membership, produced under the `view` gate that mount()
     * already asserted — so there is no user-search endpoint to authorise, and
     * no way to surface a name from a room the author cannot read.
     *
     * @return list<array{id: int, name: string, title: string}>
     */
    #[Computed]
    public function mentionCandidates(): array
    {
        return $this->room->members
            ->sortBy('name')
            ->map(fn ($member) => [
                'id' => (int) $member->getKey(),
                'name' => (string) $member->name,
                'title' => (string) ($member->job_title ?? ''),
            ])
            ->values()
            ->all();
    }

    #[Computed]
    public function savedMessageIds(): array
    {
        return SavedMessage::query()
            ->where('user_id', auth()->id())
            ->pluck('message_id')
            ->all();
    }

    // ---------------------------------------------------------------- actions

    public function onIncomingMessage(): void
    {
        $this->markRead();
        $this->dispatch('message-received');
    }

    /**
     * Bounds the whole selection, not each file.
     *
     * Every chosen file travels in one request, so the per-file limit alone
     * would let five attachments at full size become a single 200 MB POST.
     * Checked here rather than at the web server so the sender is told the
     * batch is too large, instead of the request dying with a bare 413.
     */
    private function batchWithinLimit(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $ceiling = (int) config('hub.attachments.max_batch_kilobytes');

            if (! is_array($value) || $ceiling <= 0) {
                return;
            }

            $kilobytes = array_sum(array_map(
                fn ($file) => is_object($file) && method_exists($file, 'getSize')
                    ? (int) ceil($file->getSize() / 1024)
                    : 0,
                $value,
            ));

            if ($kilobytes > $ceiling) {
                $fail(sprintf(
                    'These attachments come to %s MB together, which is over the %s MB limit for one message. Send them separately.',
                    number_format($kilobytes / 1024, 1),
                    number_format($ceiling / 1024),
                ));
            }
        };
    }

    public function send(MessageService $messages, EmergencyService $emergencies): void
    {
        Gate::authorize('post', $this->room);

        $rules = [
            'body' => [$this->uploads === [] ? 'required' : 'nullable', 'string', 'max:'.config('hub.messages.max_length')],
            'uploads' => ['array', 'max:5', $this->batchWithinLimit()],
            'uploads.*' => [
                'file',
                'max:'.config('hub.attachments.max_kilobytes'),
                'mimes:'.implode(',', config('hub.attachments.allowed_mimes')),
            ],
        ];

        // An emergency is text-only and must actually say something.
        if ($this->emergencyArmed) {
            $rules['body'] = ['required', 'string', 'min:3', 'max:'.config('hub.messages.max_length')];
        }

        $this->validate($rules, attributes: ['body' => 'message', 'uploads.*' => 'attachment']);

        if ($this->emergencyArmed) {
            $this->sendEmergency($emergencies);

            return;
        }

        $messages->send(
            auth()->user(),
            $this->room,
            $this->body,
            $this->uploads,
            $this->replyTo,
        );

        $this->reset('body', 'uploads', 'replyTo');
        $this->afterSend();
    }

    private function sendEmergency(EmergencyService $emergencies): void
    {
        Gate::authorize('sendInRoom', [Emergency::class, $this->room]);

        try {
            $emergencies->raiseInRoom(auth()->user(), $this->room, $this->body);
        } catch (EmergencyRateLimited $e) {
            $this->addError('body', $e->getMessage());

            return;
        }

        $this->reset('body', 'uploads', 'replyTo', 'emergencyArmed');
        $this->afterSend();
    }

    private function afterSend(): void
    {
        unset($this->timeline, $this->pinnedEmergencies);

        $this->markRead();
        $this->dispatch('message-received');
        $this->dispatch('scroll-to-latest');
    }

    public function acknowledge(int $emergencyId, EmergencyService $emergencies): void
    {
        $emergency = Emergency::findOrFail($emergencyId);

        Gate::authorize('acknowledge', $emergency);

        $emergencies->acknowledge($emergency, auth()->user());

        unset($this->pinnedEmergencies);
        $this->dispatch('emergency-acknowledged', emergencyId: $emergencyId);
    }

    public function resolveEmergency(int $emergencyId, EmergencyService $emergencies): void
    {
        $emergency = Emergency::findOrFail($emergencyId);

        Gate::authorize('resolve', $emergency);

        $emergencies->resolve($emergency, auth()->user());

        unset($this->pinnedEmergencies);
    }

    public function startEdit(int $messageId): void
    {
        $message = Message::findOrFail($messageId);

        Gate::authorize('update', $message);

        $this->editing = $messageId;
        $this->editBody = (string) $message->body;
    }

    public function saveEdit(MessageService $messages): void
    {
        $message = Message::findOrFail($this->editing);

        Gate::authorize('update', $message);

        $this->validate([
            'editBody' => ['required', 'string', 'max:'.config('hub.messages.max_length')],
        ], attributes: ['editBody' => 'message']);

        $messages->edit($message, $this->editBody);

        $this->reset('editing', 'editBody');
        unset($this->timeline);
    }

    public function cancelEdit(): void
    {
        $this->reset('editing', 'editBody');
    }

    public function deleteMessage(int $messageId, MessageService $messages): void
    {
        $message = Message::findOrFail($messageId);

        Gate::authorize('delete', $message);

        $messages->delete($message);

        unset($this->timeline);
    }

    public function react(int $messageId, string $emoji, MessageService $messages): void
    {
        $message = Message::findOrFail($messageId);

        Gate::authorize('react', $message);

        try {
            $messages->toggleReaction($message, auth()->user(), $emoji);
        } catch (\InvalidArgumentException $e) {
            $this->addError('reaction', $e->getMessage());

            return;
        }

        unset($this->timeline);
    }

    public function toggleSave(int $messageId): void
    {
        $message = Message::findOrFail($messageId);

        Gate::authorize('save', $message);

        $existing = SavedMessage::query()
            ->where('user_id', auth()->id())
            ->where('message_id', $messageId)
            ->first();

        $existing
            ? $existing->delete()
            : SavedMessage::create(['user_id' => auth()->id(), 'message_id' => $messageId]);

        unset($this->savedMessageIds);
    }

    public function togglePin(int $messageId): void
    {
        $message = Message::findOrFail($messageId);

        Gate::authorize('pin', $message);

        $existing = PinnedMessage::query()->where('message_id', $messageId)->first();

        $existing
            ? $existing->delete()
            : PinnedMessage::create([
                'room_id' => $message->room_id,
                'message_id' => $messageId,
                'pinned_by' => auth()->id(),
            ]);

        unset($this->pinnedMessages);
    }

    // ------------------------------------------------------------------ polls

    public function refreshTimeline(): void
    {
        unset($this->timeline);
    }

    public function openPoll(): void
    {
        Gate::authorize('createIn', [Poll::class, $this->room]);

        $this->reset('pollQuestion', 'pollOptions', 'pollClosesAt');
        $this->resetValidation();
        $this->pollOpen = true;
    }

    public function closePollComposer(): void
    {
        $this->reset('pollOpen', 'pollQuestion', 'pollOptions', 'pollClosesAt');
        $this->resetValidation();
    }

    public function addPollOption(): void
    {
        if (count($this->pollOptions) < 10) {
            $this->pollOptions[] = '';
        }
    }

    public function removePollOption(int $index): void
    {
        // Two is the floor: a poll with one answer is not a question.
        if (count($this->pollOptions) <= 2) {
            return;
        }

        unset($this->pollOptions[$index]);
        $this->pollOptions = array_values($this->pollOptions);
    }

    public function createPoll(PollService $polls): void
    {
        Gate::authorize('createIn', [Poll::class, $this->room]);

        $this->validate([
            'pollQuestion' => ['required', 'string', 'min:3', 'max:255'],
            'pollOptions' => ['array', 'min:2', 'max:10'],
            'pollOptions.*' => ['nullable', 'string', 'max:120'],
            'pollClosesAt' => ['nullable', 'date', 'after:now'],
        ], attributes: [
            'pollQuestion' => 'question',
            'pollClosesAt' => 'closing time',
        ]);

        // Blanks and duplicates are stripped by the service, so the count that
        // matters is the one after normalisation, not the raw input length.
        try {
            $polls->create(
                auth()->user(),
                $this->room,
                $this->pollQuestion,
                $this->pollOptions,
                $this->pollClosesAt !== '' ? CarbonImmutable::parse($this->pollClosesAt) : null,
            );
        } catch (\InvalidArgumentException $e) {
            $this->addError('pollOptions', $e->getMessage());

            return;
        }

        $this->closePollComposer();
        $this->afterSend();
    }

    public function vote(int $pollId, int $optionId, PollService $polls): void
    {
        $poll = Poll::findOrFail($pollId);

        Gate::authorize('vote', $poll);

        try {
            // Clicking the option you already hold retracts it, so a voter can
            // change their mind back to undecided without a second control.
            $poll->chosenOptionIdFor(auth()->user()) === $optionId
                ? $polls->retractVote($poll, auth()->user())
                : $polls->vote($poll, auth()->user(), $optionId);
        } catch (\InvalidArgumentException $e) {
            $this->addError('poll', $e->getMessage());
        }

        unset($this->timeline);
    }

    public function closePoll(int $pollId, PollService $polls): void
    {
        $poll = Poll::findOrFail($pollId);

        Gate::authorize('close', $poll);

        $polls->close($poll);

        unset($this->timeline);
    }

    // ------------------------------------------------------------------ forms

    /**
     * The forms that can be sent into this conversation: open, actually asking
     * something, and not already here. A form sent twice would give people two
     * cards leading to one response.
     *
     * @return Collection<int, Form>
     */
    #[Computed]
    public function sendableForms(): Collection
    {
        if (! Gate::allows('postIn', [Form::class, $this->room])) {
            return collect();
        }

        return Form::query()
            ->where(fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>', now()))
            ->has('fields')
            ->whereDoesntHave('postings', fn ($q) => $q->where('room_id', $this->roomId))
            ->withCount('fields')
            ->latest()
            ->limit(50)
            ->get();
    }

    public function openFormPicker(): void
    {
        Gate::authorize('postIn', [Form::class, $this->room]);

        unset($this->sendableForms);
        $this->formPickerOpen = true;
    }

    public function closeFormPicker(): void
    {
        $this->formPickerOpen = false;
    }

    public function sendForm(int $formId, FormService $forms): void
    {
        Gate::authorize('postIn', [Form::class, $this->room]);

        $form = Form::findOrFail($formId);

        try {
            $forms->postTo($form, auth()->user(), $this->room);
        } catch (\InvalidArgumentException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        $this->formPickerOpen = false;
        unset($this->sendableForms);

        $this->afterSend();
    }

    public function toggleThread(int $messageId): void
    {
        if (isset($this->openThreads[$messageId])) {
            unset($this->openThreads[$messageId]);

            return;
        }

        $this->openThreads[$messageId] = true;
    }

    public function replyInThread(int $messageId): void
    {
        $this->replyTo = $messageId;
        $this->openThreads[$messageId] = true;
    }

    public function cancelReply(): void
    {
        $this->replyTo = null;
    }

    public function loadMore(): void
    {
        $this->limit += 40;
        unset($this->timeline);
    }

    public function leaveRoom(RoomProvisioner $rooms): void
    {
        Gate::authorize('leave', $this->room);

        $rooms->removeMember($this->room, auth()->user());

        $this->redirectRoute('hub', navigate: true);
    }

    public function markRead(): void
    {
        app(MessageService::class)->markRead($this->room, auth()->user());

        $this->dispatch('messages-read');
    }

    public function updatedSearch(): void
    {
        unset($this->timeline);
    }

    private function escapeLike(string $term): string
    {
        return str_replace(['%', '_'], ['\%', '\_'], $term);
    }

    /**
     * The huddle roster as it stands when the page renders.
     *
     * After this, the banner updates itself over the room's private channel and
     * never asks the server again — a Livewire round-trip per join and leave,
     * multiplied by every member of a company-wide room, is not a thing this
     * feature can afford.
     *
     * ensureFresh() is the read-path half of reconciliation: a missing live
     * index means the cache was flushed rather than that nobody is huddling, and
     * this rebuilds it once rather than leaving every banner in the hub blank
     * until the scheduled sweep next runs.
     *
     * @return array<string, mixed>|null
     */
    private function huddleSnapshot(HuddleRegistry $huddles): ?array
    {
        if (! Gate::allows('huddle', $this->room)) {
            return null;
        }

        $huddles->ensureFresh();

        return $huddles->snapshot($this->room->getKey());
    }

    public function render(HuddleRegistry $huddles)
    {
        return view('livewire.hub.conversation', [
            'huddleSnapshot' => $this->huddleSnapshot($huddles),
        ]);
    }
}
