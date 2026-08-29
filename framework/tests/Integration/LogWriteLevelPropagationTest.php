<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\DbSyncApplicator;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Log\LogSettingsCatalog;
use Hilos\Log\LogWriteLevelApplier;
use Hilos\Log\LogWriteLevelSubscriber;
use Hilos\Utils\LogLevel;
use Hilos\Utils\Logger;

/**
 * An administrator's edit reaching another process's threshold, without a restart (HIL-761).
 *
 * The unit cases each own one link of the chain - the rule, the reader, the subscriber - and none
 * of them can say the links join up. This one walks the whole way on a real table: a settings row
 * is written the way the settings screen writes it, the frame that write produces is applied by a
 * second context standing in for another worker, and the level that context logs at follows.
 *
 * Two contexts in one process, each with its own object cache, is how a parallel worker is staged
 * throughout this suite; what is shared here is the logger, which is exactly the thing a real
 * second process would have of its own. So the assertions read the level, which the applier only
 * touches through the announcement being followed.
 */
final class LogWriteLevelPropagationTest extends FrameworkIntegrationTestCase
{
    /** @var list<string> Framework tables this case needs */
    private const array TABLES = ['hilos_setting'];

    /** Truth-source id the settings writes in this case are claimed under. */
    private const string TEST_AGENT_ID = 'log-write-level-propagation-agent';

    private ?DbContext $previousDb = null;

    private ?SettingsAccessor $previousSettings = null;

    /** A second context standing in for another worker, with its own object cache */
    private ?PropagationTestDbContext $rival = null;

    /**
     * @throws DatabaseException When a stub statement fails
     * @throws HilosException When a context cannot be configured
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::runStubs(down: true);
        self::runStubs(down: false);

        TruthSourceRegistry::register(HilosDbContext::settings, true, self::TEST_AGENT_ID);

        $this->previousDb = Hilos::$db;
        $this->previousSettings = Hilos::$setting;

        $db = new PropagationTestDbContext();
        $db->configure();
        Hilos::$db = $db;

        $this->rival = new PropagationTestDbContext();
        $this->rival->configure();

        Hilos::$setting = new SettingsAccessor(LogSettingsCatalog::class);

        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new LogWriteLevelSubscriber());
        LogWriteLevelApplier::reset();
        Logger::setWriteLevel(LogLevel::Info);
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        Logger::setWriteLevel(LogLevel::Info);
        LogWriteLevelApplier::reset();
        SourceChangeBus::reset();

        $this->rival = null;
        Hilos::$setting = $this->previousSettings;
        Hilos::$db = $this->previousDb;

        TruthSourceRegistry::unregister(HilosDbContext::settings, self::TEST_AGENT_ID);

        self::runStubs(down: true);

        parent::tearDown();
    }

    /**
     * Writing the row raises the threshold in the very process that wrote it.
     *
     * @throws HilosException When the settings write fails
     */
    public function testWritingTheRowMovesTheThresholdOfTheWritingProcess(): void
    {
        $this->writeWriteLevel(LogLevel::Warning);

        $this->assertSame(LogLevel::Warning, Logger::writeLevel());
    }

    /**
     * The whole point of the leaf: the edit was made somewhere else, the frame arrived, and the
     * threshold followed with nothing restarted and nobody polling.
     *
     * @throws HilosException When the settings write or the frame application fails
     */
    public function testAnEditInOneProcessReachesTheThresholdOfAnother(): void
    {
        $id = $this->writeWriteLevel(LogLevel::Warning);

        // The second context holds its own copy of the row, as a second worker would.
        $this->assertNotNull($this->rival?->settings[LogSettingsCatalog::WRITE_LEVEL]);

        Logger::setWriteLevel(LogLevel::Info);
        $this->applyRivalFrame($id, [ObjectSetting::value => LogLevel::Error->value]);

        $this->assertSame(LogLevel::Error, Logger::writeLevel());
    }

