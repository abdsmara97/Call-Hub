/**
 * Emergency alerting on the client.
 *
 * Three signals, deliberately independent, because any one of them can be
 * unavailable: an audible tone, a flashing tab title, and a screen-reader
 * announcement. Colour and motion are handled in CSS and are never the only cue.
 */

import { audioContext, playSequence, stopAll } from './audio.js';

const ANNOUNCER_ID = 'a11y-announcer';

let activeOscillators = [];
let titleTimer = null;
let originalTitle = document.title;

/**
 * Two-tone alternating alert, synthesised rather than loaded from a file so
 * there is no asset to fetch at the moment it is needed most.
 *
 * The context is shared with call ringing (see audio.js) — a page may only
 * create so many, and an alert is the wrong moment to find that out.
 */
function playTone({ repeats = 3, broadcast = false } = {}) {
    stopTone();

    const beepLength = 0.22;
    const gap = 0.12;
    const frequencies = broadcast ? [880, 660] : [760, 570];
    const notes = [];

    for (let cycle = 0; cycle < repeats; cycle += 1) {
        frequencies.forEach((frequency, index) => {
            notes.push({
                frequency,
                start: cycle * (frequencies.length * (beepLength + gap)) + index * (beepLength + gap),
                length: beepLength,
            });
        });
    }

    activeOscillators = playSequence(notes);
}

function stopTone() {
    stopAll(activeOscillators);
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
// is ready before the first emergency rather than after it. Unlocks the shared
// context, so call ringing gets the benefit too.
['click', 'keydown'].forEach((type) => {
    window.addEventListener(type, () => audioContext(), { once: true, passive: true });
});
