<?php

use App\Jobs\TenantJob;
use App\Models\Concerns\BelongsToTenant;
use App\Tenancy\TenantBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\TenantRecord;

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

test('every model with BelongsToTenant queries through TenantBuilder', function () {
    $offenders = [];

    // The write guards live in TenantBuilder (newEloquentBuilder in the trait). A model that
    // overrides newEloquentBuilder() or uses #[UseEloquentBuilder] would lose them silently.
    foreach ([...classesIn('app/Models', 'App\\Models'), TenantRecord::class] as $class) {
        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() || ! in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
            continue;
        }

        if (! $class::query() instanceof TenantBuilder) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe([], 'Tenant models without TenantBuilder: '.implode(', ', $offenders));
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

    // phpunit.xml: no excluded groups, nothing excluded from the test suites, no default suite that
    // leaves Feature out, and tests/Feature must be a test suite directory. <source><exclude> only
    // concerns coverage and is allowed.
    $phpunit = simplexml_load_file(base_path('phpunit.xml'));
    $directories = $phpunit === false ? [] : array_map('strval', $phpunit->xpath('//testsuites/testsuite/directory') ?: []);
    if ($phpunit === false
        // A default namespace would make the XPath queries below silently find nothing.
        || array_key_exists('', $phpunit->getDocNamespaces())
        || isset($phpunit->groups)
        || isset($phpunit['defaultTestSuite'])
        || count($phpunit->xpath('//testsuites//exclude') ?: []) > 0
        || ! in_array('tests/Feature', $directories, true)) {
        $offenders[] = 'phpunit.xml';
    }

    // composer.json: test scripts cannot filter the run, use another configuration or run only
    // changed files, and `composer test` itself runs everything (no paths).
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
    foreach ($composer['scripts'] ?? [] as $name => $commands) {
        foreach ((array) $commands as $command) {
            if (! is_string($command) || preg_match('/(artisan test|pest|phpunit)/', $command) !== 1) {
                continue;
            }
            if (preg_match('/(--(filter|group|exclude-group|testsuite|exclude-testsuite|configuration|dirty|bootstrap)\b|\s-c\s|\s-d\s)/', $command) === 1) {
                $offenders[] = "composer.json script {$name}";
            }
        }
    }

    // `composer test` (what CI runs) must be exactly this, so it always runs every test.
    if (($composer['scripts']['test'] ?? null) !== ['@php artisan config:clear --ansi', '@php artisan test']) {
        $offenders[] = 'composer.json script test (must be exactly config:clear and artisan test)';
    }

    expect($offenders)->toBe([], 'Skipped or excluded isolation tests: '.implode(', ', $offenders));
});

test('only AdminConnection may name the pgsql_admin connection', function () {
    // The connection's role has BYPASSRLS (T39-F2). config/database.php defines it, AdminConnection is the one
    // entry point and logs every use. Any other mention, also through config('database.connections…'), fails.
    $allowed = ['config/database.php', 'app/Tenancy/AdminConnection.php'];
    $offenders = [];

    foreach (['app', 'bootstrap', 'config', 'database', 'routes', 'resources/views'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory))) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen(base_path()) + 1);
            if (! in_array($relative, $allowed, true) && str_contains((string) file_get_contents($file->getPathname()), 'pgsql_admin')) {
                $offenders[] = $relative;
            }
        }
    }

    expect($offenders)->toBe([], 'pgsql_admin outside AdminConnection: '.implode(', ', $offenders));
});
