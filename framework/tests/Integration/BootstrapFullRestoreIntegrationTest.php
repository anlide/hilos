<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\AdminViewMode\AdminViewModeLatchTable;
use Hilos\Backup\BackupConnectionMeta;
use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupRestorer;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\BackupVerifier;
use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\FullRestore\TableDrop;
use Hilos\Backup\FullRestorePlan;
use Hilos\Backup\FullRestorePlanner;
use Hilos\Backup\FullRestoreSchemaSnapshot;
use Hilos\Backup\RestoreEnvDecision;
use Hilos\Backup\RestorePhase;
use Hilos\Constants\EnvConstants;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseMarker;
use Hilos\Database\DatabaseMarkerRow;
use Hilos\Database\Entity\Item\AccountOperationResult;
use Hilos\Database\Migration;
use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkMigrationOrigin;
use Hilos\Database\MigrationClaim;
use Hilos\Database\MigrationSqlReader;
use Hilos\Database\MigrationUpFile;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/** Real delivered UP and destructive FULL import require a disposable schema outside rollback scopes. */
final class BootstrapFullRestoreIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string CHANGE = '20261109000848893922_HIL-1918_bootstrap_moments';
    private const int ARCHIVE_LEVEL = 117;

    /** The session moments as an archive taken before they moved to milliseconds holds them, cut to what the delivered part alters. */
    private const string HISTORICAL_SESSION = 'CREATE TABLE hilos_session (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
        . ' user_id INT UNSIGNED DEFAULT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . ' last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . ' expires_at TIMESTAMP NULL DEFAULT NULL, pending_registration_since TIMESTAMP NULL DEFAULT NULL,'
        . ' pending_second_factor_until TIMESTAMP NULL DEFAULT NULL, KEY idx_session_expires (expires_at),'
        . ' KEY idx_session_anonymous_seen (user_id, last_seen_at)) ENGINE=InnoDB';
    private const string BACKUP_ID = '2026-11-08_12-00-00';

    private string $database;
    private string $root;
    private array $previousMigration;
    private EnvAccessor $previousEnv;
    private string|false $previousBackupDir;
    private string|false $previousLogFile;

    /** Copies the complete real Chat track and opens an isolated destination with historical rows. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousMigration = [];
        foreach (['migrationListPath', 'migrationName', 'initialized'] as $property) {
            $this->previousMigration[$property] = new ReflectionProperty(Migration::class, $property)->getValue();
        }
        $this->previousEnv = Hilos::$env;
        $this->previousBackupDir = getenv('BACKUP_DIR');
        $this->previousLogFile = getenv('DAEMON_LOG_FILE');
        $this->database = 'hilos-bootstrap-restore-' . bin2hex(random_bytes(6));
        $this->root = sys_get_temp_dir() . '/' . $this->database;
        FsPath::ensureDirectory($this->root . '/Schema', 0700);
        foreach (glob(dirname(__DIR__, 3) . '/demo/chat/backend/Database/Migration/Schema/*.sql') as $file) {
            FsPath::copy($file, $this->root . '/Schema/' . basename($file));
        }
        Migration::setMigrationListPath($this->root);
        Migration::setMigrationName('Schema');
        new ReflectionProperty(Migration::class, 'initialized')->setValue(null, []);
        $config = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::close();
        Database::configure(DatabaseConnectionDefaults::PRIMARY_INDEX,
            $config->host, 'root', Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string(),
            $config->database, $config->port, $config->charset);
        Database::connect();
        Database::sql('CREATE DATABASE `' . $this->database . '`');
        Database::close();
        Database::configure(DatabaseConnectionDefaults::PRIMARY_INDEX,
            $config->host, 'root', Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string(),
            $this->database, $config->port, $config->charset);
        Database::connect();
        Migration::initialize();
        Database::sql('INSERT INTO migration VALUES ' . $this->historyValues());
        $catalog = new FrameworkMigrationCatalog();
        $erasureDefinitions = [];
        foreach (['115_framework_install_account_erasure_operation', '116_framework_install_account_erasure_assignment'] as $migration) {
            $upSql = FsPath::read($this->root . '/Schema/' . $migration . '.sql');
            $catalog->verifyPair($upSql, FsPath::read($this->root . '/Schema/' . $migration . '_down.sql'));
            $body = substr($upSql, strpos($upSql, "\n") + 1);
            $erasureDefinitions[] = $body;
            foreach (MigrationSqlReader::statements($body) as $statement) {
                Database::sql($statement);
            }
        }
        $circleDefinition = FsPath::read($this->root . '/Schema/045_create_hilos_verifier_circle.sql');
        foreach (MigrationSqlReader::statements($circleDefinition) as $statement) {
            Database::sql($statement);
        }
        Database::sql(self::HISTORICAL_SESSION);
        Database::sql('CREATE TABLE restore_probe (id INT PRIMARY KEY, label VARCHAR(32) NOT NULL)');
        Database::sql("INSERT INTO restore_probe VALUES(1,'untouched')");
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        putenv('BACKUP_DIR=' . $this->root);
        putenv('DAEMON_LOG_FILE=' . $this->root . '/daemon.log');
        $this->publishArchive($erasureDefinitions, $circleDefinition);
    }

    /** Removes every fixture resource and restores process configuration even after a refusal. */
    protected function tearDown(): void
    {
        try {
            Database::sql('DROP DATABASE IF EXISTS `' . $this->database . '`');
            $this->removeTree($this->root);
        } finally {
            Hilos::$env = $this->previousEnv;
            putenv($this->previousBackupDir === false ? 'BACKUP_DIR' : 'BACKUP_DIR=' . $this->previousBackupDir);
            putenv($this->previousLogFile === false ? 'DAEMON_LOG_FILE' : 'DAEMON_LOG_FILE=' . $this->previousLogFile);
            foreach ($this->previousMigration as $property => $value) {
                new ReflectionProperty(Migration::class, $property)->setValue(null, $value);
            }
            parent::tearDown();
        }
    }

    /**
     * @param bool $applied Whether the target ran delivered UP 118–121, including bootstrap parts 119–121
     * @param bool $legacy Whether the target marker still uses its historical DATETIME storage
     */
    #[DataProvider('targetHistories')]
    public function testHistoricalFullRestoresThroughDeliveredBootstrapParts(bool $applied, bool $legacy): void
    {
        if ($legacy) {
            $marker = $this->legacyMarker();
        } else {
            $marker = new DatabaseMarkerRow(str_repeat('a', 32), 'target-node', 2208988800456);
            DatabaseMarker::put($marker);
        }
        if ($applied) {
            $this->assertSame(12, Migration::migrateUp());
        }
        $plan = FullRestorePlanner::prepare(FullRestorePlan::prepare($this->connections(), $this->root . '/build'));
        $this->assertSame(
            [118, 119, 120, 121, 122, 123, 124, 125, 126, 127, 128, 128, 128, 129, 129, 129, 129, 129],
            array_map(static fn ($step): int => $step->file->index, $plan->migrations->targets[DatabaseConnectionDefaults::PRIMARY_INDEX]->pending),
        );
        $this->assertSame($applied ? [
            [DatabaseConnectionDefaults::PRIMARY_INDEX, 'hilos_account_operation_result'],
            [DatabaseConnectionDefaults::PRIMARY_INDEX, 'hilos_account_operation_assignment'],
            [DatabaseConnectionDefaults::PRIMARY_INDEX, 'hilos_account_operation'],
        ] : [],
            array_map(static fn (TableDrop $drop): array => [$drop->index, $drop->table], $plan->cleanup));
        $plan->assertUnchanged();

        new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);

        $this->assertSame([['id' => '1', 'label' => 'archive']], Database::sql('SELECT * FROM restore_probe')->rows());
        $this->assertEquals($marker, DatabaseMarker::current());
        $this->assertSame($legacy ? 2208988800000 : 2208988800456, DatabaseMarker::current()?->writtenAt);
        $this->assertSame('bigint', Database::sql('SELECT DATA_TYPE FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [DatabaseMarker::TABLE, 'written_at'])->row()['DATA_TYPE']);
        $this->assertSame(1700000000000, AdminViewModeLatchTable::read()['closedAt']);
        $this->assertNull(MigrationClaim::current());
        $this->assertSame([[
            'TABLE_NAME' => 'hilos_account_operation_result',
            'COLUMN_NAME' => 'assignment_id',
            'REFERENCED_TABLE_NAME' => 'hilos_account_operation_assignment',
            'REFERENCED_COLUMN_NAME' => 'id',
        ]], Database::sql('SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME'
            . ' FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
            . ' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME', [AccountOperationResult::_table])->rows());
        $this->assertSame([118, 119, 120, 121, 122, 123, 124, 125, 126, 127, 128, 129], array_values(array_filter(
            Migration::readHistory(DatabaseConnectionDefaults::PRIMARY_INDEX)->successfulIndices(),
            static fn (int $index): bool => $index > self::ARCHIVE_LEVEL,
        )));
        $this->assertSame(0, Migration::migrateUp());
    }

    /** @return list<array{bool, bool}> Current-history replay, pending-only validation and real legacy target conversion */
    public static function targetHistories(): array
    {
        return [[true, false], [false, false], [false, true]];
    }

    /** @param bool $afterPreparation Whether the trigger appears only in the final destructive-window recheck */
    #[DataProvider('bootstrapTriggerTimes')]
    public function testLegacyMarkerTriggerRefusesBeforeImport(bool $afterPreparation): void
    {
        $marker = $this->legacyMarker();
        $markerRows = Database::sql('SELECT * FROM ' . DatabaseMarker::TABLE)->rows();
        $history = Migration::readHistory(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::sql('CREATE TABLE retained_probe (id INT PRIMARY KEY, label VARCHAR(32) NOT NULL)');
        Database::sql("INSERT INTO retained_probe VALUES(1,'untouched')");
        if (!$afterPreparation) {
            $this->markerTrigger();
        }
        try {
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW,
                onPhase: function (RestorePhase $phase) use ($afterPreparation): void {
                    if ($afterPreparation && $phase === RestorePhase::IMPORTING) {
                        $this->markerTrigger();
                    }
                });
            $this->fail('A retained bootstrap trigger must refuse before destructive import');
        } catch (RestoreFailedException $failure) {
            $this->assertFalse($failure->databaseTouched());
            $this->assertStringContainsString($afterPreparation ? 'changed after full restore preparation' : 'bootstrap UPDATE effects',
                $failure->getMessage());
            $this->assertSame([['id' => '1', 'label' => 'untouched']], Database::sql('SELECT * FROM retained_probe')->rows());
            $this->assertSame([['id' => '1', 'label' => 'untouched']], Database::sql('SELECT * FROM restore_probe')->rows());
            $this->assertSame($markerRows, Database::sql('SELECT * FROM ' . DatabaseMarker::TABLE)->rows());
            $this->assertEquals($marker, DatabaseMarker::current());
            $this->assertEquals($history, Migration::readHistory(DatabaseConnectionDefaults::PRIMARY_INDEX));
        }
    }

    /** @return list<array{bool}> An unchanged trigger and a trigger introduced after successful preparation */
    public static function bootstrapTriggerTimes(): array
    {
        return [[false], [true]];
    }

    /**
     * @param bool $afterPreparation Whether reused staging and its FK appear only after the planner succeeded
     * @param string $column Physical spelling, including the same column under case-insensitive SQL lookup
     */
    #[DataProvider('bootstrapStagingTimes')]
    public function testLegacyMarkerStagingCascadeRefusesBeforeImport(bool $afterPreparation, string $column): void
    {
        $marker = $this->legacyMarker();
        $history = Database::sql('SELECT * FROM migration ORDER BY `index`')->rows();
        // MariaDB requires the cascading child to accept NULL from nullable staging.
        Database::sql('CREATE TABLE retained_probe (id INT PRIMARY KEY, marker_epoch BIGINT NULL) ENGINE=InnoDB');
        Database::sql('INSERT INTO retained_probe VALUES(1,1)');
        if (!$afterPreparation) {
            $this->markerStagingCascade($column);
        }
        $markerRows = Database::sql('SELECT * FROM ' . DatabaseMarker::TABLE)->rows();
        $inputs = FullRestorePlan::prepare($this->connections(), $this->root . '/build');
        $snapshot = FullRestoreSchemaSnapshot::read($inputs->targets);
        $this->assertTrue($snapshot->schemas[DatabaseConnectionDefaults::PRIMARY_INDEX]->completeTriggerVisibility);
        $this->assertSame([], $snapshot->schemas[DatabaseConnectionDefaults::PRIMARY_INDEX]->triggers);
        $this->assertSame('1', (string)Database::sql('SELECT @@foreign_key_checks AS enabled')->row()['enabled']);
        try {
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW,
                onPhase: function (RestorePhase $phase) use ($afterPreparation, $column, &$markerRows): void {
                    if ($afterPreparation && $phase === RestorePhase::IMPORTING) {
                        $this->markerStagingCascade($column);
                        $markerRows = Database::sql('SELECT * FROM ' . DatabaseMarker::TABLE)->rows();
                    }
                });
            $this->fail('Reused bootstrap staging must refuse before its UPDATE can cascade to retained rows');
        } catch (RestoreFailedException $failure) {
            $this->assertFalse($failure->databaseTouched());
            $this->assertStringContainsString($afterPreparation ? 'changed after full restore preparation'
                : 'existing bootstrap staging column __hil1918_written_at', $failure->getMessage());
            $this->assertSame([['id' => '1', 'marker_epoch' => '1']], Database::sql('SELECT * FROM retained_probe')->rows());
            $this->assertSame([['id' => '1', 'label' => 'untouched']], Database::sql('SELECT * FROM restore_probe')->rows());
            $this->assertSame($markerRows, Database::sql('SELECT * FROM ' . DatabaseMarker::TABLE)->rows());
            $this->assertSame([['staging_value' => '1']],
                Database::sql('SELECT __hil1918_written_at AS staging_value FROM ' . DatabaseMarker::TABLE)->rows());
            $this->assertEquals($marker, DatabaseMarker::current());
            $this->assertSame($history, Database::sql('SELECT * FROM migration ORDER BY `index`')->rows());
        }
    }

    /** @return list<array{bool, string}> Existing staging in both identifier cases, plus appearance in the final recheck window */
    public static function bootstrapStagingTimes(): array
    {
        return [[false, '__hil1918_written_at'], [false, '__HIL1918_WRITTEN_AT'], [true, '__hil1918_written_at']];
    }

    /** @param bool $afterPreparation Whether the auto-writer and its FK appear only after successful preparation */
    #[DataProvider('bootstrapAutomaticUpdateTimes')]
    public function testLegacyMarkerAutomaticUpdateCascadeRefusesBeforeImport(bool $afterPreparation): void
    {
        $marker = $this->legacyMarker();
        $history = Database::sql('SELECT * FROM migration ORDER BY `index`')->rows();
        Database::sql('CREATE TABLE retained_probe (id INT PRIMARY KEY, audit_stamp TIMESTAMP NULL DEFAULT NULL) ENGINE=InnoDB');
        Database::sql("INSERT INTO retained_probe VALUES(1,'2000-01-01 00:00:00')");
        if (!$afterPreparation) {
            $this->markerAutomaticUpdateCascade();
        }
        $markerRows = Database::sql('SELECT * FROM ' . DatabaseMarker::TABLE)->rows();
        $inputs = FullRestorePlan::prepare($this->connections(), $this->root . '/build');
        $snapshot = FullRestoreSchemaSnapshot::read($inputs->targets);
        $metadata = $snapshot->schemas[DatabaseConnectionDefaults::PRIMARY_INDEX];
        $this->assertTrue($metadata->completeTriggerVisibility);
        $this->assertSame([], $metadata->triggers);
        $this->assertSame([], array_values(array_filter($metadata->columns,
            static fn (array $column): bool => $column['TABLE_NAME'] === DatabaseMarker::TABLE
                && strcasecmp($column['COLUMN_NAME'], '__hil1918_written_at') === 0)));
        $this->assertSame('1', (string)Database::sql('SELECT @@foreign_key_checks AS enabled')->row()['enabled']);
        try {
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW,
                onPhase: function (RestorePhase $phase) use ($afterPreparation, &$markerRows): void {
                    if ($afterPreparation && $phase === RestorePhase::IMPORTING) {
                        $this->markerAutomaticUpdateCascade();
                        $markerRows = Database::sql('SELECT * FROM ' . DatabaseMarker::TABLE)->rows();
                    }
                });
            $this->fail('A bootstrap ON UPDATE must refuse before import; retained rows: '
                . json_encode(Database::sql('SELECT * FROM retained_probe')->rows()));
        } catch (RestoreFailedException $failure) {
            $this->assertFalse($failure->databaseTouched());
            $this->assertStringContainsString($afterPreparation ? 'changed after full restore preparation'
                : 'bootstrap column audit_stamp has automatic ON UPDATE effects', $failure->getMessage());
            $this->assertSame([['id' => '1', 'audit_stamp' => '2000-01-01 00:00:00']], Database::sql('SELECT * FROM retained_probe')->rows());
            $this->assertSame([['id' => '1', 'label' => 'untouched']], Database::sql('SELECT * FROM restore_probe')->rows());
            $this->assertSame($markerRows, Database::sql('SELECT * FROM ' . DatabaseMarker::TABLE)->rows());
            $this->assertSame([['audit_stamp' => '2000-01-01 00:00:00']],
                Database::sql('SELECT audit_stamp FROM ' . DatabaseMarker::TABLE)->rows());
            $this->assertEquals($marker, DatabaseMarker::current());
            $this->assertSame($history, Database::sql('SELECT * FROM migration ORDER BY `index`')->rows());
        }
    }

    /** @return list<array{bool}> Existing auto-writer and late schema drift at the existing physical recheck */
    public static function bootstrapAutomaticUpdateTimes(): array
    {
        return [[false], [true]];
    }

    /** @param bool $forged Whether the substituted body has internally consistent but false provenance */
    #[DataProvider('compoundSubstitutions')]
    public function testUnknownOrForgedCompoundRefusesBeforeImport(bool $forged): void
    {
        $file = $this->adminViewModeBootstrapFile();
        $up = "DELIMITER $$\nBEGIN NOT ATOMIC\nDELETE FROM restore_probe;\nEND$$\nDELIMITER ;\n";
        if ($forged) {
            $part = new FrameworkMigrationCatalog()->part(self::CHANGE, 'admin_view_mode_latch');
            $origin = FrameworkMigrationOrigin::forBodies(FrameworkMigrationOrigin::CHANGE, [$part->unit], [$part], $up, $part->downSql);
            FsPath::write($file->path, $origin->header() . "\n" . $up);
            FsPath::write(substr($file->path, 0, -4) . '_down.sql', $origin->header() . "\n" . $part->downSql);
        } else {
            FsPath::write($file->path, $up);
        }
        $marker = new DatabaseMarkerRow(str_repeat('a', 32), 'target-node', -1);
        DatabaseMarker::put($marker);
        $history = Migration::readHistory(DatabaseConnectionDefaults::PRIMARY_INDEX);
        try {
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);
            $this->fail('Unproven compound SQL must refuse before destructive import');
        } catch (RestoreFailedException $failure) {
            $this->assertFalse($failure->databaseTouched());
            $this->assertStringContainsString($forged ? 'immutable source' : 'Opaque UP operation', $failure->getMessage());
            $this->assertSame([['id' => '1', 'label' => 'untouched']], Database::sql('SELECT * FROM restore_probe')->rows());
            $this->assertEquals($marker, DatabaseMarker::current());
            $this->assertEquals($history, Migration::readHistory(DatabaseConnectionDefaults::PRIMARY_INDEX));
        }
    }

    /** @return list<array{bool}> Unsigned compound SQL and a body rehashed under an authentic part reference */
    public static function compoundSubstitutions(): array
    {
        return [[false], [true]];
    }

    /** A verified UP never lets a changed counterpart survive the final evidence recheck. */
    public function testDownBodyDriftAfterPreparationRefusesBeforeImport(): void
    {
        $file = $this->adminViewModeBootstrapFile();
        $marker = new DatabaseMarkerRow(str_repeat('a', 32), 'target-node', 2208988800456);
        DatabaseMarker::put($marker);
        $history = Migration::readHistory(DatabaseConnectionDefaults::PRIMARY_INDEX);
        try {
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW,
                onPhase: static function (RestorePhase $phase) use ($file): void {
                    if ($phase === RestorePhase::IMPORTING) {
                        $path = substr($file->path, 0, -4) . '_down.sql';
                        FsPath::write($path, FsPath::read($path) . "-- changed after preparation\n");
                    }
                });
            $this->fail('Changed DOWN evidence must refuse before destructive import');
        } catch (RestoreFailedException $failure) {
            $this->assertFalse($failure->databaseTouched());
            $this->assertStringContainsString('body hash disagrees', $failure->getMessage());
            $this->assertSame([['id' => '1', 'label' => 'untouched']], Database::sql('SELECT * FROM restore_probe')->rows());
            $this->assertEquals($marker, DatabaseMarker::current());
            $this->assertEquals($history, Migration::readHistory(DatabaseConnectionDefaults::PRIMARY_INDEX));
        }
    }

    /** @return MigrationUpFile The unique verified delivered latch alteration, before deliberate source corruption */
    private function adminViewModeBootstrapFile(): MigrationUpFile
    {
        $catalog = new FrameworkMigrationCatalog();
        $matches = [];
        foreach (Migration::readUpTrack()->after(self::ARCHIVE_LEVEL) as $file) {
            if (!str_starts_with($file->content, FrameworkMigrationOrigin::PREFIX)) {
                continue;
            }
            $origin = $catalog->verifyPair($file->content, FsPath::read(substr($file->path, 0, -4) . '_down.sql'));
            if ($origin->kind === FrameworkMigrationOrigin::CHANGE && $origin->units === ['admin_view_mode_latch']
                && count($origin->parts) === 1 && $origin->parts[0]->key() === self::CHANGE . '/admin_view_mode_latch') {
                $matches[] = $file;
            }
        }
        $this->assertCount(1, $matches);
        $this->assertSame(119, $matches[0]->index);
        return $matches[0];
    }

    /** @return DatabaseMarkerRow The whole-second moment representable by the legacy target column */
    private function legacyMarker(): DatabaseMarkerRow
    {
        Database::sql('DROP TABLE ' . DatabaseMarker::TABLE);
        Database::sql('CREATE TABLE ' . DatabaseMarker::TABLE
            . ' (id TINYINT UNSIGNED PRIMARY KEY, marker CHAR(32) NOT NULL, written_by VARCHAR(255) NOT NULL,'
            . ' written_at DATETIME NOT NULL)');
        Database::sql('INSERT INTO ' . DatabaseMarker::TABLE . " VALUES(1,?,'target-node','2040-01-01 00:00:00')", [str_repeat('a', 32)]);
        return new DatabaseMarkerRow(str_repeat('a', 32), 'target-node', 2208988800000);
    }

    /** The canonical legacy UPDATE would reach a target-only table absent from the archive. */
    private function markerTrigger(): void
    {
        Database::sql('CREATE TRIGGER marker_updates_retained AFTER UPDATE ON ' . DatabaseMarker::TABLE
            . " FOR EACH ROW UPDATE retained_probe SET label='changed' WHERE id=1");
    }

    /** @param string $column Physical staging spelling; canonical lowercase references must reach the same column */
    private function markerStagingCascade(string $column): void
    {
        Database::sql('ALTER TABLE ' . DatabaseMarker::TABLE
            . ' ADD COLUMN `' . $column . '` BIGINT NULL, ADD UNIQUE KEY marker_staging (__hil1918_written_at)');
        Database::sql('UPDATE ' . DatabaseMarker::TABLE . ' SET __hil1918_written_at=1 WHERE id=1');
        Database::sql('ALTER TABLE retained_probe ADD CONSTRAINT retained_marker_epoch FOREIGN KEY (marker_epoch)'
            . ' REFERENCES ' . DatabaseMarker::TABLE . ' (__hil1918_written_at) ON UPDATE CASCADE');
    }

    /** A pristine staging UPDATE can change another owner column and cascade into the retained child. */
    private function markerAutomaticUpdateCascade(): void
    {
        Database::sql('ALTER TABLE ' . DatabaseMarker::TABLE
            . ' ADD COLUMN audit_stamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . ' ADD UNIQUE KEY marker_audit_stamp (audit_stamp)');
        Database::sql('UPDATE ' . DatabaseMarker::TABLE . " SET audit_stamp='2000-01-01 00:00:00' WHERE id=1");
        Database::sql('ALTER TABLE retained_probe ADD CONSTRAINT retained_marker_audit FOREIGN KEY (audit_stamp)'
            . ' REFERENCES ' . DatabaseMarker::TABLE . ' (audit_stamp) ON UPDATE CASCADE');
    }

    /** @return list<BackupConnectionMeta> The archive's historical primary level */
    private function connections(): array
    {
        return [new BackupConnectionMeta(DatabaseConnectionDefaults::PRIMARY_INDEX, $this->database, self::ARCHIVE_LEVEL)];
    }

    /** @return string Complete successful historical rows, rather than a synthetic high migration index */
    private function historyValues(): string
    {
        return implode(',', array_map(static fn (int $index): string => "({$index},0)", range(1, self::ARCHIVE_LEVEL)));
    }

    /**
     * Publishes a completed historical FULL stream using the creator's archive and digest layout.
     *
     * @param list<string> $erasureDefinitions Verified historical operation and assignment bodies, parent first
     * @param string $circleDefinition Historical verifier circle definition, before its moment moved to milliseconds
     */
    private function publishArchive(array $erasureDefinitions, string $circleDefinition): void
    {
        FsPath::ensureDirectory($this->root . '/build', 0700);
        FsPath::ensureDirectory($this->root . '/' . BackupScope::FULL->value, 0700);
        $sql = "DROP TABLE IF EXISTS migration;\n"
            . "CREATE TABLE migration (`index` INT NOT NULL PRIMARY KEY, failed TINYINT NOT NULL);\n"
            . 'INSERT INTO migration VALUES ' . $this->historyValues() . ";\n"
            . 'DROP TABLE IF EXISTS ' . MigrationClaim::TABLE . ";\n"
            . 'CREATE TABLE ' . MigrationClaim::TABLE
            . " (id TINYINT UNSIGNED PRIMARY KEY, holder VARCHAR(255) NOT NULL, claimed_at DATETIME NOT NULL);\n"
            . 'DROP TABLE IF EXISTS ' . AdminViewModeLatchTable::TABLE . ";\n"
            . 'CREATE TABLE ' . AdminViewModeLatchTable::TABLE
            . ' (id TINYINT UNSIGNED PRIMARY KEY, environment VARCHAR(255) NOT NULL, node VARCHAR(255) NOT NULL,'
            . " closed_at INT UNSIGNED NOT NULL);\n"
            . 'INSERT INTO ' . AdminViewModeLatchTable::TABLE . " VALUES(1,'prod','archive-node',1700000000);\n"
            . 'DROP TABLE IF EXISTS ' . DatabaseMarker::TABLE . ";\n"
            . 'CREATE TABLE ' . DatabaseMarker::TABLE
            . ' (id TINYINT UNSIGNED PRIMARY KEY, marker CHAR(32) NOT NULL, written_by VARCHAR(255) NOT NULL,'
            . " written_at DATETIME NOT NULL);\n"
            . 'INSERT INTO ' . DatabaseMarker::TABLE . " VALUES(1,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','archive-node','2026-11-08 12:00:00');\n"
            . 'DROP TABLE IF EXISTS hilos_account_erasure_assignment' . ";\n"
            . 'DROP TABLE IF EXISTS hilos_account_erasure_operation' . ";\n"
            . implode("\n", $erasureDefinitions) . "\n"
            . "DROP TABLE IF EXISTS hilos_verifier_circle;\n"
            . $circleDefinition . "\n"
            . "DROP TABLE IF EXISTS hilos_session;\n"
            . self::HISTORICAL_SESSION . ";\n"
            . "DROP TABLE IF EXISTS restore_probe;\n"
            . "CREATE TABLE restore_probe (id INT PRIMARY KEY, label VARCHAR(32) NOT NULL);\n"
            . "INSERT INTO restore_probe VALUES(1,'archive');\n"
            . "-- Dump completed on 2026-11-08 12:00:00\n";
        FsPath::write($this->root . '/build/' . BackupCreator::SQL_FILE_PREFIX . '0' . BackupCreator::SQL_FILE_SUFFIX, $sql);
        $stem = $this->root . '/' . BackupScope::FULL->value . '/' . BackupCreator::archiveBaseName(self::BACKUP_ID, 'test', BackupScope::FULL);
        $archive = $stem . BackupCreator::ARCHIVE_EXTENSION;
        exec('tar -czf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($this->root . '/build') . ' .', $output, $exitCode);
        $this->assertSame(0, $exitCode);
        $metadata = new BackupMetadata(self::BACKUP_ID, '2026-11-08T12:00:00+00:00', 'test', BackupScope::FULL,
            $this->connections(), (int)filesize($archive), 1, false, BackupStatus::SUCCESS,
            sha256: (string)hash_file(BackupVerifier::DIGEST_ALGO, $archive));
        FsPath::write($stem . BackupCreator::SIDECAR_EXTENSION, json_encode($metadata->toArray(), JSON_THROW_ON_ERROR));
    }

    /** @param string $path Fixture-owned temporary directory */
    private function removeTree(string $path): void
    {
        foreach (FsPath::entries($path) as $entry) {
            $child = $path . '/' . $entry;
            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                FsPath::delete($child);
            }
        }
        FsPath::removeDirectory($path);
    }
}
