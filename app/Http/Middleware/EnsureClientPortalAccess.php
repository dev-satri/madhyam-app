<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureClientPortalAccess
{
    /**
     * Whitelisted client-portal routes.
     */
    protected const WHITELIST = [
        'dashboard',
        'approvals', 'complaints', 'reports', 'profile',
        'contentPlanner',
    ];

    public function handle(Request $request, Closure $next, ?string $feature = null): Response
    {
        if (! $request->user('client')) {
            return redirect()->route('login');
        }
        if ($feature !== null && ! in_array($feature, self::WHITELIST, true)) {
            return redirect()->route('client.dashboard');
        }

        return $next($request);
    }
}
