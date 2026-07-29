/**
 * Typing indicator logic.
 *
 * The whispering itself needs a socket, but the parts that get the wording and
 * the expiry wrong are pure, so they are tested here.
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';

import { registerTypingIndicator } from '../../resources/js/typing.js';

const ME = { id: 1, name: 'Me Myself' };

function component() {
    let factory;
    registerTypingIndicator({ data: (_name, fn) => { factory = fn; } });

    const c = factory(7, ME);

    // No Echo in node: init() bails, which is what we want — these tests are
    // about the bookkeeping, not the transport.
    c.$watch = () => {};

    return c;
}

/** Pretend a whisper arrived from `name`, expiring `inMs` from now. */
function typed(c, name, inMs = 4000) {
    c.typists = { ...c.typists, [name]: Date.now() + inMs };
}

test('says nothing when nobody is typing', () => {
    assert.equal(component().label, '');
});

test('names a single typist', () => {
    const c = component();
    typed(c, 'Ivan Petrov');

    assert.equal(c.label, 'Ivan Petrov is typing…');
});

test('names two typists', () => {
    const c = component();
    typed(c, 'Ivan Petrov');
    typed(c, 'Grace Lin');

    // Sorted, so the wording is stable rather than arrival-order dependent.
    assert.equal(c.label, 'Grace Lin and Ivan Petrov are typing…');
});

test('counts three or more rather than listing them', () => {
    const c = component();
    typed(c, 'Ivan Petrov');
    typed(c, 'Grace Lin');
    typed(c, 'Mei Chen');

    assert.equal(c.label, '3 people are typing…');
});

test('drops a typist whose heartbeat has gone stale', () => {
    const c = component();
    typed(c, 'Ivan Petrov', -1); // already expired

    c.expire();

    assert.equal(c.label, '');
});

test('keeps a typist whose heartbeat is still fresh', () => {
    const c = component();
    typed(c, 'Ivan Petrov', 4000);

    c.expire();

    assert.equal(c.label, 'Ivan Petrov is typing…');
});

test('expires only the stale ones', () => {
    const c = component();
    typed(c, 'Ivan Petrov', -1);
    typed(c, 'Grace Lin', 4000);

    c.expire();

    assert.equal(c.label, 'Grace Lin is typing…');
});

test('forgets someone the moment they send', () => {
    const c = component();
    typed(c, 'Ivan Petrov');

    c.forget('Ivan Petrov');

    assert.equal(c.label, '');
});

test('throttles the heartbeat rather than whispering per keystroke', () => {
    const c = component();

    const sent = [];
    c.channel = { whisper: (event) => sent.push(event) };

    // A burst of keystrokes.
    for (let i = 0; i < 20; i++) c.announce();

    assert.equal(sent.length, 1, 'twenty keystrokes should produce one whisper');
});

test('announces again once the heartbeat window has passed', () => {
    const c = component();

    const sent = [];
    c.channel = { whisper: (event) => sent.push(event) };

    c.announce();
    c.lastSent = Date.now() - 5000; // pretend time moved on
    c.announce();

    assert.equal(sent.length, 2);
});

test('stopping whispers immediately, even mid-heartbeat', () => {
    const c = component();

    const sent = [];
    c.channel = { whisper: (event) => sent.push(event) };

    c.announce();
    c.stop();

    assert.deepEqual(sent, ['typing', 'stopped-typing']);
});

test('does nothing at all without a channel', () => {
    const c = component();

    assert.doesNotThrow(() => {
        c.announce();
        c.stop();
    });
});
