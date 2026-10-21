<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files;

use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\Setting;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogStub;
use Hilos\Files\Upload\UploadMemorySettingsReporter;
use Hilos\Files\Upload\UploadsSettingsCatalog;
use Hilos\Hilos;
use Hilos\Socket\Worker\DTO\WorkerDbInterestReadyMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerUploadSettingsDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Registration, source readiness and reporting span are independent of any production pool. */
final class UploadMemorySettingsTest extends TestCase
{
    public function testReporterDefersAbsentAccessorAndAbsentCatalogKey(): void
    {
        $previous = Hilos::$setting;
        $previousDb = Hilos::$db;
        Hilos::$db = null;
        try {
            $client = new UploadMemorySettingsRecordingClient();
            $reporter = new UploadMemorySettingsReporter();
            Hilos::$setting = null;
            $reporter->onRegistered($client);
            $this->assertSame([], $client->uploads());
            Hilos::$setting = new SettingsAccessor(SettingsCatalogStub::class);
            $reporter->report();
            $this->assertSame([], $client->uploads());

            // The accessor explicitly supports catalog defaults when no HilosDbContext exists.
            Hilos::$setting = new SettingsAccessor(UploadsSettingsCatalog::class);
            $reporter->report();
            $this->assertSame([2_147_483_648], $client->uploads());
        } finally {
            Hilos::$setting = $previous;
            Hilos::$db = $previousDb;
        }
    }

    public function testWorkerRegistrationWaitsForReadinessAndRebindsOnlyOneSubscriber(): void
    {
        $this->withWorkerSources(function (): void {
            $accessor = new UploadMemorySettingsTestAccessor();
            Hilos::$setting = $accessor;
            $db = new UploadMemorySettingsTestDbContext();
            Hilos::$db = $db;
            $first = new UploadMemorySettingsRecordingClient();
            $manager = new UploadMemorySettingsTestManager($first);

            $manager->handleDaemonMessage(new WorkerRegisteredDTO(1));
            $this->assertSame([], $first->uploads());
            $this->assertSame(0, $accessor->reads);
            $this->assertNotEmpty($first->sent, 'Other registration frames continue while settings wait');

            $manager->handleDaemonMessage(new WorkerDbInterestReadyMessageDTO(HilosDbContext::settings));
            $this->assertSame([HilosDbContext::settings], $db->rehydrated);
            $this->assertSame([3], $first->uploads());

            SourceChangeBus::publish(SourceChange::dbCreated(HilosDbContext::settings, '9', [Setting::key => 'other']));
            SourceChangeBus::publish(SourceChange::dbDeleted(HilosDbContext::settings, '9', [Setting::key => 'other']));
            $this->assertSame(2, $accessor->reads, 'Only the initial upload and delivery settings reads ran');

            $second = new UploadMemorySettingsRecordingClient();
            $manager->replaceChannel($second);
            $manager->handleDaemonMessage(new WorkerRegisteredDTO(1));
            $this->assertSame([3], $second->uploads(), 'A replacement channel needs its own initial report');
            $accessor->value = 1;
            $readsBefore = $accessor->reads;
            SourceChangeBus::publish(SourceChange::dbUpdated(HilosDbContext::settings, '1', [Setting::value => '1']));
            $this->assertSame($readsBefore + 2, $accessor->reads, 'One upload and one delivery reporter read each update');
            $this->assertSame([3], $first->uploads());
            $this->assertSame([3, 1], $second->uploads());
            SourceChangeBus::publish(SourceChange::dbUpdated(HilosDbContext::settings, '1', [Setting::value => '1']));
            $this->assertSame([3, 1], $second->uploads(), 'An unchanged effective value sends no duplicate');
        });
    }

