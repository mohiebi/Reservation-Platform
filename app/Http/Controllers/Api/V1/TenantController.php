<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\Models\Business;
use App\Domain\Business\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:80', Rule::unique('tenants', 'slug')],
            'timezone' => ['nullable', 'timezone'],
            'locale' => ['nullable', 'string', 'max:12'],
            'business.name' => ['required', 'string', 'max:255'],
            'business.industry_type' => ['nullable', 'string', 'max:80'],
        ]);

        $result = DB::transaction(function () use ($validated): array {
            $tenant = Tenant::query()->create([
                'name' => $validated['name'],
                'slug' => $validated['slug'] ?? Str::slug($validated['name']).'-'.Str::lower(Str::random(6)),
                'timezone' => $validated['timezone'] ?? 'UTC',
                'locale' => $validated['locale'] ?? 'en',
            ]);

            $business = Business::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'name' => $validated['business']['name'],
                'industry_type' => $validated['business']['industry_type'] ?? null,
                'default_timezone' => $validated['timezone'] ?? 'UTC',
            ]);

            return compact('tenant', 'business');
        });

        return response()->json($result, 201);
    }
}
