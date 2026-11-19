<?php

use App\Support\Semver;

it('orders versions by SemVer 2.0.0 precedence', function () {
    $ordered = ['1.0.0-alpha', '1.0.0-alpha.1', '1.0.0-alpha.beta', '1.0.0-beta', '1.0.0-beta.2', '1.0.0-beta.11', '1.0.0-rc.1', '1.0.0', '1.0.1', '1.2.0', '1.10.0', '2.0.0'];
    $shuffled = $ordered;
    shuffle($shuffled);

    expect(Semver::sort($shuffled))->toBe($ordered)
        ->and(Semver::max($shuffled))->toBe('2.0.0');
});

it('validates versions and detects prereleases', function () {
    expect(Semver::valid('1.2.3'))->toBeTrue()
        ->and(Semver::valid('1.2.3-beta.1+build.5'))->toBeTrue()
        ->and(Semver::valid('1.2'))->toBeFalse()
        ->and(Semver::valid('01.2.3'))->toBeFalse()
        ->and(Semver::isPrerelease('1.2.3-rc.1'))->toBeTrue()
        ->and(Semver::isPrerelease('1.2.3'))->toBeFalse()
        ->and(Semver::compare('1.0.0+build.1', '1.0.0+build.2'))->toBe(0);
});

it('knows which versions Composer accepts', function () {
    expect(Semver::isComposerCompatible('1.2.0'))->toBeTrue()
        ->and(Semver::isComposerCompatible('1.2.0-beta.1'))->toBeTrue()
        ->and(Semver::isComposerCompatible('2.0.0-RC2'))->toBeTrue()
        ->and(Semver::isComposerCompatible('1.2.0-next.1'))->toBeFalse();
});

it('normalizes loose versions and compares them with one comparator', function () {
    expect(Semver::normalize('v1.2'))->toBe('1.2.0')
        ->and(Semver::normalize('V2'))->toBe('2.0.0')
        ->and(Semver::normalize('1.2-beta.1'))->toBe('1.2.0-beta.1')
        ->and(Semver::normalize('1.2.3+build.5'))->toBe('1.2.3+build.5')
        ->and(Semver::normalize('dev-main'))->toBeNull()
        ->and(Semver::normalize('1.2.3.4'))->toBeNull()
        ->and(Semver::normalize(null))->toBeNull()
        ->and(Semver::compare('v1.2', '1.2.0'))->toBe(0)
        ->and(Semver::compare('1.10', '1.9.9'))->toBe(1);
});

it('decides whether a version is newer', function () {
    expect(Semver::isNewer('1.10.0', '1.9.0'))->toBeTrue()
        ->and(Semver::isNewer('1.0.0', '1.0.0-beta.1'))->toBeTrue()
        ->and(Semver::isNewer('1.0.0', '1.0.0'))->toBeFalse()
        ->and(Semver::isNewer('garbage', '1.0.0'))->toBeFalse()
        ->and(Semver::isNewer('1.0.0', null))->toBeTrue()
        ->and(Semver::isNewer('1.0.0', 'dev-main'))->toBeTrue();
});
