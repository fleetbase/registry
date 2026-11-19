<?php

use App\Casts\Money;

it('normalises money strings to integer minor units', function () {
    expect(Money::apply('$1,234'))->toBe(1234)
        ->and(Money::apply('49.00'))->toBe(4900)
        ->and(Money::apply(null))->toBe(0)
        ->and(Money::apply(4900))->toBe(4900)
        ->and(Money::apply('€-5'))->toBe(-5);
});
