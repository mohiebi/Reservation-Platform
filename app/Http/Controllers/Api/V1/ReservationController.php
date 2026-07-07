<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Services\ReservationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    public function store(Request $request, ReservationService $reservations): JsonResponse
    {
        $validated = $request->validate($this->rules());

        return response()->json([
            'data' => $reservations->create($validated),
        ], 201);
    }

    public function cancel(Request $request, Reservation $reservation, ReservationService $reservations): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json([
            'data' => $reservations->cancel($reservation, $validated['reason'] ?? 'customer_requested'),
        ]);
    }

    public function reschedule(Request $request, Reservation $reservation, ReservationService $reservations): JsonResponse
    {
        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            'timezone' => ['nullable', 'timezone'],
            'branch_id' => ['nullable', 'integer'],
            'staff_member_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'data' => $reservations->reschedule($reservation, $validated),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'business_id' => ['required', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'customer_id' => ['required_without:customer', 'integer'],
            'customer' => ['required_without:customer_id', 'array'],
            'customer.whatsapp_phone' => ['required_with:customer', 'string', 'max:40'],
            'customer.name' => ['nullable', 'string', 'max:255'],
            'customer.locale' => ['nullable', 'string', 'max:12'],
            'customer.timezone' => ['nullable', 'timezone'],
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => ['integer'],
            'staff_member_id' => ['nullable', 'integer'],
            'starts_at' => ['required', 'date'],
            'timezone' => ['nullable', 'timezone'],
            'source' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
