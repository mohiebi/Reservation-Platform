<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Service\Models\Service;
use App\Domain\Shared\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Service::query()
                ->when($request->integer('business_id'), fn ($query, int $businessId) => $query->where('business_id', $businessId))
                ->orderBy('name')
                ->paginate(),
        ]);
    }

    public function store(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $validated = $this->validated($request);

        $service = Service::query()->create([
            ...array_filter($validated, fn ($value): bool => $value !== null),
            'tenant_id' => $tenantContext->requireId(),
            'prep_minutes' => $validated['prep_minutes'] ?? 0,
            'cleanup_minutes' => $validated['cleanup_minutes'] ?? 0,
            'buffer_before_minutes' => $validated['buffer_before_minutes'] ?? 0,
            'buffer_after_minutes' => $validated['buffer_after_minutes'] ?? 0,
            'price' => $validated['price'] ?? 0,
            'currency' => $validated['currency'] ?? 'USD',
            'capacity' => $validated['capacity'] ?? 1,
            'approval_mode' => $validated['approval_mode'] ?? 'instant',
        ]);

        return response()->json(['data' => $service], 201);
    }

    public function show(Service $service): JsonResponse
    {
        return response()->json(['data' => $service->load(['category', 'staffMembers'])]);
    }

    public function update(Request $request, Service $service): JsonResponse
    {
        $service->update(array_filter($this->validated($request, partial: true), fn ($value): bool => $value !== null));

        return response()->json(['data' => $service->fresh()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $tenantId = app(TenantContext::class)->requireId();

        return $request->validate([
            'business_id' => [$required, 'integer', Rule::exists('businesses', 'id')->where('tenant_id', $tenantId)],
            'service_category_id' => ['nullable', 'integer', Rule::exists('service_categories', 'id')->where('tenant_id', $tenantId)],
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'duration_minutes' => [$required, 'integer', 'min:1', 'max:1440'],
            'prep_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'cleanup_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'buffer_before_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'buffer_after_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'approval_mode' => ['nullable', Rule::in(['instant', 'manual'])],
            'booking_rules' => ['nullable', 'array'],
            'status' => ['nullable', 'string', 'max:40'],
        ]);
    }
}
