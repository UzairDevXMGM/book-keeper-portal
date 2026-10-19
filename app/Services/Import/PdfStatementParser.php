<?php

namespace App\Services\Import;

use Carbon\CarbonImmutable;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Reads transactions out of a text-based PDF statement. A transaction starts on a line that begins with a date:
 *   01/15  AMAZON MKTPLACE PMTS  -42.19  1,203.44              (one line)
 *   01/02/25 WIRE TYPE:WIRE OUT DATE:250102 ... SERVICE         (Bank of America: the description wraps and the
 *   REF:019858 BNF:MCBRYDE FRANCO PLLC ...                       amount sits on its own line at the end)
 *   -10,000.00
 * Money direction comes from the sign, from the section the line sits in ("Deposits", "Withdrawals"), or from the
 * account's sign convention, in that order. Tables that are not transactions ("Daily ledger balances") are skipped.
 * Scanned (image-only) PDFs have no text and are refused with a clear message.
 */
class PdfStatementParser
{
    private const DATE = '(\d{1,2}\/\d{1,2}(?:\/\d{2,4})?|\d{4}-\d{2}-\d{2}|[A-Z][a-z]{2}\.? \d{1,2}(?:, \d{4})?)';
    private const AMOUNT = '(-?\(?-?\$?\d{1,3}(?:,\d{3})*\.\d{2}\)?(?:\s?(?:CR|DR|-))?)';

    /** A wrapped description longer than this many lines is not a transaction. */
    private const MAX_LINES = 6;

    private string $signConvention;
    private ?string $section = null;
    private array $lines = [];

    /** @return array{lines: list<array>, opening: ?float, closing: ?float} */
    public function parse(string $path, string $signConvention, ?int $year = null): array
    {
        try {
            $document = (new Parser)->parseFile($path);
            $text = $document->getText();
        } catch (Throwable) {
            throw new ImportException('This PDF could not be opened. If it is password-protected, download an unprotected copy or the CSV export.');
        }
        if (trim($text) === '') {
            throw new ImportException('This PDF has no readable text (it looks like a scanned image). Please upload the CSV or Excel export from your bank instead.');
        }

        $this->signConvention = $signConvention;
        $this->section = null;
        $this->lines = [];
        $periodEnd = $this->statementEnd($text, $year);
        $opening = null;
        $closing = null;
        $skipping = false;   // inside a table that is not transactions
        $pending = null;     // a transaction whose amount has not been read yet

        foreach (preg_split('/\R/', $text) as $number => $raw) {
            $line = trim(preg_replace('/\s+/', ' ', $raw));
            if ($line === '' || $this->isPageNoise($line)) {
                continue;
            }

            if (preg_match('/^(beginning|opening|previous|starting) balance\b.*?'.self::AMOUNT.'\s*$/i', $line, $m)) {
                $opening ??= Values::amount($m[2]);
                continue;
            }
            if (preg_match('/^(ending|closing|new) balance\b.*?'.self::AMOUNT.'\s*$/i', $line, $m)) {
                $closing ??= Values::amount($m[2]);
                continue;
            }
            if (preg_match('/^(daily (ledger |ending |account )?balances?|balance summary|daily balance summary)\b/i', $line)) {
                $this->finish($pending);
                $pending = null;
                $skipping = true;
                continue;
            }

            if (preg_match('/^'.self::DATE.'\s+(.*)$/', $line, $m)) {
                $this->finish($pending);
                $pending = null;
                if ($skipping) {
                    continue;
                }

                // Check listings: "03/28/25 17 -14,050.00 03/31/25 1008* -1,413.21" (one or two checks per line)
                if (preg_match_all('/'.self::DATE.'\s+(\d{1,10})\*?\s+'.self::AMOUNT.'/', $line, $checks, PREG_SET_ORDER)
                    && trim(str_replace(array_column($checks, 0), '', $line)) === '') {
                    foreach ($checks as $check) {
                        $this->finish(['row_number' => $number + 1, 'date' => $this->date($check[1], $periodEnd), 'text' => ["Check #{$check[2]}"], 'amount' => $check[3]]);
                    }
                    continue;
                }

                $start = ['row_number' => $number + 1, 'date' => $this->date($m[1], $periodEnd), 'text' => [$m[2]], 'amount' => null];
                if (preg_match('/^(.+?)\s+'.self::AMOUNT.'(?:\s+'.self::AMOUNT.')?$/', $m[2], $a)) {
                    $this->finish(['text' => [$a[1]], 'amount' => $a[2]] + $start);
                } else {
                    $pending = $start;
                }
                continue;
            }

            if ($pending) {
                // The amount alone on a line (sometimes followed by the running balance) ends the transaction
                if (preg_match('/^'.self::AMOUNT.'(?:\s+'.self::AMOUNT.')?$/', $line, $a)) {
                    $this->finish(['amount' => $a[1]] + $pending);
                    $pending = null;
                    continue;
                }
                // ... or the last description line, with the amount after a tab
                if (preg_match('/^(.*\S)\t+\s*'.self::AMOUNT.'\s*$/', trim($raw), $a)) {
                    $pending['text'][] = $a[1];
                    $this->finish(['amount' => $a[2]] + $pending);
                    $pending = null;
                    continue;
                }
                if (! $this->isHeading($line) && count($pending['text']) < self::MAX_LINES) {
                    $pending['text'][] = $line;
                    continue;
                }
                $this->finish($pending);
                $pending = null;
            }

            $section = $this->section($line);
            if ($section !== null) {
                $this->section = $section;
                $skipping = false;
            }
        }
        $this->finish($pending);

        // Statements with separate Deposits / Withdrawals columns (Wells Fargo) print every amount as a positive
        // number, so the direction can only come from the column. Those are read by position instead.
        $columnLines = $this->columnLines($document, $periodEnd);
        if ($columnLines) {
            $this->lines = $columnLines;
        }

        if (! $this->lines) {
            throw new ImportException('No transactions were found in this PDF. Some bank layouts cannot be read; the CSV or Excel export always works.');
        }

        return ['lines' => $this->lines, 'opening' => $opening, 'closing' => $closing];
    }

