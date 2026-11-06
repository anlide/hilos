<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\FullRestore\DumpServerVersion;
use Hilos\Backup\FullRestore\PreparedTarget;
use Hilos\Backup\FullRestore\TableDrop;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionConfig;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseMarker;
use Hilos\Database\DatabaseMarkerRow;
use Hilos\Database\Exception\SqlRuntime\TableNotFoundException;
use Hilos\Database\MigrationClaim;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;

/**
 * Private to one cold command or hot child and rebuilt on every invocation, never persisted.
 * This preparation binds inputs and targets; schema/history proof and cleanup planning remain
 * separate work. An empty cleanup list does not prove that the target has no extra tables.
 */
final readonly class FullRestorePlan
{
    private const string SERVER_HOST = 'server_host';
    private const string SERVER_PORT = 'server_port';
    private const string SERVER_ID = 'server_id';
    private const string SCHEMA_NAME = 'schema_name';

    /**
     * @param list<PreparedTarget> $targets Admitted streams in import order
     * @param ?DatabaseMarkerRow $marker Primary marker before any replacement, or its absence
     * @param array<int, DatabaseConnectionConfig> $configuration Low-level connection registry snapshot, private to this invocation
     * @param array<int, DumpServerVersion> $serverVersions Destination grammars used to admit each prepared stream
     * @param list<TableDrop> $cleanup Proven table removals in dependency order; not populated by input preparation
     */
    private function __construct(
        public array $targets,
        public ?DatabaseMarkerRow $marker,
        private array $configuration,
        private array $serverVersions,
        public array $cleanup = [],
    ) {
    }

    /**
     * Reads every input and checks every destination before returning any executable plan.
     * Metadata queries belong here: ORM collections cannot describe physical server identity.
     * No target DDL, migration initialization or other data write is permitted in this pass.
     *
     * @param list<BackupConnectionMeta> $connections Sidecar connections
     * @param string $workDir Private extraction directory
     * @return self One invocation's prepared inputs, destinations and primary marker
     * @throws RestoreFailedException When any input or destination refuses, before a database write
     * @throws DatabaseException When the caller's connection cannot be restored
     */
    public static function prepare(array $connections, string $workDir): self
    {
        $callerIndex = Database::getCurrentIndex();
        $index = null;
        try {
            if ($connections === []) {
                throw new RestoreFailedException('The full archive names no connections');
            }
            $configuration = self::configuration();
            usort($connections, static fn (BackupConnectionMeta $a, BackupConnectionMeta $b): int => $a->index <=> $b->index);
            $indices = [];
            $addresses = [];
            $targets = [];
            $serverVersions = [];
            $marker = null;
            foreach ($connections as $connection) {
                $index = $connection->index;
                if (isset($indices[$index])) {
                    throw new RestoreFailedException('Duplicate connection index');
                }
                $indices[$index] = true;
                $config = Database::getConnectionConfig($index);
                Database::useConnection($index);
                $address = self::targetAddress();
                if (isset($addresses[$address])) {
                    throw new RestoreFailedException('Duplicate physical target: ' . $address);
                }
                $addresses[$address] = true;
                self::refuseRollout();
                if ($index === DatabaseConnectionDefaults::PRIMARY_INDEX) {
                    $marker = DatabaseMarker::current();
                }
                $serverVersions[$index] = new DumpServerVersion(Database::getServerInfo());

                $source = $workDir . '/' . BackupCreator::SQL_FILE_PREFIX . $index . BackupCreator::SQL_FILE_SUFFIX;
                $sourceDigest = self::digest($source);
                $staging = $source . '.staged';
                if (file_exists($staging) || is_link($staging)) {
                    throw new RestoreFailedException('The prepared dump destination already exists');
                }
                if ($index === DatabaseConnectionDefaults::PRIMARY_INDEX) {
                    $dump = FullRestoreDumpReader::stagePrimary($source, $staging, $serverVersions[$index]);
                } else {
                    $dump = FullRestoreDumpReader::read($source, $serverVersions[$index]);
                    FsPath::copy($source, $staging);
                }
                if (self::digest($source) !== $sourceDigest) {
                    throw new RestoreFailedException('The extracted dump changed during preparation');
                }
                $targets[] = new PreparedTarget(
                    $connection, $config, $address, $source, $sourceDigest, $staging, self::digest($staging), $dump,
                );
            }

            return new self($targets, $marker, $configuration, $serverVersions);
        } catch (DatabaseException | FsException | RestoreFailedException $failure) {
            throw RestoreFailedException::beforeDestructive(
                'Full restore preparation, connection ' . ($index ?? 'all') . ': ' . $failure->getMessage(),
                $failure,
            );
        } finally {
            Database::useConnection($callerIndex);
        }
    }

    /**
     * Rechecks the evidence available to input preparation immediately before the first write.
     * This is not the later planner's schema, migration-track or provenance snapshot.
     *
     * @throws RestoreFailedException When a prepared input, binding, marker or rollout changed
     * @throws DatabaseException When the caller's connection cannot be restored
     */
    public function assertUnchanged(): void
    {
        $callerIndex = Database::getCurrentIndex();
        $index = null;
        try {
            if (Database::getConfiguredIndices() !== array_keys($this->configuration)) {
                throw new RestoreFailedException('The configured connection indices changed after preparation');
            }
            foreach ($this->configuration as $index => $config) {
                if (Database::getConnectionConfig($index) != $config) {
                    throw new RestoreFailedException('The configured target changed after preparation');
                }
            }
            foreach ($this->targets as $target) {
                $index = $target->connection->index;
                Database::useConnection($index);
                if (self::targetAddress() !== $target->address) {
                    throw new RestoreFailedException('The physical target changed after preparation');
                }
                if (Database::getServerInfo() !== $this->serverVersions[$index]->serverInfo) {
                    throw new RestoreFailedException('The target server version changed after preparation');
                }
                self::refuseRollout();
                if ($index === DatabaseConnectionDefaults::PRIMARY_INDEX && DatabaseMarker::current() != $this->marker) {
                    throw new RestoreFailedException('The primary database marker changed after preparation');
                }
                if (self::digest($target->sourcePath) !== $target->sourceDigest
                    || self::digest($target->sqlPath) !== $target->sqlDigest) {
                    throw new RestoreFailedException('A full restore dump changed after preparation');
                }
            }
        } catch (DatabaseException | FsException | RestoreFailedException $failure) {
            throw RestoreFailedException::beforeDestructive(
                'Full restore recheck, connection ' . ($index ?? 'all') . ': ' . $failure->getMessage(), $failure,
            );
        } finally {
            Database::useConnection($callerIndex);
        }
    }

    /**
     * @return array<int, DatabaseConnectionConfig> Snapshot of the low-level configuration registry, including unused indices
     * @throws DatabaseException When a configured index disappears during the read
     */
    private static function configuration(): array
    {
        $configuration = [];
        foreach (Database::getConfiguredIndices() as $index) {
            $configuration[$index] = Database::getConnectionConfig($index);
        }

        return $configuration;
    }

    /**
     * @return string Server-reported identity and selected schema, independent of client aliases
     * @throws DatabaseException When the target cannot be queried
     * @throws RestoreFailedException When the server does not identify the configured schema
     */
    private static function targetAddress(): string
    {
        if (!Database::isConnected()) {
            Database::connect();
        }
        $row = Database::sql('SELECT @@hostname AS ' . self::SERVER_HOST . ', @@port AS ' . self::SERVER_PORT
            . ', @@server_id AS ' . self::SERVER_ID . ', DATABASE() AS ' . self::SCHEMA_NAME)->row();
        if ($row === null || $row[self::SCHEMA_NAME] !== Database::getConnectionConfig(Database::getCurrentIndex())->database) {
            throw new RestoreFailedException('The connection selected a different database');
        }

        return json_encode([
            $row[self::SERVER_HOST], $row[self::SERVER_PORT], $row[self::SERVER_ID], $row[self::SCHEMA_NAME],
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @throws DatabaseException When the live rollout claim cannot be read
     * @throws RestoreFailedException When a rollout is still held, even by an apparently dead process
     */
    private static function refuseRollout(): void
    {
        try {
            $claim = MigrationClaim::current();
        } catch (TableNotFoundException) {
            return;
        }
        if ($claim !== null) {
            throw new RestoreFailedException('A schema rollout is held by ' . $claim->holder);
        }
    }

    /**
     * @param string $path Private input or prepared stream
     * @return string File content digest
     * @throws RestoreFailedException When the input is not an ordinary readable file
     * @throws FsException When hashing fails
     */
    private static function digest(string $path): string
    {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new RestoreFailedException('Missing or non-regular readable dump: ' . basename($path));
        }

        return FsPath::hash(BackupVerifier::DIGEST_ALGO, $path);
    }
}
