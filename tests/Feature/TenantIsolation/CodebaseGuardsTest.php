<?php

use App\Models\Concerns\BelongsToTenant;
use App\Tenancy\TenantAware;
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

test('every queued job works in a tenant, or is listed with a reason', function () {
    $allowed = require __DIR__.'/platform-jobs.php';
    $offenders = [];

    foreach (classesIn('app/Jobs', 'App\\Jobs') as $class) {
        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() || ! $reflection->implementsInterface(ShouldQueue::class)) {
            continue;
        }
        if (! $reflection->implementsInterface(TenantAware::class) && ! array_key_exists($class, $allowed)) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe([], 'Jobs without a tenant: '.implode(', ', $offenders));
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

test('the isolation tests cannot be skipped', function () {
    $offenders = [];
    foreach (['tests/Feature/TenantIsolation', 'tests/Feature/Tenancy', 'tests/Feature/Access'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory))) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || $file->getFilename() === basename(__FILE__)) {
                continue;
            }
            $content = (string) file_get_contents($file->getPathname());
            // Pest's skip()/todo()/only() and PHPUnit's skip markers; not Eloquent's only([...])
            // or a collection's skip(3).
            $pattern = '/->(skip|skipOn[A-Za-z]*|skipLocally|skipOnCi)\(\s*(\)|[\'"]|fn\b|function\b|true|false|!|\$)|->todo\(|->only\(\)|markTestSkipped|markTestIncomplete/';
            if (preg_match($pattern, $content) === 1) {
                $offenders[] = $directory.'/'.$file->getFilename();
            }
        }
    }

    expect($offenders)->toBe([], 'Skipped isolation tests: '.implode(', ', $offenders));
});
