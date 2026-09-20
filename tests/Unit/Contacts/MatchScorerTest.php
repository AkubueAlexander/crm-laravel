<?php

use App\Domain\Contacts\Matching\ContactCandidate;
use App\Domain\Contacts\Matching\MatchScorer;

beforeEach(function () {
    $this->scorer = new MatchScorer();
});

it('scores an identical name at 80 with no other signals', function () {
    $score = $this->scorer->score(
        new ContactCandidate('John', 'Smith'),
        new ContactCandidate('John', 'Smith'),
    );

    expect($score->score)->toBe(80)
        ->and($score->reasons)->toBe(['Same name']);
});

it('treats swapped name order as the same name', function () {
    $score = $this->scorer->score(
        new ContactCandidate('Smith', 'John'),
        new ContactCandidate('John', 'Smith'),
    );

    expect($score->score)->toBe(80);
});

it('flags a small typo as a similar name', function () {
    $score = $this->scorer->score(
        new ContactCandidate('Jon', 'Smith'),
        new ContactCandidate('John', 'Smith'),
    );

    expect($score->score)->toBeGreaterThan(70)->toBeLessThan(80)
        ->and($score->reasons[0])->toStartWith('Similar name');
});

it('floors the score at 90 when the email matches, even with a different name', function () {
    $score = $this->scorer->score(
        new ContactCandidate('Alice', 'Brown', 'Shared@Acme.test'),
        new ContactCandidate('Bob', 'Green', ' shared@acme.test '),
    );

    expect($score->score)->toBeGreaterThanOrEqual(90)
        ->and($score->reasons)->toContain('Same email');
});

it('matches phones regardless of formatting or country code', function () {
    $score = $this->scorer->score(
        new ContactCandidate('John', 'Smith', null, '+1 (555) 123-4567'),
        new ContactCandidate('John', 'Smith', null, '555.123.4567'),
    );

    expect($score->score)->toBe(95)
        ->and($score->reasons)->toContain('Same phone number');
});

it('scores unrelated contacts far below any sane threshold', function () {
    $score = $this->scorer->score(
        new ContactCandidate('Alice', 'Brown', 'a@one.test', '5550001111'),
        new ContactCandidate('Zhang', 'Wei', 'z@two.test', '5559998888'),
    );

    expect($score->score)->toBeLessThan(50);
});

it('ignores phone numbers that are too short to be meaningful', function () {
    expect((new ContactCandidate('A', 'B', null, '12345'))->phoneKey())->toBeNull();
});

it('normalizes names by lowercasing, stripping punctuation and collapsing whitespace', function () {
    expect((new ContactCandidate("  Sean ", "O'Brien-Jones"))->normalizedName())
        ->toBe('sean o brien jones');
});
