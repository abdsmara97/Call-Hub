/**
 * One AudioContext for the whole app.
 *
 * Browsers cap how many contexts a page may create and refuse to start any of
 * them before a user gesture. Emergencies and call ringing both need to make a
 * noise, and if each owned its own context the second one to ask would be the
 * one that silently failed — during an alert, which is the worst possible time
 * to discover it.
 */

let context = null;

/** Lazily created, and resumed on every access because tabs suspend it. */
export function audioContext() {
    const Ctor = window.AudioContext || window.webkitAudioContext;

    if (! Ctor) return null;

    if (! context) {
        context = new Ctor();
    }

    if (context.state === 'suspended') {
        context.resume().catch(() => {});
    }

    return context;
}

/**
 * Plays a sequence of beeps. Synthesised rather than loaded from a file so
 * there is no asset to fetch at the moment it is needed most.
 *
 * @param {Array<{frequency: number, start: number, length: number}>} notes
 * @param {{type?: OscillatorType, volume?: number}} options
 * @returns {OscillatorNode[]} so the caller can stop them early
 */
export function playSequence(notes, { type = 'square', volume = 0.22 } = {}) {
    const ctx = audioContext();

    if (! ctx) return [];

    const now = ctx.currentTime;
    const started = [];

    notes.forEach(({ frequency, start, length }) => {
        const at = now + start;

        const oscillator = ctx.createOscillator();
        const gain = ctx.createGain();

        oscillator.type = type;
        oscillator.frequency.setValueAtTime(frequency, at);

        // Short ramps instead of hard starts and stops, which click audibly.
        gain.gain.setValueAtTime(0.0001, at);
        gain.gain.exponentialRampToValueAtTime(volume, at + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, at + length);

        oscillator.connect(gain).connect(ctx.destination);
        oscillator.start(at);
        oscillator.stop(at + length + 0.02);

        started.push(oscillator);
    });

    return started;
}

export function stopAll(oscillators) {
    oscillators.forEach((oscillator) => {
        try {
            oscillator.stop();
        } catch {
            /* already stopped */
        }
    });
}