    /**
     * Reads a transaction table laid out in columns:
     *   Date  Check Number  Description                       Deposits/Credits  Withdrawals/Debits  Ending daily balance
     *   6/2                 RTS Financial SE Corp Pmt ...     4,740.87
     *   6/2                 Purchase authorized on 05/31 ...                     65.00
     * Every piece of text comes with its x / y position on the page. Pieces at the same height form a row; an amount
     * belongs to the column whose heading it sits under. The table ends at "Totals" or the next summary section, so
     * later tables (fee summaries, check lists) are not read twice. Returns [] when the PDF has no such table.
     */
    private function columnLines(\Smalot\PdfParser\Document $document, CarbonImmutable $periodEnd): array
    {
        $lines = [];
        $columns = null;    // [x => 'in'|'out'|'balance'], carried over to pages that only continue the table
        $inTable = false;
        $txn = null;
        $rowNumber = 0;

        $close = function () use (&$txn, &$lines) {
            if ($txn && $txn['amount'] !== null && $txn['direction'] !== null) {
                $description = $this->cleanDescription(ltrim(implode(' ', $txn['text']), '< '));
                $lines[] = [
                    'row_number' => $txn['row_number'],
                    'txn_date' => $txn['date'],
                    'description' => mb_substr($description !== '' ? $description : 'Bank transaction', 0, 500),
                    'amount' => Values::money(abs($txn['amount'])),
                    'direction' => $txn['direction'],
                    'bank_category' => null,
                    'raw' => ['line' => implode(' ', $txn['text']), 'column' => $txn['direction'], 'daily_balance' => $txn['balance']],
                    'error' => $txn['date'] === null ? 'The date could not be read.' : null,
                ];
            }
            $txn = null;
        };

        foreach ($document->getPages() as $page) {
            try {
                $items = $page->getDataTm();
            } catch (Throwable) {
                return [];
            }

            // Group the pieces of text into rows (same height, top of the page first), each row left to right
            $rows = [];
            foreach ($items as [$matrix, $text]) {
                $text = trim(preg_replace('/\s+/', ' ', (string) $text));
                if ($text === '') {
                    continue;
                }
                $y = (float) ($matrix[5] ?? 0);
                $key = null;
                foreach (array_keys($rows) as $existing) {
                    if (abs($existing - $y) <= 2.5) {
                        $key = $existing;
                        break;
                    }
                }
                $rows[(string) ($key ?? $y)][] = ['x' => (float) ($matrix[4] ?? 0), 'text' => $text];
            }
            uksort($rows, fn ($a, $b) => (float) $b <=> (float) $a);

            foreach ($rows as $row) {
                usort($row, fn ($a, $b) => $a['x'] <=> $b['x']);
                $rowText = implode(' ', array_column($row, 'text'));

                // Column headings: an "in" heading and an "out" heading on the same row start (or continue) the table
                $found = [];
                foreach ($row as $piece) {
                    if (preg_match('/^(deposits?|credits?|additions?|money in)\b/i', $piece['text'])) {
                        $found[(string) $piece['x']] = 'in';
                    } elseif (preg_match('/^(withdrawals?|debits?|subtractions?|money out|payments)\b/i', $piece['text'])) {
                        $found[(string) $piece['x']] = 'out';
                    } elseif (preg_match('/^(ending (daily )?balance|daily balance|balance)$/i', $piece['text'])) {
                        $found[(string) $piece['x']] = 'balance';
                    }
                }
                if (in_array('in', $found, true) && in_array('out', $found, true) && ! preg_match('/\d+\.\d{2}/', $rowText)) {
                    $close();
                    ksort($found, SORT_NUMERIC);
                    $columns = $found;
                    $inTable = true;
                    continue;
                }
                if ($columns && preg_match('/^(ending (daily )?balance|balance)\b/i', $rowText) && ! preg_match('/\d+\.\d{2}/', $rowText)) {
                    $columns[(string) $row[0]['x']] ??= 'balance';   // the second line of a two-line heading
                    ksort($columns, SORT_NUMERIC);
                    continue;
                }
                if (preg_match('/^transaction history/i', $rowText) && $columns) {
                    $inTable = true;   // "Transaction History (continued)" on the next page
                    continue;
                }
                if (! $inTable || ! $columns) {
                    continue;
                }
                if (preg_match('/^(totals?\b|ending balance|summary of|monthly service fee|account transaction fees|interest summary|statement period)/i', $rowText)) {
                    $close();
                    $inTable = false;
                    continue;
                }

                $first = $row[0]['text'];
                $startsTransaction = (bool) preg_match('/^\d{1,2}\/\d{1,2}(\/\d{2,4})?$/', $first);
                if ($startsTransaction) {
                    $close();
                    $txn = ['row_number' => ++$rowNumber, 'date' => $this->date($first, $periodEnd), 'text' => [], 'amount' => null, 'direction' => null, 'balance' => null];
                    array_shift($row);
                } elseif (! $txn) {
                    continue;   // text above the first transaction on a page
                }

                $firstColumn = (float) array_key_first($columns);
                foreach ($row as $piece) {
                    $isAmount = (bool) preg_match('/^-?\$?\d{1,3}(,\d{3})*\.\d{2}-?$/', $piece['text']);
                    $column = $isAmount ? $this->columnFor($piece['x'], $columns) : null;
                    if ($column === null || $piece['x'] < $firstColumn - 20) {
                        // Description text, or the check number column ("1050" before the description)
                        $txn['text'][] = $startsTransaction && ! $txn['text'] && preg_match('/^\d{3,8}$/', $piece['text']) ? "Check #{$piece['text']}" : $piece['text'];
                        continue;
                    }
                    if ($column === 'balance') {
                        $txn['balance'] = Values::amount($piece['text']);
                    } elseif ($txn['amount'] === null) {
                        $txn['amount'] = Values::amount($piece['text']);
                        $txn['direction'] = $column;
                    }
                }
                if (! $startsTransaction && count($txn['text']) > 12) {
                    $close();   // runaway text is not a transaction
                }
            }
        }
        $close();

        return $lines;
    }

