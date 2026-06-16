<?php

declare(strict_types=1);

namespace Kerroldj\MigrateFreshTable\Support;

use Illuminate\Contracts\Container\Container;
use Kerroldj\MigrateFreshTable\Contracts\FreshStrategy;
use Kerroldj\MigrateFreshTable\Exceptions\FreshTableException;

/**
 * Resolves named fresh strategies from the registry through the container, so
 * consumers can register custom strategies (with their own dependencies).
 */
final class StrategyManager
{
    public function __construct(private readonly Container $container) {}

    public function make(string $name): FreshStrategy
    {
        $registry = (array) config('migrate-fresh-table.strategies', []);

        if (! isset($registry[$name])) {
            throw FreshTableException::unknownStrategy($name);
        }

        $abstract = $registry[$name];

        $strategy = is_callable($abstract)
            ? $abstract($this->container)
            : $this->container->make($abstract);

        if (! $strategy instanceof FreshStrategy) {
            throw FreshTableException::invalidStrategy(
                is_object($strategy) ? $strategy::class : (string) $abstract
            );
        }

        return $strategy;
    }

    /**
     * Resolve the strategy name to use for a specific table, honouring the
     * --strategy override, then the per-table map, then the default.
     */
    public function strategyNameFor(string $table, ?string $override = null): string
    {
        if ($override !== null && $override !== '') {
            return $override;
        }

        $perTable = (array) config('migrate-fresh-table.table_strategies', []);

        return (string) ($perTable[$table] ?? config('migrate-fresh-table.default_strategy', 'migration'));
    }
}
