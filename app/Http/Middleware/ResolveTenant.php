<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        TenantContext::clear();

        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $user = $request->user();

        // 8c.2: a deactivated account is cut off on its very next request, whatever session or
        // remember-me cookie it still holds. Fails closed: the session is dropped and the answer is 401.
        if ($user !== null && $user->deactivated_at !== null) {
            auth()->guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
            }

            abort(401, 'This account has been deactivated.');
        }

        if ($user && $user->tenant) {
            TenantContext::set($user->tenant);

            app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant->id);
        }

        return $next($request);
    }
}
