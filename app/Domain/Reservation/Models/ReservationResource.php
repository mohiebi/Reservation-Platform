<?php

namespace App\Domain\Reservation\Models;

use App\Domain\Availability\Models\Resource;
use App\Domain\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationResource extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'reservation_id',
        'resource_id',
        'starts_at',
        'ends_at',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
