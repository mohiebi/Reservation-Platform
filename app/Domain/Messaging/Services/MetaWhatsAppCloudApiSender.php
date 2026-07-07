<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Messaging\Contracts\MessageSender;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

class MetaWhatsAppCloudApiSender implements MessageSender
{
    public function __construct(private readonly HttpFactory $http) {}

    public function sendText(string $phoneNumberId, string $to, string $text, array $payload = []): void
    {
        $token = config('whatsapp.access_token');

        if (! $token) {
            throw new RuntimeException('WhatsApp access token is not configured.');
        }

        $response = $this->http
            ->withToken($token)
            ->acceptJson()
            ->post(rtrim(config('whatsapp.cloud_api_url'), '/')."/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $text,
                ],
            ]);

        $response->throw();
    }
}
