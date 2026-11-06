<?php

declare(strict_types=1);

use CPointLabs\HostYourAI\Decisions\Questions\Choice;
use CPointLabs\HostYourAI\Decisions\Questions\Noul;
use CPointLabs\HostYourAI\Decisions\Questions\Score;

it('builds a noul question', function (): void {
    expect(Noul::make('Is it blue?')->toArray())->toBe([
        'type' => 'noul',
        'instructions' => 'Is it blue?',
    ]);
});

it('adds noul criteria only when given', function (): void {
    expect(Noul::make('Is it blue?', yes: 'Mentions blue')->toArray()['criteria'])->toBe(['true' => 'Mentions blue'])
        ->and(Noul::make('Is it blue?', 'Yes', 'No')->toArray()['criteria'])->toBe(['true' => 'Yes', 'false' => 'No']);
});

it('builds a choice question from named or listed criteria', function (): void {
    expect(Choice::make('Which?', ['a' => 'First', 'b' => 'Second'])->toArray()['criteria'])->toBe(['a' => 'First', 'b' => 'Second'])
        ->and(Choice::make('Which?', ['a', 'b'])->toArray()['criteria'])->toBe(['a' => null, 'b' => null]);
});

it('rejects a choice outside 2 to 16 criteria', function (int $count): void {
    Choice::make('Which?', array_map(fn (int $i): string => "option{$i}", range(1, $count)));
})->with([1, 17])->throws(InvalidArgumentException::class);

it('accepts a choice at the limits', function (int $count): void {
    expect(Choice::make('Which?', array_map(fn (int $i): string => "option{$i}", range(1, $count)))->criteria)
        ->toHaveCount($count);
})->with([2, 16]);

it('builds a score question with ordered levels', function (): void {
    expect(Score::make('How urgent?', ['Low', 'High'])->toArray())->toBe([
        'type' => 'score',
        'instructions' => 'How urgent?',
        'criteria' => ['Low', 'High'],
    ]);
});

it('rejects a score outside 1 to 10 levels', function (array $levels): void {
    Score::make('How urgent?', $levels);
})->with([[[]], [array_fill(0, 11, 'level')]])->throws(InvalidArgumentException::class);
