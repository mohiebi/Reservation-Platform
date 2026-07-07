<?php

namespace App\Domain\Messaging\Jobs;

use App\Domain\Messaging\Contracts\MessageSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $phoneNumberId,
        public readonly string $to,
        public readonly string $text,
        public readonly array $payload = [],
    ) {
        $this->onQueue('messages');
    }

    public function handle(MessageSender $sender): void
    {
        $sender->sendText($this->phoneNumberId, $this->to, $this->text, $this->payload);
    }
}
