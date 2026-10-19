<?php

namespace App\Services\Import;

use DateTimeInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Turns a statement file into transaction lines.
 *
 * Every line is ['row_number', 'txn_date', 'description', 'amount', 'direction', 'bank_category', 'raw', 'error'];
 * amount is positive and direction says which way the money moved ('in' or 'out').
 *
 * Sign conventions for files with one Amount column:
 *   negative_is_out — bank exports and Chase card exports: money out is negative
 *   positive_is_out — most card exports: purchases are positive
 *   auto            — read it from the file (a Details/Type column, card payment lines), else the account's usual one
 */
class StatementParser
{
    public const MAX_ROWS = 10000;

    public const AUTO = 'auto';

    /**
     * @param  ?int  $year  the year the user picked; PDF statements that print dates without a year use it
     * @return array{lines: list<array>, opening: ?float, closing: ?float}
     */
    public function parse(string $path, string $type, string $signConvention, bool $creditCard = false, ?int $year = null): array
    {
        if ($type === 'pdf') {
            return (new PdfStatementParser)->parse($path, $signConvention === self::AUTO ? self::usualSign($creditCard) : $signConvention, $year);
        }

        return $this->parseTable($type === 'xlsx' ? $this->readXlsx($path) : $this->readCsv($path), $signConvention, $creditCard);
    }

    /** Banks show money out as negative; most card issuers show purchases as positive. */
    public static function usualSign(bool $creditCard): string
    {
        return $creditCard ? 'positive_is_out' : 'negative_is_out';
    }

    /** @param list<list<mixed>> $rows */
    public function parseTable(array $rows, string $signConvention, bool $creditCard = false): array
    {
        [$headerIndex, $columns] = $this->detectColumns($rows);
        if ($columns['date'] === null || ($columns['amount'] === null && $columns['debit'] === null && $columns['credit'] === null)) {
            throw new ImportException('We could not find the date and amount columns in this file. Make sure it is the transaction export from your bank or card (with Date, Description and Amount columns).');
        }
        $rows = $this->repairSwappedExcelDates($rows, $headerIndex, $columns['date']);
        if ($signConvention === self::AUTO) {
            $signConvention = $this->detectSign($rows, $headerIndex, $columns, $creditCard);
        }

        $lines = [];
        $balances = [];   // running balance printed next to each line, when the file has a Balance column
        $opening = null;
        $closing = null;
        foreach ($rows as $index => $row) {
            if ($index <= $headerIndex || $this->isBlank($row)) {
                continue;
            }

            $cell = fn (?int $column) => $column === null ? null : ($row[$column] ?? null);
            $description = Values::text($cell($columns['description']));
            $date = Values::date($cell($columns['date']));
            [$signed, $direction] = $this->amountAndDirection($cell($columns['amount']), $cell($columns['debit']), $cell($columns['credit']), $signConvention);

            if (preg_match('/^(opening|beginning|previous|starting) balance/i', $description) && $signed !== null) {
                $opening = $signed;
                continue;
            }
            if (preg_match('/^(closing|ending|new|current) balance/i', $description) && $signed !== null) {
                $closing = $signed;
                continue;
            }
            if ($date === null && $signed === null) {
                continue;   // notes, totals and other non-transaction rows
            }
            if ($signed !== null && abs($signed) < 0.005) {
                continue;
            }

            $error = match (true) {
                $date === null => 'The date could not be read.',
                $signed === null => 'The amount could not be read.',
                $description === '' => 'The description is empty.',
                default => null,
            };

            $lines[] = [
                'row_number' => $index + 1,
                'txn_date' => $date,
                'description' => $description !== '' ? mb_substr($description, 0, 500) : null,
                'amount' => $signed === null ? null : Values::money($signed),
                'direction' => $direction,
                'bank_category' => ($category = Values::text($cell($columns['category']))) !== '' ? mb_substr($category, 0, 120) : null,
                'raw' => array_map(fn ($v) => $v instanceof DateTimeInterface ? $v->format('Y-m-d') : (is_scalar($v) || $v === null ? $v : (string) $v), $row),
                'error' => $error,
            ];
            $balances[] = Values::amount($cell($columns['balance']));

            if (count($lines) >= self::MAX_ROWS) {
                break;
            }
        }

        if (! $lines) {
            throw new ImportException('No transactions were found in this file.');
        }
        if ($opening === null && $closing === null && $columns['balance'] !== null) {
            [$opening, $closing] = $this->balancesFromColumn($lines, $balances, $creditCard);
        }

        return ['lines' => $lines, 'opening' => $opening, 'closing' => $closing];
    }

