<?php

namespace App\Domain\Availability\Models;

use App\Domain\Business\Models\Business;
use App\Domain\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Resource extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'business_id',
        'branch_id',
        'name',
        'type',
        'capacity',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
