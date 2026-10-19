<?php

namespace App\Services\Demo;

use App\Models\Company;
use App\Services\Ledger\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Two years of believable books for a small retail business, so the dashboard and reports have something to
 * show before real statements are uploaded. Everything goes through LedgerService, so every entry is balanced.
 * The numbers are random but repeatable (fixed seed).
 */
class DemoBooks
{
    /** Two years, so "same period last year" comparisons have data. */
    public const MONTHS = 24;

    private array $accounts = [];
    private CarbonImmutable $today;

    public function __construct(private LedgerService $ledger)
    {
    }

    public function generate(Company $company): int
    {
        $this->accounts = $company->accounts()->pluck('id', 'canonical_code')->all();
        $this->today = CarbonImmutable::today();
        mt_srand(20261013);

        $start = $this->today->startOfMonth()->subMonthsNoOverflow(self::MONTHS - 1);
        $company->update(['books_start_date' => $start->toDateString()]);
        $posted = 0;

        DB::transaction(function () use ($company, $start, &$posted) {
            $post = function (string $date, string $description, array $lines) use ($company, &$posted) {
                if ($date > $this->today->toDateString()) {
                    return;
                }
                $this->ledger->post($company, ['entry_date' => $date, 'description' => $description, 'source' => 'manual'],
                    array_map(fn ($l) => ['account_id' => $this->accounts[$l[0]], 'debit' => $l[1] ?? null, 'credit' => $l[2] ?? null], $lines));
                $posted++;
            };

            $post($start->toDateString(), 'Opening balances', [
                ['1030', '18500.00'], ['1300', '9500.00'], ['1550', '12000.00'], ['1560', '4800.00'],
                ['2730', null, '30000.00'], ['3900', null, '14800.00'],
            ]);

            $loan = 30000.00;
            $openInvoices = [];
            $unpaidBill = null;

            for ($i = 0; $i < self::MONTHS; $i++) {
                $month = $start->addMonthsNoOverflow($i);
                $d = fn (int $day) => $month->day(min($day, $month->daysInMonth))->toDateString();
                $season = 1 + 0.18 * sin(($month->month - 3) / 12 * 2 * M_PI);   // busier in spring / summer
                $growth = 0.9 + 0.1 * $i / (self::MONTHS - 1);                  // the business grows ~5% a year
                $vary = fn (float $base, float $spread = 0.08) => round($base * $season * $growth * (1 + mt_rand(-100, 100) / 100 * $spread), 2);
                $m = fn (float $v) => number_format($v, 2, '.', '');

                // Card sales settle weekly; Stripe-style processing fees come out the same day
                foreach ([3, 10, 17, 24] as $day) {
                    $sales = $vary(5200);
                    $post($d($day), 'Square payout - card sales', [['1030', $m($sales)], ['4010', null, $m($sales)]]);
                    $fee = round($sales * 0.029, 2);
                    $post($d($day), 'Square processing fees', [['7310', $m($fee)], ['1030', null, $m($fee)]]);
                }

                // Wholesale customer invoice on net-45 terms: each one is paid about seven weeks later
                if (count($openInvoices) >= 2) {
                    $paid = array_shift($openInvoices);
                    $post($d(6), 'Customer payment - Bayou Gift Co. invoice', [['1030', $m($paid)], ['1100', null, $m($paid)]]);
                }
                $invoice = $openInvoices[] = $vary(7400);
                $post($d(15), 'Invoice - Bayou Gift Co. wholesale order', [['1100', $m($invoice)], ['4020', null, $m($invoice)]]);

                // Inventory: one paid by debit card, one supplier bill paid about four weeks later
                $inventory = $vary(6900);
                $post($d(8), 'Lone Star Wholesale Supply - inventory', [['5000', $m($inventory)], ['1030', null, $m($inventory)]]);
                if ($unpaidBill) {
                    $post($d(10), 'Payment - Gulf Coast Packaging bill', [['2000', $m($unpaidBill)], ['1030', null, $m($unpaidBill)]]);
                }
                $unpaidBill = $vary(3600);
                $post($d(12), 'Bill - Gulf Coast Packaging', [['5000', $m($unpaidBill)], ['2000', null, $m($unpaidBill)]]);

                // Operating costs
                $post($d(1), 'Rent - Westheimer Retail Plaza', [['6200', '2400.00'], ['1030', null, '2400.00']]);
                foreach ([14, 28] as $day) {
                    $post($d($day), 'Gusto payroll', [['6010', '4600.00'], ['6080', '351.90'], ['1030', null, '4951.90']]);
                }
                $utilities = $vary(380, 0.2);
                $post($d(12), 'CenterPoint Energy - utilities', [['6300', $m($utilities)], ['1030', null, $m($utilities)]]);
                $post($d(20), 'State Farm - business insurance', [['6700', '310.00'], ['1030', null, '310.00']]);
                $post($d(28), 'Monthly service charge', [['7300', '25.00'], ['1030', null, '25.00']]);

                // Credit card spend, paid off at month end
                $card = 0.0;
                foreach ([[5, '6540', 'Shopify subscription', 189.00], [11, '6410', 'Meta Ads', $vary(850, 0.3)],
                    [18, '6550', 'Client lunch - Pappas', $vary(140, 0.4)], [22, '6500', 'Office Depot supplies', $vary(95, 0.4)]] as [$day, $code, $what, $amount]) {
                    $post($d($day), $what, [[$code, $m($amount)], ['2020', null, $m($amount)]]);
                    $card += $amount;
                }
                $post($d(27), 'Chase card payment', [['2020', $m($card)], ['1030', null, $m($card)]]);

                // SBA loan: 7% interest, $650 a month
                $interest = round($loan * 0.07 / 12, 2);
                $principal = round(650 - $interest, 2);
                $loan -= $principal;
                $post($d(15), 'SBA loan payment', [['8500', $m($interest)], ['2730', $m($principal)], ['1030', null, '650.00']]);

                $post($month->endOfMonth()->toDateString(), 'Monthly depreciation', [['7100', '280.00'], ['1640', null, '280.00']]);

                if ($i % 3 === 2) {
                    $post($d(25), 'Owner draw', [['3110', '3000.00'], ['1030', null, '3000.00']]);
                }
            }

            // Two recent card swipes nobody has categorized yet (shows the review / suspense alert)
            $post($this->today->subDays(9)->toDateString(), 'POS DEBIT 4471 HOUSTON TX', [['6599', '150.75'], ['1030', null, '150.75']]);
            $post($this->today->subDays(4)->toDateString(), 'ACH WEB PMT 88213', [['6599', '89.10'], ['1030', null, '89.10']]);
        });

        return $posted;
    }
}
