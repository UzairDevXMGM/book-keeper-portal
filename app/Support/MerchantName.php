<?php

namespace App\Support;

use App\Models\ImportRow;
use App\Services\Import\Categorizer;

/**
 * The merchant's name as a person would write it, from a bank line: the matched vendor's name ("Walmart Supercenter")
 * when a named-merchant rule found it, else the name part of the description ("Purchase authorized on 05/31 ABC ROOFING
 * CORP P0000…" → "ABC Roofing Corp").
 */
class MerchantName
{
    /** Short words kept in capitals: company forms, states, initials. */
    private const KEEP_UPPER = ['LLC', 'LLP', 'PLLC', 'PC', 'PA', 'MD', 'DDS', 'DMD', 'CPA', 'USA', 'US', 'TX', 'CA', 'NY', 'FL', 'IL',
        'HEB', 'IRS', 'ATM', 'ACH', 'BP', 'QT', 'TA', 'CVS', 'UPS', 'USPS', 'DMV', 'AT&T', 'ABC', 'II', 'III'];

    public static function for(ImportRow $row): ?string
    {
        $rule = $row->relationLoaded('rule') ? $row->rule : null;
        if ($rule && $rule->source === 'vendor' && $rule->company_id === null && ! str_starts_with((string) $rule->name, 'Local ')) {
            return $rule->name;
        }

        return self::fromDescription((string) $row->description);
    }

    public static function fromDescription(string $description): ?string
    {
        $text = trim(preg_replace([
            // Bank wording in front of the merchant
            '/^(recurring payment|purchase return|purchase|atm withdrawal|money transfer|cash advance)\s+authorized on\s+\d{1,2}\/\d{1,2}\s*/i',
            '/^business to business ach (debit|credit)\s*-\s*/i',
            '/^(checkcard|check card|pos debit|pos purchase|pos|debit card purchase|debit card|dbt crd|purchase|recurring)\s+(\d{4}\s+)?/i',
            '/^(sq|tst|sp|py|pp|paypal|square|toast|clover|ach)\s*\*\s*/i',
            '/^zelle (payment )?(to|from)\s+/i',
        ], '', $description));
        // The name ends at a memo, reference, card number or the first word with digits (store number, phone, date)
        $text = preg_replace(['/\s+-\s+.*$/', '/\s+for\s+".*$/i', '/;.*$/', '/\s+(des|id|indn|conf|ref|trn)\s*[:#].*$/i'], '', $text);
        $words = [];
        foreach (preg_split('/\s+/', $text) as $word) {
            $clean = trim($word, " ,.;:*#()\"'");
            if ($clean === '' || preg_match('/\d/', $clean) || count($words) >= 6) {
                break;
            }
            $words[] = $clean;
        }
        // Trailing city/state ("... HOUSTON TX") is left out when the line shows it after the name
        if (count($words) > 2 && preg_match('/^[A-Z]{2}$/', end($words)) && ! in_array(end($words), self::KEEP_UPPER, true)) {
            array_pop($words);
        }
        if (! $words) {
            return null;
        }

        return implode(' ', array_map(function (string $word) {
            $upper = mb_strtoupper($word);
            if (in_array($upper, self::KEEP_UPPER, true) || (mb_strlen($word) <= 2 && $word === $upper)) {
                return $upper;
            }

            // Words written in mixed case by the bank keep their own spelling ("McDonald's"); shouted ones are calmed down
            return $word === $upper ? mb_convert_case(mb_strtolower($word), MB_CASE_TITLE) : $word;
        }, $words));
    }
}
