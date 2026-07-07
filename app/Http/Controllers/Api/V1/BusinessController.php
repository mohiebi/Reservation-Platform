<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\Models\Business;
use App\Domain\Shared\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Business::query()->latest()->paginate(),
        ]);
    }

    public function store(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'industry_type' => ['nullable', 'string', 'max:80'],
            'default_timezone' => ['nullable', 'timezone'],
            'branding' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
        ]);

        $business = Business::query()->create([
            ...$validated,
            'tenant_id' => $tenantContext->requireId(),
            'default_timezone' => $validated['default_timezone'] ?? 'UTC',
        ]);

        return response()->json(['data' => $business], 201);
    }

    public function show(Business $business): JsonResponse
    {
        return response()->json(['data' => $business->load(['branches', 'services', 'staffMembers'])]);
    }

    public function update(Request $request, Business $business): JsonResponse
    {
        $business->update($request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'industry_type' => ['nullable', 'string', 'max:80'],
            'default_timezone' => ['sometimes', 'timezone'],
            'branding' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
            'status' => ['sometimes', 'string', 'max:40'],
        ]));

        return response()->json(['data' => $business->fresh()]);
    }
}
