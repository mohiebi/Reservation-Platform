<?php

namespace App\Domain\Shared\Tenancy;

use App\Domain\Business\Models\Tenant;

class TenantContext
{
    public function __construct(
        private ?Tenant $tenant = null,
    ) {}

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function requireId(): int
    {
        abort_if($this->tenant === null, 422, 'Tenant context is required.');

        return $this->tenant->id;
    }
}