    /** The column an amount sits under: the right-most heading that starts at or before it (headings are left edges). */
    private function columnFor(float $x, array $columns): ?string
    {
        $match = null;
        foreach ($columns as $headingX => $kind) {
            if ((float) $headingX - 20 <= $x) {
                $match = $kind;
            }
        }

        return $match;
    }

    /** Adds a finished transaction. One that never got an amount is kept as an error so it shows up for review. */
    private function finish(?array $txn): void
    {
        if ($txn === null) {
            return;
        }

        $description = $this->cleanDescription(implode(' ', $txn['text']));
        if ($description === '' || preg_match('/^(total|subtotal|(beginning|ending|opening|closing|new|previous) balance|balance)\b/i', $description)) {
            return;
        }

        $signed = $txn['amount'] === null ? null : Values::amount($txn['amount']);
        if ($signed !== null && abs($signed) < 0.005) {
            return;
        }

        $direction = match (true) {
            $signed === null => null,
            $signed < 0 => $this->signConvention === 'positive_is_out' ? 'in' : 'out',
            $this->section !== null => $this->section,
            default => $this->signConvention === 'positive_is_out' ? 'out' : 'in',
        };

        $this->lines[] = [
            'row_number' => $txn['row_number'],
            'txn_date' => $txn['date'],
            'description' => mb_substr($description, 0, 500),
            'amount' => $signed === null ? null : Values::money($signed),
            'direction' => $direction,
            'bank_category' => null,
            'raw' => ['line' => implode(' ', $txn['text']).($txn['amount'] !== null ? ' '.$txn['amount'] : ''), 'section' => $this->section],
            'error' => match (true) {
                $txn['date'] === null => 'The date could not be read.',
                $signed === null => 'The amount could not be read.',
                default => null,
            },
        ];
    }

