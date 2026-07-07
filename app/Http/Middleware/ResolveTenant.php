<?php

namespace App\Http\Middleware;

use App\Domain\Business\Models\Tenant;
use App\Domain\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $identifier = $request->header('X-Tenant-ID')
            ?? $request->route('tenant')
            ?? $request->query('tenant');

        if ($identifier) {
            $tenant = Tenant::query()
                ->where('id', $identifier)
                ->orWhere('slug', $identifier)
                ->firstOrFail();

            app(TenantContext::class)->set($tenant);
        }

        return $next($request);
    }
}
