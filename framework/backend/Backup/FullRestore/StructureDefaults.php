<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

final readonly class StructureDefaults
{
    /**
     * @param string $engine Default storage engine at execution
     * @param string $charset Schema character set
     * @param string $collation Schema collation
     * @param array<string, string> $collations Server default collation for each character set
     * @param string $sqlMode Actual execution mode; the empty mode is valid
     * @param string $rowFormat Effective default InnoDB row format
     * @param array<string, string> $charsetByCollation Server-proven character set for each unambiguous full collation name
     * @param bool $mariaDbJsonAlias Whether this invocation's destination proves the MariaDB JSON alias
     */
    public function __construct(
        public string $engine,
        public string $charset,
        public string $collation,
        public array $collations = [],
        public string $sqlMode = '',
        public string $rowFormat = 'dynamic',
        public array $charsetByCollation = [],
        public bool $mariaDbJsonAlias = false,
    ) {
    }
}
