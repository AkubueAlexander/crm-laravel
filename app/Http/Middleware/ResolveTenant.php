<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after Sanctum's auth middleware on every authenticated api.php route.
 *
 * 1.3 (non-negotiable): tenant_id is derived ONLY from the authenticated user's
 * own tenant relationship — never from a header, query param, or request body.
 * A client cannot switch tenants by sending a different id; the only sanctioned
 * way to change tenant is the explicit switch flow in 1.4 (re-issues the session).
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->tenant) {
            TenantContext::set($user->tenant);
        }

        return $next($request);
    }
}
