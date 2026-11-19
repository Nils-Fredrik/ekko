<?php

namespace App\Work;

use Carbon\CarbonImmutable;

/**
 * A leased work signal (ADR 0032). The processor may acknowledge only $version, the version it leased: signals
 * that arrive while it runs raise the row's version above it and stay pending. The token proves the lease;
 * after the lease expires another processor can take the signal over, and this token no longer acknowledges.
 */
final readonly class WorkClaim
{
    public function __construct(
        public int $tenantId,
        public int $signalId,
        public WorkKind $kind,
        public string $scope,
        public int $version,
        public string $token,
        public int $attempt,
        public CarbonImmutable $leaseExpiresAt,
    ) {}
}
