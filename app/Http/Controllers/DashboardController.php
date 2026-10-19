<?php

namespace App\Http\Controllers;

use App\Services\Ledger\TransactionList;
use App\Services\Onboarding;
use App\Services\Reports\AlertCenter;
use App\Services\Reports\Metrics;
use App\Services\Reports\Period;
use App\Services\Reports\ReportAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The owner dashboard: headline KPIs against last year, trend, health score, key metrics, alerts and activity. */
class DashboardController extends Controller
{
    /** Period buttons: preset => short label. */
    public const PRESETS = [
        'this_month' => 'MTD',
        'this_quarter' => 'QTD',
        'ytd' => 'YTD',
        'ttm' => '12M',
        'last_month' => 'Last month',
    ];

    public function __invoke(Request $request): View|RedirectResponse
    {
        if ($request->user()->isAdmin()) {
            return redirect()->route('admin.overview');
        }

        $company = $this->company($request);
        $preset = array_key_exists($request->query('preset'), self::PRESETS) ? $request->query('preset') : 'ytd';

        // Headline numbers are compared with the same dates one year earlier
        $period = Period::resolve($company, ['preset' => $preset, 'compare' => 'prior_year']);
        $metrics = Metrics::compute($company, $period);
        $previous = Metrics::compute($company, $period->compare);
        // Ratio metrics and the health score are add-ons; Basic sees a locked card with a way to add them
        $reports = ReportAccess::for($company);
        $unlocked = fn (array $codes) => $reports->contains(fn ($r) => $r['has_access'] && in_array($r['report']->code, $codes, true));
        $ratios = $unlocked(ReportAccess::RATIOS);

        return view('dashboard', [
            'company' => $company,
            'period' => $period,
            'presets' => self::PRESETS,
            'm' => $metrics,
            'prev' => $previous,
            'hasPrevious' => $previous['last_entry_date'] !== null && $company->journalLines()->where('entry_date', '<=', $period->compare->end->toDateString())->exists(),
            'health' => $unlocked(ReportAccess::HEALTH) ? Metrics::healthScore($metrics) : null,
            'ratiosUnlocked' => $ratios,
            'lockedAddons' => $reports->filter(fn ($r) => ! $r['has_access'] && in_array($r['report']->code, [...ReportAccess::RATIOS, ...ReportAccess::HEALTH], true))
                ->mapWithKeys(fn ($r) => [$r['report']->code => $r['report']]),
            'trend' => Metrics::trend($company),
            'cashTrend' => Metrics::cashTrend($company),
            'topExpenses' => Metrics::topExpenses($company, $period),
            'alerts' => AlertCenter::from($metrics, $ratios),
            'recent' => (new TransactionList($company))->describe(
                $company->journalEntries()->with(TransactionList::WITH)->latest('entry_date')->latest('entry_number')->limit(6)->get()
            ),
            'reports' => $reports,
            'onboarding' => Onboarding::steps($company, $request->user()),
        ]);
    }
}
