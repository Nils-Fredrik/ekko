<?php

namespace App\Work;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Builds the scope of a work signal from internal identifiers (ADR 0032). Kept apart from free text on purpose:
 * the database checks the same shapes (work_signals_scope).
 */
final class WorkScope
{
    public const TENANT = 'tenant';

    public static function runBatch(int $runBatchId): string
    {
        return 'run_batch:'.$runBatchId;
    }

    /** The status check of one submitted model batch (extraction_jobs.id). */
    public static function extractionJob(int $extractionJobId): string
    {
        return 'extraction_job:'.$extractionJobId;
    }

    /** The id in a scope of the form "<type>:<id>", or null for another shape or type. */
    public static function id(string $scope, string $type): ?int
    {
        return str_starts_with($scope, $type.':') ? (int) substr($scope, strlen($type) + 1) : null;
    }

    /** The tenant-local day of an instant, as aggregation counts it (docs/spec.md 6.4). */
    public static function localDate(DateTimeInterface $instant, string $timezone): string
    {
        return DateTimeImmutable::createFromInterface($instant)->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');
    }

    /** The UTC month of an instant, as usage_monthly counts it (docs/spec.md 12.2). */
    public static function month(DateTimeInterface $instant): string
    {
        return DateTimeImmutable::createFromInterface($instant)->setTimezone(new DateTimeZone('UTC'))->format('Y-m');
    }

    /** The shape of a scope without its identifier, for logs and alerts: tenant, run_batch, date or month. */
    public static function type(string $scope): string
    {
        return match (true) {
            $scope === self::TENANT => 'tenant',
            str_contains($scope, ':') => strstr($scope, ':', true),
            self::isDate($scope, 'Y-m-d') => 'date',
            default => 'month',
        };
    }

    public static function isDate(string $scope, string $format): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!'.$format, $scope, new DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format($format) === $scope;
    }
}
