<?php

namespace App\Work;

use Carbon\CarbonImmutable;

/**
 * A request to process one work signal no earlier than $notBefore: the contract between registration and
 * delivery (ADR 0032). It carries internal ids only. Delivery may happen more than once and late; the signal
 * row stays the source of truth, so a wake-up that finds nothing to do is harmless.
 *
 * key() is deterministic and safe as a Cloud Tasks task id (T40-F4): every signal of one pending epoch
 * (the same processed_version) with the same earliest time and reason gets the same key, so they coalesce. A retry
 * or a deferral has its own reason and time, and so its own key. A recovery wake-up adds the sweep time, because
 * the original id may still be reserved by a task that was delivered without finding the signal free.
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
        // Milliseconds and the reason: a deferral clamped to the time of the wake-up that ran it still gets its own key.
        $key = 'ws-'.$this->tenantId.'-'.$this->signalId.'-'.$this->epoch.'-'.$this->notBefore->getTimestampMs().'-'.$this->reason;

        return $this->recoveredAt === null ? $key : $key.'-'.$this->recoveredAt->getTimestampMs();
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
