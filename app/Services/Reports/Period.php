<?php

namespace App\Services\Reports;

use App\Models\Company;
use Carbon\CarbonImmutable;
use Throwable;

/** A reporting date range, resolved from a preset (fiscal-year aware) or custom dates, with an optional comparison. */
final class Period
{
    public const PRESETS = [
        'ytd' => 'Fiscal year to date',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_quarter' => 'This quarter',
        'last_quarter' => 'Last quarter',
        'last_fy' => 'Last fiscal year',
        'ttm' => 'Last 12 months',
        'all' => 'All dates',
        'custom' => 'Custom dates',
    ];

    public const COMPARISONS = [
        'none' => 'No comparison',
        'prior_period' => 'Previous period',
        'prior_year' => 'Same period last year',
    ];

    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $preset = 'custom',
        public readonly ?Period $compare = null,
        public readonly string $compareMode = 'none',
    ) {
    }

    /** @param array{preset?: string, start?: string, end?: string, compare?: string} $query */
    public static function resolve(Company $company, array $query = [], string $default = 'ytd'): self
    {
        $today = CarbonImmutable::today();
        $preset = array_key_exists($query['preset'] ?? '', self::PRESETS) ? $query['preset'] : $default;
        $fyStartMonth = $company->fiscal_year_start_month ?: 1;
        $end = $today;

        switch ($preset) {
            case 'this_month':
                $start = $today->startOfMonth();
                break;
            case 'last_month':
                $start = $today->subMonthNoOverflow()->startOfMonth();
                $end = $start->endOfMonth();
                break;
            case 'this_quarter':
                $start = $today->startOfQuarter();
                break;
            case 'last_quarter':
                $start = $today->subQuarterNoOverflow()->startOfQuarter();
                $end = $start->endOfQuarter();
                break;
            case 'last_fy':
                $start = self::fiscalYearStart($today, $fyStartMonth)->subYear();
                $end = self::fiscalYearStart($today, $fyStartMonth)->subDay();
                break;
            case 'ttm':
                $start = $today->subMonthsNoOverflow(11)->startOfMonth();
                break;
            case 'all':
                $start = CarbonImmutable::parse($company->journalLines()->min('entry_date') ?? $today);
                break;
            case 'custom':
                $start = self::date($query['start'] ?? null) ?? self::fiscalYearStart($today, $fyStartMonth);
                $end = self::date($query['end'] ?? null) ?? $today;
                break;
            default:
                $start = self::fiscalYearStart($today, $fyStartMonth);
        }

        $start = $start->startOfDay();
        $end = $end->startOfDay();
        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }

        $mode = array_key_exists($query['compare'] ?? '', self::COMPARISONS) ? $query['compare'] : 'none';
        $compare = match ($mode) {
            'prior_year' => new self($start->subYearNoOverflow(), $end->subYearNoOverflow()),
            'prior_period' => self::previous($start, $end),
            default => null,
        };

        return new self($start, $end, $preset, $compare, $mode);
    }

    /** First day of the fiscal year that contains $date. */
    public static function fiscalYearStart(CarbonImmutable $date, int $startMonth): CarbonImmutable
    {
        $year = $date->month >= $startMonth ? $date->year : $date->year - 1;

        return CarbonImmutable::create($year, $startMonth, 1);
    }

    public function fiscalYearStartFor(Company $company): CarbonImmutable
    {
        return self::fiscalYearStart($this->end, $company->fiscal_year_start_month ?: 1);
    }

    public function label(): string
    {
        return $this->start->format('M j, Y').' – '.$this->end->format('M j, Y');
    }

    public function shortLabel(): string
    {
        return $this->start->isSameDay($this->start->startOfMonth()) && $this->end->isSameDay($this->end->endOfMonth()) && $this->start->isSameMonth($this->end)
            ? $this->start->format('M Y')
            : $this->start->format('M j, Y').' – '.$this->end->format('M j, Y');
    }

    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end) + 1;
    }

    /** The same length of time just before this period (whole months shift by months). */
    private static function previous(CarbonImmutable $start, CarbonImmutable $end): self
    {
        if ($start->day === 1 && $end->isSameDay($end->endOfMonth())) {
            $months = (int) round($start->diffInMonths($end->addDay()));
            $prevStart = $start->subMonthsNoOverflow($months);

            return new self($prevStart, $start->subDay());
        }

        $length = (int) $start->diffInDays($end) + 1;

        return new self($start->subDays($length), $start->subDay());
    }

    private static function date(?string $value): ?CarbonImmutable
    {
        if (! $value) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('Y-m-d', $value)?->startOfDay();
        } catch (Throwable) {
            return null;
        }

        // createFromFormat rolls 2026-02-31 over into March; treat that as invalid instead
        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
