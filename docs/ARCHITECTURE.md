# WhatsApp Reservation Platform Architecture

This Laravel 13 application is structured as a modular monolith. The core domain lives under `app/Domain`, with each module owning its models, services, events, jobs, and contracts.

## Modules

- `Business`: tenants, businesses, branches, WhatsApp number ownership.
- `Customer`: tenant-scoped customer records and WhatsApp identities.
- `Service`: configurable categories, services, durations, buffers, pricing, and approval rules.
- `Availability`: working hours, holidays, breaks, and slot calculation.
- `Reservation`: transactional booking, rescheduling, cancellation, and reservation items.
- `Conversation`: WhatsApp conversation threads, inbound/outbound messages, intent routing.
- `Messaging`: WhatsApp webhook processing and outbound message sending contracts.
- `AI`: deterministic assistant gateway today, replaceable with an LLM-backed implementation.
- `Notification`: reminder scheduling.
- `Shared/Tenancy`: tenant context and global tenant scoping.

## Tenant Isolation

Tenant-owned tables include `tenant_id`. Tenant-aware models use `BelongsToTenant`, which applies a global scope when `TenantContext` is resolved. API routes use the `tenant` middleware and accept `X-Tenant-ID`, route tenant, or query tenant identifiers.

Webhook jobs resolve tenant context from `whatsapp_phone_numbers.phone_number_id`, so each business can own a dedicated Meta WhatsApp number.

## Booking Flow

1. Client calls `GET /api/v1/availability`.
2. `AvailabilityService` calculates slots from service duration, working hours, holidays, and existing reservations.
3. Client calls `POST /api/v1/reservations`.
4. `ReservationService` obtains a cache lock, starts a database transaction, checks conflicts, creates reservation records, emits `ReservationCreated`, and schedules reminders.

The conflict query prevents overlapping reservations for the selected staff member or branch. In production, Redis cache locks should be used across all app nodes.

## WhatsApp Flow

1. Meta calls `POST /api/v1/webhooks/whatsapp`.
2. The controller verifies the optional app-secret signature, stores an idempotent `webhook_events` row, and dispatches `ProcessWhatsAppWebhook`.
3. The job resolves the tenant/business from `phone_number_id`.
4. `ConversationService` upserts the customer and conversation, stores the inbound message, detects intent, records an outbound message, and queues `SendWhatsAppMessage`.

The current sender is `NullMessageSender` for safe local development. Bind `MessageSender` to a Meta Cloud API implementation for production.

## Queues

Use Redis queues and scale workers by queue group:

- `webhooks`
- `messages`
- `reservations`
- `notifications`
- `reminders`
- `ai`
- `reports`
- `billing`
- `integrations`

The Docker worker runs all queues by default. Larger installs should split worker processes by queue type.

## Production Stack

- PHP 8.4 FPM
- Laravel 13
- PostgreSQL 17
- Redis 7
- Nginx
- S3-compatible storage
- GitHub Actions CI

Recommended additions before launch:

- Laravel Sanctum or OAuth token enforcement on API routes.
- Horizon for queue monitoring.
- Pulse and Sentry for app health and tracing.
- Filament resources for business, staff, service, reservation, customer, and conversation management.
- Real `MessageSender` implementation for Meta WhatsApp Cloud API.
- Calendar/payment adapters using explicit provider contracts.
