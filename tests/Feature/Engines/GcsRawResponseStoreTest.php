<?php

use App\Engines\GcsRawResponseStore;
use App\Engines\RawResponseStore;
use Carbon\CarbonImmutable;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\StorageObject;

it('uploads the provider JSON to the configured bucket under the tenant and UTC month', function () {
    config(['engines.raw_bucket' => 'test-raw']);
    $raw = ['answer' => 'syntetisk'];
    $objectPath = '12/2026/11/91.json';

    $bucket = Mockery::mock(Bucket::class);
    $bucket->expects('upload')->with(json_encode($raw, JSON_THROW_ON_ERROR), [
        'name' => $objectPath,
        'metadata' => ['contentType' => 'application/json'],
    ])->andReturn(Mockery::mock(StorageObject::class));

    $storage = Mockery::mock(StorageClient::class);
    $storage->expects('bucket')->with('test-raw')->andReturn($bucket);
    app()->instance(StorageClient::class, $storage);

    $uri = app(RawResponseStore::class)->put(12, 91, CarbonImmutable::parse('2026-12-01 00:10:00+01:00'), $raw);

    expect(app(RawResponseStore::class))->toBeInstanceOf(GcsRawResponseStore::class)
        ->and($uri)->toBe("gs://test-raw/{$objectPath}");
});
