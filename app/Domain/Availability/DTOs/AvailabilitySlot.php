<?php

namespace App\Domain\Availability\DTOs;

use Carbon\CarbonImmutable;

class AvailabilitySlot
{
    public function __construct(
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
        public readonly ?int $staffMemberId = null,
        public readonly ?int $branchId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'starts_at' => $this->startsAt->toIso8601String(),
            'ends_at' => $this->endsAt->toIso8601String(),
            'staff_member_id' => $this->staffMemberId,
            'branch_id' => $this->branchId,
        ];
    }
}
