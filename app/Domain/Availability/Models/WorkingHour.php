<?php

namespace App\Domain\Availability\Models;

use App\Domain\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WorkingHour extends Model
{
    use BelongsToTenant;

    protected $guarded = [];
}
