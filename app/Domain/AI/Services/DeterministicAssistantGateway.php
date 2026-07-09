<?php

namespace App\Domain\AI\Services;

use App\Domain\AI\Contracts\AssistantGateway;
use Illuminate\Support\Str;

class DeterministicAssistantGateway implements AssistantGateway
{
    public function understand(string $message, array $context = []): array
    {
        $text = Str::lower($message);

        $intent = match (true) {
            Str::contains($text, ['cancel']) => 'cancel_reservation',
            Str::contains($text, ['reschedule', 'change time', 'move']) => 'reschedule_reservation',
            Str::contains($text, ['book', 'appointment', 'reserve']) => 'book_appointment',
            Str::contains($text, ['price', 'cost']) => 'ask_price',
            Str::contains($text, ['hour', 'open', 'close']) => 'ask_hours',
            Str::contains($text, ['location', 'address', 'direction']) => 'ask_location',
            Str::contains($text, ['human', 'agent', 'person']) => 'talk_to_human',
            default => 'unknown',
        };

        return [
            'intent' => $intent,
            'confidence' => $intent === 'unknown' ? 0.0 : 1.0,
            'entities' => [],
            'source' => 'deterministic',
        ];
    }
}
