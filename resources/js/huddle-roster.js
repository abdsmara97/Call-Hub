/**
 * The join banner's view model.
 *
 * Pure, and separate from huddle-machine.js because the banner and the huddle
 * are genuinely different things: hundreds of people see the banner in a
 * company-wide room and perhaps four of them ever join. This runs for all of
 * them; the machine runs for the four.
 *
 * `startedAt` is the huddle's identity. There is no database and therefore no
 * id, so dismissal is keyed on the start time — which gives the behaviour you
 * want for free: dismissing hides *this* huddle, and a later one in the same
 * room shows up again.
 */

// Explicit extension: this module is loaded directly by node in tests/js, and
// node will not guess it the way Vite does.
import { initialsOf, rosterLabel } from './huddle-machine.js';

const FACEPILE = 3;

export function bannerFrom(payload, { meId = null, dismissedAt = null } = {}) {
    if (! payload || ! payload.active) {
        return hidden(payload);
    }

    const participants = (payload.participants ?? []).map((p) => ({
        ...p,
        initials: initialsOf(p.name),
    }));

    const count = payload.participant_count ?? participants.length;
    const iAmIn = participants.some((p) => p.id === meId);

    // Dismissal is per huddle, not per room.
    if (dismissedAt !== null && Number(dismissedAt) === Number(payload.started_at) && ! iAmIn) {
        return { ...hidden(payload), phase: 'hidden' };
    }

    return {
        visible: true,
        phase: 'live',
        facepile: participants.slice(0, FACEPILE),
        // The payload carries at most `broadcast_participants` entries, so the
        // overflow is computed from the true count rather than the array.
        overflow: Math.max(0, count - FACEPILE),
        count,
        iAmIn,
        startedAt: payload.started_at ?? null,
        headline: 'Huddle in progress',
        subline: rosterLabel(participants, meId),
    };
}

/**
 * The banner never simply vanishes mid-glance — that reads as a bug. When the
 * roster empties it holds an "ended" line briefly and then leaves.
 */
function hidden(payload) {
    return {
        visible: !! payload?.was_active,
        phase: payload?.was_active ? 'ended' : 'hidden',
        facepile: [],
        overflow: 0,
        count: 0,
        iAmIn: false,
        startedAt: payload?.started_at ?? null,
        headline: 'Huddle ended',
        subline: '',
    };
}

/**
 * Keep the newest snapshot and discard anything older.
 *
 * Every payload is a complete snapshot with a monotonic version, which is what
 * makes a dropped or out-of-order broadcast harmless — but only if the client
 * actually checks.
 */
export function mergeSnapshot(current, incoming) {
    if (! incoming) return current;

    if (current && Number(incoming.version) < Number(current.version)) {
        return current;
    }

    return {
        ...incoming,
        // Remembered so the banner can show "Huddle ended" rather than
        // disappearing the instant the last person leaves.
        was_active: !! current?.active,
    };
}

export function elapsedLabel(startedAt, now) {
    if (! startedAt) return '';

    const seconds = Math.max(0, Math.floor(now / 1000) - Number(startedAt));

    if (seconds < 60) return 'just started';

    const minutes = Math.floor(seconds / 60);

    if (minutes < 60) return `${minutes} min`;

    const hours = Math.floor(minutes / 60);

    return `${hours} hr ${minutes % 60} min`;
}
