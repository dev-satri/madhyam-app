<?php

namespace App\Http\Middleware;

use App\Services\RbacService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeatureAccess
{
    public function __construct(protected RbacService $rbac) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user() ?? $request->user('client');
        if (! $user) {
            return redirect()->route('login');
        }
        $role = $user->role ?? 'client';
        if (! $this->rbac->hasFeature($role, $feature)) {
            abort(403, 'You do not have access to this feature.');
        }

        return $next($request);
    }
}
