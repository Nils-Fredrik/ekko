<?php

use App\Engines\GcsRawResponseStore;
use App\Engines\RawResponseStore;
use App\Enums\TenantStatus;
use App\Models\Team;
use App\Models\User;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\StorageObject;
use Illuminate\Support\Facades\Log;

// docs/spec.md 16, T39-F6: a closed tenant is deleted with all its data, raw answers in GCS included, 30 days
// after it was closed.

function closedTenant(?int $daysAgo): Team
{
    $tenant = Team::factory()->tenant()->create();
    $tenant->forceFill(['status' => TenantStatus::Closed, 'closed_at' => $daysAgo === null ? null : now()->subDays($daysAgo)])->save();

    return $tenant;
}

test('a tenant closed 30 days ago is deleted with its raw answers, younger ones are kept', function () {
    $due = closedTenant(31);
    $due->users()->attach($member = User::factory()->create(), ['role' => 'editor']);
    $young = closedTenant(29);
    $unknown = closedTenant(null);
    $active = Team::factory()->tenant()->create();

    $raw = Mockery::mock(RawResponseStore::class);
    $raw->expects('deleteTenant')->with($due->id)->andReturn(3);
    app()->instance(RawResponseStore::class, $raw);
    Log::spy();

    $this->artisan('ekko:purge-closed-tenants')->assertSuccessful();

    expect(Team::query()->find($due->id))->toBeNull()
        ->and($member->fresh())->not->toBeNull()
        ->and($member->fresh()->teams)->toHaveCount(0)
        ->and(Team::query()->find($young->id))->not->toBeNull()
        ->and(Team::query()->find($unknown->id))->not->toBeNull()
        ->and(Team::query()->find($active->id))->not->toBeNull();
    Log::shouldHaveReceived('info')->with('tenant.purged', Mockery::on(fn (array $context) => $context['tenant_id'] === $due->id && $context['raw_objects'] === 3));
});

test('when the raw answers cannot be deleted, the tenant stays for the next run', function () {
    $due = closedTenant(40);
    $raw = Mockery::mock(RawResponseStore::class);
    $raw->expects('deleteTenant')->andThrow(new RuntimeException('GCS unavailable'));
    app()->instance(RawResponseStore::class, $raw);

    $this->artisan('ekko:purge-closed-tenants')->assertFailed();

    expect(Team::query()->find($due->id))->not->toBeNull();
});

test('GCS deletes every version of the objects under the tenant prefix', function () {
    config(['engines.raw_bucket' => 'test-raw']);
    $objects = [Mockery::mock(StorageObject::class), Mockery::mock(StorageObject::class)];
    foreach ($objects as $object) {
        $object->expects('delete');
    }
    $bucket = Mockery::mock(Bucket::class);
    $bucket->expects('objects')->with(['prefix' => '12/', 'versions' => true])->andReturn(new ArrayIterator($objects));
    $storage = Mockery::mock(StorageClient::class);
    $storage->expects('bucket')->with('test-raw')->andReturn($bucket);

    expect((new GcsRawResponseStore($storage))->deleteTenant(12))->toBe(2);
});
