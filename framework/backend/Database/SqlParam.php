<?php

declare(strict_types=1);

namespace Hilos\Database;

use Hilos\Core\Exception\InvalidArgumentException;

/**
 * Single SQL parameter with type information for prepared statements.
 *
 * Type chars: i=integer, d=double, s=string, b=blob.
 */
readonly class SqlParam
{
    /**
     * Creates SQL parameter with explicit type.
     *
     * @param mixed $value Bound value
     * @param string $type Type char: i, d, s, b
     * @throws InvalidArgumentException When type is not one of i, d, s, b
     */
    public function __construct(
        public mixed $value,
        public string $type = 's' // i=integer, d=double, s=string, b=blob
    ) {
        if (!in_array($type, ['i', 'd', 's', 'b'], true)) {
            throw new InvalidArgumentException("Invalid parameter type: {$type}. Must be one of: i, d, s, b");
        }
    }

    /**
     * Auto-detects type from value (int→i, float→d, bool→i, null→s, else→s).
     *
     * @param mixed $value Value to bind
     * @return self Parameter instance
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
     * Creates integer parameter.
     *
     * @param int $value Integer value
     * @return self Parameter instance
     */
    public static function int(int $value): self
    {
        return new self($value, 'i');
    }

    /**
     * Creates double parameter.
     *
     * @param float $value Float value
     * @return self Parameter instance
     */
    public static function double(float $value): self
    {
        return new self($value, 'd');
    }

    /**
     * Creates string parameter.
     *
     * @param string $value String value
     * @return self Parameter instance
     */
    public static function string(string $value): self
    {
        return new self($value, 's');
    }

    /**
     * Creates blob parameter.
     *
     * @param string $value Blob value
     * @return self Parameter instance
     */
    public static function blob(string $value): self
    {
        return new self($value, 'b');
    }

    /**
     * Creates boolean parameter (stored as integer 0/1).
     *
     * @param bool $value Boolean value
     * @return self Parameter instance
     */
    public static function bool(bool $value): self
    {
        return new self($value ? 1 : 0, 'i');
    }
}

