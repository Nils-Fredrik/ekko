<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Store the ciphertext as a JSON string so the column can remain jsonb.
 *
 * @implements CastsAttributes<array<string, mixed>, array<string, mixed>>
 */
final class EncryptedIntegrationConfig implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        return json_decode(Crypt::decryptString(json_decode($value, true, flags: JSON_THROW_ON_ERROR)), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return json_encode(Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
    }
}
