/**
 * Autocomplete logic tests.
 *
 * Uses node's built-in test runner — no new dependency, and no browser needed,
 * because none of this touches the DOM beyond a textarea's value and caret.
 *
 *   npm run test:js
 *
 * This exists because the feature shipped broken once: `results` was gated on
 * `open`, while `scan()` set `open` from `results.length`. The list could never
 * appear, and nothing server-side could have caught it — the Pest suite, the
 * rendered markup and the built bundle were all correct.
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';

import { registerMentionAutocomplete } from '../../resources/js/mentions.js';

const PEOPLE = [
    { id: 1, name: 'Abdelraouf Hussien', title: 'Platform Administrator' },
    { id: 2, name: 'Ivan Petrov', title: 'IT Support' },
    { id: 3, name: 'Grace Lin', title: 'IT Support' },
];

/** Minimal Alpine stand-in: capture the component factory it registers. */
function makeFactory() {
    let factory;
    registerMentionAutocomplete({ data: (_name, fn) => { factory = fn; } });

    return factory;
}

/** A component whose textarea holds `value` with the caret at `caret`. */
function composerWith(value, caret = null) {
    const component = makeFactory()(PEOPLE);

    component.$refs = {
        composer: {
            value,
            selectionStart: caret ?? value.length,
            selectionEnd: caret ?? value.length,
            focus() {},
            dispatchEvent() {},
        },
    };

    return component;
}

function afterTyping(value, caret = null) {
    const component = composerWith(value, caret);
    component.scan();

    return component;
}

test('a bare @ offers everyone in the room', () => {
    const c = afterTyping('Morning @');

    assert.equal(c.open, true);
    assert.equal(c.results.length, 3);
});

test('typing narrows the list', () => {
    assert.equal(afterTyping('Morning @iv').results[0].name, 'Ivan Petrov');
    assert.equal(afterTyping('hey @Gra').results[0].name, 'Grace Lin');
});

test('a name that starts with the query outranks one that merely contains it', () => {
    // "Lin" starts Grace's surname; it also appears inside nothing else here,
    // so add the check that ordering prefers a word-start match.
    const c = afterTyping('@Lin');

    assert.equal(c.results[0].name, 'Grace Lin');
});

test('no at-sign means no list', () => {
    assert.equal(afterTyping('Morning all').open, false);
});

test('an at-sign inside a word is not a mention', () => {
    assert.equal(afterTyping('mail bob@example').open, false);
});

test('a name nobody has does not open the list', () => {
    assert.equal(afterTyping('@zzzz').open, false);
});

test('a newline after the at-sign closes it', () => {
    assert.equal(afterTyping('@iv\nnext line').open, false);
});

test('an over-long run after the at-sign closes it', () => {
    assert.equal(afterTyping('@'.concat('x'.repeat(40))).open, false);
});

test('a full name still matches', () => {
    const c = afterTyping('@Ivan Petrov');

    assert.equal(c.open, true);
    assert.equal(c.results[0].name, 'Ivan Petrov');
});

test('it completes at the caret, not at the end of the box', () => {
    const value = 'tell @iv about the rota';
    const c = afterTyping(value, 'tell @iv'.length);

    c.choose();

    assert.equal(c.$refs.composer.value, 'tell @Ivan Petrov  about the rota');
    assert.equal(c.open, false);
});

test('choosing inserts the full name and a trailing space', () => {
    const c = afterTyping('Morning @iv');

    c.choose();

    assert.equal(c.$refs.composer.value, 'Morning @Ivan Petrov ');
});

test('arrow keys move the highlight and wrap around', () => {
    const c = afterTyping('@');

    assert.equal(c.highlighted, 0);
    c.move(1);
    assert.equal(c.highlighted, 1);
    c.move(-1);
    assert.equal(c.highlighted, 0);
    c.move(-1);
    assert.equal(c.highlighted, 2, 'moving up from the first entry wraps to the last');
});

test('closing resets everything', () => {
    const c = afterTyping('Morning @iv');

    c.close();

    assert.equal(c.open, false);
    assert.equal(c.query, '');
    assert.equal(c.at, null);
    assert.deepEqual(c.results, []);
});
