<?php

namespace App\Api;

use Laravel\Sanctum\PersonalAccessToken;

/** Server-owned binding, kept separate from the editable read/write permissions. */
final class TokenTenant
{
    public static function id(PersonalAccessToken $token): ?int
    {
        $bindings = array_values(array_filter($token->abilities ?? [], fn ($ability) => is_string($ability) && str_starts_with($ability, 'tenant:')));

        if (count($bindings) !== 1 || preg_match('/^tenant:([1-9][0-9]*)$/D', $bindings[0], $match) !== 1) {
            return null;
        }

        $id = filter_var($match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }
}
