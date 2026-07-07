<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Availability\Services\AvailabilityService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function index(Request $request, AvailabilityService $availability): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => ['required', 'integer'],
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => ['integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'integer'],
            'staff_member_id' => ['nullable', 'integer'],
            'step_minutes' => ['nullable', 'integer', 'min:5', 'max:120'],
        ]);

        return response()->json([
            'data' => $availability->availableSlots(
                businessId: (int) $validated['business_id'],
                serviceIds: $validated['service_ids'],
                date: $validated['date'],
                branchId: $validated['branch_id'] ?? null,
                staffMemberId: $validated['staff_member_id'] ?? null,
                stepMinutes: $validated['step_minutes'] ?? 15,
            ),
        ]);
    }
}
