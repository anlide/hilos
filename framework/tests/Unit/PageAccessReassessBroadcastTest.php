<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Hilos;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerPageAccessReassessMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * The master's half of the access re-decision (HIL-644): it fans the announcement out and does
 * nothing else with it.
 *
 * The master is the only process that can address "every worker of this node", and it is also
 * the one process that cannot say who is behind a connection - so what is pinned here is a
 * write per worker link, and the ORDER that write happens in. The order is not a nicety: the
 * database sync of the flag that was just written rides the same queue, and a worker that
 * re-decides ahead of it answers against a flag it has not seen change.
 */
final class PageAccessReassessBroadcastTest extends TestCase
{
    private const int USER_ID = 41;

    /** Temporary main log file the assertions read the written lines back from */
    private string $logFile = '';

    protected function setUp(): void
    {
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-page-access-broadcast');
        Logger::setLogFile($this->logFile);
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();

        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testTheAnnouncementIsWrittenToEveryWorkerLink(): void
    {
        $manager = new PageAccessReassessBroadcastTestManager();
        $workerServer = $manager->addWorkerServer();
        $workerServer->addWorker();
        $workerServer->addWorker();

        $manager->receiveAnnouncement(self::USER_ID);
        $manager->dispatch();

        $perWorker = $workerServer->framesPerWorker();
        $this->assertCount(2, $perWorker);
        foreach ($perWorker as $frames) {
            $this->assertCount(1, $frames);
            $restored = WorkerDTO::factoryWorkerDTO($frames[0]);
            $this->assertInstanceOf(WorkerPageAccessReassessMessageDTO::class, $restored);
            $this->assertSame(self::USER_ID, $restored->userId);
        }
        $this->assertSame('', $this->written());
    }

    /**
     * The load-bearing one, and the reason the master queues what it received instead of acting
     * on it. Both frames arrive from the writing worker in the order that worker queued them -
     * the flag's database sync first, the announcement second - and both are still sitting in
     * the master's own queue when the second arrives. Only the dispatch pass writes anything,
     * so each worker link is written the sync before the announcement and re-decides against
     * the flag it has just been told about. An announcement written at receipt would overtake
     * the sync and arrive first.
     */
    public function testTheFlagSyncIsWrittenToEachWorkerBeforeTheAnnouncement(): void
    {
        $manager = new PageAccessReassessBroadcastTestManager();
        $workerServer = $manager->addWorkerServer();
        $workerServer->addWorker();

        $manager->receiveFlagSync(self::USER_ID);
        $manager->receiveAnnouncement(self::USER_ID);

        $this->assertSame([[]], $workerServer->framesPerWorker(), 'Receipt writes nothing by itself');

        $manager->dispatch();

        $frames = $workerServer->framesPerWorker()[0];
        $this->assertCount(2, $frames);
        $this->assertInstanceOf(WorkerDbSyncUpdatedMessageDTO::class, WorkerDTO::factoryWorkerDTO($frames[0]));
        $this->assertInstanceOf(
            WorkerPageAccessReassessMessageDTO::class,
            WorkerDTO::factoryWorkerDTO($frames[1]),
        );
    }

    /**
     * A frame the master cannot build is a frame it must not invent a user id for: nothing goes
     * out, and the line names the class that arrived instead.
     */
    public function testAnAnnouncementCarryingTheWrongPayloadIsWrittenAndNotSent(): void
    {
        $manager = new PageAccessReassessBroadcastTestManager();
        $workerServer = $manager->addWorkerServer();
        $workerServer->addWorker();

        $this->queueMalformedAnnouncement(new SignalData(['userId' => self::USER_ID]));
        $manager->dispatch();

        $this->assertSame([[]], $workerServer->framesPerWorker());
        $this->assertStringContainsString('access re-decision carries invalid data', $this->written());
        $this->assertStringContainsString(SignalData::class, $this->written());
    }

    /**
     * Puts one announcement in the master's own queue carrying a payload no handler would build,
     * which is the only way the malformed case can be reached at all.
     *
     * @param SignalData $data Payload the announcement carries instead of the expected one
     */
    private function queueMalformedAnnouncement(SignalData $data): void
    {
        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::PAGE_ACCESS_REASSESS_USER),
            signalName: new SignalName(SignalConstants::PAGE_ACCESS_REASSESS_USER),
            signalData: $data,
        );
    }

    /**
     * Reads back whatever the dispatch pass wrote to the temporary log.
     *
     * @return string Log contents, empty when the pass stayed silent
     */
    private function written(): string
    {
        return (string)file_get_contents($this->logFile);
    }
}

