<?php

namespace App\Domain\Reservation\Models;

use App\Domain\Business\Models\Branch;
use App\Domain\Business\Models\Business;
use App\Domain\Customer\Models\Customer;
use App\Domain\Shared\Tenancy\BelongsToTenant;
use App\Domain\Staff\Models\StaffMember;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reservation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'business_id',
        'branch_id',
        'customer_id',
        'staff_member_id',
        'status',
        'approval_status',
        'starts_at',
        'ends_at',
        'timezone',
        'source',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReservationItem::class);
    }

    public function reservationResources(): HasMany
    {
        return $this->hasMany(ReservationResource::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(\App\Domain\Notification\Models\Reminder::class);
    }
}
