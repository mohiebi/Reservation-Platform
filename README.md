# WhatsApp Reservation Platform

A Laravel 13 SaaS foundation for businesses that manage reservations through WhatsApp. The platform is designed as a reusable, multi-tenant reservation system rather than a bot for one specific industry.

Customers can discover services, ask questions, book, reschedule, cancel, and receive reminders inside WhatsApp. Business owners manage tenants, services, customers, reservations, conversations, and settings through the web/API layer.

## Stack

- Laravel 13
- PHP 8.4
- React + TypeScript + Inertia
- PostgreSQL
- Redis queues/cache/locks
- Meta WhatsApp Cloud API
- Docker, Docker Compose, Nginx
- Pest, Pint, Larastan

## Current Implementation

The project currently includes the production-shaped backend spine:

- Tenant-aware domain architecture under `app/Domain`
- Tenant context and tenant middleware
- Core SaaS migration for businesses, branches, staff, services, availability, reservations, conversations, messages, reminders, payments, invoices, and audit logs
- Availability engine
- Transactional reservation creation with conflict prevention
- Reservation cancellation and rescheduling
- WhatsApp webhook verification, idempotent storage, and queued processing
- Conversation engine with deterministic intent detection placeholder
- Meta WhatsApp sender contract with safe null-sender fallback
- Docker deployment scaffold
- GitHub Actions CI scaffold
- Focused feature tests for availability, booking, and WhatsApp webhook idempotency

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for module boundaries and runtime flow.

## Quick Start

Copy the environment file:

```bash
cp .env.example .env
```

Install dependencies:

```bash
composer install
npm install
```

Generate the app key and run migrations:

```bash
php artisan key:generate
php artisan migrate
```

Start the Laravel/Vite development workflow:

```bash
composer dev
```

## Docker Setup

The repository includes a production-oriented local Docker stack:

```bash
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

The app is served by Nginx on:

```text
http://localhost:8080
```

Main services:

- `app`: PHP-FPM Laravel app
- `nginx`: web server
- `worker`: queue workers
- `scheduler`: Laravel scheduler
- `postgres`: database
- `redis`: cache, queue, and locks

## Environment

Important values in `.env`:

```env
APP_NAME="Reservation Platform"
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=reservation_platform
DB_USERNAME=reservation
DB_PASSWORD=reservation

CACHE_STORE=redis
QUEUE_CONNECTION=redis
FILESYSTEM_DISK=s3

WHATSAPP_CLOUD_API_URL=https://graph.facebook.com/v21.0
WHATSAPP_ACCESS_TOKEN=
WHATSAPP_VERIFY_TOKEN=
WHATSAPP_APP_SECRET=
```

If `WHATSAPP_ACCESS_TOKEN` is empty, outbound WhatsApp messages use `NullMessageSender` and are logged instead of sent. This keeps local development safe.

## API

All tenant-scoped API requests should include:

```http
X-Tenant-ID: tenant-slug-or-id
```

Main endpoints:

```text
POST   /api/v1/tenants
GET    /api/v1/businesses
POST   /api/v1/businesses
GET    /api/v1/services
POST   /api/v1/services
GET    /api/v1/availability
POST   /api/v1/reservations
POST   /api/v1/reservations/{reservation}/cancel
POST   /api/v1/reservations/{reservation}/reschedule
GET    /api/v1/webhooks/whatsapp
POST   /api/v1/webhooks/whatsapp
```

Example tenant bootstrap:

```bash
curl -X POST http://localhost:8080/api/v1/tenants \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Demo Tenant",
    "slug": "demo",
    "timezone": "UTC",
    "business": {
      "name": "Demo Studio"
    }
  }'
```

Example service creation:

```bash
curl -X POST http://localhost:8080/api/v1/services \
  -H "Content-Type: application/json" \
  -H "X-Tenant-ID: demo" \
  -d '{
    "business_id": 1,
    "name": "Consultation",
    "duration_minutes": 60,
    "price": 50,
    "currency": "USD"
  }'
```

Example reservation:

```bash
curl -X POST http://localhost:8080/api/v1/reservations \
  -H "Content-Type: application/json" \
  -H "X-Tenant-ID: demo" \
  -d '{
    "business_id": 1,
    "service_ids": [1],
    "starts_at": "2030-01-02T14:00:00Z",
    "timezone": "UTC",
    "customer": {
      "whatsapp_phone": "+15551234567",
      "name": "Ava"
    }
  }'
```

## WhatsApp Webhook

Configure Meta WhatsApp Cloud API to point to:

```text
GET  /api/v1/webhooks/whatsapp
POST /api/v1/webhooks/whatsapp
```

Webhook verification uses:

```env
WHATSAPP_VERIFY_TOKEN=
```

Webhook signature validation is enabled when this is configured:

```env
WHATSAPP_APP_SECRET=
```

Each WhatsApp number must be mapped to a tenant/business in `whatsapp_phone_numbers` using Meta's `phone_number_id`.

## Tests

Run the focused reservation platform tests:

```bash
php artisan test tests/Feature/ReservationPlatformTest.php
```

Run formatting:

```bash
./vendor/bin/pint
```

Run static analysis:

```bash
./vendor/bin/phpstan analyse
```

## Architecture Notes

The system is a modular monolith. That is intentional: it keeps the codebase maintainable while the product is young, but the modules are separated enough to extract high-pressure areas later.

Core modules:

- `Business`
- `Customer`
- `Staff`
- `Service`
- `Availability`
- `Reservation`
- `Conversation`
- `Messaging`
- `Notification`
- `Automation`
- `Billing`
- `AI`
- `Audit`

The AI layer does not directly mutate critical business data. It detects intent and extracts entities, then deterministic services validate and execute reservation actions.

## Roadmap

Recommended next steps:

- Add Sanctum/API authentication enforcement
- Add Filament admin resources
- Add staff/branch/resource API endpoints
- Add real WhatsApp template-message support
- Add human handoff dashboard
- Add Horizon, Pulse, and Sentry
- Add Google/Outlook calendar adapters
- Add Stripe/PayPal payment adapters
- Add subscription plans and tenant limits
- Add PostgreSQL exclusion constraints for stronger production-grade double-booking prevention

