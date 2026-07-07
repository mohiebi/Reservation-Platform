<?php

use App\Domain\Availability\Models\WorkingHour;
use App\Domain\Availability\Services\AvailabilityService;
use App\Domain\Business\Models\Business;
use App\Domain\Business\Models\Tenant;
use App\Domain\Customer\Models\Customer;
use App\Domain\Messaging\Jobs\ProcessWhatsAppWebhook;
use App\Domain\Messaging\Models\WhatsAppPhoneNumber;
use App\Domain\Notification\Models\Reminder;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Service\Models\Service;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('calculates tenant-scoped availability and excludes conflicting reservations', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    app(TenantContext::class)->set($tenant);

    $business = Business::query()->create(['name' => 'Clinic', 'default_timezone' => 'UTC']);
    $service = Service::query()->create([
        'business_id' => $business->id,
        'name' => 'Consultation',
        'duration_minutes' => 60,
    ]);

    WorkingHour::query()->create([
        'owner_type' => 'business',
        'owner_id' => $business->id,
        'weekday' => 3,
        'start_time' => '09:00',
        'end_time' => '12:00',
    ]);

    $customer = Customer::query()->create([
        'business_id' => $business->id,
        'whatsapp_phone' => '+15551234567',
    ]);

    Reservation::query()->create([
        'business_id' => $business->id,
        'customer_id' => $customer->id,
        'starts_at' => '2030-01-02 10:00:00',
        'ends_at' => '2030-01-02 11:00:00',
        'timezone' => 'UTC',
    ]);

    $slots = app(AvailabilityService::class)->availableSlots(
        businessId: $business->id,
        serviceIds: [$service->id],
        date: '2030-01-02',
        stepMinutes: 60,
    );

    expect(collect($slots)->pluck('starts_at')->all())
        ->toHaveCount(2)
        ->sequence(
            fn ($value) => $value->toContain('2030-01-02T09:00:00'),
            fn ($value) => $value->toContain('2030-01-02T11:00:00'),
        );
});

it('creates reservations through the tenant API and schedules reminders', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Tenant B', 'slug' => 'tenant-b']);
    app(TenantContext::class)->set($tenant);

    $business = Business::query()->create(['name' => 'Studio', 'default_timezone' => 'UTC']);
    $service = Service::query()->create([
        'business_id' => $business->id,
        'name' => 'Session',
        'duration_minutes' => 45,
    ]);

    $response = $this
        ->withHeader('X-Tenant-ID', $tenant->slug)
        ->postJson('/api/v1/reservations', [
            'business_id' => $business->id,
            'service_ids' => [$service->id],
            'starts_at' => '2030-01-02T14:00:00Z',
            'timezone' => 'UTC',
            'customer' => [
                'whatsapp_phone' => '+15557654321',
                'name' => 'Ava',
            ],
        ]);

    $response->assertCreated();

    expect(Reservation::query()->count())->toBe(1)
        ->and(Reminder::query()->count())->toBe(2);
});

it('stores WhatsApp webhooks idempotently and queues processing once', function (): void {
    Queue::fake();

    $tenant = Tenant::query()->create(['name' => 'Tenant C', 'slug' => 'tenant-c']);
    app(TenantContext::class)->set($tenant);
    $business = Business::query()->create(['name' => 'Salon', 'default_timezone' => 'UTC']);

    WhatsAppPhoneNumber::query()->create([
        'tenant_id' => $tenant->id,
        'business_id' => $business->id,
        'phone_number_id' => '123456',
        'display_phone_number' => '+15550000000',
    ]);

    $payload = [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => ['phone_number_id' => '123456'],
                    'messages' => [[
                        'id' => 'wamid.test-1',
                        'from' => '15551230000',
                        'type' => 'text',
                        'text' => ['body' => 'book appointment'],
                    ]],
                ],
            ]],
        ]],
    ];

    $this->postJson('/api/v1/webhooks/whatsapp', $payload)->assertOk();
    $this->postJson('/api/v1/webhooks/whatsapp', $payload)->assertOk();

    Queue::assertPushed(ProcessWhatsAppWebhook::class, 1);
});
