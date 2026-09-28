<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\AdminViewMode\AdminViewModeLatchFile;
use Hilos\AdminViewMode\AdminViewModeLatchTable;
use Hilos\AdminViewMode\AdminViewModeStartup;
use Hilos\Constants\EnvConstants;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseSql;
use Hilos\Database\Migration;
use Hilos\Hilos;
use Hilos\Utils\Logger;

/**
 * The admin view mode decided at the start of a node, against a real log directory and the live
 * test database (HIL-1249).
 *
 * The verdict itself is a pure function and is held case by case in its unit test; what is held
 * here is the reading and the writing around it. On production a missing half of the latch is
 * written back from the other, a row already there is not a failure, and a latch that cannot be
 * read keeps the mode off without refusing the start. On a stand neither half is looked at.
 */
final class AdminViewModeStartupIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Node id the cases start under. */
    private const string NODE = 'latch-test-node';

    /** Environment and node a latch written "by another node" carries. */
    private const string OTHER_ENVIRONMENT = 'staging';

    /** Node a latch written "by another node" carries. */
    private const string OTHER_NODE = 'another-node';

    /** When the latch of another node was closed: far from now, so a copy of it is told from a fresh one. */
    private const int OTHER_CLOSED_AT = 1700000000;

    /**
     * Connection index no other case migrates on, so the framework tables are probed on it afresh:
     * the flag that skips the probe is per connection and lives as long as the process. Apart from
     * the rollout claim case's index 4 for the same reason.
     */
    private const int FRESH_INDEX = 5;

    /** @var list<string> Variables the cases set, whose earlier values tearDown puts back */
    private const array SET_VARIABLES = [
        EnvConstants::APP_ENV->name,
        EnvConstants::CLUSTER_NODE_ID->name,
        EnvConstants::DAEMON_LOG_FILE->name,
        EnvConstants::HILOS_ADMIN_VIEW_MODE_ENABLED->name,
    ];

    private string $logRoot = '';

    private string $logFile = '';

    /** @var array<string, string|false> Variable name => value before the case */
    private array $previousValues = [];

    private bool $tableDropped = false;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::SET_VARIABLES as $name) {
            $this->previousValues[$name] = getenv($name);
        }

        $this->logRoot = sys_get_temp_dir() . '/hilos-admin-view-mode-it-' . getmypid();
        $this->removeTree($this->logRoot);
        mkdir($this->logRoot, 0700, true);
        $this->logFile = $this->logRoot . '/daemon.log';
        file_put_contents($this->logFile, '');
        Logger::setLogFile($this->logFile);

        putenv(EnvConstants::DAEMON_LOG_FILE->name . '=' . $this->logFile);
        putenv(EnvConstants::CLUSTER_NODE_ID->name . '=' . self::NODE);
        putenv(EnvConstants::APP_ENV->name . '=prod');

        Migration::initialize();
        Database::sql('DELETE FROM `' . AdminViewModeLatchTable::TABLE . '`');
    }

    /**
     * @throws DatabaseException When the latch table cannot be created again or emptied
     */
    protected function tearDown(): void
    {
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        if ($this->tableDropped) {
            $this->createTheFrameworkTablesAfresh();
        }
        Database::sql('DELETE FROM `' . AdminViewModeLatchTable::TABLE . '`');

        Logger::resetLogFile();
        foreach ($this->previousValues as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        $this->removeTree($this->logRoot);

        parent::tearDown();
    }

    public function testProductionStartedWithTheModeOffWritesBothHalvesAndKeepsItOff(): void
    {
        $this->setVariable(false);

        $this->assertFalse(AdminViewModeStartup::run());

        $file = AdminViewModeLatchFile::read($this->logRoot, 'unused', 'unused');
        $row = AdminViewModeLatchTable::read();
        $this->assertNotNull($file, 'The file half must be written');
        $this->assertSame($file, $row, 'Both halves must carry one record');
        $this->assertSame('prod', $row[AdminViewModeLatchFile::KEY_ENVIRONMENT]);
        $this->assertSame(self::NODE, $row[AdminViewModeLatchFile::KEY_NODE]);
        $this->assertStringContainsString('closed on this installation for good', $this->journal());
    }

    public function testProductionWithTheVariableOnAndNoLatchHasTheModeOnAndWritesNothing(): void
    {
        $this->setVariable(true);

        $this->assertTrue(AdminViewModeStartup::run());

        $this->assertNull(AdminViewModeLatchFile::read($this->logRoot, 'unused', 'unused'));
        $this->assertNull(AdminViewModeLatchTable::read());
        $this->assertStringContainsString('WARNING: Admin view mode: on in production', $this->journal());
    }

    public function testAFileAloneKeepsTheModeOffAndTheRowIsWrittenBackFromIt(): void
    {
        $this->setVariable(true);
        AdminViewModeLatchFile::publish($this->logRoot, self::OTHER_ENVIRONMENT, self::OTHER_NODE, self::OTHER_CLOSED_AT);

        $this->assertFalse(AdminViewModeStartup::run());

        $this->assertSame($this->otherNodesRecord(), AdminViewModeLatchTable::read());
        $journal = $this->journal();
        $this->assertStringContainsString('has been written back from ' . AdminViewModeLatchFile::pathIn($this->logRoot), $journal);
        $this->assertStringContainsString('ERROR: Admin view mode: HILOS_ADMIN_VIEW_MODE_ENABLED is on', $journal);
        $this->assertStringContainsString('delete ' . AdminViewModeLatchFile::pathIn($this->logRoot), $journal);
    }

    public function testARowAloneKeepsTheModeOffAndTheFileIsWrittenBackFromIt(): void
    {
        $this->setVariable(true);
        AdminViewModeLatchTable::close(self::OTHER_ENVIRONMENT, self::OTHER_NODE, self::OTHER_CLOSED_AT);

        $this->assertFalse(AdminViewModeStartup::run());

        $this->assertSame(
            $this->otherNodesRecord(),
            AdminViewModeLatchFile::read($this->logRoot, 'unused', 'unused'),
        );
        $this->assertStringContainsString(
            'Admin view mode: the latch file ' . AdminViewModeLatchFile::pathIn($this->logRoot) . ' was missing',
            $this->journal(),
        );
    }

    /**
     * Two nodes closing the mode together: the second insert finds the first one's row, and that
     * is the same outcome - the row stays as the first node wrote it.
     */
    public function testClosingOverARowAnotherNodeWroteIsNotAFailure(): void
    {
        AdminViewModeLatchTable::close(self::OTHER_ENVIRONMENT, self::OTHER_NODE, self::OTHER_CLOSED_AT);

        AdminViewModeLatchTable::close('prod', self::NODE, time());

        $this->assertSame($this->otherNodesRecord(), AdminViewModeLatchTable::read());
    }

    /**
     * A database migrated by nothing that knows the latch: the read fails, the mode stays off, and
     * the start goes on - nothing is written on the strength of a half-read latch.
     */
    public function testAMissingTableKeepsTheModeOffWithoutRefusingTheStart(): void
    {
        $this->setVariable(true);
        Database::sql('DROP TABLE `' . AdminViewModeLatchTable::TABLE . '`');
        $this->tableDropped = true;

        $this->assertFalse(AdminViewModeStartup::run());

        $this->assertNull(AdminViewModeLatchFile::read($this->logRoot, 'unused', 'unused'));
        $this->assertStringContainsString('ERROR: Admin view mode: the latch could not be read or written', $this->journal());
    }

    /**
     * On a stand the variable is the whole answer: a latch the stand is handed is neither read nor
     * rewritten, so both halves stay exactly as they were.
     */
    public function testAStandHasTheModeTheVariableSaysAndLeavesTheLatchAlone(): void
    {
        putenv(EnvConstants::APP_ENV->name . '=test');
        $this->setVariable(true);
        AdminViewModeLatchTable::close(self::OTHER_ENVIRONMENT, self::OTHER_NODE, self::OTHER_CLOSED_AT);

        $this->assertTrue(AdminViewModeStartup::run());

        $this->assertNull(AdminViewModeLatchFile::read($this->logRoot, 'unused', 'unused'));
        $this->assertSame($this->otherNodesRecord(), AdminViewModeLatchTable::read());
        $this->assertStringContainsString('Admin view mode: on.', $this->journal());
    }

    private function setVariable(bool $on): void
    {
        putenv(EnvConstants::HILOS_ADMIN_VIEW_MODE_ENABLED->name . '=' . ($on ? 'true' : 'false'));
    }

    /**
     * @return array{environment: string, node: string, closedAt: int} The latch record another node wrote
     */
    private function otherNodesRecord(): array
    {
        return [
            AdminViewModeLatchFile::KEY_ENVIRONMENT => self::OTHER_ENVIRONMENT,
            AdminViewModeLatchFile::KEY_NODE => self::OTHER_NODE,
            AdminViewModeLatchFile::KEY_CLOSED_AT => self::OTHER_CLOSED_AT,
        ];
    }

    private function journal(): string
    {
        return (string)file_get_contents($this->logFile);
    }

    /**
     * Creates the framework tables again on a connection whose probe flag is still down.
     *
     * @throws DatabaseException When the fresh connection cannot be opened or a table cannot be created
     */
    private function createTheFrameworkTablesAfresh(): void
    {
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

        Database::sql(DatabaseSql::tableExistsProbe(AdminViewModeLatchTable::TABLE));
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff((array)scandir($path), ['.', '..']) as $entry) {
            unlink($path . '/' . $entry);
        }
        rmdir($path);
    }
}
