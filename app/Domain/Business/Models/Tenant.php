<?php

namespace App\Domain\Business\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = ['name', 'slug', 'status', 'timezone', 'locale', 'settings', 'api_key'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }
}
