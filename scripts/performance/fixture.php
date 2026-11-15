<?php

// CLI only. UUID is required for both creation and deletion, so interrupted loads remain recoverable.
use App\Performance\PerformanceFixture;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $fixture = app(PerformanceFixture::class);
    $action = $argv[1] ?? '';
    $key = $argv[2] ?? '';
    $result = match ($action) {
        'create' => $fixture->create($key, getenv('PERF_PASSWORD') ?: ''),
        'verify' => $fixture->verify($key),
        'delete' => $fixture->delete($key),
        default => throw new InvalidArgumentException('Usage: php scripts/performance/fixture.php create|verify|delete UUID'),
    };
    echo json_encode($result ?? ['deleted' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    // SQL exceptions can contain a credential. Never print their message or trace.
    fwrite(STDERR, 'Fixture operation failed ('.$error::class.'). Check prerequisites; keep the UUID for cleanup.'.PHP_EOL);
    exit(1);
}
