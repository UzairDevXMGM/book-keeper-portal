<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Workspace pages belong to a business; admins (who have none) are sent to the admin area instead. */
class EnsureCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isAdmin()) {
            return redirect()->route('admin.overview');
        }
        abort_if($user?->company === null, 404);

        return $next($request);
    }
}
