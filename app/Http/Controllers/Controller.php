<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;

abstract class Controller
{
    /** The signed-in customer's business. Routes that use this sit behind the `customer` middleware. */
    protected function company(Request $request): Company
    {
        $company = $request->user()->company;
        abort_if($company === null, 404);

        return $company;
    }

    /** 404 for records that belong to another company, so ids cannot be probed. */
    protected function authorizeCompany(Request $request, ?int $companyId): Company
    {
        $company = $this->company($request);
        abort_unless($companyId === $company->id, 404);

        return $company;
    }
}
