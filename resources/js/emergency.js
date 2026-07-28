/**
 * Emergency alerting on the client.
 *
 * Three signals, deliberately independent, because any one of them can be
 * unavailable: an audible tone, a flashing tab title, and a screen-reader
 * announcement. Colour and motion are handled in CSS and are never the only cue.
 */

const ANNOUNCER_ID = 'a11y-announcer';

let audioContext = null;
let activeOscillators = [];
let titleTimer = null;
let originalTitle = document.title;

/** Lazily created — browsers refuse an AudioContext before a user gesture. */
function context() {
    if (!audioContext) {
        const Ctor = window.AudioContext || window.webkitAudioContext;
        if (!Ctor) return null;
        audioContext = new Ctor();
    }

    if (audioContext.state === 'suspended') {
        audioContext.resume().catch(() => {});
    }

    return audioContext;
}

/**
 * Two-tone alternating alert, synthesised rather than loaded from a file so
 * there is no asset to fetch at the moment it is needed most.
 */
function playTone({ repeats = 3, broadcast = false } = {}) {
    const ctx = context();
    if (!ctx) return;

    stopTone();

    const now = ctx.currentTime;
    const beepLength = 0.22;
    const gap = 0.12;
    const frequencies = broadcast ? [880, 660] : [760, 570];

    for (let cycle = 0; cycle < repeats; cycle += 1) {
        frequencies.forEach((frequency, index) => {
            const start = now + cycle * (frequencies.length * (beepLength + gap)) + index * (beepLength + gap);

            const oscillator = ctx.createOscillator();
            const gain = ctx.createGain();

            oscillator.type = 'square';
            oscillator.frequency.setValueAtTime(frequency, start);

            // Short ramps instead of hard starts/stops, which click audibly.
            gain.gain.setValueAtTime(0.0001, start);
            gain.gain.exponentialRampToValueAtTime(0.22, start + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + beepLength);

            oscillator.connect(gain).connect(ctx.destination);
            oscillator.start(start);
            oscillator.stop(start + beepLength + 0.02);

            activeOscillators.push(oscillator);
        });
    }
}

function stopTone() {
    activeOscillators.forEach((oscillator) => {
        try {
            oscillator.stop();
        } catch {
            /* already stopped */
        }
    });
    activeOscillators = [];
}

/** Flashes the tab title so a backgrounded tab still signals. */
function flashTitle(label) {
    stopTitleFlash();

    originalTitle = document.title.replace(/^⚠ EMERGENCY .*/, '') || originalTitle;

    let toggled = false;
    titleTimer = window.setInterval(() => {
        document.title = toggled ? originalTitle : `⚠ EMERGENCY — ${label}`;
        toggled = !toggled;
    }, 900);
}

function stopTitleFlash() {
    if (titleTimer) {
        window.clearInterval(titleTimer);
        titleTimer = null;
    }
    document.title = originalTitle;
}

/**
 * Pushes text into the assertive live region. Cleared first so repeated
 * emergencies with identical wording are still announced.
 */
function announce(text) {
    const region = document.getElementById(ANNOUNCER_ID);
    if (!region) return;

    region.textContent = '';
    window.setTimeout(() => {
        region.textContent = text;
    }, 60);
}

export function registerEmergencyAlerting(Alpine) {
    Alpine.data('emergencyAlerting', () => ({
        fire(detail = {}) {
            const sender = detail.sender || 'A colleague';
            const isBroadcast = Boolean(detail.isBroadcast);
            const isEscalation = Boolean(detail.isEscalation);

            const lead = isEscalation
                ? `Emergency still unacknowledged from ${sender}.`
                : `Emergency message from ${sender}.`;

            announce(`${lead} ${detail.body || ''} Acknowledge it to stop further alerts.`);
            flashTitle(sender);

            // Respect reduced-motion for the visual flash, but never silence the
            // audio — a reduced-motion preference is not a do-not-disturb signal.
            playTone({ repeats: isBroadcast ? 4 : 3, broadcast: isBroadcast });
        },

        stop() {
            stopTone();
            stopTitleFlash();
        },
    }));
}

// A first user gesture unlocks audio for the rest of the session, so the tone
// is ready before the first emergency rather than after it.
['click', 'keydown'].forEach((type) => {
    window.addEventListener(type, () => context(), { once: true, passive: true });
});
