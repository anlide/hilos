<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalRouter;
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
use Hilos\Database\Settings\Exception\SettingValueRefusedException;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\WebSocket\Delivery\DeliverySettingsMirror;
use Hilos\Socket\Worker\DTO\WorkerDbInterestReadyMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisterDTO;
use Hilos\Utils\Logger;
use RuntimeException;
use Hilos\Socket\WebSocket\Delivery\DeliverySettingsReporter;
use Hilos\Socket\WebSocket\Delivery\DeliverySettingsCatalog;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Worker\DTO\WorkerDeliverySettingsDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use ReflectionProperty;

/**
 * Settings actions and an arriving sync drive the worker reporter over separate DB object caches.
 * The worker link is in process, as in ProtectedModeSettingsReportIntegrationTest; no browser delivery queue is claimed.
 */
final class DeliverySettingsReportIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string TEST_AGENT_ID = 'delivery-settings-report';

    private ?DbContext $previousDb = null;
    private ?SettingsAccessor $previousSettings = null;
    private DeliverySettingsIntegrationClient $client;
    private DeliverySettingsReporter $reporter;

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
        Hilos::$db = new DeliverySettingsIntegrationDbContext();
        Hilos::$db->configure();
        Hilos::$setting = new SettingsAccessor();
        $this->client = new DeliverySettingsIntegrationClient();
        $this->reporter = new DeliverySettingsReporter();
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
        $this->assertReported([1000]);
        Hilos::$db->settings->actions->add(
            DeliverySettingsCatalog::WINDOW_MS_KEY,
            3,
            Hilos::$setting->catalog(),
        );
        $this->assertReported([1000, 3]);

        Hilos::$db->settings[DeliverySettingsCatalog::WINDOW_MS_KEY]->actions->updateValue(1);
        $this->assertReported([1000, 3, 1]);
        Hilos::$db->settings[DeliverySettingsCatalog::WINDOW_MS_KEY]->actions->updateValue(1);
        $this->reporter->report();
        $this->assertReported([1000, 3, 1]);

        Hilos::$db->settings->actions->add(
            SettingsCatalogConstants::STUB_KEY_EXAMPLE_BOOLEAN,
            true,
            Hilos::$setting->catalog(),
        );
        Hilos::$db->settings[SettingsCatalogConstants::STUB_KEY_EXAMPLE_BOOLEAN]->actions->delete();
        $this->assertReported([1000, 3, 1]);

        Hilos::$db->settings[DeliverySettingsCatalog::WINDOW_MS_KEY]->actions->delete();
        $this->assertReported([1000, 3, 1, 1000]);
    }

    /** @throws HilosException When the fixture write or ready source read fails */
    public function testAnUnreadySourceDoesNotSendAGuessedDefault(): void
    {
        Hilos::$db->settings->actions->add(
            DeliverySettingsCatalog::WINDOW_MS_KEY,
            3,
            Hilos::$setting->catalog(),
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
            SourceChangeBus::reset();
            $manager = new DeliverySettingsIntegrationWorker($this->client);
            $manager->handleDaemonMessage(new WorkerRegisteredDTO(1));
            $this->assertReported([]);
            $manager->handleDaemonMessage(new WorkerDbInterestReadyMessageDTO(HilosDbContext::settings));
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
            DeliverySettingsCatalog::WINDOW_MS_KEY,
            3,
            Hilos::$setting->catalog(),
        );
        $receiver = new DeliverySettingsIntegrationDbContext();
        $receiver->configure();
        Hilos::$db = $receiver;
        $this->assertSame('3', Hilos::$db->settings[DeliverySettingsCatalog::WINDOW_MS_KEY]->value);
        $this->reporter->onRegistered($this->client);
        $this->assertReported([3]);

        // Keep the other context's write out of this reporter's process-local source bus.
        SourceChangeBus::reset();
        Hilos::$db = $writer;
        Hilos::$db->settings[DeliverySettingsCatalog::WINDOW_MS_KEY]->actions->updateValue(1);
        $id = Hilos::$db->settings[DeliverySettingsCatalog::WINDOW_MS_KEY]->id;
        $this->assertNotNull($id);
        SourceChangeBus::subscribe($this->reporter);
        Hilos::$db = $receiver;
        $this->assertSame('3', Hilos::$db->settings[DeliverySettingsCatalog::WINDOW_MS_KEY]->value);
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

    public function testReRegistrationUsesTheNewChannelAndKeepsOneSubscriber(): void
    {
        SourceChangeBus::reset();
        $manager = new DeliverySettingsIntegrationWorker($this->client);
        $manager->handleDaemonMessage(new WorkerRegisteredDTO(1));
        $this->assertReported([1000]);
        $next = new DeliverySettingsIntegrationClient();
        $manager->bind($next);
        $manager->handleDaemonMessage(new WorkerRegisteredDTO(1));
        self::assertCount(1, array_filter(
            new ReflectionProperty(SourceChangeBus::class, 'reactions')->getValue(),
            static fn (object $subscriber): bool => $subscriber instanceof DeliverySettingsReporter,
        ));
        Hilos::$db->settings->actions->add(DeliverySettingsCatalog::WINDOW_MS_KEY, 250, Hilos::$setting->catalog());
        $this->assertReported([1000]);
        $this->client = $next;
        $this->assertReported([1000, 250]);
        // A repeated registration still owes an initial report on the same link.
        $manager->handleDaemonMessage(new WorkerRegisteredDTO(1));
        $this->assertReported([1000, 250, 250]);
    }

    public function testInvalidStoredValuesKeepTheLastValidWindowWithoutLogSpam(): void
    {
        $this->reporter->onRegistered($this->client);
        Hilos::$db->settings->actions->add(DeliverySettingsCatalog::WINDOW_MS_KEY, 250, Hilos::$setting->catalog());
        $id = Hilos::$db->settings[DeliverySettingsCatalog::WINDOW_MS_KEY]->id;
        self::assertNotNull($id);
        $logFile = tempnam(sys_get_temp_dir(), 'hilos-delivery-settings-');
        self::assertNotFalse($logFile);
        $logAddress = new ReflectionProperty(Logger::class, 'logFile');
        $previousLog = $logAddress->getValue();
        Logger::setLogFile($logFile);
        try {
            // Emulate an external invalid write: ordinary settings actions refuse these values.
            foreach (['bad', '0', '-2'] as $value) {
                Database::sql('UPDATE hilos_setting SET value = ? WHERE id = ?', [$value, $id]);
                DbSyncApplicator::applyUpdated(new DbSyncUpdatedSignalData(
                    HilosDbContext::settings, (string)$id, [ObjectSetting::value => $value],
                ));
                $this->reporter->report();
                $this->reporter->report();
                $this->assertReported([1000, 250]);
            }
            self::assertSame(2, substr_count((string)file_get_contents($logFile), 'WebSocket delivery settings could not be read:'));
            Hilos::$db->settings[DeliverySettingsCatalog::WINDOW_MS_KEY]->actions->updateValue(400);
            $this->assertReported([1000, 250, 400]);
            Database::sql('UPDATE hilos_setting SET value = ? WHERE id = ?', ['0', $id]);
            DbSyncApplicator::applyUpdated(new DbSyncUpdatedSignalData(
                HilosDbContext::settings, (string)$id, [ObjectSetting::value => '0'],
            ));
            self::assertSame(3, substr_count((string)file_get_contents($logFile), 'WebSocket delivery settings could not be read:'));
            $this->assertReported([1000, 250, 400]);
        } finally {
            $logAddress->setValue(null, $previousLog);
            unlink($logFile);
        }
    }

    public function testSettingsWritesRejectNonpositiveAndNonintegerValues(): void
    {
        $this->reporter->onRegistered($this->client);
        Hilos::$db->settings->actions->add(DeliverySettingsCatalog::WINDOW_MS_KEY, 250, Hilos::$setting->catalog());
        foreach ([0, -1, 1.5, true, false, '1.5', 'abc'] as $value) {
            try {
                Hilos::$db->settings[DeliverySettingsCatalog::WINDOW_MS_KEY]->actions->updateValue($value);
                self::fail('An invalid delivery window must be refused');
            } catch (SettingValueRefusedException) {
                $this->assertReported([1000, 250]);
            }
        }
    }

    public function testAnUnregisteredWorkerCannotUpdateTheMaster(): void
    {
        $mirror = new DeliverySettingsMirror();
        $masterClient = new DeliverySettingsIntegrationMasterClient();
        $masterClient->setDeliverySettingsSink($mirror);
        try {
            $masterClient->receive(new WorkerDeliverySettingsDTO(250));
            self::fail('Unregistered channel must be refused');
        } catch (InvalidFormatException) {
            self::assertSame(1000, $mirror->getWindowMs());
        }
    }

    /** @param list<int> $expected Exact values queued on the worker link */
    private function assertReported(array $expected): void
    {
        $values = [];
        $previous = [Hilos::$db, Hilos::$setting, Hilos::$cluster];
        Hilos::$db = null;
        Hilos::$setting = null;
        Hilos::$cluster = null;
        try {
            $mirror = new DeliverySettingsMirror();
            $masterClient = new DeliverySettingsIntegrationMasterClient();
            $masterClient->setDeliverySettingsSink($mirror);
            $masterClient->receive(new WorkerRegisterDTO(1));
            foreach ($this->client->sent as $frame) {
                if (!$frame instanceof WorkerDeliverySettingsDTO) {
                    continue;
                }
                $restored = WorkerDTO::factoryWorkerDTO($frame->toJson());
                $this->assertInstanceOf(WorkerDeliverySettingsDTO::class, $restored);
                $masterClient->receive($restored);
                $values[] = $mirror->getWindowMs();
            }
        } finally {
            [Hilos::$db, Hilos::$setting, Hilos::$cluster] = $previous;
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

/** Separate instances have the same DB source and independent ORM item caches. */
final class DeliverySettingsIntegrationDbContext extends HilosDbContext
{
}

/** In-process worker link, following ProtectedModeSettingsReportIntegrationTest. */
final class DeliverySettingsIntegrationClient extends WorkerDaemonClient
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

/** Uses the worker's real registration, source subscription and ready-message handlers. */
final class DeliverySettingsIntegrationWorker extends WorkerManager
{
    /** @param WorkerDaemonClient $client In-process link */
    public function __construct(WorkerDaemonClient $client)
    {
        $this->workerIndex = 1;
        $this->isMonopolistic = false;
        $this->daemonClient = $client;
    }

    /** @param WorkerDaemonClient $client New link for the next registration */
    public function bind(WorkerDaemonClient $client): void
    {
        $this->daemonClient = $client;
    }

    /** @return SignalRouter Unused in this fixture */
    protected function createSignalRouter(): SignalRouter
    {
        throw new RuntimeException('Unused factory');
    }

    /** @return AgentManager Unused in this fixture */
    protected function createAgentManager(): AgentManager
    {
        throw new RuntimeException('Unused factory');
    }
}

/** The real registered-worker gate and typed IPC parser, without a live socket. */
final class DeliverySettingsIntegrationMasterClient extends WorkerClient
{
    public function __construct()
    {
    }

    /** @param WorkerDTO $frame Frame received on this channel */
    public function receive(WorkerDTO $frame): void
    {
        $this->readBuffer = $frame->toJson() . "\n";
        $this->processReadBuffer();
    }
}
