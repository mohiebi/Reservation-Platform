<?php

namespace App\Domain\Customer\Models;

use App\Domain\Business\Models\Business;
use App\Domain\Conversation\Models\ConversationThread;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'consent' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function conversationThreads(): HasMany
    {
        return $this->hasMany(ConversationThread::class);
    }
}
