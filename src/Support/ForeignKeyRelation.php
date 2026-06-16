<?php

declare(strict_types=1);

namespace Kerroldj\MigrateFreshTable\Support;

/**
 * Describes one foreign-key relationship discovered in the live schema.
 *
 * Direction is expressed relative to the table being freshed:
 *   - "references"    : the target table has an FK pointing at another table
 *                       (the other table is a PARENT of the target).
 *   - "referenced_by" : another table has an FK pointing at the target table
 *                       (the other table is a CHILD/dependent of the target).
 */
final class ForeignKeyRelation
{
    public const REFERENCES = 'references';

    public const REFERENCED_BY = 'referenced_by';

    /**
     * @param  list<string>  $localColumns
     * @param  list<string>  $foreignColumns
     */
    public function __construct(
        public readonly string $constraint,
        public readonly string $localTable,
        public readonly array $localColumns,
        public readonly string $foreignTable,
        public readonly array $foreignColumns,
        public readonly string $direction,
        public readonly ?string $onDelete = null,
        public readonly ?string $onUpdate = null,
    ) {}

    public function isParent(): bool
    {
        return $this->direction === self::REFERENCES;
    }

    public function isChild(): bool
    {
        return $this->direction === self::REFERENCED_BY;
    }

    /**
     * Human-readable form, e.g. "orders.user_id -> users.id".
     */
    public function describe(): string
    {
        $local = $this->localTable.'.'.implode(',', $this->localColumns);
        $foreign = $this->foreignTable.'.'.implode(',', $this->foreignColumns);

        return $local.' -> '.$foreign;
    }
}
