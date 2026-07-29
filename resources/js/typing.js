/**
 * "X is typing…" for rooms and direct messages.
 *
 * Carried by client whispers on the room's presence channel, so a keystroke
 * never reaches the server: no request, no broadcast, no queue. The channel is
 * already authorised by membership in routes/channels.php.
 *
 * Each whisper is a heartbeat rather than a start/stop pair. Stop events get
 * lost — a closed laptop or a dropped socket sends nothing — and a stuck
 * "typing…" that never clears is worse than one that lingers half a second. So
 * every name carries its own expiry and is dropped when it goes stale.
 */

const HEARTBEAT_MS = 1500; // how often a typist re-announces while typing
const EXPIRY_MS = 4000;    // how long a name survives without a new heartbeat
const SWEEP_MS = 1000;

export function registerTypingIndicator(Alpine) {
    Alpine.data('typingIndicator', (roomId, me) => ({
        /** name => timestamp it expires at */
        typists: {},
        channel: null,
        lastSent: 0,
        sweep: null,

        init() {
            if (! window.Echo) return;

            this.channel = window.Echo.join(`presence.room.${roomId}`);

            this.channel.listenForWhisper('typing', (payload) => {
                if (! payload?.name || payload.id === me.id) return;

                this.typists = { ...this.typists, [payload.name]: Date.now() + EXPIRY_MS };
            });

            // Someone who sends stops typing; clear them immediately rather
            // than waiting out the expiry.
            this.channel.listenForWhisper('stopped-typing', (payload) => {
                if (! payload?.name) return;

                this.forget(payload.name);
            });

            this.sweep = window.setInterval(() => this.expire(), SWEEP_MS);

            this.$watch('typists', () => {});
        },

        destroy() {
            if (this.sweep) window.clearInterval(this.sweep);

            if (this.channel && window.Echo) {
                window.Echo.leave(`presence.room.${roomId}`);
            }
        },

        /** Called on every keystroke in the composer; throttled to a heartbeat. */
        announce() {
            if (! this.channel) return;

            const now = Date.now();
            if (now - this.lastSent < HEARTBEAT_MS) return;

            this.lastSent = now;
            this.channel.whisper('typing', { id: me.id, name: me.name });
        },

        /** Called once the message is away, so the others clear straight away. */
        stop() {
            if (! this.channel) return;

            this.lastSent = 0;
            this.channel.whisper('stopped-typing', { id: me.id, name: me.name });
        },

        forget(name) {
            const next = { ...this.typists };
            delete next[name];
            this.typists = next;
        },

        expire() {
            const now = Date.now();
            const live = Object.entries(this.typists).filter(([, until]) => until > now);

            if (live.length !== Object.keys(this.typists).length) {
                this.typists = Object.fromEntries(live);
            }
        },

        get names() {
            return Object.keys(this.typists).sort();
        },

        /** "Ivan is typing…", "Ivan and Grace are typing…", "3 people are typing…" */
        get label() {
            const names = this.names;

            if (names.length === 0) return '';
            if (names.length === 1) return `${names[0]} is typing…`;
            if (names.length === 2) return `${names[0]} and ${names[1]} are typing…`;

            return `${names.length} people are typing…`;
        },
    }));
}
