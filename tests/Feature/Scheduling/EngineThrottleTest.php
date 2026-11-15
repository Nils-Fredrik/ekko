<?php

use App\Models\Engine;
use App\Scheduling\EngineThrottle;
use Illuminate\Support\Facades\Cache;

test('engine slots and sliding rate limits are shared and separate between engines', function () {
    $engine = Engine::factory()->create(['config' => ['concurrency' => 1, 'calls_per_minute' => 2]]);
    $other = Engine::factory()->create(['config' => ['concurrency' => 1, 'calls_per_minute' => 2]]);
    $throttle = app(EngineThrottle::class);
    $first = $throttle->acquire($engine);
    expect($first)->not->toBeNull()->and($throttle->acquire($engine))->toBeNull();
    $parallel = $throttle->acquire($other);
    expect($parallel)->not->toBeNull();
    $first->release();
    $second = $throttle->acquire($engine);
    expect($second)->not->toBeNull();
    $second->release();
    expect($throttle->acquire($engine))->toBeNull();
    Cache::store('database')->put('engine:'.$engine->key.':rate', [microtime(true) - 61], 60);
    $after = $throttle->acquire($engine);
    expect($after)->not->toBeNull();
    $after->release();
    $parallel->release();
});
