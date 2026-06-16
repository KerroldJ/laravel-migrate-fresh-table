<?php

declare(strict_types=1);

namespace Kerroldj\MigrateFreshTable\Events;

use Kerroldj\MigrateFreshTable\Events\Concerns\DescribesTableEvent;

/** Fired before a table is freshed (before drop). */
final class TableFreshing
{
    use DescribesTableEvent;
}
