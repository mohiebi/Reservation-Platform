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
        $token = $request->bearerToken();

        abort_if($token === null, 401, 'An API key is required.');

        $tenant = Tenant::query()
            ->where('api_key', hash('sha256', $token))
            ->where('status', 'active')
            ->first();

        abort_if($tenant === null, 401, 'Invalid or inactive API key.');

        app(TenantContext::class)->set($tenant);

        return $next($request);
    }
}
