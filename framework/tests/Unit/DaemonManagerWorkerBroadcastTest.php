<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\DaemonWorkerSignalDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * What one broadcast to the workers of this node COSTS the master loop.
 *
 * The frame the master writes to every worker link is the same string for all of them, so
 * packing it once per link instead of once per broadcast is work paid for nothing - and it is
 * paid on the loop every sync signal passes through. These cases pin that cost, which no
 * caller can observe for itself: the delivered frames look identical either way.
 *
 * The counter hangs on the payload rather than on the DTO, because that is where one packing
 * is visible exactly once: {@see DaemonWorkerSignalDTO::toArray()} hands the payload to
 * SignalDataEnvelope::encode(), which calls toArray() on it. The third case guards the other
 * side of the same trade - a saving must not turn into "we send the wrong thing".
 *
 * The public door {@see DaemonManager::sendToWorkers()} is what the cases knock on, so neither
 * Closure::bind nor reflection is needed to reach the private broadcast behind it.
 */
final class DaemonManagerWorkerBroadcastTest extends TestCase
{
    private const string SIGNAL_NAME = 'project_master_reaction';

    /** Temporary main log file the assertions read written lines back from */
    private string $logFile = '';

    protected function setUp(): void
    {
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-worker-broadcast');
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

    public function testTheFrameIsSerializedOncePerBroadcastNotPerLink(): void
    {
        $manager = new DaemonManagerWorkerBroadcastTestManager();
        $workerServer = $manager->addWorkerServer();
        $workerServer->addWorker();
        $workerServer->addWorker();
        $payload = new DaemonManagerWorkerBroadcastTestPayload();

        $manager->sendToWorkers(self::SIGNAL_NAME, $payload);

        $this->assertSame(1, $payload->encodes);
        $frames = $workerServer->framesPerWorker();
        $this->assertCount(2, $frames);
        foreach ($frames as $ofOneWorker) {
            $this->assertCount(1, $ofOneWorker);
        }
    }

    /**
     * A node whose worker links are not up yet - daemon startup - must not pay for a frame
     * nobody receives. The door itself does not complain here: its "no worker server" line is
     * about a server that is missing, and this server is registered and simply empty.
     */
    public function testNothingIsSerializedWhenThereIsNoWorkerLink(): void
    {
        $manager = new DaemonManagerWorkerBroadcastTestManager();
        $manager->addWorkerServer();
        $payload = new DaemonManagerWorkerBroadcastTestPayload();

        $manager->sendToWorkers(self::SIGNAL_NAME, $payload);

        $this->assertSame(0, $payload->encodes);
        $this->assertSame('', $this->written());
    }

    /**
     * The payload is read out of the frame itself rather than off the restored spy: the spy
     * rebuilds from nothing and would answer the same on a frame that carried no payload at
     * all, so asking it what travelled proves nothing. The frame is asked what travelled, and
     * the restore is asked only what it alone can answer - that the shared string still comes
     * back as the right DTO under the right signal name.
     */
    public function testEveryLinkGetsTheSameFrameAndItStillRestores(): void
    {
        $manager = new DaemonManagerWorkerBroadcastTestManager();
        $workerServer = $manager->addWorkerServer();
        $workerServer->addWorker();
        $workerServer->addWorker();

        $manager->sendToWorkers(self::SIGNAL_NAME, new DaemonManagerWorkerBroadcastTestPayload());

        $frames = $workerServer->framesPerWorker();
        $this->assertSame($frames[0][0], $frames[1][0]);
        $sent = json_decode($frames[0][0], true);
        $this->assertIsArray($sent);
        $this->assertSame(['reason' => 'degraded'], $sent[SignalPayloadConstants::FIELD_DATA]);
        $this->assertSame(
            DaemonManagerWorkerBroadcastTestPayload::class,
            $sent[SignalPayloadConstants::FIELD_DATA_TYPE],
        );
        $restored = WorkerDTO::factoryWorkerDTO($frames[0][0]);
        $this->assertInstanceOf(DaemonWorkerSignalDTO::class, $restored);
        $this->assertSame(self::SIGNAL_NAME, $restored->signalName);
    }

    /**
     * @return string Everything written to the main log during this case
     */
    private function written(): string
    {
        return (string)file_get_contents($this->logFile);
    }
}

/**
 * A manager that owns a stand-in worker server instead of real worker processes.
 */
final class DaemonManagerWorkerBroadcastTestManager extends DaemonManager
{
    /**
     * Registers the stand-in worker server the broadcast door looks for.
     *
     * @return DaemonManagerWorkerBroadcastTestWorkerServer The registered stand-in, for arranging the case
     */
    public function addWorkerServer(): DaemonManagerWorkerBroadcastTestWorkerServer
    {
        $workerServer = new DaemonManagerWorkerBroadcastTestWorkerServer();
        $this->registerServer($workerServer);

        return $workerServer;
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DaemonManagerWorkerBroadcastTestAgentManagerDaemon();
    }
}

final class DaemonManagerWorkerBroadcastTestAgentManagerDaemon extends AgentManagerDaemon
{
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}

/**
 * A worker server holding stand-in links instead of processes, so a case can say how many
 * links the broadcast finds and read back what each was written.
 */
final class DaemonManagerWorkerBroadcastTestWorkerServer extends WorkerServer
{
    public function __construct()
    {
    }

    /**
     * Adds one more worker link for the broadcast to write to.
     */
    public function addWorker(): void
    {
        $this->clients[] = new DaemonManagerWorkerBroadcastTestWorkerClient();
    }

    /**
     * @return list<list<string>> Raw frames each worker link was written, one entry per link
     */
    public function framesPerWorker(): array
    {
        $frames = [];
        foreach ($this->clients as $client) {
            if ($client instanceof DaemonManagerWorkerBroadcastTestWorkerClient) {
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
final class DaemonManagerWorkerBroadcastTestWorkerClient extends WorkerClient
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

/**
 * A payload that counts how many times it was serialized.
 *
 * One packing of the frame calls this exactly once, which is what makes the count readable as
 * "packings per broadcast".
 */
final class DaemonManagerWorkerBroadcastTestPayload implements SignalDataInterface
{
    /** How many times this payload was asked to serialize itself */
    public int $encodes = 0;

    /**
     * @return array<string, mixed> Signal payload
     */
    public function toArray(): array
    {
        $this->encodes++;

        return ['reason' => 'degraded'];
    }

    /**
     * @param array<string, mixed> $data Signal payload
     * @return static Restored signal payload
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}
