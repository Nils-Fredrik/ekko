<?php

// The admin connection falls back to the normal login when DB_ADMIN_* is empty, as in .env.example.

function adminConnectionWith(array $env): array
{
    $keys = ['DB_ADMIN_USERNAME', 'DB_ADMIN_PASSWORD', 'DB_USERNAME', 'DB_PASSWORD'];
    $saved = [];
    foreach ($keys as $key) {
        $saved[$key] = $_ENV[$key] ?? null;
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }

    foreach ($env as $key => $value) {
        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    try {
        $config = require dirname(__DIR__, 2).'/config/database.php';

        return $config['connections']['pgsql_admin'];
    } finally {
        foreach ($keys as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
            if ($saved[$key] !== null) {
                $_ENV[$key] = $_SERVER[$key] = $saved[$key];
                putenv("{$key}={$saved[$key]}");
            }
        }
    }
}

test('an empty DB_ADMIN_USERNAME and DB_ADMIN_PASSWORD fall back to the normal login', function () {
    $admin = adminConnectionWith([
        'DB_ADMIN_USERNAME' => '', 'DB_ADMIN_PASSWORD' => '', 'DB_USERNAME' => 'app_user', 'DB_PASSWORD' => 'app_secret',
    ]);

    expect($admin['username'])->toBe('app_user')
        ->and($admin['password'])->toBe('app_secret');
});

test('DB_ADMIN_USERNAME and DB_ADMIN_PASSWORD are used when set', function () {
    $admin = adminConnectionWith([
        'DB_ADMIN_USERNAME' => 'ekko_admin', 'DB_ADMIN_PASSWORD' => 'admin_secret', 'DB_USERNAME' => 'app_user', 'DB_PASSWORD' => 'app_secret',
    ]);

    expect($admin['username'])->toBe('ekko_admin')
        ->and($admin['password'])->toBe('admin_secret');
});