    /**
     * A US bank CSV opened in Excel on a computer set to day-first dates comes back broken: every date whose day is
     * 12 or less becomes a real Excel date with day and month swapped (08/10/2025 -> 8 October), while the others stay
     * text (08/25/2025). When one date column mixes the two like that, the Excel dates are swapped back.
     */
    private function repairSwappedExcelDates(array $rows, int $headerIndex, int $column): array
    {
        $excelDates = [];   // row index => date, for cells Excel stored as dates (date objects or day serial numbers)
        $monthFirstText = false;
        foreach ($rows as $index => $row) {
            $value = $index > $headerIndex ? ($row[$column] ?? null) : null;
            if ($value instanceof DateTimeInterface || is_int($value) || is_float($value)) {
                $date = Values::date($value);
                if ($date === null) {
                    continue;
                }
                if ((int) substr($date, 8, 2) > 12) {
                    return $rows;   // a date with a day above 12 was never swapped, so the column is fine
                }
                $excelDates[$index] = $date;
            } elseif (is_string($value) && preg_match('/^\s*\d{1,2}\/(\d{1,2})\/\d{2,4}\s*$/', $value, $m) && (int) $m[1] > 12) {
                $monthFirstText = true;
            }
        }
        if (! $monthFirstText || ! $excelDates) {
            return $rows;
        }

        foreach ($excelDates as $index => $date) {
            [$year, $month, $day] = explode('-', $date);
            $rows[$index][$column] = "{$day}/{$month}/{$year}";   // the stored day is the real month
        }

        return $rows;
    }

    /**
     * Which way a single Amount column is signed. Each line with a hint votes: a Details/Type value (DEBIT, CREDIT,
     * Sale, Payment, ACH_CREDIT ...) or, on a card, a "Payment - thank you" line. Without hints a card file whose
     * amounts are mostly negative signs purchases as negative (Chase). Otherwise the account's usual convention.
     */
    private function detectSign(array $rows, int $headerIndex, array $columns, bool $creditCard): string
    {
        if ($columns['amount'] === null) {
            return self::usualSign($creditCard);   // separate debit and credit columns carry the direction
        }

        $votes = ['negative_is_out' => 0, 'positive_is_out' => 0];
        $values = 0;
        $negatives = 0;
        foreach ($rows as $index => $row) {
            $value = $index > $headerIndex ? Values::amount($row[$columns['amount']] ?? null) : null;
            if ($value === null || abs($value) < 0.005) {
                continue;
            }
            $values++;
            $negatives += $value < 0 ? 1 : 0;

            $hint = $this->directionHint(
                $columns['indicator'] === null ? '' : Values::text($row[$columns['indicator']] ?? null),
                $columns['description'] === null ? '' : Values::text($row[$columns['description']] ?? null),
                $creditCard,
            );
            if ($hint !== null) {
                $votes[($hint === 'out') === ($value < 0) ? 'negative_is_out' : 'positive_is_out']++;
            }
        }

        if ($votes['negative_is_out'] + $votes['positive_is_out'] > 0) {
            return $votes['negative_is_out'] >= $votes['positive_is_out'] ? 'negative_is_out' : 'positive_is_out';
        }
        if ($creditCard && $values >= 5 && $negatives / $values > 0.6) {
            return 'negative_is_out';
        }

        return self::usualSign($creditCard);
    }

    /** 'in' or 'out' when a line says so itself, else null. */
    private function directionHint(string $indicator, string $description, bool $creditCard): ?string
    {
        $indicator = strtolower($indicator);
        if (preg_match('/(^|_)(credit|cr|payment|return|refund|deposit|dslip|incoming)($|_)/', $indicator)) {
            return 'in';
        }
        if (preg_match('/(^|_)(debit|dr|sale|purchase|fee|withdrawal|check|outgoing)($|_)/', $indicator)) {
            return 'out';
        }
        if ($creditCard && preg_match('/payment.{0,20}thank|thank you.{0,20}pay/i', $description)) {
            return 'in';
        }

        return null;
    }

    /**
     * Opening and closing balance from a running Balance column (the balance after each line). Works for files
     * listed newest first (Chase) or oldest first. Bank balances grow with money in; card balances with money out.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function balancesFromColumn(array $lines, array $balances, bool $creditCard): array
    {
        $first = 0;
        $last = count($lines) - 1;
        if ($last < 1 || $balances[$first] === null || $balances[$last] === null || $lines[$first]['error'] || $lines[$last]['error']
            || $lines[$first]['txn_date'] === $lines[$last]['txn_date']) {
            return [null, null];
        }

        [$oldest, $newest] = $lines[$first]['txn_date'] < $lines[$last]['txn_date'] ? [$first, $last] : [$last, $first];
        $growth = ($lines[$oldest]['direction'] === ($creditCard ? 'out' : 'in') ? 1 : -1) * (float) $lines[$oldest]['amount'];

        return [round($balances[$oldest] - $growth, 2), round($balances[$newest], 2)];
    }

    /** @return array{0: ?float, 1: ?string} signed amount and direction */
    private function amountAndDirection(mixed $amount, mixed $debit, mixed $credit, string $signConvention): array
    {
        $out = Values::amount($debit);
        $in = Values::amount($credit);
        if ($out !== null && abs($out) >= 0.005) {
            return [abs($out), 'out'];
        }
        if ($in !== null && abs($in) >= 0.005) {
            return [abs($in), 'in'];
        }

        $value = Values::amount($amount);
        if ($value === null) {
            return [($out !== null || $in !== null) ? 0.0 : null, null];
        }
        $isOut = $signConvention === 'positive_is_out' ? $value > 0 : $value < 0;

        return [$value, $isOut ? 'out' : 'in'];
    }

