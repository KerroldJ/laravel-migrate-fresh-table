<?php

declare(strict_types=1);

namespace Kerroldj\MigrateFreshTable\Events;

use Kerroldj\MigrateFreshTable\Events\Concerns\DescribesTableEvent;

/** Fired after a table has been freshed (after recreate). */
final class TableFreshed
{
    use DescribesTableEvent;
}
