<?php

namespace App\Work;

use Carbon\CarbonImmutable;

/**
 * A request to process one work signal no earlier than $notBefore: the contract between registration and
 * delivery (ADR 0032). It carries internal ids only. Delivery may happen more than once and late; the signal
 * row stays the source of truth, so a wake-up that finds nothing to do is harmless.
 *
 * key() is deterministic and safe as a Cloud Tasks task id (T40-F4): every signal of one pending epoch
 * (the same processed_version) with the same earliest time gets the same key, so they coalesce. A retry or
 * a deferral moves available_at and so gets a new key. A recovery wake-up adds its own suffix, because the
 * original id may still be reserved by a task that was delivered without finding the signal free.
 */
final readonly class WorkWakeUp
{
    public const REASON_SIGNAL = 'signal';

    public const REASON_REMAINING = 'remaining';

    public const REASON_RETRY = 'retry';

    public const REASON_DEFER = 'defer';

    public const REASON_RECOVERY = 'recovery';

    public function __construct(
        public int $tenantId,
        public int $signalId,
        public int $epoch,
        public CarbonImmutable $notBefore,
        public string $reason = self::REASON_SIGNAL,
        public ?CarbonImmutable $recoveredAt = null,
    ) {}

    public function key(): string
    {
        $key = 'ws-'.$this->tenantId.'-'.$this->signalId.'-'.$this->epoch.'-'.$this->notBefore->getTimestamp();

        return $this->recoveredAt === null ? $key : $key.'-r'.$this->recoveredAt->getTimestamp();
    }

    /**
     * What a delivery may carry (T40-F4): ids only, never answers, secrets or commands.
     *
     * @return array{tenant_id: int, signal_id: int}
     */
    public function payload(): array
    {
        return ['tenant_id' => $this->tenantId, 'signal_id' => $this->signalId];
    }
}
