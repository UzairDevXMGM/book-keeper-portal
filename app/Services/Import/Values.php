<?php

namespace App\Services\Import;

use DateTimeImmutable;
use DateTimeInterface;

/** Reading dates and amounts the way US banks write them. */
class Values
{
    /** Tried in order; US month-first formats come before day-first ones. */
    private const DATE_FORMATS = [
        'Y-m-d', 'm/d/Y', 'n/j/Y', 'm/d/y', 'n/j/y', 'm-d-Y', 'n-j-Y', 'Y/m/d',
        'M j, Y', 'M d, Y', 'F j, Y', 'j M Y', 'd M Y', 'd-M-Y', 'j-M-Y', 'd-M-y', 'M j Y', 'd.m.Y',
    ];

    /** A date cell -> "Y-m-d", or null. Excel serial numbers are understood too. */
    public static function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\d{5}(\.\d+)?$/', trim($value)))) {
            $serial = (float) $value;
            if ($serial > 20000 && $serial < 80000) {   // Excel day numbers for roughly 1954 - 2119
                return (new DateTimeImmutable('1899-12-30'))->modify('+'.(int) $serial.' days')->format('Y-m-d');
            }

            return null;
        }

        $text = trim(preg_replace('/\s+/', ' ', (string) $value));
        $text = preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?(\s?[AP]M)?$/i', '', $text);   // drop a time of day
        if ($text === '' || strlen($text) > 30) {
            return null;
        }

        foreach (self::DATE_FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $text);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && ! ($errors && ($errors['warning_count'] || $errors['error_count']))) {
                $year = (int) $date->format('Y');
                // "01/02/25" also fits m/d/Y as the year 25; keep trying until a format gives a real year (m/d/y)
                if ($year >= 1990 && $year <= 2100) {
                    return $date->format('Y-m-d');
                }
            }
        }

        return null;
    }

    /**
     * An amount cell -> signed float, or null. Understands $1,234.56, -12.00, 12.00-, (12.00), 12.00 CR / DR.
     */
    public static function amount(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $text = strtoupper(trim((string) $value));
        if ($text === '') {
            return null;
        }

        $negative = false;
        if (preg_match('/^\((.*)\)$/', $text, $m)) {
            $negative = true;
            $text = $m[1];
        }
        if (preg_match('/^(.*?)\s*(CR|DR)$/', $text, $m)) {
            $negative = $m[2] === 'DR';
            $text = $m[1];
        }
        if (str_ends_with($text, '-')) {
            $negative = true;
            $text = rtrim($text, '-');
        }
        if (str_starts_with($text, '-')) {
            $negative = true;
            $text = ltrim($text, '-');
        }
        $text = str_replace(['$', ',', ' ', 'USD'], '', $text);
        if (str_starts_with($text, '-')) {
            $negative = true;
            $text = ltrim($text, '-');
        }

        if (! preg_match('/^\d+(\.\d+)?$/', $text)) {
            return null;
        }

        return $negative ? -(float) $text : (float) $text;
    }

    /** Positive amount with two decimals, as stored. */
    public static function money(float $value): string
    {
        return number_format(abs(round($value, 2)), 2, '.', '');
    }

    /** Description cleaned for display and matching. */
    public static function text(mixed $value): string
    {
        return trim(preg_replace('/\s+/', ' ', (string) ($value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value)));
    }
}
