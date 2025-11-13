<?php

namespace Hilos\Database\Schema;

/**
 * IndexInfo - Information about a database index
 */
readonly class IndexInfo
{
    /**
     * @param string $name Index name
     * @param array<string> $columns Column names in index
     * @param bool $unique Whether index is unique
     */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique,
    ) {
    }
}

