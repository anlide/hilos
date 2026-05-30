<?php

declare(strict_types=1);

namespace Hilos\Database;

/**
 * Single SQL parameter with mysqli bind type.
 */
readonly class SqlParam
{
    /**
     * @param mixed $value Bound value
     * @param SqlBindType $type mysqli bind_param type specifier
     */
    public function __construct(
        public mixed $value,
        public SqlBindType $type = SqlBindType::STRING,
    ) {
    }

    /**
     * @param mixed $value Value to bind
     * @return self Parameter with inferred type
     */
    public static function auto(mixed $value): self
    {
        $type = match (true) {
            is_int($value) => SqlBindType::INTEGER,
            is_float($value) => SqlBindType::DOUBLE,
            is_bool($value) => SqlBindType::INTEGER,
            is_null($value) => SqlBindType::STRING,
            default => SqlBindType::STRING,
        };

        return new self($value, $type);
    }

    /**
     * @param int $value Integer value
     * @return self Integer parameter
     */
    public static function int(int $value): self
    {
        return new self($value, SqlBindType::INTEGER);
    }

    /**
     * @param float $value Float value
     * @return self Double parameter
     */
    public static function double(float $value): self
    {
        return new self($value, SqlBindType::DOUBLE);
    }

    /**
     * @param string $value String value
     * @return self String parameter
     */
    public static function string(string $value): self
    {
        return new self($value, SqlBindType::STRING);
    }

    /**
     * @param string $value Blob value
     * @return self Blob parameter
     */
    public static function blob(string $value): self
    {
        return new self($value, SqlBindType::BLOB);
    }

    /**
     * @param bool $value Boolean value stored as 0/1
     * @return self Integer parameter
     */
    public static function bool(bool $value): self
    {
        return new self($value ? 1 : 0, SqlBindType::INTEGER);
    }
}
