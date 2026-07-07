<?php

namespace App\Domain\Conversation\Events;

use App\Domain\Conversation\Models\Message;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InboundMessageReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Message $message) {}
}
