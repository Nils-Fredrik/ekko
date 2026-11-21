<?php

namespace App\Brands;

use App\Integrations\BrandIntegrations;
use App\Models\Brand;
use App\Models\Market;
use App\Rules\SafeRegexAlias;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class IdentityLibrary
{
    public const DOMAIN = '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}\z/i';

    public function __construct(private MarketLibrary $markets, private BrandIntegrations $integrations) {}

    /** @param array<string, mixed> $data */
    public function updateSettings(Brand $brand, array $data): void
    {
        DB::transaction(function () use ($brand, $data): void {
            $brand = $this->lock($brand);
            $this->saveIdentity($brand, $data);
            $this->saveAliases($brand, $data);
            $this->saveDomains($brand, $data);
            $this->saveMarkets($brand, $data);
            $this->integrations->apply($brand, $data);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Brand $brand, array $data): void
    {
        DB::transaction(fn () => $this->saveIdentity($this->lock($brand), $data));
    }

    /** @param array<string, mixed> $data */
    private function saveIdentity(Brand $brand, array $data): void
    {
        $changes = Arr::only($data, ['name', 'primary_domain', 'status']);
        if (isset($changes['primary_domain'])) {
            $changes['primary_domain'] = strtolower($changes['primary_domain']);
        }
        if ($changes !== []) {
            $brand->fill($changes)->save();
        }
    }

    public function saveAlias(Brand $brand, ?int $id, ?string $text, string $mode): void
    {
        DB::transaction(function () use ($brand, $id, $text, $mode): void {
            $brand = $this->lock($brand);
            $alias = $id === null ? $brand->aliases()->make() : $brand->aliases()->findOrFail($id);
            $text ??= $alias->alias;
            if ($mode === 'regex') {
                Validator::make(['alias' => $text], ['alias' => [new SafeRegexAlias]])->validate();
            }
            if ($brand->aliases()->whereRaw('lower(alias) = lower(?)', [$text])->where('match_mode', $mode)
                ->when($id !== null, fn ($q) => $q->whereKeyNot($id))->exists()) {
                throw ValidationException::withMessages(['alias' => __('app.workspace.duplicate')]);
            }
            $alias->fill(['alias' => $text, 'match_mode' => $mode])->save();
        });
    }

    public function deleteAlias(Brand $brand, int $id): void
    {
        DB::transaction(function () use ($brand, $id): void {
            $brand = $this->lock($brand);
            $alias = $brand->aliases()->findOrFail($id);
            if (! $brand->aliases()->whereKeyNot($id)->exists()) {
                throw ValidationException::withMessages(['alias' => __('app.workspace.last_alias')]);
            }
            $alias->delete();
        });
    }

    /** @param array<string, mixed> $data */
    public function saveDomain(Brand $brand, ?int $id, array $data): void
    {
        DB::transaction(function () use ($brand, $id, $data): void {
            $brand = $this->lock($brand);
            $domain = $id === null ? $brand->domains()->make() : $brand->domains()->findOrFail($id);
            if ($id === null && $brand->domains()->where('domain', $data['domain'])->exists()) {
                throw ValidationException::withMessages(['domain' => __('app.workspace.duplicate')]);
            }
            $domain->fill($data)->save();
        });
    }

    public function deleteDomain(Brand $brand, int $id): void
    {
        DB::transaction(fn () => $this->lock($brand)->domains()->findOrFail($id)->delete());
    }

    /** @param array<string, mixed> $data */
    public function updateIntegration(Brand $brand, array $data): void
    {
        DB::transaction(fn () => $this->integrations->apply($this->lock($brand), $data));
    }

    private function lock(Brand $brand): Brand
    {
        return Brand::query()->lockForUpdate()->findOrFail($brand->id);
    }

    /** @param  array<string, mixed>  $data */
    private function saveAliases(Brand $brand, array $data): void
    {
        if (! array_key_exists('aliases', $data) || ! is_array($data['aliases'])) {
            return;
        }

        $seen = [];
        foreach ($data['aliases'] as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $alias = is_string($row['alias'] ?? null) ? trim($row['alias']) : '';
            $mode = is_string($row['match_mode'] ?? null) ? $row['match_mode'] : '';
            $key = mb_strtolower($alias).'|'.$mode;
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    "aliases.{$index}.alias" => __('app.workspace.duplicate'),
                ]);
            }
            $seen[$key] = true;
            $data['aliases'][$index]['alias'] = $alias;
        }

        if ($data['aliases'] === [] && $brand->aliases()->exists() && ! $brand->aliases()->where('match_mode', 'regex')->exists()) {
            throw ValidationException::withMessages(['aliases' => __('app.workspace.last_alias')]);
        }
        $brand->aliases()->where('match_mode', '!=', 'regex')->delete();
        foreach ($data['aliases'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $brand->aliases()->create([
                'alias' => $row['alias'],
                'match_mode' => $row['match_mode'],
            ]);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function saveDomains(Brand $brand, array $data): void
    {
        if (! array_key_exists('domains', $data) || ! is_array($data['domains'])) {
            return;
        }

        $seen = [];
        foreach ($data['domains'] as $index => $row) {
            if (! is_array($row) || ! is_string($row['domain'] ?? null)) {
                continue;
            }
            $domain = strtolower($row['domain']);
            if (isset($seen[$domain])) {
                throw ValidationException::withMessages([
                    "domains.{$index}.domain" => __('app.workspace.duplicate'),
                ]);
            }
            $seen[$domain] = true;
            $data['domains'][$index]['domain'] = $domain;
        }

        $brand->domains()->delete();
        foreach ($data['domains'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $brand->domains()->create([
                'domain' => $row['domain'],
                'include_subdomains' => (bool) ($row['include_subdomains'] ?? false),
            ]);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function saveMarkets(Brand $brand, array $data): void
    {
        if (! array_key_exists('markets', $data) || ! is_array($data['markets'])) {
            return;
        }

        $markets = $brand->markets()->get()->keyBy('id');
        foreach ($data['markets'] as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $market = $markets->get($row['id'] ?? null);
            if (! $market instanceof Market) {
                throw ValidationException::withMessages([
                    "markets.{$index}.id" => __('app.workspace.unknown_market'),
                ]);
            }
            Gate::authorize('update', $market);
            $label = $row['location_label'] ?? null;
            $this->markets->update($market, [
                'location_label' => is_string($label) && $label !== '' ? $label : null,
                'is_active' => (bool) $row['is_active'],
            ]);
        }
    }
}
