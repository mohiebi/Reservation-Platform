<?php

namespace App\Providers;

use App\Domain\AI\Contracts\AssistantGateway;
use App\Domain\AI\Services\DeterministicAssistantGateway;
use App\Domain\Messaging\Contracts\MessageSender;
use App\Domain\Messaging\Services\MetaWhatsAppCloudApiSender;
use App\Domain\Messaging\Services\NullMessageSender;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;

class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->bind(AssistantGateway::class, DeterministicAssistantGateway::class);
        $this->app->bind(MessageSender::class, config('whatsapp.access_token')
            ? MetaWhatsAppCloudApiSender::class
            : NullMessageSender::class);
    }
}
