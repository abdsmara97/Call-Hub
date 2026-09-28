/**
 * Ringing, for both ends of a call.
 *
 * Deliberately unlike the emergency tone. That one is a hard square wave at 760
 * and 570 Hz played loud; these are soft sine tones, quieter, and lower. If a
 * call could be mistaken for an emergency the emergency stops meaning anything,
 * which is the failure this whole product is built to avoid.
 *
 * Synthesised rather than loaded, for the same reason emergencies are: no asset
 * to fetch at the moment it is needed.
 */

import { playSequence, stopAll } from './audio.js';

const CYCLE_MS = 3000;
const VOLUME = 0.12;

let oscillators = [];
let repeat = null;

/** The callee's ring: a rising two-note pattern, repeated like a phone. */
const RINGTONE = [
    { frequency: 480, start: 0, length: 0.38 },
    { frequency: 620, start: 0.42, length: 0.38 },
];

/** The caller's ringback: a single low tone, quieter still. */
const RINGBACK = [
    { frequency: 420, start: 0, length: 0.9 },
];

function loop(notes, volume) {
    stopRinging();

    const play = () => {
        stopAll(oscillators);
        oscillators = playSequence(notes, { type: 'sine', volume });
    };

    play();
    repeat = window.setInterval(play, CYCLE_MS);
}

/** Played to the person being called. Silent when their DND says so. */
export function startRingtone({ quiet = false } = {}) {
    if (quiet) return;

    loop(RINGTONE, VOLUME);
}

/** Played to the caller while they wait. */
export function startRingback() {
    loop(RINGBACK, VOLUME * 0.6);
}

export function stopRinging() {
    if (repeat) {
        window.clearInterval(repeat);
        repeat = null;
    }

    stopAll(oscillators);
    oscillators = [];
}