    public function testDisabledFeatureDoesNotReadOrSubscribeAnUploadReporter(): void
    {
        $this->withWorkerSources(function (): void {
            new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, UploadMemorySettingsDisabledHilos::class);
            $accessor = new UploadMemorySettingsTestAccessor();
            Hilos::$setting = $accessor;
            Hilos::$db = new UploadMemorySettingsTestDbContext();
            $client = new UploadMemorySettingsRecordingClient();
            $manager = new UploadMemorySettingsTestManager($client);
            $manager->handleDaemonMessage(new WorkerRegisteredDTO(1));
            $manager->handleDaemonMessage(new WorkerDbInterestReadyMessageDTO(HilosDbContext::settings));
            SourceChangeBus::publish(SourceChange::dbUpdated(HilosDbContext::settings, '1', [Setting::value => '1']));
            $this->assertSame([], $client->uploads());
            $this->assertSame(2, $accessor->reads, 'The always-on delivery reporter reads at readiness and update');
        });
    }

    private function withWorkerSources(callable $case): void
    {
        $previousDb = Hilos::$db;
        $previousSettings = Hilos::$setting;
        $previousRouter = Hilos::$sr;
        $previousApp = Hilos::appClass();
        $consumers = new ReflectionProperty(SourceInterestRegistry::class, 'byConsumer');
        $states = new ReflectionProperty(SourceInterestRegistry::class, 'states');
        $previousConsumers = $consumers->getValue();
        $previousStates = $states->getValue();
        $delivery = new ReflectionProperty(SourceInterestRegistry::class, 'readsWhatIsDelivered');
        $previousDelivery = $delivery->getValue();
        $consumers->setValue(null, []);
        $states->setValue(null, []);
        SourceInterestRegistry::readsWhatIsDelivered();
        SourceInterestRegistry::register(SourceChange::KIND_DB, HilosDbContext::settings, 'upload-memory-test');
        SourceChangeBus::reset();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, UploadMemorySettingsEnabledHilos::class);
        try {
            $case();
        } finally {
            SourceChangeBus::reset();
            $consumers->setValue(null, $previousConsumers);
            $states->setValue(null, $previousStates);
            $delivery->setValue(null, $previousDelivery);
            Hilos::$db = $previousDb;
            Hilos::$setting = $previousSettings;
            Hilos::$sr = $previousRouter;
            new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $previousApp);
        }
    }
}

/** Typed catalog accessor that counts reads of the one budget setting. */
final class UploadMemorySettingsTestAccessor extends SettingsAccessor
{
    public int $reads = 0;
    public int $value = 3;

    public function __construct()
    {
        parent::__construct(UploadsSettingsCatalog::class);
    }

    public function effectiveValueFor(string $key): mixed
    {
        ++$this->reads;
        return $this->value;
    }
}

/** Readiness reaches the normal worker handler without opening a database in a unit case. */
final class UploadMemorySettingsTestDbContext extends HilosDbContext
{
    /** @var list<string> Collections refreshed before readiness */
    public array $rehydrated = [];

    public function reHydrateCollection(string $collectionKey): void
    {
        $this->rehydrated[] = $collectionKey;
    }
}

/** Recording transport shared by a reporter for exactly one registration span. */
final class UploadMemorySettingsRecordingClient extends WorkerDaemonClient
{
    /** @var list<WorkerDTO|array<string, mixed>> Frames queued for the master */
    public array $sent = [];

    public function __construct()
    {
    }

    public function send(WorkerDTO|array $data): void
    {
        $this->sent[] = $data;
    }

    /** @return list<int> Budgets from upload settings reports only */
    public function uploads(): array
    {
        return array_values(array_map(
            static fn (WorkerUploadSettingsDTO $frame): int => $frame->memoryPoolBytes,
            array_filter($this->sent, static fn (WorkerDTO|array $frame): bool => $frame instanceof WorkerUploadSettingsDTO),
        ));
    }
}

/** Drives the real registration and readiness dispatchers. */
final class UploadMemorySettingsTestManager extends WorkerManager
{
    public function __construct(WorkerDaemonClient $client)
    {
        parent::__construct(1);
        $this->daemonClient = $client;
    }

    public function replaceChannel(WorkerDaemonClient $client): void
    {
        $this->daemonClient = $client;
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManager(): AgentManager
    {
        return new class extends AgentManager {
            protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
            {
                throw new LogicException('This fixture starts no agents');
            }
        };
    }
}

abstract class UploadMemorySettingsEnabledHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::UPLOADS];
}

abstract class UploadMemorySettingsDisabledHilos extends Hilos
{
}
