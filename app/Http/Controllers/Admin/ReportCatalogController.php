<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanyAddon;
use App\Models\Report;
use App\Services\Billing\StripePriceSync;
use App\Services\Reports\ReportRunner;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Admin → Report catalog: names, descriptions, add-on prices and which reports are offered. */
class ReportCatalogController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        return view('admin.reports.index', [
            'reports' => Report::with('plans:id,name')
                ->withCount(['companyAddons as active_addons_count' => fn ($q) => $q->where('status', CompanyAddon::STATUS_ACTIVE)])
                ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
                ->orderBy('sort_order')->paginate(20)->withQueryString(),
            'search' => $search,
        ]);
    }

    public function edit(Report $report): View
    {
        return view('admin.reports.edit', ['report' => $report, 'ready' => ReportRunner::supports($report->code)]);
    }

    public function update(Request $request, Report $report, StripePriceSync $sync): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category' => ['nullable', 'string', 'max:100'],
            'addon_price' => ['required', 'numeric', 'min:0', 'max:9999'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $priceChanged = bccomp((string) $report->addon_price, number_format((float) $data['addon_price'], 2, '.', ''), 2) !== 0;
        $report->update($data + [
            'is_active' => $request->boolean('is_active'),
            'stripe_price_id' => $priceChanged ? null : $report->stripe_price_id,
        ]);
        Audit::log('admin.report_updated', $report, ['code' => $report->code, 'price' => $data['addon_price']]);

        return redirect()->route('admin.reports.index')->with('status', "{$report->name} saved.".($priceChanged ? $sync->afterPriceChange() : ''));
    }
}
