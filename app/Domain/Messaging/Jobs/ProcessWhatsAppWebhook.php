<?php

namespace App\Domain\Messaging\Jobs;

use App\Domain\Conversation\Services\ConversationService;
use App\Domain\Messaging\Models\WhatsAppPhoneNumber;
use App\Domain\Webhooks\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Throwable;

class ProcessWhatsAppWebhook implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $webhookEventId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(ConversationService $conversation): void
    {
        $event = WebhookEvent::query()->findOrFail($this->webhookEventId);
        $phoneNumberId = data_get($event->payload, 'entry.0.changes.0.value.metadata.phone_number_id');

        if (! $phoneNumberId) {
            $event->update(['status' => 'ignored', 'processed_at' => Carbon::now()]);

            return;
        }

        $phoneNumber = WhatsAppPhoneNumber::query()
            ->where('phone_number_id', $phoneNumberId)
            ->where('status', 'active')
            ->with(['tenant', 'business'])
            ->first();

        if (! $phoneNumber) {
            $event->update(['status' => 'unmapped', 'processed_at' => Carbon::now()]);

            return;
        }

        $event->update(['tenant_id' => $phoneNumber->tenant_id, 'status' => 'processing']);

        try {
            $conversation->handleInbound($phoneNumber, $event->payload);
            $event->update(['status' => 'processed', 'processed_at' => Carbon::now()]);
        } catch (Throwable $e) {
            $event->update(['status' => 'failed', 'processed_at' => Carbon::now()]);
            throw $e;
        }
    }
}
