<?php

namespace Hilos\Database;

/**
 * Single SQL parameter with type information
 */
readonly class SqlParam
{
    public function __construct(
        public mixed  $value,
        public string $type = 's' // i=integer, d=double, s=string, b=blob
    ) {
        if (!in_array($type, ['i', 'd', 's', 'b'], true)) {
            throw new \InvalidArgumentException("Invalid parameter type: {$type}. Must be one of: i, d, s, b");
        }
    }

    /**
     * Auto-detect type from value
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
     * Create integer parameter
     */
    public static function int(int $value): self
    {
        return new self($value, 'i');
    }

    /**
     * Create double parameter
     */
    public static function double(float $value): self
    {
        return new self($value, 'd');
    }

    /**
     * Create string parameter
     */
    public static function string(string $value): self
    {
        return new self($value, 's');
    }

    /**
     * Create blob parameter
     */
    public static function blob(string $value): self
    {
        return new self($value, 'b');
    }

    /**
     * Create boolean parameter (stored as integer)
     */
    public static function bool(bool $value): self
    {
        return new self($value ? 1 : 0, 'i');
    }
}

