<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
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
use Hilos\Files\Upload\UploadMemorySettingsReporter;
use Hilos\Files\Upload\UploadsSettingsCatalog;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Worker\DTO\WorkerUploadSettingsDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use ReflectionProperty;

/**
 * Settings actions and an arriving sync drive the worker reporter over separate DB object caches.
 * The worker link is in process, as in ProtectedModeSettingsReportIntegrationTest; no live pool is claimed.
 */
final class UploadMemorySettingsReportIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string TEST_AGENT_ID = 'upload-memory-settings-report';

    private ?DbContext $previousDb = null;
    private ?SettingsAccessor $previousSettings = null;
    private UploadMemorySettingsIntegrationClient $client;
    private UploadMemorySettingsReporter $reporter;

    /**
     * @throws HilosException When the case's settings table or DB context cannot be prepared
     */
    protected function setUp(): void
    {
        parent::setUp();
        // Same table fixture as ProtectedModeSettingsReportIntegrationTest; connection reset is the base's.
        self::runStub(down: true);
        self::runStub(down: false);
        TruthSourceRegistry::register(HilosDbContext::settings, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        $this->previousDb = Hilos::$db;
        $this->previousSettings = Hilos::$setting;
        Hilos::$db = new UploadMemorySettingsIntegrationDbContext();
        Hilos::$db->configure();
        Hilos::$setting = new SettingsAccessor(UploadMemorySettingsIntegrationCatalog::class);
        $this->client = new UploadMemorySettingsIntegrationClient();
        $this->reporter = new UploadMemorySettingsReporter();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe($this->reporter);
    }

    /** @throws DatabaseException When the settings fixture cannot be removed */
    protected function tearDown(): void
    {
        SourceChangeBus::reset();
        Hilos::$setting = $this->previousSettings;
        Hilos::$db = $this->previousDb;
        TruthSourceRegistry::unregister(HilosDbContext::settings, self::TEST_AGENT_ID);
        self::runStub(down: true);
        parent::tearDown();
    }

    /** @throws HilosException When settings actions cannot write or announce their mutation */
    public function testDefaultUpdateDeleteAndNoDuplicateReports(): void
    {
        $this->reporter->onRegistered($this->client);
        $this->assertReported([2_147_483_648]);
        Hilos::$db->settings->actions->add(
            UploadsSettingsCatalog::MEMORY_POOL_BYTES_KEY,
            3,
            UploadMemorySettingsIntegrationCatalog::getCatalog(),
        );
        $this->assertReported([2_147_483_648, 3]);

        Hilos::$db->settings[UploadsSettingsCatalog::MEMORY_POOL_BYTES_KEY]->actions->updateValue(1);
        $this->assertReported([2_147_483_648, 3, 1]);
        Hilos::$db->settings[UploadsSettingsCatalog::MEMORY_POOL_BYTES_KEY]->actions->updateValue(1);
        $this->reporter->report();
        $this->assertReported([2_147_483_648, 3, 1]);

        Hilos::$db->settings->actions->add(
            SettingsCatalogConstants::STUB_KEY_EXAMPLE_BOOLEAN,
            true,
            UploadMemorySettingsIntegrationCatalog::getCatalog(),
        );
        Hilos::$db->settings[SettingsCatalogConstants::STUB_KEY_EXAMPLE_BOOLEAN]->actions->delete();
        $this->assertReported([2_147_483_648, 3, 1]);

        Hilos::$db->settings[UploadsSettingsCatalog::MEMORY_POOL_BYTES_KEY]->actions->delete();
        $this->assertReported([2_147_483_648, 3, 1, 2_147_483_648]);
    }

    /** @throws HilosException When the fixture write or ready source read fails */
    public function testAnUnreadySourceDoesNotSendAGuessedDefault(): void
    {
        Hilos::$db->settings->actions->add(
            UploadsSettingsCatalog::MEMORY_POOL_BYTES_KEY,
            3,
            UploadMemorySettingsIntegrationCatalog::getCatalog(),
        );
        $states = new ReflectionProperty(SourceInterestRegistry::class, 'states');
        $consumers = new ReflectionProperty(SourceInterestRegistry::class, 'byConsumer');
        $delivery = new ReflectionProperty(SourceInterestRegistry::class, 'readsWhatIsDelivered');
        $previousStates = $states->getValue();
        $previousConsumers = $consumers->getValue();
        $previousDelivery = $delivery->getValue();
        $states->setValue(null, []);
        $consumers->setValue(null, []);
        SourceInterestRegistry::readsWhatIsDelivered();
        SourceInterestRegistry::register(SourceChange::KIND_DB, HilosDbContext::settings, self::TEST_AGENT_ID);
        try {
            $this->reporter->onRegistered($this->client);
            $this->reporter->report();
            $this->assertReported([]);
            Hilos::$db->reHydrateCollection(HilosDbContext::settings);
            SourceInterestRegistry::markReady(SourceChange::KIND_DB, HilosDbContext::settings);
            $this->reporter->report();
            $this->assertReported([3]);
        } finally {
            $states->setValue(null, $previousStates);
            $consumers->setValue(null, $previousConsumers);
            $delivery->setValue(null, $previousDelivery);
        }
    }

    /** @throws HilosException When the writer's action or the receiver's sync application fails */
    public function testRemoteSourceChangeReportsTheNewValueFromAnotherContext(): void
    {
        $writer = Hilos::$db;
        Hilos::$db->settings->actions->add(
            UploadsSettingsCatalog::MEMORY_POOL_BYTES_KEY,
            3,
            UploadMemorySettingsIntegrationCatalog::getCatalog(),
        );
        $receiver = new UploadMemorySettingsIntegrationDbContext();
        $receiver->configure();
        Hilos::$db = $receiver;
        $this->assertSame('3', Hilos::$db->settings[UploadsSettingsCatalog::MEMORY_POOL_BYTES_KEY]->value);
        $this->reporter->onRegistered($this->client);
        $this->assertReported([3]);

        // Keep the other context's write out of this reporter's process-local source bus.
        SourceChangeBus::reset();
        Hilos::$db = $writer;
        Hilos::$db->settings[UploadsSettingsCatalog::MEMORY_POOL_BYTES_KEY]->actions->updateValue(1);
        $id = Hilos::$db->settings[UploadsSettingsCatalog::MEMORY_POOL_BYTES_KEY]->id;
        $this->assertNotNull($id);
        SourceChangeBus::subscribe($this->reporter);
        Hilos::$db = $receiver;
        $this->assertSame('3', Hilos::$db->settings[UploadsSettingsCatalog::MEMORY_POOL_BYTES_KEY]->value);
        try {
            DbSyncApplicator::applyUpdated(new DbSyncUpdatedSignalData(
                HilosDbContext::settings,
                (string)$id,
                [ObjectSetting::value => '1'],
            ));
            $this->assertReported([3, 1]);
            $this->reporter->report();
            $this->assertReported([3, 1]);
        } finally {
            Hilos::$db = $writer;
        }
    }

    /** @param list<int> $expected Exact values queued on the worker link */
    private function assertReported(array $expected): void
    {
        $values = [];
        foreach ($this->client->sent as $frame) {
            $restored = WorkerDTO::factoryWorkerDTO($frame->toJson());
            $this->assertInstanceOf(WorkerUploadSettingsDTO::class, $restored);
            $values[] = $restored->memoryPoolBytes;
        }
        $this->assertSame($expected, $values);
    }

    /**
     * @param bool $down Whether to remove the table fixture
     * @throws DatabaseException When fixture SQL cannot run
     */
    private static function runStub(bool $down): void
    {
        $name = $down ? 'create_hilos_setting_down.sql' : 'create_hilos_setting.sql';
        Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/' . $name));
    }
}

/** Catalog also includes a neighboring key whose create/delete must not trigger a report. */
final class UploadMemorySettingsIntegrationCatalog implements CatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> Upload and neighboring setting metadata */
    public static function getCatalog(): array
    {
        return array_replace(UploadsSettingsCatalog::getCatalog(), SettingsCatalogStub::getCatalog());
    }
}

/** Separate instances have the same DB source and independent ORM item caches. */
final class UploadMemorySettingsIntegrationDbContext extends HilosDbContext
{
}

/** In-process worker link, following ProtectedModeSettingsReportIntegrationTest. */
final class UploadMemorySettingsIntegrationClient extends WorkerDaemonClient
{
    /** @var list<WorkerDTO> Frames queued for the master */
    public array $sent = [];

    public function __construct()
    {
    }

    public function send(WorkerDTO|array $data): void
    {
        if ($data instanceof WorkerDTO) {
            $this->sent[] = $data;
        }
    }
}
