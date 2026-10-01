<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\EnvConstants;
use Hilos\Core\CLI\Commands\MigrationUpCommand;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseSql;
use Hilos\Database\Exception\MigrationNumberTakenTwiceException;
use Hilos\Database\Exception\MigrationSkippedBelowLevelException;
use Hilos\Database\Migration;
use Hilos\Database\MigrationClaim;

/**
 * A track the database cannot follow is refused against the live test database (HIL-1238).
 *
 * The cases stage what the migrator used to pass in silence - a file below the level that never
 * ran, a number taken by two files - and the one write that keeps a restored database judgeable:
 * a declared level leaves a row for every file under it. The rules that need a database with no
 * real rows (the lowest row, an empty table) are proven by MigrationTrackCheckTest instead.
 */
final class MigrationTrackIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Migration track the fixture files are written under. */
    private const string MIGRATION_TRACK = 'main';

    /**
     * Lowest and highest index a case may use: far above any real schema and apart from the
     * restore fixtures' 9000/9001 and the claim fixture's 9101. Every row in the range leaves on
     * teardown - one left behind lifts the shared database's level above 9101, and the claim
     * cases would then be refused for skipping their own fixture.
     */
    private const int FIRST_FIXTURE_INDEX = 9200;

    private const int LAST_FIXTURE_INDEX = 9299;

    /** Table a fixture migration creates; the proof that it ran. Dropped on teardown. */
    private const string PROBE_TABLE = 'hilos_fw_migration_track_probe';

    /** Node id the cases run under, so the rollout claim has a known holder name. */
    private const string NODE = 'track-test-node';

    private string $migrationRoot = '';

    private string|false $previousNode = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrationRoot = sys_get_temp_dir() . '/hilos-migration-track-it-' . getmypid();
        $this->removeTree($this->migrationRoot);
        mkdir($this->migrationRoot . '/' . self::MIGRATION_TRACK, 0700, true);
        Migration::setMigrationListPath($this->migrationRoot);
        Migration::setMigrationName(self::MIGRATION_TRACK);

        $this->previousNode = getenv(EnvConstants::CLUSTER_NODE_ID->name);
        putenv(EnvConstants::CLUSTER_NODE_ID->name . '=' . self::NODE);

        Migration::initialize();
        MigrationClaim::clear();
    }

    /**
     * @throws DatabaseException When the fixture rows cannot be dropped
     */
    protected function tearDown(): void
    {
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Migration::initialize();
        MigrationClaim::clear();
        Database::sql(
            'DELETE FROM `migration` WHERE `index` BETWEEN ? AND ?',
            [self::FIRST_FIXTURE_INDEX, self::LAST_FIXTURE_INDEX],
        );
        Database::sql('DROP TABLE IF EXISTS `' . self::PROBE_TABLE . '`');

        if ($this->previousNode === false) {
            putenv(EnvConstants::CLUSTER_NODE_ID->name);
        } else {
            putenv(EnvConstants::CLUSTER_NODE_ID->name . '=' . $this->previousNode);
        }
        $this->removeTree($this->migrationRoot);

        parent::tearDown();
    }

    public function testAFileBelowTheLevelThatNeverRanIsRefusedByName(): void
    {
        $this->listFile('9201_first.sql', $this->harmlessSql());
        $this->listFile('9202_create_probe.sql', $this->createProbeSql());
        $this->listFile('9203_third.sql', $this->harmlessSql());
        $this->recordRow(9201);
        $this->recordRow(9203);

        try {
            Migration::migrateUp();
            $this->fail('A file below the level that never ran must be refused');
        } catch (MigrationSkippedBelowLevelException $refusal) {
            $this->assertStringContainsString(
                "9202_create_probe.sql is below the database's level 9203 and was never applied",
                $refusal->getMessage(),
            );
        }

        $this->assertSame([9201, 9203], $this->fixtureRows());
        $this->assertFalse($this->probeExists(), 'The skipped file must not be applied out of order');
        $this->assertNull(MigrationClaim::current(), 'The check takes no claim');
    }

    public function testANumberTakenByTwoFilesIsRefusedBeforeAnythingIsApplied(): void
    {
        $this->listFile('9211_create_probe.sql', $this->createProbeSql());
        $this->listFile('9211_other.sql', $this->harmlessSql());

        try {
            Migration::migrateUp();
            $this->fail('A number taken by two files must be refused');
        } catch (MigrationNumberTakenTwiceException $refusal) {
            $this->assertStringContainsString(
                'number 9211 is taken by more than one file (9211_create_probe.sql, 9211_other.sql)',
                $refusal->getMessage(),
            );
        }

        $this->assertSame([], $this->fixtureRows());
        $this->assertFalse($this->probeExists(), 'Neither file may be applied');
    }

    public function testADeclaredLevelLeavesARowForEveryFileUnderIt(): void
    {
        // Replaying either lower file would fail on a table that does not exist: a green rollout
        // is the proof that both were declared rather than run.
        $this->listFile('9221_replayed.sql', $this->failingSql());
        $this->listFile('9222_replayed.sql', $this->failingSql());
        $this->listFile('9224_create_probe.sql', $this->createProbeSql());

        Migration::recordAppliedLevel(9223);

        $this->assertSame([9221, 9222, 9223], $this->fixtureRows());
        $this->assertSame(1, Migration::migrateUp());
        $this->assertSame([9221, 9222, 9223, 9224], $this->fixtureRows());
        $this->assertTrue($this->probeExists(), 'The file above the declared level must be applied');
    }

    public function testTheUpCommandRefusesAHoleRatherThanReportingUpToDate(): void
    {
        $this->listFile('9231_first.sql', $this->harmlessSql());
        $this->listFile('9232_create_probe.sql', $this->createProbeSql());
        $this->listFile('9233_third.sql', $this->harmlessSql());
        $this->recordRow(9231);
        $this->recordRow(9233);

        ob_start();
        try {
            new MigrationUpCommand()->execute(['force' => true], []);
            $this->fail('A database at the code\'s level with a hole below it must be refused');
        } catch (MigrationSkippedBelowLevelException $refusal) {
            $this->assertStringContainsString('9232_create_probe.sql', $refusal->getMessage());
        } finally {
            $output = (string)ob_get_clean();
        }

        $this->assertStringNotContainsString('up to date', $output);
        $this->assertFalse($this->probeExists(), 'The skipped file must not be applied');
    }

    public function testARollbackRefusesANumberTakenByTwoDownFiles(): void
    {
        $this->listFile('9241_create_probe.sql', $this->createProbeSql());
        $this->listFile('9241_a_down.sql', $this->harmlessSql());
        $this->listFile('9241_b_down.sql', $this->harmlessSql());
        $this->recordRow(9241);

        try {
            Migration::migrateDown(9240);
            $this->fail('A rollback must not pick one of two down files by name');
        } catch (MigrationNumberTakenTwiceException $refusal) {
            $this->assertStringContainsString(
                'number 9241 is taken by more than one file (9241_a_down.sql, 9241_b_down.sql, 9241_create_probe.sql)',
                $refusal->getMessage(),
            );
        }

        $this->assertSame([9241], $this->fixtureRows());
        $this->assertNull(MigrationClaim::current(), 'The rollback gives its claim up on the refusal');
    }

    /**
     * @param string $fileName Name of the migration file in the fixture track
     * @param string $sql What the file runs
     */
    private function listFile(string $fileName, string $sql): void
    {
        file_put_contents($this->migrationRoot . '/' . self::MIGRATION_TRACK . '/' . $fileName, $sql);
    }

    /**
     * Records an index as applied, the row a rollout leaves for a file it ran.
     *
     * @param int $index Fixture index to record
     * @throws DatabaseException When the row cannot be written
     */
    private function recordRow(int $index): void
    {
        Database::sql('INSERT INTO `migration` (`index`, `failed`) VALUES (?, 0)', [$index]);
    }

    /**
     * @return list<int> Fixture indices the `migration` table holds, ascending
     * @throws DatabaseException When the table cannot be read
     */
    private function fixtureRows(): array
    {
        Database::sql(
            'SELECT `index` FROM `migration` WHERE `index` BETWEEN ? AND ? ORDER BY `index`',
            [self::FIRST_FIXTURE_INDEX, self::LAST_FIXTURE_INDEX],
        );
        $rows = [];
        while ($row = Database::row()) {
            $rows[] = (int)$row['index'];
        }

        return $rows;
    }

    /**
     * @return string SQL that creates the probe table
     */
    private function createProbeSql(): string
    {
        return 'CREATE TABLE `' . self::PROBE_TABLE . "` (id INT PRIMARY KEY);\n";
    }

    /**
     * @return string SQL that changes nothing
     */
    private function harmlessSql(): string
    {
        return "SELECT 1;\n";
    }

    /**
     * @return string SQL that fails whenever it runs
     */
    private function failingSql(): string
    {
        return "SELECT * FROM `hilos_fw_migration_track_absent`;\n";
    }

    /**
     * @return bool Whether the probe table exists
     */
    private function probeExists(): bool
    {
        try {
            Database::sql(DatabaseSql::tableExistsProbe(self::PROBE_TABLE));
        } catch (DatabaseException) {
            return false;
        }

        return true;
    }

    /**
     * @param string $path Directory to remove with everything under it
     */
    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff((array)scandir($path), ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
