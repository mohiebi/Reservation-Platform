<?php

namespace App\Domain\Staff\Models;

use App\Domain\Business\Models\Branch;
use App\Domain\Business\Models\Business;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Service\Models\Service;
use App\Domain\Shared\Tenancy\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StaffMember extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'business_id',
        'branch_id',
        'user_id',
        'name',
        'email',
        'phone',
        'status',
        'profile',
    ];

    protected function casts(): array
    {
        return [
            'profile' => 'array',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'staff_service')->withTimestamps();
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
