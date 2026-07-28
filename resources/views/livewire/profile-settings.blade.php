<div>
    <div class="mx-auto max-w-6xl px-4 py-6">
        <x-panel-heading title="Profile &amp; notifications"
                         description="Your photo, contact details, password and quiet hours." />

        <div class="grid gap-5 lg:grid-cols-3">

            {{-- ==================================================== 1. identity --}}
            <section class="panel p-4 lg:col-span-3" aria-labelledby="identity-heading">
                <h2 id="identity-heading" class="text-lg font-semibold tracking-tight">Your details</h2>
                <p class="mt-1 text-sm text-content-muted">
                    Your name, email address, job title, company, administration and role are
                    maintained by an administrator. Ask them if any of it is wrong.
                </p>

                <div class="mt-4 flex flex-wrap items-start gap-4">
                    <x-avatar :user="$user" size="xl" :presence="true" />

                    <dl class="grid min-w-0 flex-1 gap-x-6 gap-y-3 sm:grid-cols-2">
                        <div class="min-w-0">
                            <dt class="label">Name</dt>
                            <dd class="truncate text-base font-semibold">{{ $user->name }}</dd>
                        </div>

                        <div class="min-w-0">
                            <dt class="label">Email</dt>
                            <dd class="truncate text-base">{{ $user->email }}</dd>
                        </div>

                        <div class="min-w-0">
                            <dt class="label">Job title</dt>
                            <dd class="truncate text-base">{{ $user->job_title ?: '—' }}</dd>
                        </div>

                        <div class="min-w-0">
                            <dt class="label">Administration &amp; company</dt>
                            <dd class="truncate text-base">
                                {{ $user->administration?->name ?? '—' }} · {{ $user->company?->name ?? '—' }}
                            </dd>
                        </div>

                        <div class="min-w-0">
                            <dt class="label">Role</dt>
                            <dd class="flex flex-wrap gap-1">
                                @forelse ($roleNames as $roleName)
                                    <span class="badge-brand">{{ Str::headline($roleName) }}</span>
                                @empty
                                    <span class="badge-neutral">No role assigned</span>
                                @endforelse
                            </dd>
                        </div>

                        <div class="min-w-0">
                            <dt class="label">Account status</dt>
                            <dd>
                                <span class="badge-neutral">{{ $user->status->label() }}</span>
                            </dd>
                        </div>
                    </dl>
                </div>
            </section>

            {{-- ======================================================= 2. photo --}}
            <section class="panel p-4 lg:col-span-1" aria-labelledby="photo-heading">
                <h2 id="photo-heading" class="text-lg font-semibold tracking-tight">Profile photo</h2>
                <p class="mt-1 text-sm text-content-muted">
                    JPG, PNG or WebP, up to 2&nbsp;MB. Without a photo your initials are shown.
                </p>

                <div role="status" aria-live="polite">
                    @if ($photoStatus)
                        <p class="mt-3 flex items-start gap-2 rounded-md bg-brand-tint px-3 py-2 text-sm font-medium text-brand-text">
                            <x-icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>{{ $photoStatus }}</span>
                        </p>
                    @endif
                </div>

                <form wire:submit="savePhoto" class="mt-4 space-y-4">
                    <div class="flex items-center gap-4">
                        @if ($photo && ! $errors->has('photo'))
                            <img src="{{ $photo->temporaryUrl() }}" alt="Preview of the photo you selected"
                                 class="h-20 w-20 rounded-full object-cover ring-1 ring-brand-border" />
                            <p class="text-sm text-content-muted">
                                New photo selected. Save to apply it.
                            </p>
                        @else
                            <x-avatar :user="$user" size="xl" />
                            <p class="text-sm text-content-muted">Your current photo.</p>
                        @endif
                    </div>

                    <div>
                        <x-input-label for="photo" value="Choose a new photo" />
                        <input wire:model="photo" id="photo" type="file" accept="image/*"
                               class="field file:mr-3 file:rounded-md file:border-0 file:bg-surface-sunken
                                      file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-content" />
                        <x-input-error :messages="$errors->get('photo')" />

                        <p wire:loading wire:target="photo" class="mt-1 text-xs text-content-subtle">
                            Uploading…
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <x-primary-button wire:loading.attr="disabled" wire:target="photo,savePhoto">
                            Save photo
                        </x-primary-button>

                        @if ($photo)
                            <x-secondary-button type="button" wire:click="cancelPhoto">
                                Cancel
                            </x-secondary-button>
                        @endif

                        @if ($user->avatar_path)
                            <button type="button" wire:click="removePhoto" class="btn-ghost">
                                <x-icon name="trash" class="h-4 w-4" />
                                Remove photo
                            </button>
                        @endif
                    </div>
                </form>
            </section>

            {{-- ============================================ 3. contact & status --}}
            <section class="panel p-4 lg:col-span-2" aria-labelledby="contact-heading">
                <h2 id="contact-heading" class="text-lg font-semibold tracking-tight">Contact &amp; availability</h2>
                <p class="mt-1 text-sm text-content-muted">
                    These appear on your directory card and next to your name in every room.
                </p>

                <div role="status" aria-live="polite">
                    @if ($contactStatus)
                        <p class="mt-3 flex items-start gap-2 rounded-md bg-brand-tint px-3 py-2 text-sm font-medium text-brand-text">
                            <x-icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>{{ $contactStatus }}</span>
                        </p>
                    @endif
                </div>

                <form wire:submit="saveContact" class="mt-4 space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="phone" value="Phone" />
                            <x-text-input wire:model="phone" id="phone" type="tel" maxlength="40"
                                          autocomplete="tel" placeholder="e.g. +20 100 000 0000" />
                            <x-input-error :messages="$errors->get('phone')" />
                        </div>

                        <div>
                            <x-input-label for="status_message" value="Status message" />
                            <x-text-input wire:model="status_message" id="status_message" type="text"
                                          maxlength="120" placeholder="e.g. On site until Thursday" />
                            <p class="mt-1 text-xs text-content-subtle">Up to 120 characters. Leave blank to clear it.</p>
                            <x-input-error :messages="$errors->get('status_message')" />
                        </div>
                    </div>

                    <fieldset>
                        <legend class="label">Availability</legend>

                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($availabilities as $case)
                                <label for="availability-{{ $case->value }}"
                                       class="flex cursor-pointer items-center gap-3 rounded-md border border-line
                                              bg-surface px-3 py-2 text-sm hover:bg-surface-hover">
                                    <input wire:model="availability" type="radio" name="availability"
                                           id="availability-{{ $case->value }}" value="{{ $case->value }}"
                                           class="h-4 w-4 border-line-strong text-brand focus:ring-brand/30" />
                                    {{-- Colour is decoration; the written label carries the meaning. --}}
                                    <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $case->dotClass() }}"
                                          aria-hidden="true"></span>
                                    <span class="font-medium">{{ $case->label() }}</span>
                                </label>
                            @endforeach
                        </div>

                        <x-input-error :messages="$errors->get('availability')" />
                    </fieldset>

                    <div>
                        <x-primary-button>Save contact &amp; availability</x-primary-button>
                    </div>
                </form>
            </section>

            {{-- ==================================================== 4. password --}}
            <section class="panel p-4 lg:col-span-1" aria-labelledby="password-heading">
                <h2 id="password-heading" class="text-lg font-semibold tracking-tight">Password</h2>
                <p class="mt-1 text-sm text-content-muted">
                    At least 12 characters. Changing it signs out every other device.
                </p>

                <div role="status" aria-live="polite">
                    @if ($passwordStatus)
                        <p class="mt-3 flex items-start gap-2 rounded-md bg-brand-tint px-3 py-2 text-sm font-medium text-brand-text">
                            <x-icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>{{ $passwordStatus }}</span>
                        </p>
                    @endif
                </div>

                <form wire:submit="updatePassword" class="mt-4 space-y-4">
                    <div>
                        <x-input-label for="current_password" value="Current password" />
                        <x-text-input wire:model="current_password" id="current_password" type="password"
                                      autocomplete="current-password" />
                        <x-input-error :messages="$errors->get('current_password')" />
                    </div>

                    <div>
                        <x-input-label for="new_password" value="New password" />
                        <x-text-input wire:model="password" id="new_password" type="password"
                                      autocomplete="new-password" />
                        <x-input-error :messages="$errors->get('password')" />
                    </div>

                    <div>
                        <x-input-label for="password_confirmation" value="Confirm new password" />
                        <x-text-input wire:model="password_confirmation" id="password_confirmation" type="password"
                                      autocomplete="new-password" />
                    </div>

                    <div>
                        <x-primary-button>
                            <x-icon name="lock" class="h-4 w-4" />
                            Change password
                        </x-primary-button>
                    </div>
                </form>
            </section>

            {{-- ========================================================= 5. dnd --}}
            <section class="panel p-4 lg:col-span-2" aria-labelledby="dnd-heading">
                <h2 id="dnd-heading" class="text-lg font-semibold tracking-tight">Do Not Disturb</h2>
                <p class="mt-1 text-sm text-content-muted">
                    Quiet periods mute ordinary notifications on the days and times you choose.
                </p>

                {{-- The product guarantee. Stated plainly, in the loudest surface we have. --}}
                <p class="mt-3 flex items-start gap-2 rounded-md border border-emergency-border
                          bg-emergency-tint px-3 py-3 text-sm font-semibold text-emergency-text">
                    <x-icon name="alert" class="mt-0.5 h-5 w-5 shrink-0" />
                    <span>
                        Emergency messages always come through, regardless of Do Not Disturb.
                        Quiet hours never silence an emergency — it will still alert you, on every
                        device, with sound.
                    </span>
                </p>

                <div role="status" aria-live="polite">
                    @if ($dndStatus)
                        <p class="mt-3 flex items-start gap-2 rounded-md bg-brand-tint px-3 py-2 text-sm font-medium text-brand-text">
                            <x-icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>{{ $dndStatus }}</span>
                        </p>
                    @endif
                </div>

                {{-- ------------------------------------------------ add a window --}}
                <form wire:submit="addDndWindow" class="mt-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto_auto_auto] sm:items-end">
                    <div>
                        <x-input-label for="dnd_day" value="Day" />
                        <select wire:model="dnd_day" id="dnd_day" class="field">
                            @foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $index => $dayName)
                                <option value="{{ $index }}">{{ $dayName }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('dnd_day')" />
                    </div>

                    <div>
                        <x-input-label for="dnd_starts_at" value="From" />
                        <x-text-input wire:model="dnd_starts_at" id="dnd_starts_at" type="time" />
                        <x-input-error :messages="$errors->get('dnd_starts_at')" />
                    </div>

                    <div>
                        <x-input-label for="dnd_ends_at" value="To" />
                        <x-text-input wire:model="dnd_ends_at" id="dnd_ends_at" type="time" />
                        <x-input-error :messages="$errors->get('dnd_ends_at')" />
                    </div>

                    <div>
                        <x-primary-button>
                            <x-icon name="plus" class="h-4 w-4" />
                            Add
                        </x-primary-button>
                    </div>
                </form>

                <p class="mt-2 text-xs text-content-subtle">
                    An end time earlier than the start time is allowed — it means the quiet period
                    runs past midnight into the next morning.
                </p>

                {{-- ------------------------------------------------- window list --}}
                <h3 class="mt-5 text-sm font-semibold">Your quiet periods</h3>

                @if ($dndWindows->isEmpty())
                    <p class="mt-2 rounded-md bg-surface-sunken px-3 py-3 text-sm text-content-muted">
                        No quiet periods yet. Ordinary notifications reach you at any time of day.
                    </p>
                @else
                    <ul role="list" class="mt-2 divide-y divide-line rounded-md border border-line">
                        @foreach ($dndWindows as $window)
                            @php
                                $from = Str::substr((string) $window->starts_at, 0, 5);
                                $to = Str::substr((string) $window->ends_at, 0, 5);
                                $isOvernight = (string) $window->starts_at > (string) $window->ends_at;
                            @endphp

                            <li class="flex flex-wrap items-center gap-3 px-3 py-2.5 text-sm">
                                <x-icon name="clock" class="h-4 w-4 shrink-0 text-content-subtle" />

                                <span class="w-24 font-semibold">{{ $window->dayName() }}</span>

                                <span class="text-content-muted">
                                    {{ $from }} to {{ $to }}
                                    @if ($isOvernight)
                                        <span class="badge-neutral ml-1 align-middle">Overnight</span>
                                    @endif
                                </span>

                                <button type="button"
                                        wire:click="deleteDndWindow({{ $window->id }})"
                                        class="btn-ghost ml-auto !px-2 !py-1"
                                        aria-label="Delete the {{ $window->dayName() }} quiet period from {{ $from }} to {{ $to }}">
                                    <x-icon name="trash" class="h-4 w-4" />
                                    <span class="text-xs">Delete</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- ======================================= 6. browser notifications --}}
            <section class="panel p-4 lg:col-span-3" aria-labelledby="push-heading">
                <h2 id="push-heading" class="text-lg font-semibold tracking-tight">Browser notifications</h2>
                <p class="mt-1 text-sm text-content-muted">
                    Emergency messages are delivered by browser push and are announced with a sound,
                    so they reach you even when this tab is in the background or closed. Allowing
                    notifications is what makes that possible on this device.
                </p>

                {{--
                    wire:ignore: the permission state lives in the browser, not in the
                    component. Livewire must never re-render over it.
                --}}
                <div wire:ignore class="mt-4"
                     x-data="{
                         supported: typeof window.Notification !== 'undefined',
                         permission: (typeof window.Notification !== 'undefined' ? window.Notification.permission : 'unsupported'),
                         busy: false,
                         refresh() {
                             this.permission = this.supported ? window.Notification.permission : 'unsupported';
                         },
                         get label() {
                             return {
                                 granted: 'Allowed on this browser',
                                 denied: 'Blocked on this browser',
                                 default: 'Not yet requested',
                             }[this.permission] ?? 'This browser does not support notifications';
                         },
                         get labelClass() {
                             if (this.permission === 'granted') return 'text-brand-text';
                             if (this.permission === 'denied') return 'text-emergency-text';
                             return 'text-content-muted';
                         },
                         enable() {
                             this.busy = true;
                             Promise.resolve(window.OakTreeHub?.enablePush?.())
                                 .catch(() => {})
                                 .finally(() => { this.busy = false; this.refresh(); });
                         },
                     }">
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="button"
                                x-on:click="enable()"
                                x-bind:disabled="busy || permission === 'denied' || !supported"
                                class="btn-primary">
                            <x-icon name="bell" class="h-4 w-4" />
                            <span x-text="busy ? 'Requesting…' : 'Enable browser notifications'">Enable browser notifications</span>
                        </button>

                        {{-- State is spelled out in words, never signalled by colour alone. --}}
                        <p class="text-sm" role="status" aria-live="polite">
                            <span class="font-semibold">Current status:</span>
                            <span x-text="label" x-bind:class="labelClass" class="text-content-muted">Checking…</span>
                        </p>
                    </div>

                    {{-- Hidden until Alpine has read the real permission, so the warning
                         never flashes at someone who is not actually blocked. --}}
                    <p x-show="permission === 'denied'" x-cloak style="display: none"
                       class="mt-3 flex items-start gap-2 rounded-md border border-emergency-border bg-emergency-tint
                              px-3 py-2 text-sm text-emergency-text">
                        <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0" />
                        <span>
                            Notifications are blocked for this site, so emergency alerts cannot pop up
                            here. Re-allow them from the padlock or site-settings icon in your browser's
                            address bar, then reload this page.
                        </span>
                    </p>
                </div>
            </section>

        </div>
    </div>
</div>
