/**
 * The huddle state machine and its layout selectors.
 *
 * Like the call machine, none of this can be observed in a browser test without
 * several machines and a lot of patience — and unlike the call machine, the
 * interesting cases start at six participants. Kept pure, they run here in
 * milliseconds.
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    CONNECTING,
    ENDED,
    IDLE,
    JOINING,
    LIVE,
    RECONNECTING,
    chooseStage,
    gridClass,
    initial,
    initialsOf,
    layoutMode,
    reduce,
    rosterLabel,
    visibleTiles,
} from '../../resources/js/huddle-machine.js';

const ROOM = 7;

function run(...events) {
    return events.reduce((state, event) => reduce(state, event), initial());
}

const peer = (identity, over = {}) => ({ identity, id: Number(identity.slice(1)), name: 'Grace Okafor', ...over });

const joining = () => run({ type: 'JOIN_REQUESTED', roomId: ROOM });
const connecting = () => reduce(joining(), { type: 'TOKEN_OK', roomId: ROOM });
const live = (...peers) => reduce(connecting(), { type: 'CONNECTED', roomId: ROOM, peers });

/* ------------------------------------------------------------------ basics */

test('starts idle with nothing connected', () => {
    const s = initial();

    assert.equal(s.state, IDLE);
    assert.equal(s.roomId, null);
    assert.deepEqual(s.peers, []);
    assert.equal(s.local.micOn, false);
});

test('walks from idle to live', () => {
    assert.equal(joining().state, JOINING);
    assert.equal(connecting().state, CONNECTING);
    assert.equal(live().state, LIVE);
    assert.equal(live().roomId, ROOM);
});

test('a refused token ends the join and keeps the server sentence', () => {
    const s = reduce(joining(), {
        type: 'TOKEN_REFUSED',
        roomId: ROOM,
        reason: 'full',
        message: 'This huddle is full (30 people).',
    });

    assert.equal(s.state, ENDED);
    assert.equal(s.reason, 'full');
    assert.equal(s.notice, 'This huddle is full (30 people).');
});

test('a refused token without a message falls back to our own wording', () => {
    const s = reduce(joining(), { type: 'TOKEN_REFUSED', roomId: ROOM, reason: 'media_denied' });

    assert.match(s.notice, /microphone/i);
});

/* ------------------------------------------------------------------- guards */

test('an event for another room is inert', () => {
    const s = live(peer('u1'));
    const after = reduce(s, { type: 'PARTICIPANT_JOINED', roomId: 99, peer: peer('u2') });

    assert.equal(after.peers.length, 1);
    assert.equal(after, s, 'the state object should be returned unchanged');
});

test('ended is absorbing and only RESET escapes it', () => {
    const ended = reduce(live(peer('u1')), { type: 'LEAVE_LOCAL', roomId: ROOM });

    assert.equal(ended.state, ENDED);

    const late = reduce(ended, { type: 'PARTICIPANT_JOINED', roomId: ROOM, peer: peer('u2') });
    assert.equal(late.state, ENDED);
    assert.deepEqual(late.peers, []);

    assert.equal(reduce(ended, { type: 'RESET' }).state, IDLE);
});

/* --------------------------------------------------------------- the roster */

test('a repeated join for one identity is one tile', () => {
    const s = reduce(
        live(peer('u1')),
        { type: 'PARTICIPANT_JOINED', roomId: ROOM, peer: peer('u1', { name: 'Grace O.' }) },
    );

    assert.equal(s.peers.length, 1);
    assert.equal(s.peers[0].name, 'Grace O.', 'the newer details should win');
});

test('a departure for somebody unknown is inert', () => {
    const s = live(peer('u1'));

    assert.equal(reduce(s, { type: 'PARTICIPANT_LEFT', roomId: ROOM, identity: 'u9' }), s);
});

test('a departure removes exactly one tile', () => {
    const s = reduce(
        live(peer('u1'), peer('u2')),
        { type: 'PARTICIPANT_LEFT', roomId: ROOM, identity: 'u1' },
    );

    assert.deepEqual(s.peers.map((p) => p.identity), ['u2']);
});

