<?php

namespace App\Domain\Service\Models;

use App\Domain\Business\Models\Business;
use App\Domain\Reservation\Models\ReservationItem;
use App\Domain\Shared\Tenancy\BelongsToTenant;
use App\Domain\Staff\Models\StaffMember;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'booking_rules' => 'array',
            'price' => 'decimal:2',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function staffMembers(): BelongsToMany
    {
        return $this->belongsToMany(StaffMember::class, 'staff_service')->withTimestamps();
    }

    public function reservationItems(): HasMany
    {
        return $this->hasMany(ReservationItem::class);
    }

    public function totalDurationMinutes(): int
    {
        return $this->prep_minutes
            + $this->buffer_before_minutes
            + $this->duration_minutes
            + $this->cleanup_minutes
            + $this->buffer_after_minutes;
    }
}
