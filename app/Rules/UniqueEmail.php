<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * An e-mail address is unique regardless of case (ADR 0008): "Ann@x.no" and "ann@x.no" are the same
 * address. The database has a unique index on lower(email) as well.
 */
final class UniqueEmail implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreUserId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $taken = User::query()
            ->whereRaw('lower(email) = ?', [Str::lower(trim((string) $value))])
            ->when($this->ignoreUserId !== null, fn ($query) => $query->whereKeyNot($this->ignoreUserId))
            ->exists();

        if ($taken) {
            $fail('validation.unique')->translate(['attribute' => $attribute]);
        }
    }
}
