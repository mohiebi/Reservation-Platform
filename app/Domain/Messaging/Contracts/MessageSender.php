<?php

namespace App\Domain\Messaging\Contracts;

interface MessageSender
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function sendText(string $phoneNumberId, string $to, string $text, array $payload = []): void;
}
