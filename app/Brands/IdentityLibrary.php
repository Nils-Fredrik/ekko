<?php

namespace App\Brands;

use App\Integrations\BrandIntegrations;
use App\Models\Brand;
use App\Rules\SafeRegexAlias;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class IdentityLibrary
{
    public const DOMAIN = '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}\z/i';

    public function __construct(private BrandIntegrations $integrations) {}

    /** @param array<string, mixed> $data */
    public function update(Brand $brand, array $data): void
    {
        DB::transaction(fn () => $this->saveIdentity($this->lock($brand), $data));
    }

    public function archive(Brand $brand, string $confirmation): void
    {
        DB::transaction(function () use ($brand, $confirmation): void {
            $locked = $this->lock($brand);
            if ($confirmation !== $locked->name) {
                throw ValidationException::withMessages(['brand_name_confirmation' => __('workspace.setup.confirm_mismatch')]);
            }
            $locked->forceFill(['status' => 'archived', 'archived_at' => now()])->save();
        });
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
        return Brand::query()->unarchived()->lockForUpdate()->findOrFail($brand->id);
    }
}
