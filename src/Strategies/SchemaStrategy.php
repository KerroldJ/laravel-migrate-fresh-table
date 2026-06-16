<?php

declare(strict_types=1);

namespace Kerroldj\MigrateFreshTable\Strategies;

use Illuminate\Contracts\Config\Repository;
use Kerroldj\MigrateFreshTable\Contracts\FreshStrategy;
use Kerroldj\MigrateFreshTable\Exceptions\FreshTableException;
use Kerroldj\MigrateFreshTable\Support\FreshContext;

/**
 * Re-creates a table from an explicit Blueprint callback supplied in config,
 * so a table can be freshed even when no single migration cleanly owns it.
 */
final class SchemaStrategy implements FreshStrategy
{
    public function __construct(private readonly Repository $config) {}

    public function name(): string
    {
        return 'schema';
    }

    public function recreate(FreshContext $context): void
    {
        $definition = $this->config->get("migrate-fresh-table.schema.{$context->table}");

        if (! is_callable($definition)) {
            throw FreshTableException::missingSchemaDefinition($context->table);
        }

        $context->schemaBuilder->create($context->table, $definition);
    }
}