    /** Masked card numbers ("XXXXXXXXXXXX0035 XXXX XXXX XXXX 0035") say nothing about the transaction. */
    private function cleanDescription(string $text): string
    {
        $text = preg_replace('/\bX{4,}\d{0,4}\b|(?:\bX{4}\s+){2,}(?:X{4}\s+)?\d{4}\b/', ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /** Page furniture that can sit between two lines of one transaction. */
    private function isPageNoise(string $line): bool
    {
        return (bool) preg_match('/^page \d+ of \d+$|continued on (the )?next page|^continued from (the )?previous page|\baccount (#|number)\b|^date\s+(transaction )?description\b|^this page intentionally left blank/i', $line);
    }

    /**
     * A section title or a total line: it ends any transaction that is still waiting for its amount. Wrapped
     * description lines ("ID:123000220 PMT DET:556368646 LOAN DEPOSIT") carry digits or colons; titles do not.
     */
    private function isHeading(string $line): bool
    {
        return (bool) preg_match('/^(sub)?total\b/i', $line)
            || (! preg_match('/[\d:]/', $line)
                && preg_match('/^(deposits?|credits?|withdrawals?|debits?|checks|service fees|fees|purchases|payments|charges|other|electronic|atm|card)\b/i', $line)
                && $this->section($line) !== null);
    }

    /** "Deposits and additions" -> in, "Withdrawals", "Checks paid", "Service fees", "Purchases" ... -> out. */
    private function section(string $line): ?string
    {
        if (strlen($line) > 60 || preg_match('/\d+\.\d{2}/', $line) || preg_match('/^(sub)?total\b|^#/i', $line)) {
            return null;
        }
        if (preg_match('/\b(deposits?|credits?|additions|money in|payments and credits)\b/i', $line)) {
            return 'in';
        }
        if (preg_match('/\b(withdrawals?|debits?|checks|checks paid|purchases|fees|subtractions|money out|card activity|charges)\b/i', $line)) {
            return 'out';
        }

        return null;
    }

    /**
     * Most statements print dates without the year ("01/15"). The year comes from the statement period's end date;
     * a month after that end month belongs to the year before (a December line on a statement ending in January).
     */
    private function date(string $value, CarbonImmutable $periodEnd): ?string
    {
        if (preg_match('/^(\d{1,2})\/(\d{1,2})$/', $value, $m)) {
            $month = (int) $m[1];
            if ($month < 1 || $month > 12) {
                return null;
            }
            $year = $month > $periodEnd->month ? $periodEnd->year - 1 : $periodEnd->year;

            return Values::date("{$m[1]}/{$m[2]}/{$year}");
        }

        return Values::date(preg_replace('/^([A-Z][a-z]{2})\./', '$1', $value));
    }

    /**
     * The latest full date printed near the top of the statement (the period end). Without one: the end of the year
     * the user picked for the upload, else today.
     */
    private function statementEnd(string $text, ?int $year): CarbonImmutable
    {
        preg_match_all('/\b(\d{1,2}\/\d{1,2}\/\d{4}|[A-Z][a-z]+ \d{1,2}, \d{4})\b/', mb_substr($text, 0, 4000), $matches);
        $dates = array_filter(array_map(fn ($d) => Values::date($d), $matches[1]));

        return match (true) {
            (bool) $dates => CarbonImmutable::parse(max($dates)),
            $year !== null => CarbonImmutable::create($year, 12, 31),
            default => CarbonImmutable::today(),
        };
    }
}
