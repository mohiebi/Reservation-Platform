<?php

namespace App\Domain\Availability\Services;

use App\Domain\Availability\DTOs\AvailabilitySlot;
use App\Domain\Availability\Models\Holiday;
use App\Domain\Availability\Models\WorkingHour;
use App\Domain\Business\Models\Business;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Models\ReservationResource;
use App\Domain\Service\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AvailabilityService
{
    /**
     * @param  array<int>  $serviceIds
     * @return array<int, array<string, mixed>>
     */
    public function availableSlots(
        int $businessId,
        array $serviceIds,
        string $date,
        ?int $branchId = null,
        ?int $staffMemberId = null,
        int $stepMinutes = 15,
    ): array {
        $business = Business::query()->findOrFail($businessId);
        $timezone = $business->default_timezone ?: 'UTC';
        $services = Service::query()
            ->where('business_id', $businessId)
            ->whereIn('id', $serviceIds)
            ->where('status', 'active')
            ->with(['staffMembers:id', 'resources'])
            ->get();

        abort_if($services->count() !== count(array_unique($serviceIds)), 422, 'One or more services are unavailable.');

        $duration = $services->sum(fn (Service $service): int => $service->totalDurationMinutes());
        $capacity = (int) ($services->min('capacity') ?? 1);
        $staffIds = $this->candidateStaffIds($services, $staffMemberId);
        $day = CarbonImmutable::parse($date, $timezone)->startOfDay();

        [$holidaySet, $workingHoursMap, $dayReservations, $resourceAllocations] =
            $this->prefetchDayData($businessId, $branchId, $staffIds, $day, $services);

        return collect($staffIds)
            ->flatMap(fn (?int $candidateStaffId): array => $this->slotsForStaff(
                businessId: $businessId,
                day: $day,
                durationMinutes: $duration,
                stepMinutes: $stepMinutes,
                branchId: $branchId,
                staffMemberId: $candidateStaffId,
                capacity: $capacity,
                holidaySet: $holidaySet,
                workingHoursMap: $workingHoursMap,
                dayReservations: $dayReservations,
                services: $services,
                resourceAllocations: $resourceAllocations,
            ))
            ->sortBy(fn (AvailabilitySlot $slot): string => $slot->startsAt->toIso8601String().'-'.$slot->staffMemberId)
            ->map(fn (AvailabilitySlot $slot): array => $slot->toArray())
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Service>  $services
     * @return array<int, int|null>
     */
    private function candidateStaffIds(Collection $services, ?int $staffMemberId): array
    {
        if ($staffMemberId !== null) {
            return [$staffMemberId];
        }

        $staffIds = $services
            ->flatMap(fn (Service $service): Collection => $service->staffMembers->pluck('id'))
            ->unique()
            ->values()
            ->all();

        return $staffIds ?: [null];
    }

    /**
     * Batch-load all data needed to compute slots for a given day.
     * Returns [holidaySet, workingHoursMap, dayReservations, resourceAllocations].
     *
     * @param  array<int, int|null>  $staffIds
     * @param  Collection<int, Service>  $services
     * @return array{array<string, true>, Collection<string, Collection>, Collection<int, Reservation>, Collection<int|string, Collection>}
     */
    private function prefetchDayData(
        int $businessId,
        ?int $branchId,
        array $staffIds,
        CarbonImmutable $day,
        Collection $services,
    ): array {
        // Build all owner pairs we need to load holidays and working hours for
        $ownerPairs = [['business', $businessId]];
        if ($branchId !== null) {
            $ownerPairs[] = ['branch', $branchId];
        }
        foreach (array_filter($staffIds, fn ($id) => $id !== null) as $sid) {
            $ownerPairs[] = ['staff_member', $sid];
        }

        // Holidays: one query for all relevant owners on this date
        $holidaySet = Holiday::query()
            ->where(function ($q) use ($ownerPairs): void {
                foreach ($ownerPairs as [$type, $id]) {
                    $q->orWhere(fn ($sq) => $sq->where('owner_type', $type)->where('owner_id', $id));
                }
            })
            ->whereDate('date', $day->toDateString())
            ->get(['owner_type', 'owner_id'])
            ->mapWithKeys(fn ($h): array => [$h->owner_type.':'.$h->owner_id => true])
            ->all();

        // Working hours: one query for all relevant owners on this weekday
        $workingHoursMap = WorkingHour::query()
            ->where(function ($q) use ($ownerPairs): void {
                foreach ($ownerPairs as [$type, $id]) {
                    $q->orWhere(fn ($sq) => $sq->where('owner_type', $type)->where('owner_id', $id));
                }
            })
            ->where('weekday', $day->dayOfWeek)
            ->orderBy('start_time')
            ->get()
            ->groupBy(fn ($wh): string => $wh->owner_type.':'.$wh->owner_id);

        // Reservations: one query covering the entire day for this business/branch
        $dayStart = $day->startOfDay()->utc();
        $dayEnd = $day->endOfDay()->utc();

        $dayReservations = Reservation::query()
            ->where('business_id', $businessId)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNotIn('status', ['cancelled', 'rejected', 'rescheduled'])
            ->where('starts_at', '<', $dayEnd)
            ->where('ends_at', '>', $dayStart)
            ->get(['starts_at', 'ends_at', 'staff_member_id']);

        // Resource allocations: one query for all resources needed by these services
        $resourceIds = $services
            ->flatMap(fn (Service $s): Collection => $s->resources->pluck('id'))
            ->unique()
            ->filter()
            ->values()
            ->all();

        $resourceAllocations = collect();
        if (! empty($resourceIds)) {
            $resourceAllocations = ReservationResource::query()
                ->whereIn('resource_id', $resourceIds)
                ->where('starts_at', '<', $dayEnd)
                ->where('ends_at', '>', $dayStart)
                ->get(['resource_id', 'starts_at', 'ends_at', 'quantity'])
                ->groupBy('resource_id');
        }

        return [$holidaySet, $workingHoursMap, $dayReservations, $resourceAllocations];
    }

    /**
     * @param  array<string, true>  $holidaySet
     * @param  Collection<string, Collection>  $workingHoursMap
     * @param  Collection<int, Reservation>  $dayReservations
     * @param  Collection<int, Service>  $services
     * @param  Collection<int|string, Collection>  $resourceAllocations
     * @return array<int, AvailabilitySlot>
     */
    private function slotsForStaff(
        int $businessId,
        CarbonImmutable $day,
        int $durationMinutes,
        int $stepMinutes,
        ?int $branchId,
        ?int $staffMemberId,
        int $capacity,
        array $holidaySet,
        Collection $workingHoursMap,
        Collection $dayReservations,
        Collection $services,
        Collection $resourceAllocations,
    ): array {
        if (isset($holidaySet['business:'.$businessId])) {
            return [];
        }
        if ($branchId !== null && isset($holidaySet['branch:'.$branchId])) {
            return [];
        }
        if ($staffMemberId !== null && isset($holidaySet['staff_member:'.$staffMemberId])) {
            return [];
        }

        $workingHours = $this->resolveWorkingHours($businessId, $branchId, $staffMemberId, $workingHoursMap);
        if ($workingHours->isEmpty()) {
            return [];
        }

        // Pre-filter reservations to only those relevant for this staff member
        $scopedReservations = $staffMemberId !== null
            ? $dayReservations->where('staff_member_id', $staffMemberId)
            : $dayReservations;

        // Build required resource needs for these services
        $requiredResources = $this->requiredResources($services);

        $slots = [];

        foreach ($workingHours as $window) {
            $cursor = $day->setTimeFromTimeString($window->start_time);
            $windowEnd = $day->setTimeFromTimeString($window->end_time);

            while ($cursor->addMinutes($durationMinutes)->lessThanOrEqualTo($windowEnd)) {
                $slotEnd = $cursor->addMinutes($durationMinutes);
                $slotStartUtc = $cursor->utc();
                $slotEndUtc = $slotEnd->utc();

                $conflictCount = $scopedReservations
                    ->filter(fn ($r): bool => $r->starts_at->lt($slotEndUtc) && $r->ends_at->gt($slotStartUtc))
                    ->count();

                if ($conflictCount < $capacity && $this->resourcesAvailable($slotStartUtc, $slotEndUtc, $requiredResources, $resourceAllocations)) {
                    $slots[] = new AvailabilitySlot($cursor, $slotEnd, $staffMemberId, $branchId);
                }

                $cursor = $cursor->addMinutes($stepMinutes);
            }
        }

        return $slots;
    }

    /**
     * @param  Collection<string, Collection>  $workingHoursMap
     * @return Collection<int, WorkingHour>
     */
    private function resolveWorkingHours(
        int $businessId,
        ?int $branchId,
        ?int $staffMemberId,
        Collection $workingHoursMap,
    ): Collection {
        $candidates = [
            ['staff_member', $staffMemberId],
            ['branch', $branchId],
            ['business', $businessId],
        ];

        foreach ($candidates as [$type, $id]) {
            if ($id === null) {
                continue;
            }
            $hours = $workingHoursMap->get($type.':'.$id, collect());
            if ($hours->isNotEmpty()) {
                return $hours;
            }
        }

        return collect();
    }

    /**
     * @param  Collection<int, Service>  $services
     * @return Collection<string|int, array{id: int, capacity: int, needed: int, name: string}>
     */
    private function requiredResources(Collection $services): Collection
    {
        return $services
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
    }

    /**
     * @param  Collection<string|int, array{id: int, capacity: int, needed: int, name: string}>  $requiredResources
     * @param  Collection<int|string, Collection>  $resourceAllocations
     */
    private function resourcesAvailable(
        CarbonImmutable $slotStartUtc,
        CarbonImmutable $slotEndUtc,
        Collection $requiredResources,
        Collection $resourceAllocations,
    ): bool {
        foreach ($requiredResources as $need) {
            $allocated = $resourceAllocations
                ->get($need['id'], collect())
                ->filter(fn ($a): bool => $a->starts_at->lt($slotEndUtc) && $a->ends_at->gt($slotStartUtc))
                ->sum('quantity');

            if ($allocated + $need['needed'] > $need['capacity']) {
                return false;
            }
        }

        return true;
    }
}