/**
 * Daemon manager standing on a worker server and nothing else, able to run one dispatch pass.
 */
final class PageAccessReassessBroadcastTestManager extends DaemonManager
{
    /** Stand-in worker server, absent until a case registers one */
    public ?PageAccessReassessBroadcastTestWorkerServer $workerServer = null;

    /**
     * Registers the stand-in worker server the fan-out writes to.
     *
     * @return PageAccessReassessBroadcastTestWorkerServer The registered stand-in, for arranging the case
     */
    public function addWorkerServer(): PageAccessReassessBroadcastTestWorkerServer
    {
        $this->workerServer = new PageAccessReassessBroadcastTestWorkerServer();
        $this->registerServer($this->workerServer);

        return $this->workerServer;
    }

    /**
     * Hands the master the announcement frame the way a worker link does, through the handler
     * that receives it - so what this file asserts includes the decision to queue rather than
     * act.
     *
     * @param int $userId User the arriving announcement names
     * @throws InvalidArgumentException When the queued announcement carries an empty name
     */
    public function receiveAnnouncement(int $userId): void
    {
        $this->agentManagerDaemon->handleWorkerPageAccessReassess(
            new WorkerPageAccessReassessMessageDTO($userId),
        );
    }

    /**
     * Hands the master the database sync of the freshly written admin flag, through the same
     * receipt path, so both frames enter the queue exactly as they do in a live grant.
     *
     * @param int $userId User whose row was updated
     * @throws InvalidArgumentException When the queued sync carries an empty name
     */
    public function receiveFlagSync(int $userId): void
    {
        $this->agentManagerDaemon->handleWorkerDbSyncUpdated(new WorkerDbSyncUpdatedMessageDTO(
            new DbSyncUpdatedSignalData('users', (string)$userId, ['admin' => 1]),
        ));
    }

    /**
     * Drains the queue through the real dispatch pass, which is private to the manager.
     */
    public function dispatch(): void
    {
        $dispatch = Closure::bind(
            static function (DaemonManager $manager): void {
                $manager->dispatchSignals();
            },
            null,
            DaemonManager::class,
        );

        $dispatch($this);
    }

    /**
     * Swallows the master's own application of a sync frame: this file is about what leaves the
     * master, and applying a row would ask for a database nobody mounted here.
     *
     * @param SignalDTO $signal Signal being dispatched
     */
    protected function handleDaemonSignal(SignalDTO $signal): void
    {
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new PageAccessReassessBroadcastTestAgentManagerDaemon();
    }
}

final class PageAccessReassessBroadcastTestAgentManagerDaemon extends AgentManagerDaemon
{
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}

/**
 * A worker server that keeps the frames written to each of its links instead of owning processes.
 */
final class PageAccessReassessBroadcastTestWorkerServer extends WorkerServer
{
    public function __construct()
    {
    }

    /**
     * Adds one more worker link for the fan-out to write to.
     */
    public function addWorker(): void
    {
        $this->clients[] = new PageAccessReassessBroadcastTestWorkerClient();
    }

    /**
     * @return list<list<string>> Raw frames each worker link was written, one entry per link
     */
    public function framesPerWorker(): array
    {
        $frames = [];
        foreach ($this->clients as $client) {
            if ($client instanceof PageAccessReassessBroadcastTestWorkerClient) {
                $frames[] = $client->frames;
            }
        }

        return $frames;
    }

    protected function onStart(): void
    {
    }
}

/**
 * A worker link that keeps what was written to it instead of owning a socket.
 */
final class PageAccessReassessBroadcastTestWorkerClient extends WorkerClient
{
    /** @var list<string> Raw frames the master wrote to this link, in order */
    public array $frames = [];

    public function __construct()
    {
    }

    /**
     * @param string $message Frame the master wants written to this worker
     */
    public function send(string $message): void
    {
        $this->frames[] = $message;
    }
}
