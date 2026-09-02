<?php

if (! function_exists('money')) {
    /**
     * Format a dollar amount for display: money(-31.5) => "-$31.50".
     */
    function money(float|int|string|null $amount, bool $signed = false): string
    {
        $amount = round((float) $amount, 2);
        $formatted = '$'.number_format(abs($amount), 2);

        if ($amount < 0) {
            return '-'.$formatted;
        }

        return ($signed && $amount > 0 ? '+' : '').$formatted;
    }
}