/*
 * The invariant that keeps video from flickering. Re-sorting the roster moves
 * DOM nodes, and moving a node containing a <video> re-attaches its track.
 */
test('speaking never reorders the roster', () => {
    let s = live(peer('u1'), peer('u2'), peer('u3'));
    const before = s.peers.map((p) => p.identity);

    s = reduce(s, { type: 'SPEAKERS', roomId: ROOM, identities: ['u3'] });
    s = reduce(s, { type: 'SPEAKERS', roomId: ROOM, identities: ['u2'] });
    s = reduce(s, { type: 'SPEAKERS', roomId: ROOM, identities: ['u3', 'u1'] });

    assert.deepEqual(s.peers.map((p) => p.identity), before);
});

test('speakers sets and clears the flag for exactly the named people', () => {
    let s = live(peer('u1'), peer('u2'));

    s = reduce(s, { type: 'SPEAKERS', roomId: ROOM, identities: ['u2'] });
    assert.deepEqual(s.peers.map((p) => p.speaking), [false, true]);

    s = reduce(s, { type: 'SPEAKERS', roomId: ROOM, identities: [] });
    assert.deepEqual(s.peers.map((p) => p.speaking), [false, false]);
});

test('a camera going off clears the sid but keeps the person', () => {
    let s = live(peer('u1'));

    s = reduce(s, { type: 'TRACK_CHANGED', roomId: ROOM, identity: 'u1', kind: 'video', sid: 'TR_1', enabled: true });
    assert.equal(s.peers[0].camOn, true);
    assert.equal(s.peers[0].videoSid, 'TR_1');

    s = reduce(s, { type: 'TRACK_CHANGED', roomId: ROOM, identity: 'u1', kind: 'video', enabled: false });
    assert.equal(s.peers[0].camOn, false);
    assert.equal(s.peers[0].videoSid, null);
    assert.equal(s.peers.length, 1, 'a camera off is not somebody leaving');
});

test('a screen share is tracked separately from the camera', () => {
    const s = reduce(
        live(peer('u1')),
        { type: 'TRACK_CHANGED', roomId: ROOM, identity: 'u1', kind: 'screen', sid: 'TR_S', enabled: true },
    );

    assert.equal(s.peers[0].screenSid, 'TR_S');
    assert.equal(s.peers[0].camOn, false);
});

/*
 * The control must show what LiveKit did, not what we asked it to do. A mic the
 * browser refuses to open has to leave the button reading "muted".
 */
test('local flags move only on confirmation', () => {
    const s = live();

    assert.equal(s.local.micOn, false);
    assert.equal(reduce(s, { type: 'LOCAL_CHANGED', roomId: ROOM, local: { micOn: true } }).local.micOn, true);
});

test('blocked autoplay is recorded so the panel can offer a sound button', () => {
    const s = reduce(live(), { type: 'AUDIO_BLOCKED', roomId: ROOM, blocked: true });

    assert.equal(s.local.audioBlocked, true);
});

test('a network blip goes to reconnecting and back without losing the roster', () => {
    let s = live(peer('u1'), peer('u2'));

    s = reduce(s, { type: 'NET_RECONNECTING', roomId: ROOM });
    assert.equal(s.state, RECONNECTING);
    assert.equal(s.peers.length, 2);

    s = reduce(s, { type: 'NET_RECONNECTED', roomId: ROOM });
    assert.equal(s.state, LIVE);
    assert.equal(s.notice, '');
});

/* --------------------------------------------------------- yield and surface */

test('yielding collapses to the pill and restores the surface afterwards', () => {
    let s = reduce(live(peer('u1')), { type: 'SURFACE', surface: 'expanded' });

    s = reduce(s, { type: 'YIELD', yielding: true });
    assert.equal(s.surface, 'pill');
    assert.equal(s.yielding, true);

    s = reduce(s, { type: 'YIELD', yielding: false });
    assert.equal(s.surface, 'expanded', 'the surface should come back as it was');
    assert.equal(s.yielding, false);
});

