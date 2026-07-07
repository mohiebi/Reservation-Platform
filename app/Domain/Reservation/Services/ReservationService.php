<?php

namespace App\Domain\Reservation\Services;

use App\Domain\Business\Models\Business;
use App\Domain\Customer\Models\Customer;
use App\Domain\Notification\Services\ReminderService;
use App\Domain\Reservation\Events\ReservationCancelled;
use App\Domain\Reservation\Events\ReservationCreated;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Service\Models\Service;
use App\Domain\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReservationService
{
    public function __construct(
        private readonly ReminderService $reminders,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Reservation
    {
        $tenantId = $this->tenantContext->requireId();
        $serviceIds = collect($data['service_ids'])->map(fn ($id): int => (int) $id)->all();
        $startsAt = CarbonImmutable::parse($data['starts_at'], $data['timezone'] ?? 'UTC');
        Business::query()->findOrFail($data['business_id']);

        $services = Service::query()
            ->where('business_id', $data['business_id'])
            ->whereIn('id', $serviceIds)
            ->get();

        abort_if($services->count() !== count(array_unique($serviceIds)), 422, 'One or more services are unavailable.');

        $durationMinutes = $services->sum(fn (Service $service): int => $service->totalDurationMinutes());
        $endsAt = $startsAt->addMinutes($durationMinutes);
        $staffMemberId = $data['staff_member_id'] ?? null;
        $lockKey = implode(':', [
            'tenant',
            $tenantId,
            'reservation',
            $data['business_id'],
            $staffMemberId ?: 'capacity',
            $startsAt->utc()->format('YmdHi'),
        ]);

        return Cache::lock($lockKey, 10)->block(5, function () use ($data, $services, $startsAt, $endsAt, $staffMemberId): Reservation {
            return DB::transaction(function () use ($data, $services, $startsAt, $endsAt, $staffMemberId): Reservation {
                $this->ensureSlotIsFree(
                    businessId: (int) $data['business_id'],
                    startsAt: $startsAt,
                    endsAt: $endsAt,
                    staffMemberId: $staffMemberId ? (int) $staffMemberId : null,
                    branchId: isset($data['branch_id']) ? (int) $data['branch_id'] : null,
                );

                $customer = $this->resolveCustomer($data);
                $approvalMode = $services->contains(fn (Service $service): bool => $service->approval_mode === 'manual')
                    ? 'pending'
                    : 'approved';

                /** @var Reservation $reservation */
                $reservation = Reservation::query()->create([
                    'business_id' => $data['business_id'],
                    'branch_id' => $data['branch_id'] ?? null,
                    'customer_id' => $customer->id,
                    'staff_member_id' => $staffMemberId,
                    'status' => $approvalMode === 'pending' ? 'pending_approval' : 'confirmed',
                    'approval_status' => $approvalMode,
                    'starts_at' => $startsAt->utc(),
                    'ends_at' => $endsAt->utc(),
                    'timezone' => $data['timezone'] ?? 'UTC',
                    'source' => $data['source'] ?? 'dashboard',
                    'notes' => $data['notes'] ?? null,
                    'metadata' => $data['metadata'] ?? null,
                ]);

                foreach ($services as $service) {
                    $reservation->items()->create([
                        'service_id' => $service->id,
                        'staff_member_id' => $staffMemberId,
                        'duration_minutes' => $service->totalDurationMinutes(),
                        'price' => $service->price,
                    ]);
                }

                event(new ReservationCreated($reservation));
                $this->reminders->scheduleForReservation($reservation);

                return $reservation->fresh(['customer', 'items.service']);
            });
        });
    }

    public function cancel(Reservation $reservation, string $reason = 'customer_requested'): Reservation
    {
        $reservation->update([
            'status' => 'cancelled',
            'metadata' => array_merge($reservation->metadata ?? [], ['cancellation_reason' => $reason]),
        ]);

        event(new ReservationCancelled($reservation));

        return $reservation->fresh(['customer', 'items.service']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function reschedule(Reservation $reservation, array $data): Reservation
    {
        $newReservation = $this->create([
            'business_id' => $reservation->business_id,
            'branch_id' => $data['branch_id'] ?? $reservation->branch_id,
            'customer_id' => $reservation->customer_id,
            'staff_member_id' => $data['staff_member_id'] ?? $reservation->staff_member_id,
            'service_ids' => $reservation->items()->pluck('service_id')->all(),
            'starts_at' => $data['starts_at'],
            'timezone' => $data['timezone'] ?? $reservation->timezone,
            'source' => $data['source'] ?? 'dashboard',
            'metadata' => ['rescheduled_from_id' => $reservation->id],
        ]);

        $reservation->update(['status' => 'rescheduled']);

        return $newReservation;
    }

    private function ensureSlotIsFree(int $businessId, CarbonImmutable $startsAt, CarbonImmutable $endsAt, ?int $staffMemberId, ?int $branchId): void
    {
        $conflict = Reservation::query()
            ->where('business_id', $businessId)
            ->when($staffMemberId !== null, fn ($query) => $query->where('staff_member_id', $staffMemberId))
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->whereNotIn('status', ['cancelled', 'rejected', 'rescheduled'])
            ->where('starts_at', '<', $endsAt->utc())
            ->where('ends_at', '>', $startsAt->utc())
            ->exists();

        abort_if($conflict, 409, 'The selected slot is no longer available.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveCustomer(array $data): Customer
    {
        if (isset($data['customer_id'])) {
            return Customer::query()->findOrFail($data['customer_id']);
        }

        return Customer::query()->firstOrCreate(
            [
                'business_id' => $data['business_id'],
                'whatsapp_phone' => $data['customer']['whatsapp_phone'],
            ],
            [
                'name' => $data['customer']['name'] ?? null,
                'locale' => $data['customer']['locale'] ?? null,
                'timezone' => $data['customer']['timezone'] ?? null,
                'consent' => $data['customer']['consent'] ?? ['transactional' => true],
            ],
        );
    }
}
