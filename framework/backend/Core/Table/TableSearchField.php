<?php

declare(strict_types=1);

namespace Hilos\Core\Table;

use Hilos\Core\Table\Definition\TableDefinition;

/**
 * One searched field of a table's declaration: the column it is read from and how a term matches it.
 *
 * An entry of {@see TableDefinition::searchableFields()} is written one of two ways - the bare column,
 * which is searched as a substring the way every field always was, or this pair, when the field is
 * searched another way. Both are read here and nowhere else, so none of the paths that search a
 * table has to know that the second way of writing exists.
 */
final readonly class TableSearchField
{
    /**
     * @param string $column Column the field is read from, qualified the way its table's query needs it
     * @param TableSearchMatch $match How a term is compared with the field's value
     */
    public function __construct(
        public string $column,
        public TableSearchMatch $match = TableSearchMatch::Substring,
    ) {
    }

    /**
     * Declares a field whose value a starred term is matched against whole, the star standing for any run.
     *
     * @param string $column Column the field is read from
     * @return self Field searched as a mask
     */
    public static function mask(string $column): self
    {
        return new self($column, TableSearchMatch::Mask);
    }

    /**
     * Reads one entry of a declaration, whichever of its two forms it was written in.
     *
     * @param string|self $declared Bare column, searched as a substring, or the field as declared
     * @return self Field with its column and its way of matching
     */
    public static function of(string|self $declared): self
    {
        return $declared instanceof self ? $declared : new self($declared);
    }
}
