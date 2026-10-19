<?php

// View formatting helpers (autoloaded through composer.json "files").

if (! function_exists('money')) {
    /** $1,234.56, negatives in accounting parentheses: ($1,234.56). Null prints a dash. */
    function money(float|int|string|null $value, bool $blankZero = false): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $value = round((float) $value, 2);
        if ($blankZero && $value == 0.0) {
            return '—';
        }
        $formatted = '$'.number_format(abs($value), 2);

        return $value < 0 ? "({$formatted})" : $formatted;
    }
}

if (! function_exists('money_whole')) {
    /** Headline amounts without cents: $274,939 / ($1,505). */
    function money_whole(float|int|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $value = round((float) $value);
        $formatted = '$'.number_format(abs($value));

        return $value < 0 ? "({$formatted})" : $formatted;
    }
}

if (! function_exists('pct')) {
    function pct(float|int|null $value, int $decimals = 1): string
    {
        return $value === null ? '—' : number_format($value, $decimals).'%';
    }
}

if (! function_exists('ratio')) {
    function ratio(float|int|null $value): string
    {
        return $value === null ? '—' : number_format($value, 2).'x';
    }
}
