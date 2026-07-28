<?php

use App\Support\ImportReport;

/*
 * The queue worker builds this and the Livewire screen reads it back out of the
 * cache, so the array round trip is load-bearing, not incidental.
 */

it('starts empty', function () {
    $report = new ImportReport;

    expect($report->isEmpty())->toBeTrue()
        ->and($report->created())->toBe(0)
        ->and($report->skipped())->toBe(0)
        ->and($report->wasTruncated())->toBeFalse();
});

it('counts created rows and failures separately', function () {
    $report = new ImportReport;

    $report->recordCreated();
    $report->recordCreated();
    $report->recordFailure(4, 'bad@example.test', ['The email has already been taken.']);

    expect($report->created())->toBe(2)
        ->and($report->skipped())->toBe(1)
        ->and($report->isEmpty())->toBeFalse();
});

it('normalises a blank email on a failed row to null', function () {
    $report = new ImportReport;

    $report->recordFailure(7, '', ['The email field is required.']);
    $report->recordFailure(8, null, ['The email field is required.']);

    expect($report->failures())->each->toHaveKey('email')
        ->and(array_column($report->failures(), 'email'))->toBe([null, null]);
});

it('accepts any iterable of errors and stores them as a list of strings', function () {
    $report = new ImportReport;

    $report->recordFailure(2, 'a@example.test', new ArrayIterator(['first', 'second']));

    expect($report->failures()[0]['errors'])->toBe(['first', 'second']);
});

it('survives a round trip through toArray and fromArray', function () {
    $report = new ImportReport;

    $report->recordCreated();
    $report->recordFailure(3, 'nope@example.test', ['Unknown administration.']);
    $report->markTruncated();

    $restored = ImportReport::fromArray($report->toArray());

    expect($restored->toArray())->toBe($report->toArray())
        ->and($restored->created())->toBe(1)
        ->and($restored->skipped())->toBe(1)
        ->and($restored->wasTruncated())->toBeTrue()
        ->and($restored->failures()[0]['row'])->toBe(3);
});

it('rebuilds from a partial array without fataling', function () {
    $restored = ImportReport::fromArray([]);

    expect($restored->isEmpty())->toBeTrue()
        ->and($restored->created())->toBe(0)
        ->and($restored->wasTruncated())->toBeFalse();
});

it('namespaces its cache keys per administrator', function () {
    expect(ImportReport::cacheKey(7))->not->toBe(ImportReport::cacheKey(8))
        ->and(ImportReport::cacheKey(7))->not->toBe(ImportReport::runningKey(7));
});
