<?php

namespace App\Http\Controllers;

use App\Services\Reports\AlertCenter;
use App\Services\Reports\Metrics;
use App\Services\Reports\Period;
use App\Services\Reports\ReportAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AlertController extends Controller
{
    public function __invoke(Request $request): View
    {
        $company = $this->company($request);
        $metrics = Metrics::compute($company, Period::resolve($company, ['preset' => 'ytd']));

        return view('alerts.index', [
            'company' => $company,
            'alerts' => AlertCenter::from($metrics, ReportAccess::hasAny($company, ReportAccess::RATIOS)),
        ]);
    }
}
