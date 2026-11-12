<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

/** A named table CHECK of a modeled form: one comparison of an integer column with a column or a decimal literal. */
final readonly class CheckShape
{
    public const string COLUMN = 'column';
    public const string LITERAL = 'literal';

    /**
     * @param string $operator Comparison operator
     * @param list<array{string, string}> $operands Ordered kind/value pairs: a case-insensitive column or exact decimal digits
     * @param bool $enforced DDL enforcement status
     */
    public function __construct(public string $operator, public array $operands, public bool $enforced)
    {
    }

    /**
     * @return list<string> Columns the comparison reads, in operand order
     */
    public function columns(): array
    {
        $columns = [];
        foreach ($this->operands as [$kind, $value]) {
            if ($kind === self::COLUMN) {
                $columns[] = $value;
            }
        }
        return $columns;
    }
}
