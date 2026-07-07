<?php

namespace App\Domain\Conversation\Services;

use App\Domain\AI\Contracts\AssistantGateway;
use App\Domain\Business\Models\Business;
use App\Domain\Conversation\Events\InboundMessageReceived;
use App\Domain\Conversation\Models\ConversationThread;
use App\Domain\Conversation\Models\Message;
use App\Domain\Customer\Models\Customer;
use App\Domain\Messaging\Jobs\SendWhatsAppMessage;
use App\Domain\Messaging\Models\WhatsAppPhoneNumber;
use App\Domain\Service\Models\Service;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

class ConversationService
{
    public function __construct(
        private readonly AssistantGateway $assistant,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleInbound(WhatsAppPhoneNumber $phoneNumber, array $payload): void
    {
        $this->tenantContext->set($phoneNumber->tenant);

        foreach ($this->extractMessages($payload) as $inbound) {
            $customer = Customer::query()->firstOrCreate([
                'business_id' => $phoneNumber->business_id,
                'whatsapp_phone' => $inbound['from'],
            ], [
                'name' => $inbound['profile_name'] ?? null,
                'consent' => ['transactional' => true],
            ]);

            $thread = ConversationThread::query()->firstOrCreate([
                'business_id' => $phoneNumber->business_id,
                'customer_id' => $customer->id,
                'channel' => 'whatsapp',
                'status' => 'open',
            ], [
                'last_message_at' => now(),
                'context' => [],
            ]);

            /** @var Message|null $message */
            $message = Message::query()->firstOrCreate([
                'provider_message_id' => $inbound['id'],
            ], [
                'conversation_thread_id' => $thread->id,
                'direction' => 'inbound',
                'type' => $inbound['type'],
                'text' => $inbound['text'],
                'payload' => $inbound['raw'],
                'status' => 'received',
            ]);

            $thread->update(['last_message_at' => Carbon::now()]);

            if (! $message->wasRecentlyCreated) {
                continue;
            }

            event(new InboundMessageReceived($message));
            $this->reply($phoneNumber, $thread, $message, $phoneNumber->business);
        }
    }

    private function reply(WhatsAppPhoneNumber $phoneNumber, ConversationThread $thread, Message $message, Business $business): void
    {
        $understanding = $this->assistant->understand($message->text ?? '', $thread->context ?? []);

        $reply = match ($understanding['intent']) {
            'book_appointment' => $this->bookAppointmentPrompt($business),
            'ask_price' => $this->serviceList($business, prefix: 'Here are our current services and prices:'),
            'ask_hours' => 'I can help with opening hours. Please choose a branch or ask for a specific day.',
            'ask_location' => 'I can share location details. Please tell me which branch you want to visit.',
            'cancel_reservation' => 'I can cancel a reservation. Please send the reservation code or appointment date.',
            'reschedule_reservation' => 'I can reschedule that. Please send the reservation code and your preferred new date.',
            'talk_to_human' => 'I have notified the team. A human will continue this conversation soon.',
            default => 'Hi. I can help you book, reschedule, cancel, or answer questions about services.',
        };

        $thread->messages()->create([
            'direction' => 'outbound',
            'type' => 'text',
            'text' => $reply,
            'status' => 'queued',
            'payload' => ['intent' => $understanding],
        ]);

        SendWhatsAppMessage::dispatch(
            $phoneNumber->phone_number_id,
            $thread->customer->whatsapp_phone,
            $reply,
            ['thread_id' => $thread->id],
        );
    }

    private function bookAppointmentPrompt(Business $business): string
    {
        return $this->serviceList($business, prefix: 'Sure. Which service would you like to book?');
    }

    private function serviceList(Business $business, string $prefix): string
    {
        $services = Service::query()
            ->where('business_id', $business->id)
            ->where('status', 'active')
            ->orderBy('name')
            ->limit(10)
            ->get();

        if ($services->isEmpty()) {
            return 'This business has not published services yet. I can ask the team to follow up.';
        }

        $lines = $services->map(fn (Service $service): string => sprintf(
            '- %s (%s min, %s %s)',
            $service->name,
            $service->duration_minutes,
            $service->price,
            $service->currency,
        ));

        return $prefix."\n".$lines->implode("\n");
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function extractMessages(array $payload): array
    {
        $messages = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $contacts = collect($value['contacts'] ?? [])->keyBy('wa_id');

                foreach ($value['messages'] ?? [] as $message) {
                    $from = $message['from'] ?? null;

                    if (! $from) {
                        continue;
                    }

                    $messages[] = [
                        'id' => $message['id'] ?? sha1(json_encode($message)),
                        'from' => $from,
                        'profile_name' => data_get($contacts->get($from), 'profile.name'),
                        'type' => $message['type'] ?? 'unknown',
                        'text' => data_get($message, 'text.body'),
                        'raw' => $message,
                    ];
                }
            }
        }

        return $messages;
    }
}
