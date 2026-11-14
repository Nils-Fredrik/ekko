<?php

// The admin connection falls back to the normal login when DB_ADMIN_* is empty, as in .env.example.

function adminConnectionWith(array $env): array
{
    $keys = ['DB_ADMIN_USERNAME', 'DB_ADMIN_PASSWORD', 'DB_USERNAME', 'DB_PASSWORD'];

    // Save from every source: with variables_order=GPCS (CLI default) process variables, for
    // example from CI, live only in getenv() and $_SERVER, not in $_ENV.
    $saved = [];
    foreach ($keys as $key) {
        $saved[$key] = [
            'env' => array_key_exists($key, $_ENV) ? $_ENV[$key] : null,
            'server' => array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null,
            'getenv' => getenv($key),
        ];
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

            if ($saved[$key]['env'] !== null) {
                $_ENV[$key] = $saved[$key]['env'];
            }
            if ($saved[$key]['server'] !== null) {
                $_SERVER[$key] = $saved[$key]['server'];
            }
            if ($saved[$key]['getenv'] !== false) {
                putenv("{$key}={$saved[$key]['getenv']}");
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

test('the helper restores process variables that are not in $_ENV', function () {
    $original = [
        'getenv' => getenv('DB_USERNAME'),
        'server' => $_SERVER['DB_USERNAME'] ?? null,
        'env' => $_ENV['DB_USERNAME'] ?? null,
    ];

    putenv('DB_USERNAME=from_process');
    $_SERVER['DB_USERNAME'] = 'from_process';
    unset($_ENV['DB_USERNAME']);

    try {
        adminConnectionWith(['DB_USERNAME' => 'temporary']);

        expect(getenv('DB_USERNAME'))->toBe('from_process')
            ->and($_SERVER['DB_USERNAME'])->toBe('from_process')
            ->and(array_key_exists('DB_USERNAME', $_ENV))->toBeFalse();
    } finally {
        $original['getenv'] === false ? putenv('DB_USERNAME') : putenv('DB_USERNAME='.$original['getenv']);
        if ($original['server'] === null) {
            unset($_SERVER['DB_USERNAME']);
        } else {
            $_SERVER['DB_USERNAME'] = $original['server'];
        }
        if ($original['env'] !== null) {
            $_ENV['DB_USERNAME'] = $original['env'];
        }
    }
});
