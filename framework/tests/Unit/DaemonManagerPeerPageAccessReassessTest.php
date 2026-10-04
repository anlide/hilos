<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Page\DTO\PageAccessReassessConnectionsSignalData;
use Hilos\Core\Page\DTO\PageAccessReassessSessionSignalData;
use Hilos\Core\Page\DTO\PageAccessReassessUserSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\WorkerPageAccessReassessConnectionsMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerPageAccessReassessMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * A remote page access announcement reaches every worker once and stops there (HIL-1306).
 */
final class DaemonManagerPeerPageAccessReassessTest extends TestCase
{
    private string $logFile = '';

    protected function setUp(): void
    {
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-peer-reassess-');
        Logger::setLogFile($this->logFile);
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();
        unlink($this->logFile);
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testAByUserAnnouncementIsWrittenToEveryWorkerWithoutAnEcho(): void
    {
        $daemon = new PeerPageAccessReassessTestManager();
        $workers = $daemon->addWorkers();

        $daemon->deliverPageAccessReassess('node-b', $this->signal(
            SignalTypeConstants::PAGE_ACCESS_REASSESS_USER,
            new PageAccessReassessUserSignalData(41),
        ));

        foreach ($workers->framesPerWorker() as $frames) {
            $this->assertCount(1, $frames);
            $frame = WorkerDTO::factoryWorkerDTO($frames[0]);
            $this->assertInstanceOf(WorkerPageAccessReassessMessageDTO::class, $frame);
            $this->assertSame(41, $frame->userId);
        }
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testAByConnectionAnnouncementIsWrittenToEveryWorkerWithoutAnEcho(): void
    {
        $daemon = new PeerPageAccessReassessTestManager();
        $workers = $daemon->addWorkers();

        $daemon->deliverPageAccessReassess('node-b', $this->signal(
            SignalTypeConstants::PAGE_ACCESS_REASSESS_CONNECTIONS,
            new PageAccessReassessConnectionsSignalData(['ak-1', 'ak-2']),
        ));

        foreach ($workers->framesPerWorker() as $frames) {
            $this->assertCount(1, $frames);
            $frame = WorkerDTO::factoryWorkerDTO($frames[0]);
            $this->assertInstanceOf(WorkerPageAccessReassessConnectionsMessageDTO::class, $frame);
            $this->assertSame(['ak-1', 'ak-2'], $frame->acceptKeys);
        }
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testABySessionOrUnrelatedAnnouncementIsRefused(): void
    {
        $daemon = new PeerPageAccessReassessTestManager();
        $workers = $daemon->addWorkers();

        $daemon->deliverPageAccessReassess('node-b', $this->signal(
            SignalTypeConstants::PAGE_ACCESS_REASSESS_SESSION,
            new PageAccessReassessSessionSignalData('session-hash'),
        ));
        $daemon->deliverPageAccessReassess('node-b', $this->signal('unrelated', new SignalData([])));
        $daemon->deliverPageAccessReassess('node-b', $this->signal(
            SignalTypeConstants::PAGE_ACCESS_REASSESS_USER,
            new SignalData(['userId' => 41]),
        ));

        $this->assertSame([[], []], $workers->framesPerWorker());
        $this->assertSame(3, substr_count((string)file_get_contents($this->logFile),
            'only a by-user or by-connection announcement is fanned out'));
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testANodeWithoutWorkerServerDropsTheAnnouncement(): void
    {
        $daemon = new PeerPageAccessReassessTestManager();

        $daemon->deliverPageAccessReassess('node-b', $this->signal(
            SignalTypeConstants::PAGE_ACCESS_REASSESS_USER,
            new PageAccessReassessUserSignalData(41),
        ));

        $this->assertSame('', (string)file_get_contents($this->logFile));
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * @param string $type Carried signal type
     * @param SignalDataInterface $data Carried payload
     * @return SignalDTO Signal a peer frame carried
     */
    private function signal(string $type, SignalDataInterface $data): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType($type),
            new SignalName($type === SignalTypeConstants::PAGE_ACCESS_REASSESS_USER
                ? SignalConstants::PAGE_ACCESS_REASSESS_USER
                : $type),
            $data,
        );
    }
}

/**
 * Daemon with two socketless worker links.
 */
final class PeerPageAccessReassessTestManager extends DaemonManager
{
    /**
     * @return PeerPageAccessReassessTestWorkerServer Registered worker server
     */
    public function addWorkers(): PeerPageAccessReassessTestWorkerServer
    {
        $server = new PeerPageAccessReassessTestWorkerServer();
        $server->addWorker();
        $server->addWorker();
        $this->registerServer($server);

        return $server;
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new PeerPageAccessReassessTestAgentManagerDaemon();
    }
}

/**
 * No agent starts in the peer receipt tests.
 */
final class PeerPageAccessReassessTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type to start
     * @param ?string $agentIndex Agent index to start
     * @return AgentDaemonInterface Never returned
     * @throws AgentDaemonCreationFailedException Always
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('the peer receipt test starts no agent');
    }
}

/**
 * Worker pool that records each outgoing frame.
 */
final class PeerPageAccessReassessTestWorkerServer extends WorkerServer
{
    public function __construct()
    {
    }

    public function addWorker(): void
    {
        $client = new PeerPageAccessReassessTestWorkerClient();
        $client->setWorkerIndex(count($this->clients));
        $this->clients[] = $client;
    }

    /**
     * @return list<list<string>> Frames by worker
     */
    public function framesPerWorker(): array
    {
        $frames = [];
        foreach ($this->clients as $client) {
            $frames[] = $client->frames;
        }

        return $frames;
    }

    protected function onStart(): void
    {
    }
}

/**
 * Socketless worker connection that records sent frames.
 */
final class PeerPageAccessReassessTestWorkerClient extends WorkerClient
{
    /** @var list<string> Sent worker frames */
    public array $frames = [];

    public function __construct()
    {
    }

    /**
     * @param string $message Frame sent to this worker
     */
    public function send(string $message): void
    {
        $this->frames[] = $message;
    }
}
