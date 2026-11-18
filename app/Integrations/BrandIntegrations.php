<?php

namespace App\Integrations;

use App\Models\Brand;
use App\Models\Integration;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates the encrypted ClickHouse and GA4 config on a brand (docs/spec.md 10, T28-F1).
 * Blank fields leave the stored secret unchanged. The config itself is never returned to the browser.
 */
final class BrandIntegrations
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'clickhouse_host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'clickhouse_database' => ['sometimes', 'nullable', 'string', 'max:64'],
            'clickhouse_table' => ['sometimes', 'nullable', 'string', 'max:64'],
            'clickhouse_user' => ['sometimes', 'nullable', 'string', 'max:255'],
            'clickhouse_password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ga4_property_id' => ['sometimes', 'nullable', 'string', 'max:20'],
            'ga4_client_email' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ga4_private_key' => ['sometimes', 'nullable', 'string', 'max:8192'],
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function apply(Brand $brand, array $data): void
    {
        $this->applyClickHouse($brand, $data);
        $this->applyGa4($brand, $data);
    }

    /** @return list<string> */
    public function endpoints(): array
    {
        $endpoints = [];
        foreach (config()->array('ekko.bot_logs.clickhouse_endpoints') as $endpoint) {
            if (is_string($endpoint) && $endpoint !== '') {
                $endpoints[] = $endpoint;
            }
        }

        return $endpoints;
    }

    /** @param  array<string, mixed>  $data */
    private function applyClickHouse(Brand $brand, array $data): void
    {
        $provided = $this->provided($data, [
            'clickhouse_host' => 'host',
            'clickhouse_database' => 'database',
            'clickhouse_table' => 'table',
            'clickhouse_user' => 'user',
            'clickhouse_password' => 'password',
        ]);
        if ($provided === []) {
            return;
        }

        $integration = $brand->integrations()->where('type', 'clickhouse_logs')->first();
        if (! $integration instanceof Integration) {
            foreach (['host', 'database', 'table', 'user', 'password'] as $key) {
                if (! isset($provided[$key])) {
                    throw ValidationException::withMessages([
                        'clickhouse_'.$key => __('app.workspace.clickhouse_required'),
                    ]);
                }
            }
            Gate::authorize('create', Integration::class);
            $brand->integrations()->create([
                'type' => 'clickhouse_logs',
                'config' => $this->clickHouseConfig($provided),
                'status' => 'pending',
            ]);

            return;
        }

        Gate::authorize('update', $integration);
        $config = $integration->config;
        foreach ($this->clickHouseConfig($provided) as $key => $value) {
            $config[$key] = $value;
        }
        $integration->config = $config;
        $integration->save();
    }

    /** @param  array<string, mixed>  $data */
    private function applyGa4(Brand $brand, array $data): void
    {
        $provided = $this->provided($data, [
            'ga4_property_id' => 'property_id',
            'ga4_client_email' => 'client_email',
            'ga4_private_key' => 'private_key',
        ]);
        if ($provided === []) {
            return;
        }

        $integration = $brand->integrations()->where('type', 'ga4')->first();
        if (! $integration instanceof Integration) {
            foreach (['property_id', 'client_email', 'private_key'] as $key) {
                if (! isset($provided[$key])) {
                    throw ValidationException::withMessages([
                        'ga4_'.($key === 'property_id' ? 'property_id' : ($key === 'client_email' ? 'client_email' : 'private_key')) => __('app.workspace.ga4_required'),
                    ]);
                }
            }
            Gate::authorize('create', Integration::class);
            $brand->integrations()->create([
                'type' => 'ga4',
                'config' => $this->ga4Config($provided),
                'status' => 'pending',
            ]);

            return;
        }

        Gate::authorize('update', $integration);
        $config = $integration->config;
        $account = is_array($config['service_account'] ?? null) ? $config['service_account'] : [];
        $next = $this->ga4Config($provided, $account);
        $integration->config = array_merge($config, $next);
        $integration->save();
    }

    /**
     * @param  array<string, string>  $map
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function provided(array $data, array $map): array
    {
        $provided = [];
        foreach ($map as $input => $key) {
            if (! array_key_exists($input, $data) || ! is_string($data[$input])) {
                continue;
            }
            $value = trim($data[$input]);
            if ($value !== '') {
                $provided[$key] = $value;
            }
        }

        return $provided;
    }

    /**
     * @param  array<string, string>  $provided
     * @return array<string, string>
     */
    private function clickHouseConfig(array $provided): array
    {
        $config = [];
        if (isset($provided['host'])) {
            if (! in_array($provided['host'], $this->endpoints(), true)) {
                throw ValidationException::withMessages([
                    'clickhouse_host' => __('app.workspace.clickhouse_invalid'),
                ]);
            }
            $config['host'] = $provided['host'];
        }
        foreach (['database', 'table'] as $key) {
            if (! isset($provided[$key])) {
                continue;
            }
            if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $provided[$key]) !== 1) {
                throw ValidationException::withMessages([
                    'clickhouse_'.$key => __('app.workspace.clickhouse_identifier'),
                ]);
            }
            $config[$key] = $provided[$key];
        }
        foreach (['user', 'password'] as $key) {
            if (isset($provided[$key])) {
                $config[$key] = $provided[$key];
            }
        }

        return $config;
    }

    /**
     * @param  array<string, string>  $provided
     * @param  array<string, mixed>  $account
     * @return array<string, mixed>
     */
    private function ga4Config(array $provided, array $account = []): array
    {
        $config = [];
        if (isset($provided['property_id'])) {
            if (preg_match('/\A[1-9][0-9]*\z/D', $provided['property_id']) !== 1) {
                throw ValidationException::withMessages([
                    'ga4_property_id' => __('app.workspace.ga4_property_invalid'),
                ]);
            }
            $config['property_id'] = $provided['property_id'];
        }
        if (isset($provided['client_email'])) {
            if (filter_var($provided['client_email'], FILTER_VALIDATE_EMAIL) === false) {
                throw ValidationException::withMessages([
                    'ga4_client_email' => __('app.workspace.ga4_email_invalid'),
                ]);
            }
            $account['client_email'] = $provided['client_email'];
        }
        if (isset($provided['private_key'])) {
            if (str_contains($provided['private_key'], "\0")) {
                throw ValidationException::withMessages([
                    'ga4_private_key' => __('app.workspace.ga4_required'),
                ]);
            }
            $account['private_key'] = $provided['private_key'];
        }
        if ($account !== []) {
            $config['service_account'] = $account;
        }

        return $config;
    }
}
