<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

use Hilos\Backup\BackupConnectionMeta;
use Hilos\Database\DatabaseConnectionConfig;

/** One run's admitted input and the exact configured destination it was checked against. */
final readonly class PreparedTarget
{
    /**
     * @param BackupConnectionMeta $connection Source metadata; its database name never selects the target
     * @param DatabaseConnectionConfig $config Immutable mysql destination and credentials
     * @param string $address Server-reported endpoint and schema, without credentials
     * @param string $sourcePath Extracted input file
     * @param string $sourceDigest Input digest before admission
     * @param string $sqlPath Private prepared stream used by mysql
     * @param string $sqlDigest Prepared stream digest
     * @param Dump $dump Admitted schema and explicit archive history
     */
    public function __construct(
        public BackupConnectionMeta $connection,
        public DatabaseConnectionConfig $config,
        public string $address,
        public string $sourcePath,
        public string $sourceDigest,
        public string $sqlPath,
        public string $sqlDigest,
        public Dump $dump,
    ) {
    }
}
