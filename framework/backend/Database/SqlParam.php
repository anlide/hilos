<?php

declare(strict_types=1);

namespace Hilos\Database;

use Hilos\Database\Exception\DatabaseParamsException;

/**
 * Single SQL parameter with bind type (i, d, s, b).
 */
readonly class SqlParam
{
    /**
     * @param mixed $value Bound value
     * @param string $type Type char: i, d, s, b
     * @throws DatabaseParamsException When type is not one of i, d, s, b
     */
    public function __construct(
        public mixed $value,
        public string $type = 's' // i=integer, d=double, s=string, b=blob
    ) {
        if (!in_array($type, ['i', 'd', 's', 'b'], true)) {
            throw new DatabaseParamsException("Invalid parameter type: {$type}. Must be one of: i, d, s, b");
        }
    }

    /**
     * @param mixed $value Value to bind
     * @return self Parameter with inferred type
     * @throws DatabaseParamsException When inferred bind type is invalid
     */
    public static function auto(mixed $value): self
    {
        $type = match (true) {
            is_int($value) => 'i',
            is_float($value) => 'd',
            is_bool($value) => 'i',
            is_null($value) => 's',
            default => 's',
        };

        return new self($value, $type);
    }

    /**
     * @param int $value Integer value
     * @return self Integer parameter
     * @throws DatabaseParamsException When bind type is invalid
     */
    public static function int(int $value): self
    {
        return new self($value, 'i');
    }

    /**
     * @param float $value Float value
     * @return self Double parameter
     * @throws DatabaseParamsException When bind type is invalid
     */
    public static function double(float $value): self
    {
        return new self($value, 'd');
    }

    /**
     * @param string $value String value
     * @return self String parameter
     * @throws DatabaseParamsException When bind type is invalid
     */
    public static function string(string $value): self
    {
        return new self($value, 's');
    }

    /**
     * @param string $value Blob value
     * @return self Blob parameter
     * @throws DatabaseParamsException When bind type is invalid
     */
    public static function blob(string $value): self
    {
        return new self($value, 'b');
    }

    /**
     * @param bool $value Boolean value stored as 0/1
     * @return self Integer parameter
     * @throws DatabaseParamsException When bind type is invalid
     */
    public static function bool(bool $value): self
    {
        return new self($value ? 1 : 0, 'i');
    }
}
