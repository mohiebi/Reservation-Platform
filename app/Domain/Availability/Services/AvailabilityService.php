<?php

namespace App\Domain\Availability\Services;

use App\Domain\Availability\DTOs\AvailabilitySlot;
use App\Domain\Availability\Models\Holiday;
use App\Domain\Availability\Models\WorkingHour;
use App\Domain\Business\Models\Business;
use App\Domain\Reservation\Models\Reservation;
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
            ->get();

        abort_if($services->count() !== count(array_unique($serviceIds)), 422, 'One or more services are unavailable.');

        $duration = $services->sum(fn (Service $service): int => $service->totalDurationMinutes());
        $staffIds = $this->candidateStaffIds($services, $staffMemberId);
        $day = CarbonImmutable::parse($date, $timezone)->startOfDay();

        return collect($staffIds)
            ->flatMap(fn (?int $candidateStaffId): array => $this->slotsForStaff(
                businessId: $businessId,
                day: $day,
                durationMinutes: $duration,
                stepMinutes: $stepMinutes,
                branchId: $branchId,
                staffMemberId: $candidateStaffId,
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
            ->flatMap(fn (Service $service): Collection => $service->staffMembers()->pluck('staff_members.id'))
            ->unique()
            ->values()
            ->all();

        return $staffIds ?: [null];
    }

    /**
     * @return array<int, AvailabilitySlot>
     */
    private function slotsForStaff(
        int $businessId,
        CarbonImmutable $day,
        int $durationMinutes,
        int $stepMinutes,
        ?int $branchId,
        ?int $staffMemberId,
    ): array {
        if ($this->isHoliday('business', $businessId, $day) ||
            ($branchId !== null && $this->isHoliday('branch', $branchId, $day)) ||
            ($staffMemberId !== null && $this->isHoliday('staff_member', $staffMemberId, $day))) {
            return [];
        }

        $workingHours = $this->workingHours($businessId, $day, $branchId, $staffMemberId);
        $slots = [];

        foreach ($workingHours as $window) {
            $cursor = $day->setTimeFromTimeString($window->start_time);
            $windowEnd = $day->setTimeFromTimeString($window->end_time);

            while ($cursor->addMinutes($durationMinutes)->lessThanOrEqualTo($windowEnd)) {
                $slotEnd = $cursor->addMinutes($durationMinutes);

                if (! $this->hasReservationConflict($businessId, $cursor, $slotEnd, $staffMemberId, $branchId)) {
                    $slots[] = new AvailabilitySlot($cursor, $slotEnd, $staffMemberId, $branchId);
                }

                $cursor = $cursor->addMinutes($stepMinutes);
            }
        }

        return $slots;
    }

    /**
     * @return Collection<int, WorkingHour>
     */
    private function workingHours(int $businessId, CarbonImmutable $day, ?int $branchId, ?int $staffMemberId): Collection
    {
        $ownerCandidates = [
            ['staff_member', $staffMemberId],
            ['branch', $branchId],
            ['business', $businessId],
        ];

        foreach ($ownerCandidates as [$ownerType, $ownerId]) {
            if ($ownerId === null) {
                continue;
            }

            $hours = WorkingHour::query()
                ->where('owner_type', $ownerType)
                ->where('owner_id', $ownerId)
                ->where('weekday', $day->dayOfWeek)
                ->orderBy('start_time')
                ->get();

            if ($hours->isNotEmpty()) {
                return $hours;
            }
        }

        return collect();
    }

    private function isHoliday(string $ownerType, int $ownerId, CarbonImmutable $day): bool
    {
        return Holiday::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->whereDate('date', $day->toDateString())
            ->exists();
    }

    private function hasReservationConflict(
        int $businessId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?int $staffMemberId,
        ?int $branchId,
    ): bool {
        return Reservation::query()
            ->where('business_id', $businessId)
            ->when($staffMemberId !== null, fn ($query) => $query->where('staff_member_id', $staffMemberId))
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->where('starts_at', '<', $endsAt->utc())
            ->where('ends_at', '>', $startsAt->utc())
            ->exists();
    }
}
