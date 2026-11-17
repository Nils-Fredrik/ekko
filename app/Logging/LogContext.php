<?php

namespace App\Logging;

use Illuminate\Support\Facades\Log;
use Throwable;
use WeakMap;

/** Only internal identifiers belong in shared context, never request input or model attributes. */
final class LogContext
{
    /** @var array{tenant_id: ?int, user_id: ?int, run_id: ?int} */
    private array $ids = ['tenant_id' => null, 'user_id' => null, 'run_id' => null];

    /** @var WeakMap<Throwable, array{tenant_id: ?int, user_id: ?int, run_id: ?int}> */
    private WeakMap $failures;

    public function __construct()
    {
        $this->failures = new WeakMap;
    }

    /** @param array{tenant_id?: ?int, user_id?: ?int, run_id?: ?int} $ids */
    public function share(array $ids): void
    {
        $this->ids = array_replace($this->ids, $ids);
        Log::shareContext($this->ids);
    }

    public function clear(): void
    {
        $this->share(['tenant_id' => null, 'user_id' => null, 'run_id' => null]);
    }

    /**
     * @template T
     *
     * @param  array{tenant_id?: ?int, user_id?: ?int, run_id?: ?int}  $ids
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(array $ids, callable $callback): mixed
    {
        $previous = $this->ids;
        $this->share($ids);

        try {
            return $callback();
        } catch (Throwable $failure) {
            $this->capture($failure);
            throw $failure;
        } finally {
            $this->share($previous);
        }
    }

    public function capture(Throwable $failure): void
    {
        // Keep the innermost scope. Commands and queue workers may report only after it has unwound.
        $this->failures[$failure] ??= $this->ids;
    }

    /** @return array{tenant_id: ?int, user_id: ?int, run_id: ?int} */
    public function forException(Throwable $failure): array
    {
        return $this->failures[$failure] ?? $this->ids;
    }
}