    /**
     * Finds the header row and the column of each field. Falls back to guessing from the data when there is
     * no header (some banks export without one).
     *
     * @return array{0: int, 1: array{date: ?int, description: ?int, amount: ?int, debit: ?int, credit: ?int, category: ?int}}
     */
    private function detectColumns(array $rows): array
    {
        foreach (array_slice($rows, 0, 25, true) as $index => $row) {
            $headers = array_map(fn ($v) => strtolower(Values::text($v)), $row);
            $find = function (string $pattern, array $skip = []) use ($headers) {
                foreach ($headers as $i => $h) {
                    if ($h !== '' && preg_match($pattern, $h) && ! in_array($i, $skip, true)) {
                        return $i;
                    }
                }

                return null;
            };

            $date = $find('/^(transaction date|trans\.? date|txn date|date)$/') ?? $find('/date/');
            $amount = $find('/^(amount|transaction amount|amount \(.+\)|amt)$/') ?? $find('/amount(?!.*balance)/');
            $debit = $find('/^(debit|debits|withdrawal|withdrawals|money out|paid out|outflow|charges?|debit amount|withdrawal amount)$/');
            $credit = $find('/^(credit|credits|deposit|deposits|money in|paid in|inflow|payments?|credit amount|deposit amount)$/');
            if ($date === null || ($amount === null && $debit === null && $credit === null)) {
                continue;
            }

            $used = array_values(array_filter([$date, $amount, $debit, $credit], fn ($v) => $v !== null));
            // Most specific first: Chase puts DEBIT/CREDIT in a "Details" column before the real "Description"
            $description = null;
            foreach (['/^description$/', '/description/', '/^(payee|merchant|name|merchant name|payee name)$/', '/(details|memo|narrative|particulars|transaction)/'] as $pattern) {
                if (($description = $find($pattern, $used)) !== null) {
                    break;
                }
            }
            $category = $find('/^category$/', $used);
            $balance = $find('/^(balance|running balance|ledger balance|available balance)$/', $used);
            // A column that says which way each line went: Chase "Details" (DEBIT/CREDIT) or "Type" (Sale/Payment)
            $indicator = $find('/^(details|type|transaction type|trans\.? type|dr\/cr|cr\/dr|debit\/credit|credit\/debit)$/', array_merge($used, [$description]));

            return [$index, compact('date', 'description', 'amount', 'debit', 'credit', 'category', 'balance', 'indicator')];
        }

        return [-1, $this->guessColumns($rows)];
    }

    /** No header: the date column is the one that parses as dates, the amount the right-most numeric one, the description the longest text. */
    private function guessColumns(array $rows): array
    {
        $sample = array_slice(array_filter($rows, fn ($r) => ! $this->isBlank($r)), 0, 30);
        $width = max(array_map('count', $sample ?: [[]]));
        $score = ['date' => [], 'amount' => [], 'text' => []];
        foreach (range(0, max(0, $width - 1)) as $c) {
            $cells = array_map(fn ($r) => $r[$c] ?? null, $sample);
            $score['date'][$c] = count(array_filter($cells, fn ($v) => Values::date($v) !== null));
            $score['amount'][$c] = count(array_filter($cells, fn ($v) => Values::amount($v) !== null && Values::date($v) === null));
            $score['text'][$c] = array_sum(array_map(fn ($v) => Values::amount($v) === null && Values::date($v) === null ? strlen(Values::text($v)) : 0, $cells));
        }

        $half = max(1, (int) floor(count($sample) / 2));
        $date = collect($score['date'])->filter(fn ($n) => $n >= $half)->keys()->first();
        $amount = collect($score['amount'])->filter(fn ($n) => $n >= $half)->keys()->reject(fn ($c) => $c === $date)->last();
        $description = collect($score['text'])->reject(fn ($n, $c) => $c === $date || $c === $amount)->sortDesc()->keys()->first();

        return ['date' => $date, 'description' => $description, 'amount' => $amount, 'debit' => null, 'credit' => null, 'category' => null, 'balance' => null, 'indicator' => null];
    }

    private function readXlsx(string $path): array
    {
        try {
            $reader = new XlsxReader;
            $reader->open($path);
            $rows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                    if (count($rows) > self::MAX_ROWS + 50) {
                        break;
                    }
                }
                break;   // transactions are on the first sheet
            }
            $reader->close();
        } catch (Throwable) {
            throw new ImportException('This Excel file could not be opened. Save it again as .xlsx or export a CSV.');
        }

        return $rows;
    }

    private function readCsv(string $path): array
    {
        $content = (string) file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);   // UTF-8 byte order mark
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($content, "\n") ?: '';
        $delimiter = collect([',', ';', "\t", '|'])->sortByDesc(fn ($d) => substr_count($firstLine, $d))->first();

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false && count($rows) <= self::MAX_ROWS + 50) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    private function isBlank(array $row): bool
    {
        return collect($row)->every(fn ($v) => Values::text($v) === '');
    }
}
