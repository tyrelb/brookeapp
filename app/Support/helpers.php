<?php

if (! function_exists('csv_text')) {
    /**
     * Neutralise a text cell for a CSV export: a leading = + - @ (or tab / CR) would make
     * Excel or Sheets run it as a formula, so it is prefixed with an apostrophe.
     * Use on names and other free text only; numbers must stay numbers.
     */
    function csv_text(?string $value): string
    {
        $value = (string) $value;

        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }
}

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
