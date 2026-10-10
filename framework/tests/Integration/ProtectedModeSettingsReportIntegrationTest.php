<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\DbSyncApplicator;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\SettingsCatalogStub;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\ProtectedMode\ProtectedModeSettingsCatalog;
use Hilos\ProtectedMode\ProtectedModeSettingsReporter;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeSettingsDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;

/**
 * The effective setting crosses the worker link when a settings row changes, including by sync.
 *
 * A second DB context gives an arriving frame its own object cache, as another worker has. The
 * reporter listens to the same source bus and speaks only when the effective boolean changes.
 */
final class ProtectedModeSettingsReportIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string TEST_AGENT_ID = 'protected-mode-settings-report';

    private ?DbContext $previousDb = null;

    private ?SettingsAccessor $previousSettings = null;

    private ProtectedModeSettingsRecordingClient $client;

    private ProtectedModeSettingsReporter $reporter;

    /**
     * @throws DatabaseException When the settings stub cannot be prepared
     * @throws HilosException When a DB context cannot be configured
     */
    protected function setUp(): void
    {
        parent::setUp();
        self::runStub(down: true);
        self::runStub(down: false);

        TruthSourceRegistry::register(HilosDbContext::settings, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        $this->previousDb = Hilos::$db;
        $this->previousSettings = Hilos::$setting;

        $db = new ProtectedModeSettingsTestDbContext();
        $db->configure();
        Hilos::$db = $db;

        $this->client = new ProtectedModeSettingsRecordingClient();
        $this->reporter = new ProtectedModeSettingsReporter($this->client);
        SourceChangeBus::reset();
        SourceChangeBus::subscribe($this->reporter);
    }

    /**
     * @throws DatabaseException When the settings stub cannot be dropped
     */
    protected function tearDown(): void
    {
        SourceChangeBus::reset();
        Hilos::$setting = $this->previousSettings;
        Hilos::$db = $this->previousDb;
        TruthSourceRegistry::unregister(HilosDbContext::settings, self::TEST_AGENT_ID);
        self::runStub(down: true);
        parent::tearDown();
    }

    /**
     * @throws HilosException When a settings write or sync frame fails
     */
    public function testDefaultLocalWriteAndRemoteUpdateProduceOnlyChangedReports(): void
    {
        Hilos::$setting = new SettingsAccessor(SettingsCatalogStub::class);
        $this->reporter->report();
        $this->assertReported([true]);

        Hilos::$setting = new SettingsAccessor(ProtectedModeSettingsTestCatalog::class);
        $this->reporter->report();
        $this->assertReported([true]);

        $db = Hilos::$db;
        $this->assertInstanceOf(ProtectedModeSettingsTestDbContext::class, $db);
        $setting = $db->settings->actions->add(
            ProtectedModeSettingsCatalog::MANUAL_RESTART_IS_NORMAL,
            false,
            ProtectedModeSettingsTestCatalog::getCatalog(),
        );
        $this->assertReported([true, false]);

        $this->reporter->report();
        $this->assertReported([true, false]);

        $other = $db->settings->actions->add(
            SettingsCatalogConstants::STUB_KEY_EXAMPLE_BOOLEAN,
            true,
            ProtectedModeSettingsTestCatalog::getCatalog(),
        );
        $this->assertReported([true, false]);
        $other->actions->delete();
        $this->assertReported([true, false]);

        $rival = new ProtectedModeSettingsTestDbContext();
        $rival->configure();
        $this->assertNotNull($rival->settings[ProtectedModeSettingsCatalog::MANUAL_RESTART_IS_NORMAL]);
        $id = $setting->id;
        $this->assertNotNull($id);
        Database::sql('UPDATE `hilos_setting` SET `value` = ? WHERE `id` = ?', ['1', $id]);

        Hilos::$db = $rival;
        try {
            DbSyncApplicator::applyUpdated(new DbSyncUpdatedSignalData(
                HilosDbContext::settings,
                (string)$id,
                [ObjectSetting::value => '1'],
            ));
        } finally {
            Hilos::$db = $db;
        }

        $this->assertReported([true, false, true]);
    }

    /**
     * @param list<bool> $expected Values expected on the worker link
     */
    private function assertReported(array $expected): void
    {
        $values = [];
        foreach ($this->client->sent as $frame) {
            $this->assertInstanceOf(WorkerProtectedModeSettingsDTO::class, $frame);
            $values[] = $frame->manualRestartIsNormal;
        }
        $this->assertSame($expected, $values);
    }

    /**
     * @param bool $down Whether to drop the settings table
     * @throws DatabaseException When the stub statement fails
     */
    private static function runStub(bool $down): void
    {
        // external-boundary: the neutral element of the name being built - the up file carries no suffix
        $suffix = $down ? '_down' : '';
        $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_hilos_setting{$suffix}.sql";
        Database::sqlRun((string)file_get_contents($stub));
    }
}

/** Catalog containing this feature's key and a neighboring boolean setting. */
final class ProtectedModeSettingsTestCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Test catalog entries
     */
    public static function getCatalog(): array
    {
        return array_replace(ProtectedModeSettingsCatalog::getCatalog(), SettingsCatalogStub::getCatalog());
    }
}

/** Framework settings collection with no project collections added. */
final class ProtectedModeSettingsTestDbContext extends HilosDbContext
{
}

/** In-process worker link that records only the frame the reporter sends. */
final class ProtectedModeSettingsRecordingClient extends WorkerDaemonClient
{
    /** @var list<WorkerDTO|array<string, mixed>> Frames queued for the master */
    public array $sent = [];

    public function __construct()
    {
    }

    /**
     * @param WorkerDTO|array<string, mixed> $data Worker frame
     */
    public function send(WorkerDTO|array $data): void
    {
        $this->sent[] = $data;
    }
}
