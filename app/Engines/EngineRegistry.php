<?php

namespace App\Engines;

use Illuminate\Contracts\Container\Container;
use LogicException;
use OutOfBoundsException;
use ReflectionClass;

final class EngineRegistry
{
    /** @var array<string, EngineAdapter> */
    private array $adapters = [];

    /** @param iterable<EngineAdapter> $adapters */
    public function __construct(iterable $adapters = [])
    {
        foreach ($adapters as $adapter) {
            $key = $adapter->key();

            if (isset($this->adapters[$key])) {
                throw new LogicException("Multiple adapters registered for engine {$key}.");
            }

            $this->adapters[$key] = $adapter;
        }
    }

    public static function discover(Container $container): self
    {
        $adapters = [];

        foreach (glob(app_path('Engines/Adapters/*.php')) ?: [] as $path) {
            $class = 'App\\Engines\\Adapters\\'.pathinfo($path, PATHINFO_FILENAME);

            if (is_subclass_of($class, EngineAdapter::class) && (new ReflectionClass($class))->isInstantiable()) {
                $adapters[] = $container->make($class);
            }
        }

        return new self($adapters);
    }

    public function resolve(string $key): EngineAdapter
    {
        return $this->adapters[$key] ?? throw new OutOfBoundsException("No adapter registered for engine {$key}.");
    }
}
