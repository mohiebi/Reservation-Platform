<?php

namespace App\Domain\Reservation\Models;

use App\Domain\Service\Models\Service;
use App\Domain\Shared\Tenancy\BelongsToTenant;
use App\Domain\Staff\Models\StaffMember;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'reservation_id',
        'service_id',
        'staff_member_id',
        'duration_minutes',
        'price',
        'resource_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'resource_snapshot' => 'array',
            'price' => 'decimal:2',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class);
    }
}