    /**
     * An update frame names no key - editing a setting changes its value alone - so a frame about
     * a neighbouring row cannot be dismissed without looking. It costs one settings read, and the
     * threshold lands on what the settings actually say rather than on a guess about the frame.
     *
     * @throws HilosException When the settings write or the frame application fails
     */
    public function testAFrameAboutANeighbouringRowLeavesTheThresholdOnWhatTheSettingsSay(): void
    {
        $this->writeWriteLevel(LogLevel::Error);
        $cronId = $this->writeOtherSetting(LogSettingsCatalog::ROTATION_CRON, '0 3 * * *');

        $rival = $this->rival;
        $this->assertNotNull($rival);
        $this->assertNotNull($rival->settings[LogSettingsCatalog::WRITE_LEVEL]);
        $this->assertNotNull($rival->settings[LogSettingsCatalog::ROTATION_CRON]);

        Logger::setWriteLevel(LogLevel::Info);
        $this->applyRivalFrame($cronId, [ObjectSetting::value => '0 4 * * *']);

        $this->assertSame(LogLevel::Error, Logger::writeLevel());
    }

    /**
     * Writes the write-level row the way the settings screen writes one, and reports its id.
     *
     * @param LogLevel $level Level to store
     * @return int Id of the written row
     * @throws HilosException When the settings write fails
     */
    private function writeWriteLevel(LogLevel $level): int
    {
        $db = Hilos::$db;
        $this->assertInstanceOf(PropagationTestDbContext::class, $db);

        $written = $db->settings->actions->add(
            LogSettingsCatalog::WRITE_LEVEL,
            $level->value,
            LogSettingsCatalog::getCatalog(),
        );

        $id = $written->id;
        $this->assertNotNull($id);

        return $id;
    }

    /**
     * Writes some other settings row, so a frame about a row that is not ours can be staged.
     *
     * @param string $key Setting key to write
     * @param string $value Value to store
     * @return int Id of the written row
     * @throws HilosException When the settings write fails
     */
    private function writeOtherSetting(string $key, string $value): int
    {
        $db = Hilos::$db;
        $this->assertInstanceOf(PropagationTestDbContext::class, $db);

        $written = $db->settings->actions->add($key, $value, LogSettingsCatalog::getCatalog());

        $id = $written->id;
        $this->assertNotNull($id);

        return $id;
    }

    /**
     * Hands the second context the frame another process's edit of one row produces.
     *
     * The table is written first, as the other process would have written it, so what the second
     * context reads back after applying the frame is the state the cluster is actually in.
     *
     * @param int $id Row id the frame is about
     * @param array<string, mixed> $diff Columns the row now holds, as the frame carries them
     * @throws HilosException When applying the frame fails
     */
    private function applyRivalFrame(int $id, array $diff): void
    {
        $rival = $this->rival;
        $this->assertNotNull($rival);

        Database::sql(
            'UPDATE `hilos_setting` SET `value` = ? WHERE `id` = ?',
            [$diff[ObjectSetting::value], $id],
        );

        $previousDb = Hilos::$db;
        Hilos::$db = $rival;
        try {
            DbSyncApplicator::applyUpdated(new DbSyncUpdatedSignalData(
                HilosDbContext::settings,
                (string)$id,
                $diff,
            ));
        } finally {
            Hilos::$db = $previousDb;
        }
    }

    /**
     * Runs one direction of the stub file of every table this case uses.
     *
     * @param bool $down Run the down (drop) stubs when true, the create stubs when false
     * @throws DatabaseException When a stub statement fails
     */
    private static function runStubs(bool $down): void
    {
        // external-boundary: the neutral element of the name being built - the up file carries no suffix
        $suffix = $down ? '_down' : '';
        foreach (self::TABLES as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }
}

/**
 * A framework database context with nothing but the framework's own collections.
 *
 * The settings are a framework collection, so the smallest honest context for this case is
 * {@see HilosDbContext} with no project collections added.
 */
final class PropagationTestDbContext extends HilosDbContext
{
}
