<?php

namespace App\Domain\Reservation\Services;

use App\Domain\Business\Models\Business;
use App\Domain\Customer\Models\Customer;
use App\Domain\Notification\Services\ReminderService;
use App\Domain\Reservation\Events\ReservationCancelled;
use App\Domain\Reservation\Events\ReservationCreated;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Models\ReservationResource;
use App\Domain\Service\Models\Service;
use App\Domain\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
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
            ->with('resources')
            ->get();

        abort_if($services->count() !== count(array_unique($serviceIds)), 422, 'One or more services are unavailable.');

        $durationMinutes = $services->sum(fn (Service $service): int => $service->totalDurationMinutes());
        $capacity = (int) ($services->min('capacity') ?? 1);
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

        return Cache::lock($lockKey, 10)->block(5, function () use ($data, $services, $startsAt, $endsAt, $staffMemberId, $capacity): Reservation {
            return DB::transaction(function () use ($data, $services, $startsAt, $endsAt, $staffMemberId, $capacity): Reservation {
                $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;

                $this->ensureSlotIsFree(
                    businessId: (int) $data['business_id'],
                    startsAt: $startsAt,
                    endsAt: $endsAt,
                    staffMemberId: $staffMemberId ? (int) $staffMemberId : null,
                    branchId: $branchId,
                    capacity: $capacity,
                    services: $services,
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

                    foreach ($service->resources as $resource) {
                        $reservation->reservationResources()->create([
                            'resource_id' => $resource->id,
                            'starts_at' => $startsAt->utc(),
                            'ends_at' => $endsAt->utc(),
                            'quantity' => $resource->pivot->quantity_required,
                        ]);
                    }
                }

                event(new ReservationCreated($reservation));
                $this->reminders->scheduleForReservation($reservation);

                return $reservation->fresh(['customer', 'items.service']);
            });
        });
    }

    public function cancel(Reservation $reservation, string $reason = 'customer_requested'): Reservation
    {
        abort_if(
            in_array($reservation->status, ['cancelled', 'rejected', 'rescheduled'], true),
            422,
            'This reservation cannot be cancelled in its current state.'
        );

        $reservation->update([
            'status' => 'cancelled',
            'metadata' => array_merge($reservation->metadata ?? [], ['cancellation_reason' => $reason]),
        ]);

        $reservation->reminders()->where('status', 'pending')->update(['status' => 'cancelled']);

        event(new ReservationCancelled($reservation));

        return $reservation->fresh(['customer', 'items.service']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function reschedule(Reservation $reservation, array $data): Reservation
    {
        abort_if(
            in_array($reservation->status, ['cancelled', 'rejected', 'rescheduled'], true),
            422,
            'This reservation cannot be rescheduled in its current state.'
        );

        $reservation->reminders()->where('status', 'pending')->update(['status' => 'cancelled']);

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

    /**
     * @param  Collection<int, Service>  $services
     */
    private function ensureSlotIsFree(
        int $businessId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?int $staffMemberId,
        ?int $branchId,
        int $capacity,
        Collection $services,
    ): void {
        $conflictCount = Reservation::query()
            ->where('business_id', $businessId)
            ->when($staffMemberId !== null, fn ($query) => $query->where('staff_member_id', $staffMemberId))
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->whereNotIn('status', ['cancelled', 'rejected', 'rescheduled'])
            ->where('starts_at', '<', $endsAt->utc())
            ->where('ends_at', '>', $startsAt->utc())
            ->count();

        abort_if($conflictCount >= $capacity, 409, 'The selected slot is no longer available.');

        $this->ensureResourcesAvailable($services, $startsAt, $endsAt);
    }

    /**
     * @param  Collection<int, Service>  $services
     */
    private function ensureResourcesAvailable(Collection $services, CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        $resourceNeeds = $services
            ->flatMap(fn (Service $s): Collection => $s->resources->map(fn ($r): array => [
                'id' => $r->id,
                'capacity' => (int) $r->capacity,
                'needed' => (int) $r->pivot->quantity_required,
                'name' => $r->name,
            ]))
            ->groupBy('id')
            ->map(fn (Collection $group): array => [
                'id' => $group->first()['id'],
                'capacity' => $group->first()['capacity'],
                'needed' => $group->sum('needed'),
                'name' => $group->first()['name'],
            ]);

        if ($resourceNeeds->isEmpty()) {
            return;
        }

        $allocated = ReservationResource::query()
            ->whereIn('resource_id', $resourceNeeds->keys())
            ->whereHas('reservation', fn ($q) => $q->whereNotIn('status', ['cancelled', 'rejected', 'rescheduled']))
            ->where('starts_at', '<', $endsAt->utc())
            ->where('ends_at', '>', $startsAt->utc())
            ->selectRaw('resource_id, SUM(quantity) as total_allocated')
            ->groupBy('resource_id')
            ->pluck('total_allocated', 'resource_id');

        foreach ($resourceNeeds as $need) {
            $currentAllocation = (int) $allocated->get($need['id'], 0);
            abort_if(
                $currentAllocation + $need['needed'] > $need['capacity'],
                409,
                "Resource '{$need['name']}' is not available for the selected time slot."
            );
        }
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
