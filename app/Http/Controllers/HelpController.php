<?php

namespace App\Http\Controllers;

use App\Models\CategoryType;
use App\Models\Plan;
use App\Models\Report;
use App\Services\Reports\ReportAccess;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The guides. Customers: every page of their portal, their plan and add-ons, common questions.
 * Platform admin: running the platform (customers, prices, catalog, categories, users, settings).
 * Prices, reports and limits are read live, so the guides never go out of date.
 */
class HelpController extends Controller
{
    public function show(Request $request): View
    {
        $company = $this->company($request);
        $reports = ReportAccess::for($company);

        return view('guide.customer', [
            'company' => $company,
            'plan' => ReportAccess::planFor($company),
            'included' => $reports->filter(fn ($r) => $r['included'])->pluck('report'),
            'addons' => $reports->reject(fn ($r) => $r['included'])->pluck('report'),
            'owned' => $reports->filter(fn ($r) => $r['purchased'])->pluck('report.id')->all(),
            'threshold' => (int) Settings::get('review_threshold'),
            'maxMb' => (int) Settings::get('upload_max_mb'),
            'support' => config('mail.from.address'),
        ]);
    }

    public function admin(): View
    {
        return view('guide.admin', [
            'plans' => Plan::orderBy('sort_order')->get(),
            'reportCount' => Report::count(),
            'addonCount' => Report::where('addon_price', '>', 0)->count(),
            'categoryCount' => CategoryType::count(),
            'threshold' => (int) Settings::get('review_threshold'),
            'maxMb' => (int) Settings::get('upload_max_mb'),
            'signupsOpen' => (bool) Settings::get('registration_open'),
            'webhookSet' => filled(config('cashier.webhook.secret')),
            'stripeMode' => str_starts_with((string) config('cashier.secret'), 'sk_live') ? 'live' : 'test',
        ]);
    }
}
