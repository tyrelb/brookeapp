<?php

use App\Services\GstCalculator;

it('adds gst on top of a before-tax amount', function () {
    expect(GstCalculator::onExclusive(60.00, 5))->toBe(3.00)
        ->and(GstCalculator::onExclusive(30.00, 5))->toBe(1.50)
        ->and(GstCalculator::totalWithGst(60.00, 5))->toBe(63.00)
        ->and(GstCalculator::onExclusive(33.33, 5))->toBe(1.67);
});

it('extracts gst embedded in a tax-inclusive amount', function () {
    expect(GstCalculator::embeddedIn(300.00, 5))->toBe(14.29)
        ->and(GstCalculator::exclusiveOf(300.00, 5))->toBe(285.71)
        ->and(GstCalculator::embeddedIn(63.00, 5))->toBe(3.00);
});

it('returns zero gst when the rate is zero', function () {
    expect(GstCalculator::onExclusive(60.00, 0))->toBe(0.0)
        ->and(GstCalculator::embeddedIn(300.00, 0))->toBe(0.0)
        ->and(GstCalculator::totalWithGst(60.00, 0))->toBe(60.00);
});
