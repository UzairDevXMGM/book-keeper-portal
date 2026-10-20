<?php

namespace App\View\Composers;

use App\Models\Company;
use App\Models\ImportRow;
use App\Services\Reports\AlertCenter;
use App\Services\Reports\ReportAccess;
use Illuminate\View\View;

/** Data the signed-in layout needs on every page: the business, its plan, the alert count for the bell and the lines waiting on "Check categories". */
class AppLayoutComposer
{
    public function compose(View $view): void
    {
        $user = auth()->user();
        $company = $user?->company;
        $subscription = $company?->subscription(Company::SUBSCRIPTION);

        $view->with([
            'layoutUser' => $user,
            'layoutCompany' => $company,
            'layoutPlan' => $company ? ReportAccess::planFor($company) : null,
            'layoutPlanStatus' => $subscription?->stripe_status,
            'layoutAlerts' => $alerts = ($company ? AlertCenter::listFor($company) : []),
            'layoutAlertCount' => count($alerts),
            'layoutReviewCount' => $company ? ImportRow::where('company_id', $company->id)->where('status', ImportRow::STATUS_NEEDS_REVIEW)->count() : 0,
            'websiteUrl' => config('bookkeeper.website_url'),
        ]);
    }
}