test('yielding twice does not lose the original surface', () => {
    let s = reduce(live(), { type: 'SURFACE', surface: 'expanded' });

    s = reduce(s, { type: 'YIELD', yielding: true });
    s = reduce(s, { type: 'YIELD', yielding: true });
    s = reduce(s, { type: 'YIELD', yielding: false });

    assert.equal(s.surface, 'expanded');
});

/* ------------------------------------------------------------------ layout */

test('the grid picks a real class for every plausible size', () => {
    for (const n of [1, 2, 3, 4, 6, 9, 12, 13, 40]) {
        assert.match(gridClass(n), /grid-cols-\d/, `count ${n}`);
    }

    assert.equal(gridClass(1), 'grid-cols-1');
    assert.equal(gridClass(4), 'grid-cols-2');
    assert.equal(gridClass(9), 'grid-cols-3');
    assert.equal(gridClass(40), gridClass(12), 'anything past twelve uses the widest grid');
});

test('the dock is always a grid; only an expanded panel goes to stage', () => {
    assert.equal(layoutMode(30, 'dock'), 'grid');
    assert.equal(layoutMode(30, 'pill'), 'grid');
    assert.equal(layoutMode(12, 'expanded'), 'grid');
    assert.equal(layoutMode(13, 'expanded'), 'stage');
});

test('tiles are capped and the remainder is reported', () => {
    const peers = Array.from({ length: 30 }, (_, i) => ({ identity: `u${i}`, joinedSeq: i }));

    assert.equal(visibleTiles(peers, 'dock').tiles.length, 4);
    assert.equal(visibleTiles(peers, 'dock').overflow, 26);
    assert.equal(visibleTiles(peers, 'expanded').tiles.length, 12);
    assert.equal(visibleTiles(peers, 'expanded').overflow, 18);

    const four = peers.slice(0, 4);
    assert.equal(visibleTiles(four, 'dock').overflow, 0);
    assert.equal(visibleTiles(peers.slice(0, 5), 'dock').overflow, 1);
});

test('visible tiles come back in join order', () => {
    const peers = [
        { identity: 'c', joinedSeq: 3 },
        { identity: 'a', joinedSeq: 1 },
        { identity: 'b', joinedSeq: 2 },
    ];

    assert.deepEqual(visibleTiles(peers, 'dock').tiles.map((p) => p.identity), ['a', 'b', 'c']);
});

/* ------------------------------------------------------------------- stage */

test('a screen share takes the stage regardless of who is talking', () => {
    const peers = [
        { identity: 'u1', speaking: true, speakingSince: 0 },
        { identity: 'u2', screenSid: 'TR_S' },
    ];

    assert.equal(chooseStage(peers, 10_000), 'u2');
});

test('a brief noise does not take the stage but sustained speech does', () => {
    const now = 10_000;
    const brief = [{ identity: 'u1', speaking: true, speakingSince: now - 500 }];
    const sustained = [{ identity: 'u1', speaking: true, speakingSince: now - 2500 }];

    assert.equal(chooseStage(brief, now, 'u2'), 'u2', 'the stage should not flicker on a half-second');
    assert.equal(chooseStage(sustained, now, 'u2'), 'u1');
});

test('the stage stays put while its holder keeps talking', () => {
    const now = 10_000;
    const peers = [
        { identity: 'u1', speaking: true, speakingSince: now - 5000 },
        { identity: 'u2', speaking: true, speakingSince: now - 4000 },
    ];

    assert.equal(chooseStage(peers, now, 'u2'), 'u2');
});

test('silence leaves the stage where it was', () => {
    const peers = [{ identity: 'u1', speaking: false }, { identity: 'u2', speaking: false }];

    assert.equal(chooseStage(peers, 10_000, 'u2'), 'u2');
});

/* ------------------------------------------------------------------ labels */

