<?php

namespace App\Tenancy;

use DomainException;

/**
 * Thrown when a partner relation breaks the one-level rule (docs/spec.md 5.1).
 */
final class InvalidPartnerException extends DomainException
{
    public static function self(): self
    {
        return new self('A tenant cannot be its own partner.');
    }

    public static function missing(int $partnerTeamId): self
    {
        return new self("Partner tenant {$partnerTeamId} does not exist.");
    }

    public static function nested(): self
    {
        return new self('A partner tenant cannot itself have a partner.');
    }

    public static function hasClients(): self
    {
        return new self('A tenant with client tenants cannot get a partner.');
    }
}
