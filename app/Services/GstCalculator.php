<?php

namespace App\Services;

/**
 * The single place GST arithmetic and cent rounding happen.
 * Rates are percentages (5.00 = 5%). Rounding is half-up to the cent.
 */
class GstCalculator
{
    /** GST to add on top of a before-tax amount. */
    public static function onExclusive(float $subtotal, float $ratePercent): float
    {
        if ($ratePercent <= 0 || $subtotal == 0.0) {
            return 0.0;
        }

        return round($subtotal * $ratePercent / 100, 2);
    }

    /** Before-tax amount plus GST. */
    public static function totalWithGst(float $subtotal, float $ratePercent): float
    {
        return round($subtotal + self::onExclusive($subtotal, $ratePercent), 2);
    }

    /** The GST portion embedded in a tax-inclusive amount (e.g. a deposit received). */
    public static function embeddedIn(float $gross, float $ratePercent): float
    {
        if ($ratePercent <= 0 || $gross == 0.0) {
            return 0.0;
        }

        return round($gross - $gross / (1 + $ratePercent / 100), 2);
    }

    /** The before-tax portion of a tax-inclusive amount. */
    public static function exclusiveOf(float $gross, float $ratePercent): float
    {
        return round($gross - self::embeddedIn($gross, $ratePercent), 2);
    }
}
