<?php

namespace App\Domain\Messaging\Models;

use App\Domain\Business\Models\Business;
use App\Domain\Business\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppPhoneNumber extends Model
{
    protected $table = 'whatsapp_phone_numbers';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
