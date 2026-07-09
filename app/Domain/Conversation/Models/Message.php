<?php

namespace App\Domain\Conversation\Models;

use App\Domain\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'conversation_thread_id',
        'direction',
        'provider_message_id',
        'type',
        'text',
        'payload',
        'status',
        'sent_at',
        'delivered_at',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function conversationThread(): BelongsTo
    {
        return $this->belongsTo(ConversationThread::class);
    }
}
