<?php

use App\Models\User;
use App\Services\MentionParser;
use Illuminate\Support\Collection;

/**
 * The parser is pure, so every failure mode is cheap to pin down here rather
 * than through a component.
 */
function members(array $namesById): Collection
{
    return collect($namesById)->map(function (string $name, int $id) {
        $user = new User(['name' => $name]);
        $user->id = $id;

        return $user;
    })->values();
}

function parse(string $body, array $namesById): array
{
    return (new MentionParser)->parse($body, members($namesById));
}

it('finds a full name', function () {
    $found = parse('Can @Ivan Petrov check bay two', [7 => 'Ivan Petrov']);

    expect($found)->toHaveCount(1)
        ->and($found[0]['user_id'])->toBe(7)
        ->and($found[0]['start'])->toBe(4)
        ->and($found[0]['length'])->toBe(12);
});

it('reports offsets that actually slice the mention back out', function () {
    $body = 'Morning @Ivan Petrov — bay two please';
    $found = parse($body, [7 => 'Ivan Petrov']);

    expect(mb_substr($body, $found[0]['start'], $found[0]['length']))->toBe('@Ivan Petrov');
});

it('prefers the longest matching name', function () {
    $found = parse('@Ivan Petrov please', [1 => 'Ivan', 7 => 'Ivan Petrov']);

    expect($found)->toHaveCount(1)
        ->and($found[0]['user_id'])->toBe(7)
        ->and($found[0]['length'])->toBe(12);
});

/*
 * The bug that prompted extracting this class: a plain substring search claimed
 * "@Ivan Petrov" out of "@Ivan Petrovich" and would have pinged the wrong person.
 */
it('does not match a name that is only a prefix of a longer word', function () {
    expect(parse('Ask @Ivan Petrovich about it', [7 => 'Ivan Petrov']))->toBe([]);
});

it('ignores an at-sign in the middle of a word', function () {
    expect(parse('mail bob@Ivan Petrov please', [7 => 'Ivan Petrov']))->toBe([])
        ->and(parse('write to ivan.petrov@oaktreetech.com', [7 => 'oaktreetech.com']))->toBe([]);
});

it('accepts trailing punctuation after the name', function () {
    foreach ([',', '.', '!', '?', ')', ':'] as $mark) {
        $found = parse("Thanks @Ivan Petrov{$mark}", [7 => 'Ivan Petrov']);

        expect($found)->toHaveCount(1, "failed on trailing '{$mark}'");
    }
});

it('matches at the very start and the very end of a body', function () {
    expect(parse('@Ivan Petrov', [7 => 'Ivan Petrov']))->toHaveCount(1)
        ->and(parse('over to @Ivan Petrov', [7 => 'Ivan Petrov']))->toHaveCount(1);
});

it('is case insensitive', function () {
    expect(parse('@ivan petrov here', [7 => 'Ivan Petrov']))->toHaveCount(1);
});

it('finds the same person named twice', function () {
    $found = parse('@Ivan Petrov and again @Ivan Petrov', [7 => 'Ivan Petrov']);

    expect($found)->toHaveCount(2)
        ->and($found[0]['start'])->toBeLessThan($found[1]['start']);
});

it('finds several different people', function () {
    $found = parse('@Ivan Petrov and @Grace Lin', [7 => 'Ivan Petrov', 9 => 'Grace Lin']);

    expect(array_column($found, 'user_id'))->toBe([7, 9]);
});

it('mentions both people when two members share a name', function () {
    $found = parse('@Ivan Petrov please', [7 => 'Ivan Petrov', 8 => 'Ivan Petrov']);

    expect(array_column($found, 'user_id'))->toBe([7, 8]);
});

it('ignores a name belonging to nobody in the room', function () {
    expect(parse('@Someone Else hello', [7 => 'Ivan Petrov']))->toBe([]);
});

it('ignores a bare at-sign', function () {
    expect(parse('meet @ the gate', [7 => 'Ivan Petrov']))->toBe([])
        ->and(parse('ends with @', [7 => 'Ivan Petrov']))->toBe([]);
});

/*
 * Offsets are in characters. Mixing byte and character offsets garbles any body
 * containing an emoji — and the composer has an emoji picker.
 */
it('counts characters, not bytes, around emoji', function () {
    $body = '🚧🚧 @Ivan Petrov to bay two';
    $found = parse($body, [7 => 'Ivan Petrov']);

    expect($found)->toHaveCount(1)
        ->and(mb_substr($body, $found[0]['start'], $found[0]['length']))->toBe('@Ivan Petrov');
});

it('handles a non-ascii name', function () {
    $body = 'Ping @Zoë Müller about the rota';
    $found = parse($body, [7 => 'Zoë Müller']);

    expect($found)->toHaveCount(1)
        ->and(mb_substr($body, $found[0]['start'], $found[0]['length']))->toBe('@Zoë Müller');
});

it('returns nothing for an empty body or an empty room', function () {
    expect(parse('', [7 => 'Ivan Petrov']))->toBe([])
        ->and((new MentionParser)->parse('@Ivan Petrov', collect()))->toBe([]);
});
