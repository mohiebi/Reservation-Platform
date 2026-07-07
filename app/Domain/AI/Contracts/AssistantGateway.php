<?php

namespace App\Domain\AI\Contracts;

interface AssistantGateway
{
    /**
     * @param  array<string, mixed>  $context
     * @return array{intent: string, confidence: float, entities: array<string, mixed>}
     */
    public function understand(string $message, array $context = []): array;
}
