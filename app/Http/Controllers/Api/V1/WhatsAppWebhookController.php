<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Messaging\Jobs\ProcessWhatsAppWebhook;
use App\Domain\Webhooks\Models\WebhookEvent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        abort_if($request->query('hub.mode') !== 'subscribe', 403);
        abort_if($request->query('hub.verify_token') !== config('whatsapp.verify_token'), 403);

        return response($request->query('hub.challenge'));
    }

    public function handle(Request $request): JsonResponse
    {
        $this->verifySignature($request);

        $payload = $request->all();
        $eventId = $this->eventId($payload);

        $event = WebhookEvent::query()->firstOrCreate([
            'provider' => 'whatsapp',
            'event_id' => $eventId,
        ], [
            'payload' => $payload,
            'status' => 'pending',
        ]);

        if ($event->wasRecentlyCreated) {
            ProcessWhatsAppWebhook::dispatch($event->id);
        }

        return response()->json(['status' => 'accepted']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function eventId(array $payload): string
    {
        return data_get($payload, 'entry.0.changes.0.value.messages.0.id')
            ?? data_get($payload, 'entry.0.changes.0.value.statuses.0.id')
            ?? sha1(json_encode($payload));
    }

    private function verifySignature(Request $request): void
    {
        $secret = config('whatsapp.app_secret');

        if (! $secret) {
            return;
        }

        $signature = $request->header('X-Hub-Signature-256');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        abort_unless(is_string($signature) && hash_equals($expected, $signature), 403);
    }
}
