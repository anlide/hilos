<?php

declare(strict_types=1);

namespace Hilos\Database\Schema;

use Hilos\Database\Exception\InvalidMomentDeclarationException;

/** Semantic placement of a physical moment column, independent of its current SQL type. */
final readonly class MomentColumn
{
    /**
     * @param string $column Physical column name
     * @param bool $frameworkOwned Whether the framework owns the column's representation
     * @param bool $mapped Whether the requested Entity maps the column in _columns
     * @param ?string $projectStorage Explicit project representation and reason, null for signed BIGINT epoch milliseconds
     * @throws InvalidMomentDeclarationException When a name or explanation is empty, or a framework column opts out
     */
    public function __construct(
        public string $column,
        public bool $frameworkOwned,
        public bool $mapped,
        public ?string $projectStorage = null,
    ) {
        if (trim($column) === '') {
            throw new InvalidMomentDeclarationException('Moment column name must be non-empty');
        }
        if ($projectStorage !== null && (trim($projectStorage) === '' || $frameworkOwned)) {
            throw new InvalidMomentDeclarationException(
                "Moment column [{$column}] needs a non-empty project storage explanation and cannot override framework storage",
            );
        }
    }
}
