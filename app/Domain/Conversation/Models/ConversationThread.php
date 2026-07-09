<?php

namespace App\Domain\Conversation\Models;

use App\Domain\Business\Models\Business;
use App\Domain\Customer\Models\Customer;
use App\Domain\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConversationThread extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'business_id',
        'customer_id',
        'channel',
        'status',
        'assigned_user_id',
        'last_message_at',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'last_message_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
