<?php

namespace App\Alerts;

/**
 * A condition a detector found. subject identifies what it is about (for example market, engine and
 * period), and is what the cooldown looks at so a lasting condition is alerted once.
 */
final readonly class AlertCandidate
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public AlertType $type,
        public ?int $brandId,
        public string $subject,
        public array $payload,
    ) {}
}
