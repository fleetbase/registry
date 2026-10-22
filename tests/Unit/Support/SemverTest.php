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
