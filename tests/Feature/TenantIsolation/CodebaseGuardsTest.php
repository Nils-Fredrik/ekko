<?php

use App\Jobs\TenantJob;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

// Guards that make new code fail CI when it skips tenant isolation (docs/spec.md 5.3, T06).

/**
 * @return list<class-string>
 */
function classesIn(string $directory, string $namespace): array
{
    $path = base_path($directory);
    if (! is_dir($path)) {
        return [];
    }

    $classes = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($path) + 1, -4);
        $class = $namespace.'\\'.str_replace('/', '\\', $relative);
        if (class_exists($class)) {
            $classes[] = $class;
        }
    }
    sort($classes);

    return $classes;
}

test('every queued class extends TenantJob, or is listed with a reason', function () {
    $allowed = require __DIR__.'/platform-jobs.php';
    $offenders = [];

    // All of app/: jobs, but also queued listeners, notifications and mail. TenantJob installs
    // SetTenantContext and ApplyTenantRls; implementing TenantAware alone does not.
    foreach (classesIn('app', 'App') as $class) {
        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() || $reflection->isInterface() || ! $reflection->implementsInterface(ShouldQueue::class)) {
            continue;
        }
        if (! $reflection->isSubclassOf(TenantJob::class) && ! array_key_exists($class, $allowed)) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe([], 'Queued classes without TenantJob: '.implode(', ', $offenders));
});

test('every model with a tenant_id column uses BelongsToTenant', function () {
    $offenders = [];

    foreach (classesIn('app/Models', 'App\\Models') as $class) {
        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
            continue;
        }

        /** @var Model $model */
        $model = $reflection->newInstanceWithoutConstructor();
        $table = $model->getTable();

        if (Schema::hasTable($table) && Schema::hasColumn($table, 'tenant_id')
            && ! in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe([], 'Models with tenant_id without BelongsToTenant: '.implode(', ', $offenders));
});

test('every MCP tool is in the MCP tool register, and the register has no stale entries', function () {
    $register = require __DIR__.'/mcp-tools.php';
    $tools = classesIn('app/Mcp/Tools', 'App\\Mcp\\Tools');

    expect(array_values(array_diff($tools, array_keys($register))))->toBe([])
        ->and(array_values(array_diff(array_keys($register), $tools)))->toBe([]);
});

test('the isolation tests cannot be skipped or excluded', function () {
    $offenders = [];
    // Any skip, todo, only or PHPUnit skip marker, whatever its argument. A collection's skip()
    // would also be flagged; that is the price of tests that cannot be skipped (5.3).
    $pattern = '/->(skip|skipOn[A-Za-z]*|skipLocally|todo)\(|->only\(\)|markTestSkipped|markTestIncomplete/';

    foreach (['tests/Feature/TenantIsolation', 'tests/Feature/Tenancy', 'tests/Feature/Access'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory))) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || $file->getFilename() === basename(__FILE__)) {
                continue;
            }
            if (preg_match($pattern, (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = $directory.'/'.$file->getFilename();
            }
        }
    }

    if (preg_match($pattern, (string) file_get_contents(base_path('tests/Pest.php'))) === 1) {
        $offenders[] = 'tests/Pest.php';
    }

    // phpunit.xml: no excluded groups, and nothing excluded from the test suites. <source><exclude>
    // only concerns coverage and is allowed.
    $phpunit = simplexml_load_file(base_path('phpunit.xml'));
    if ($phpunit === false || isset($phpunit->groups) || count($phpunit->xpath('//testsuites//exclude') ?: []) > 0) {
        $offenders[] = 'phpunit.xml';
    }

    // composer.json: the test scripts cannot filter the run.
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
    foreach ($composer['scripts'] ?? [] as $name => $commands) {
        foreach ((array) $commands as $command) {
            if (is_string($command) && preg_match('/(artisan test|pest|phpunit)/', $command) === 1
                && preg_match('/--(exclude-group|exclude-filter|filter|group|testsuite|exclude-testsuite)\b/', $command) === 1) {
                $offenders[] = "composer.json script {$name}";
            }
        }
    }

    expect($offenders)->toBe([], 'Skipped or excluded isolation tests: '.implode(', ', $offenders));
});
