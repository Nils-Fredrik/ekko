<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Onboarding\OnboardingFakes;

// Only the disposable browser server loads fakes. Production contains neither this router nor tests.
if (getenv('EKKO_BROWSER_TEST') !== '1') {
    http_response_code(503);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = realpath(__DIR__.'/../../public'.$path);
if ($path !== '/' && $file !== false && str_starts_with($file, realpath(__DIR__.'/../../public').DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->booted(function () use ($app): void {
    $database = DB::connection()->getDatabaseName();
    if (! $app->environment('local', 'testing') || ! str_ends_with($database, '_testing') || $database !== env('DB_TEST_DATABASE')) {
        throw new RuntimeException('Browser server requires the dedicated test database.');
    }
    Http::preventStrayRequests();
    OnboardingFakes::bind(Request::capture()->is('brands/*/market-proposals') ? OnboardingFakes::marketJson(['running_shoes', 'shoe_stores']) : null);
    config(['collection.cloud_run.enabled' => false]);
});
$app->handleRequest(Request::capture());
