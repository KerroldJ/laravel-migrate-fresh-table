<?php

declare(strict_types=1);

namespace Kerroldj\MigrateFreshTable\Events\Concerns;

trait DescribesTableEvent
{
    public function __construct(
        public readonly string $connection,
        public readonly string $table,
        public readonly ?string $schema = null,
    ) {}
}
