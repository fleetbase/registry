<?php

use App\Support\Compat;

it('evaluates npm and composer style ranges', function (?string $version, ?string $range, bool $expected) {
    expect(Compat::satisfies($version, $range))->toBe($expected);
})->with([
    'no range' => ['0.7.2', null, true],
    'star' => ['0.7.2', '*', true],
    'gte' => ['0.7.2', '>=0.7.0', true],
    'gte fails' => ['0.6.9', '>=0.7.0', false],
    'caret' => ['1.6.10', '^1.6', true],
    'caret major' => ['2.0.0', '^1.6', false],
    'and' => ['1.5.0', '>=1.0 <2.0', true],
    'or' => ['2.3.0', '1.x || 2.x', true],
    'tilde' => ['0.7.9', '~0.7.2', true],
    'leading v' => ['v0.7.2', '>=0.7', true],
    'unknown version' => [null, '>=99', true],
    'non-semver version' => ['dev-main', '>=99', true],
    'broken range' => ['1.0.0', 'not a range', false],
]);

it('checks that ranges parse', function () {
    expect(Compat::validRange('>=0.7 <1.0'))->toBeTrue()
        ->and(Compat::validRange('^1.6 || ^2.0'))->toBeTrue()
        ->and(Compat::validRange(null))->toBeTrue()
        ->and(Compat::validRange('not a range'))->toBeFalse();
});
