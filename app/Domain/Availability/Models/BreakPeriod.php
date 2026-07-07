<?php

namespace App\Domain\Availability\Models;

use App\Domain\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class BreakPeriod extends Model
{
    use BelongsToTenant;

    protected $table = 'breaks';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