test('the roster label reads naturally at every size', () => {
    const p = (id, name) => ({ id, name });

    assert.equal(rosterLabel([], 1), 'Nobody yet');
    assert.equal(rosterLabel([p(1, 'Me Myself')], 1), 'Just you');
    assert.equal(rosterLabel([p(2, 'Ivan Petrov')], 1), 'Ivan');
    assert.equal(rosterLabel([p(2, 'Ivan Petrov'), p(3, 'Grace Okafor')], 1), 'Ivan and Grace');
    assert.equal(
        rosterLabel([p(1, 'Me Myself'), p(2, 'Ivan Petrov')], 1),
        'You and Ivan',
    );
    assert.equal(
        rosterLabel([p(1, 'Me'), p(2, 'Ivan P'), p(3, 'Grace O'), p(4, 'Sam T')], 1),
        'You, Ivan and 2 others',
    );
    assert.equal(
        rosterLabel([p(2, 'Ivan P'), p(3, 'Grace O'), p(4, 'Sam T'), p(5, 'Ada L')], 1),
        'Ivan, Grace and 2 others',
    );
});

test('one other person is singular', () => {
    const p = (id, name) => ({ id, name });

    assert.equal(rosterLabel([p(1, 'Me'), p(2, 'Ivan P'), p(3, 'Grace O')], 1), 'You, Ivan and 1 other');
});

test('initials cope with one name, two names and nothing at all', () => {
    assert.equal(initialsOf('Grace Okafor'), 'GO');
    assert.equal(initialsOf('Grace'), 'G');
    assert.equal(initialsOf('Ana Maria Torres'), 'AT');
    assert.equal(initialsOf(''), '?');
    assert.equal(initialsOf(null), '?');
});

/* ----------------------------------------------------------------- devices */

const MIC = { deviceId: 'mic-1', label: 'Jabra Evolve' };
const CAM = { deviceId: 'cam-1', label: 'Logitech C920' };

test('the device list is accepted in any state, including ended', () => {
    const ended = reduce(live(), { type: 'DISCONNECTED', roomId: ROOM, reason: 'left' });

    assert.equal(ended.state, ENDED);

    const withDevices = reduce(ended, { type: 'DEVICES', devices: { audioinput: [MIC] } });

    assert.deepEqual(withDevices.devices.audioinput, [MIC]);
    assert.equal(withDevices.state, ENDED, 'a plugged-in headset must not revive the huddle');
});

test('a partial device update leaves the other kinds alone', () => {
    const both = reduce(
        reduce(initial(), { type: 'DEVICES', devices: { audioinput: [MIC], videoinput: [CAM] } }),
        { type: 'DEVICES', devices: { audioinput: [] } },
    );

    assert.deepEqual(both.devices.audioinput, []);
    assert.deepEqual(both.devices.videoinput, [CAM], 'the camera list was not part of the update');
});

test('selecting a device records it by kind', () => {
    const s = reduce(initial(), { type: 'DEVICE_SELECTED', kind: 'audioinput', deviceId: 'mic-1' });

    assert.equal(s.selected.audioinput, 'mic-1');
    assert.equal(s.selected.videoinput, null);
});

test('an unknown device kind is ignored rather than added', () => {
    const s = reduce(initial(), { type: 'DEVICE_SELECTED', kind: 'midiinput', deviceId: 'x' });

    assert.deepEqual(Object.keys(s.selected).sort(), ['audioinput', 'audiooutput', 'videoinput']);
});

/*
 * The point of persisting a choice: it has to survive the huddle it was made in.
 */
test('a chosen device survives joining the next huddle', () => {
    const chosen = reduce(initial(), { type: 'DEVICE_SELECTED', kind: 'audioinput', deviceId: 'mic-1' });
    const joined = reduce(chosen, { type: 'JOIN_REQUESTED', roomId: ROOM });

    assert.equal(joined.state, JOINING);
    assert.equal(joined.selected.audioinput, 'mic-1');
});

test('a chosen device survives dismissing an ended huddle, but the list does not', () => {
    const s = reduce(
        reduce(initial(), { type: 'DEVICE_SELECTED', kind: 'audioinput', deviceId: 'mic-1' }),
        { type: 'DEVICES', devices: { audioinput: [MIC] } },
    );

    const dismissed = reduce(s, { type: 'RESET' });

    assert.equal(dismissed.state, IDLE);
    assert.equal(dismissed.selected.audioinput, 'mic-1');
    assert.deepEqual(dismissed.devices.audioinput, [], 'labels go stale, so the list is re-read on next join');
});
