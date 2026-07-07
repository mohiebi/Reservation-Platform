<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Messaging\Contracts\MessageSender;
use Illuminate\Support\Facades\Log;

class NullMessageSender implements MessageSender
{
    public function sendText(string $phoneNumberId, string $to, string $text, array $payload = []): void
    {
        Log::info('WhatsApp message suppressed by null sender.', [
            'phone_number_id' => $phoneNumberId,
            'to' => $to,
            'text' => $text,
            'payload' => $payload,
        ]);
    }
}
