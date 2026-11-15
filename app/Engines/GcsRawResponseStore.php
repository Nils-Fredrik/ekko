<?php

namespace App\Engines;

use Carbon\CarbonImmutable;
use Google\Cloud\Storage\StorageClient;

final readonly class GcsRawResponseStore implements RawResponseStore
{
    public function __construct(private StorageClient $storage) {}

    public function put(int $tenantId, int $runId, CarbonImmutable $collectedAt, array $raw): string
    {
        $bucketName = config()->string('engines.raw_bucket');
        $month = $collectedAt->utc()->format('Y/m');
        $path = "{$tenantId}/{$month}/{$runId}.json";

        $this->storage->bucket($bucketName)->upload(json_encode($raw, JSON_THROW_ON_ERROR), [
            'name' => $path,
            'metadata' => ['contentType' => 'application/json'],
        ]);

        return "gs://{$bucketName}/{$path}";
    }
}
