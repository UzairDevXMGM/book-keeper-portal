<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Customers need an active plan before they can use the dashboard. Admins are never blocked. */
class EnsureCompanySubscribed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isAdmin() && ! $user->company?->hasActivePlan()) {
            return redirect()->route('billing.show');
        }

        return $next($request);
    }
}
