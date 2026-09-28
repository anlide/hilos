<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\EnvConstants;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseSql;
use Hilos\Database\Exception\MigrationMarkedFailedException;
use Hilos\Database\Migration;
use Hilos\Database\MigrationClaim;
use Hilos\Database\MigrationClaimHolder;
use Hilos\Hilos;
use Hilos\Utils\Logger;

/**
 * The schema rollout claim against the live test database (HIL-1228).
 *
 * Nodes starting together cannot be raced inside one PHP process, so the cases pin down the
 * pieces the race is decided by: a database at its level takes no claim, a rollout gives its
 * claim up whichever way it ends, a migration an earlier holder left failed is refused by name,
 * a node's start takes its own row back and no other process does, and a waiting process goes on
 * the moment the row is gone. The wait itself is driven through the pause the claim takes, so a
 * case never sleeps and never hangs: a pause a case did not expect fails it.
 */
final class MigrationClaimIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Migration track the fixture migration file is written under. */
    private const string MIGRATION_TRACK = 'main';

    /**
     * Index of the fixture migration. Far above any real schema and apart from the restore
     * fixtures' 9000/9001: it is applied to the shared test database, and tearDown deletes it.
     */
    private const int FIXTURE_MIGRATION_INDEX = 9101;

    /** Table the fixture migration creates; the proof that it ran. Dropped on teardown. */
    private const string PROBE_TABLE = 'hilos_fw_migration_claim_probe';

    /** Node id the cases run under, so the node-start holder has a known name. */
    private const string NODE = 'claim-test-node';

    /** Holder of a claim that is somebody else's. */
    private const string OTHER_HOLDER = 'another-node';

    /** When a stale claim was taken: long enough ago that taking it back visibly moves it. */
    private const string STALE_CLAIMED_AT = '2026-01-02 03:04:05';

    /** Polls after which the pause of a waiting case removes the row it waits on. */
    private const int POLLS_BEFORE_RELEASE = 3;

    /**
     * Connection index no other case migrates on, so the migration tables are probed on it afresh:
     * the flag that skips the probe is per connection and lives as long as the process.
     */
    private const int FRESH_INDEX = 4;

    private string $migrationRoot = '';

    private string $logFile = '';

    private string|false $previousNode = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrationRoot = sys_get_temp_dir() . '/hilos-migration-claim-it-' . getmypid();
        $this->removeTree($this->migrationRoot);
        mkdir($this->migrationRoot . '/' . self::MIGRATION_TRACK, 0700, true);
        Migration::setMigrationListPath($this->migrationRoot);
        Migration::setMigrationName(self::MIGRATION_TRACK);

        $this->previousNode = getenv(EnvConstants::CLUSTER_NODE_ID->name);
        putenv(EnvConstants::CLUSTER_NODE_ID->name . '=' . self::NODE);

        $this->logFile = $this->migrationRoot . '/main.log';
        file_put_contents($this->logFile, '');
        Logger::setLogFile($this->logFile);

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
        // The migration table is shared with every other suite against this database, so the
        // fixture's row leaves with the fixture.
        Database::sql('DELETE FROM `migration` WHERE `index` = ?', [self::FIXTURE_MIGRATION_INDEX]);
        Database::sql('DROP TABLE IF EXISTS `' . self::PROBE_TABLE . '`');

        Logger::resetLogFile();
        if ($this->previousNode === false) {
            putenv(EnvConstants::CLUSTER_NODE_ID->name);
        } else {
            putenv(EnvConstants::CLUSTER_NODE_ID->name . '=' . $this->previousNode);
        }
        $this->removeTree($this->migrationRoot);

        parent::tearDown();
    }

    public function testADatabaseAtItsLevelTakesNoClaim(): void
    {
        $this->listFixtureMigration();
        Migration::recordAppliedLevel(self::FIXTURE_MIGRATION_INDEX);
        $this->insertClaim(self::OTHER_HOLDER);

        $this->assertSame(0, Migration::migrateUp());

        $this->assertSame(self::OTHER_HOLDER, MigrationClaim::current()?->holder, 'Another holder\'s row must stay');
    }

    public function testAPendingMigrationIsAppliedAndTheClaimGivenUp(): void
    {
        $this->listFixtureMigration();

        $this->assertSame(1, Migration::migrateUp());

        $this->assertSame(self::FIXTURE_MIGRATION_INDEX, Migration::getCurrentIndex());
        $this->assertTrue($this->probeExists(), 'The fixture migration must have run');
        $this->assertNull(MigrationClaim::current());
    }

    public function testAMigrationAnEarlierRunLeftFailedIsRefusedByNameAndTheClaimGivenUp(): void
    {
        $this->listFixtureMigration();
        Database::sql(
            'INSERT INTO `migration` (`index`, `failed`) VALUES (?, 1)',
            [self::FIXTURE_MIGRATION_INDEX],
        );

        try {
            Migration::migrateUp();
            $this->fail('A migration marked failed must refuse the rollout');
        } catch (MigrationMarkedFailedException $refusal) {
            $this->assertStringContainsString(
                'Migration ' . self::FIXTURE_MIGRATION_INDEX . ' is marked failed',
                $refusal->getMessage(),
            );
            $this->assertStringContainsString(
                'db:migration:retry ' . self::FIXTURE_MIGRATION_INDEX,
                $refusal->getMessage(),
            );
        }

        $this->assertFalse($this->probeExists(), 'The failed migration must not run again');
        $this->assertNull(MigrationClaim::current(), 'The next holder must not wait on a refused rollout');
    }

    public function testANodeStartTakesItsOwnRowBack(): void
    {
        $holder = MigrationClaimHolder::nodeStart();
        $this->insertClaim($holder->name);

        MigrationClaim::take($holder, $this->unexpectedPause(...));

        $claim = MigrationClaim::current();
        $this->assertSame(self::NODE, $claim?->holder);
        $this->assertNotSame(self::STALE_CLAIMED_AT, $claim?->claimedAt, 'Taking the row back must restamp it');
        $this->assertStringContainsString(
            "Taking back the schema rollout claim this node's previous start left at " . self::STALE_CLAIMED_AT,
            $this->log(),
        );
    }

    public function testANodeStartRollsOutOverItsOwnStaleRow(): void
    {
        $this->listFixtureMigration();
        $this->insertClaim(self::NODE);

        $this->assertSame(1, Migration::migrateUp(holder: MigrationClaimHolder::nodeStart()));

        $this->assertTrue($this->probeExists(), 'The fixture migration must have run');
        $this->assertNull(MigrationClaim::current());
    }

    public function testAnyOtherProcessWaitsEvenOnARowWithItsOwnName(): void
    {
        $holder = MigrationClaimHolder::process();
        $this->insertClaim($holder->name);
        $pauses = 0;

        MigrationClaim::take($holder, function () use (&$pauses): void {
            $pauses++;
            if ($pauses === self::POLLS_BEFORE_RELEASE) {
                $this->assertTrue(MigrationClaim::releaseHeldBy($this->holderOfTheWaitedRow()));
            }
        });

        $this->assertSame(self::POLLS_BEFORE_RELEASE, $pauses, 'The claim is taken on the first poll after the row is gone');
        $claim = MigrationClaim::current();
        $this->assertSame($holder->name, $claim?->holder);
        $this->assertNotSame(self::STALE_CLAIMED_AT, $claim?->claimedAt, 'The claim must be a new row');
        $this->assertSame(
            1,
            substr_count($this->log(), 'Waiting for the schema rollout claim held by ' . $holder->name),
            'Three polls write one waiting line: the first',
        );
    }

    public function testAClaimIsReleasedOnlyByItsHolder(): void
    {
        $this->insertClaim(self::OTHER_HOLDER);

        MigrationClaim::release(MigrationClaimHolder::process());
        $this->assertFalse(MigrationClaim::releaseHeldBy(self::NODE));

        $this->assertSame(self::OTHER_HOLDER, MigrationClaim::current()?->holder);
        $this->assertTrue(MigrationClaim::releaseHeldBy(self::OTHER_HOLDER));
        $this->assertNull(MigrationClaim::current());
    }

    public function testTheClaimTableIsCreatedWhereTheMigrationTableAlreadyIs(): void
    {
        Database::sql('DROP TABLE IF EXISTS `' . MigrationClaim::TABLE . '`');
        Database::configure(
            index: self::FRESH_INDEX,
            host: Hilos::$env[EnvConstants::DB_HOST]->string(),
            user: Hilos::$env[EnvConstants::DB_USERNAME]->string(),
            password: Hilos::$env[EnvConstants::DB_PASSWORD]->string(),
            database: Hilos::$env[EnvConstants::DB_DATABASE]->string(),
            port: Hilos::$env[EnvConstants::DB_PORT]->int(),
            charset: DatabaseConnectionDefaults::CHARSET,
        );
        Database::connect(self::FRESH_INDEX);
        Database::useConnection(self::FRESH_INDEX);
        try {
            Migration::initialize();
        } finally {
            Database::close(self::FRESH_INDEX);
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }

        Database::sql(DatabaseSql::tableExistsProbe(MigrationClaim::TABLE));
        $this->assertNull(MigrationClaim::current(), 'The table must be there, and empty');
    }

    /**
     * Writes the one migration file the rollout cases apply: it creates the probe table.
     */
    private function listFixtureMigration(): void
    {
        file_put_contents(
            $this->migrationRoot . '/' . self::MIGRATION_TRACK . '/' . self::FIXTURE_MIGRATION_INDEX . '_up.sql',
            'CREATE TABLE `' . self::PROBE_TABLE . "` (id INT PRIMARY KEY);\n",
        );
    }

    /**
     * Puts a claim row in place as a process that took it long ago would have left it.
     *
     * @param string $holder Holder name the row carries
     * @throws DatabaseException When the row cannot be written
     */
    private function insertClaim(string $holder): void
    {
        Database::sql(
            'INSERT INTO `' . MigrationClaim::TABLE . '` (`id`, `holder`, `claimed_at`) VALUES (?, ?, ?)',
            [MigrationClaim::CLAIM_ID, $holder, self::STALE_CLAIMED_AT],
        );
    }

    /**
     * @return string Holder of the row a waiting case is waiting on
     * @throws DatabaseException When the claim cannot be read
     */
    private function holderOfTheWaitedRow(): string
    {
        return MigrationClaim::current()?->holder ?? $this->fail('The waited row must still be there');
    }

    /**
     * Pause of a claim that must be taken without waiting.
     */
    private function unexpectedPause(): void
    {
        $this->fail('The claim must be taken without waiting');
    }

    /**
     * @return bool Whether the fixture migration's table exists
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
     * @return string What the cases wrote to the journal so far
     */
    private function log(): string
    {
        return (string)file_get_contents($this->logFile);
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
