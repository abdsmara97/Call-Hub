/**
 * The "Huddle in progress — Join" banner.
 *
 * Subscribes to `huddle.updated` on the room's private channel from JavaScript
 * rather than through a Livewire listener, and that is a deliberate performance
 * decision rather than a style one. Adding `.huddle.updated` to the Conversation
 * component's listeners would fire a full server round-trip — including
 * Conversation's timeline query and its eager loads — on every single join and
 * leave, for every member of the room. In a 300-member company room a huddle
 * filling up would be tens of thousands of requests.
 *
 * All this needs is a payload, and the payload is already complete.
 */

import { bannerFrom, elapsedLabel, mergeSnapshot } from './huddle-roster.js';
import { arbitrate } from './huddle-arbiter.js';
import { joinRoomChannel, releaseRoomChannel } from './room-channel.js';

const DISMISS_KEY = 'oaktree-huddle-dismissed-';
const ENDED_LINGER_MS = 8000;

export function registerHuddleBanner(Alpine) {
    Alpine.data('huddleBanner', (roomId, me, initialSnapshot = null) => ({
        roomId,
        me,
        snapshot: initialSnapshot,
        callState: 'idle',
        now: Date.now(),

        channel: null,
        handler: null,
        stopWatchingCall: null,
        timers: {},

        init() {
            this.handler = (payload) => this.receive(payload);
            this.channel = joinRoomChannel(this.roomId);
            this.channel?.listen('.huddle.updated', this.handler);

            // wire:navigate swaps the body; rebind rather than assume we survived.
            this.rebind = () => {
                this.channel = joinRoomChannel(this.roomId);
                this.channel?.listen('.huddle.updated', this.handler);
            };
            document.addEventListener('livewire:navigated', this.rebind);

            this.watchCall(Alpine);

            // Only to keep the elapsed label honest; nothing depends on it.
            this.timers.tick = window.setInterval(() => {
                this.now = Date.now();
            }, 30000);
        },

        destroy() {
            // Callers own their listeners — see room-channel.js.
            this.channel?.stopListening('.huddle.updated', this.handler);
            releaseRoomChannel(this.roomId);
            document.removeEventListener('livewire:navigated', this.rebind);
            this.stopWatchingCall?.();
            Object.values(this.timers).forEach((t) => window.clearInterval(t) || window.clearTimeout(t));
        },

        receive(payload) {
            this.snapshot = mergeSnapshot(this.snapshot, payload);

            // Hold the "ended" line briefly rather than vanishing mid-glance.
            if (this.snapshot && ! this.snapshot.active && this.snapshot.was_active) {
                window.clearTimeout(this.timers.ended);
                this.timers.ended = window.setTimeout(() => {
                    this.snapshot = { ...this.snapshot, was_active: false };
                }, ENDED_LINGER_MS);
            }
        },

        watchCall(alpine) {
            const el = document.querySelector('[x-data^="callSession"]');

            if (! el) return;

            const effect = alpine.effect(() => {
                this.callState = alpine.$data(el)?.call?.state ?? 'idle';
            });

            this.stopWatchingCall = () => alpine.release?.(effect);
        },

        get view() {
            return bannerFrom(this.snapshot, {
                meId: this.me.id,
                dismissedAt: this.dismissedAt(),
            });
        },

        get visible() {
            return this.view.visible;
        },

        get phase() {
            return this.view.phase;
        },

        get facepile() {
            return this.view.facepile;
        },

        get overflow() {
            return this.view.overflow;
        },

        get count() {
            return this.view.count;
        },

        get iAmIn() {
            return this.view.iAmIn;
        },

        get headline() {
            return this.view.headline;
        },

        get subline() {
            const elapsed = elapsedLabel(this.view.startedAt, this.now);

            return elapsed ? `${this.view.subline} · ${elapsed}` : this.view.subline;
        },

        /*
         * The display half of the arbitration rule. The real control is the
         * guard inside huddleSession.join(); this only means somebody sees the
         * reason before clicking rather than after.
         */
        get canJoin() {
            return ! arbitrate(this.callState).blockJoin;
        },

        get joinBlockedReason() {
            return arbitrate(this.callState).blockedReason;
        },

        join() {
            if (! this.canJoin) return;

            window.dispatchEvent(new CustomEvent('huddle-join', { detail: { roomId: this.roomId } }));
        },

        start() {
            window.dispatchEvent(new CustomEvent('huddle-start', { detail: { roomId: this.roomId } }));
        },

        /*
         * sessionStorage, not localStorage: dismissing is "not right now", not a
         * preference. Keyed on the start time so a later huddle in the same room
         * is a new offer and shows again.
         */
        dismiss() {
            try {
                window.sessionStorage.setItem(DISMISS_KEY + this.roomId, String(this.view.startedAt ?? ''));
            } catch {
                // Private browsing; the banner simply stays.
            }

            this.snapshot = { ...this.snapshot };
        },

        dismissedAt() {
            try {
                const stored = window.sessionStorage.getItem(DISMISS_KEY + this.roomId);

                return stored === null || stored === '' ? null : Number(stored);
            } catch {
                return null;
            }
        },
    }));
}
