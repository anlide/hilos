<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * What the daemon says about a signal that reached nobody (HIL-567).
 *
 * The drain used to answer an empty destination list with a bare `continue`, so a signal
 * whose route was never declared was dropped in complete silence — the only way to notice
 * was to watch database rows that never changed, which is exactly how the dead mail and SMS
 * delivery went unnoticed for as long as it did. The line written here is that missing word.
 *
 * It is deliberately not written for every empty list. Most signal types are routed by live
 * state — who is subscribed, who is connected — and are empty in the ordinary case; a line
 * for those would bury the real losses in noise within the first minute. Which types are
 * which is the router's answer ({@see SignalRouter::expectsDestination()}), and this file
 * only checks that the daemon asks it and writes what it is told.
 *
 * Reflection reaches the private drain: the code-style rule grants tests that exception, and
 * the alternative — running a daemon loop — would say less about more.
 */
final class DaemonManagerLostSignalLogTest extends TestCase
{
    private const string UNROUTED_SIGNAL = 'hilos_unrouted_test_send';

    /** Temporary main log file the assertions read the written line back from */
    private string $logFile = '';

    protected function setUp(): void
    {
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-lost-signal-log');
        Logger::setLogFile($this->logFile);
    }

    protected function tearDown(): void
    {
        new ReflectionClass(Logger::class)->getProperty('logFile')->setValue(null, null);

        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testAnAgentSignalThatReachedNobodyIsWrittenWithItsNameTypeAndSource(): void
    {
        $manager = new DaemonManagerLostSignalLogTestManager();
        $this->queueAgentSignal(new SignalSource(SignalSource::WORKER), self::UNROUTED_SIGNAL);

        $manager->drainQueue();

        $written = $this->written();
        $this->assertStringContainsString(self::UNROUTED_SIGNAL, $written);
        $this->assertStringContainsString(SignalTypeConstants::AGENT_SIGNAL, $written);
        $this->assertStringContainsString(SignalSource::WORKER, $written);
    }

    /**
     * The source alone rarely identifies the sender; when the sender named its own instance,
     * the line has to carry that too, or the reader learns only that "some agent" lost it.
     */
    public function testTheSourceTypeAndIndexAreWrittenWhenTheSenderSetThem(): void
    {
        $manager = new DaemonManagerLostSignalLogTestManager();
        $this->queueAgentSignal(
            new SignalSource(SignalSource::AGENT, 'hilos_mail', '3'),
            self::UNROUTED_SIGNAL,
        );

        $manager->drainQueue();

        $this->assertStringContainsString('hilos_mail', $this->written());
        $this->assertStringContainsString('#3', $this->written());
    }

    /**
     * A group broadcast nobody is subscribed to is the ordinary case, not a loss.
     */
    public function testAGroupBroadcastWithNoSubscribersIsNotWritten(): void
    {
        $manager = new DaemonManagerLostSignalLogTestManager();
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::WS_GROUP),
            new SignalName('room_update'),
            new SignalData([]),
        );

        $manager->drainQueue();

        $this->assertSame('', $this->written());
    }

    public function testAnAgentSignalThatFoundItsAgentIsDeliveredAndNotWritten(): void
    {
        $manager = new DaemonManagerLostSignalLogTestManager();
        $this->queueAgentSignal(
            new SignalSource(SignalSource::WORKER),
            DaemonManagerLostSignalLogTestRouter::ROUTED_SIGNAL,
        );

        $manager->drainQueue();

        $this->assertSame([DaemonManagerLostSignalLogTestRouter::AGENT_TYPE], $manager->deliveredTo());
        $this->assertSame('', $this->written());
    }

    /**
     * Queues one agent signal, the shape every case here shares apart from its source.
     *
     * @param SignalSource $source Source the signal is queued from
     * @param string $signalName Signal name to queue under
     */
    private function queueAgentSignal(SignalSource $source, string $signalName): void
    {
        Hilos::$sr->queueSignal(
            $source,
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName($signalName),
            new AgentSignalData(new SignalData([])),
        );
    }

    /**
     * Reads back whatever the drain wrote to the temporary log.
     *
     * @return string Log contents, empty when the drain stayed silent
     */
    private function written(): string
    {
        return (string)file_get_contents($this->logFile);
    }
}

/**
 * Daemon manager carrying a stand-in worker server, the one thing the drain needs before it
 * looks at a signal at all.
 */
final class DaemonManagerLostSignalLogTestManager extends DaemonManager
{
    /** The stand-in worker server the drain finds and delivers through */
    private DaemonManagerLostSignalLogTestWorkerServer $workerServer;

    public function __construct()
    {
        parent::__construct();

        $this->workerServer = new DaemonManagerLostSignalLogTestWorkerServer();
        $this->registerServer($this->workerServer);
    }

    /**
     * Runs the private queue drain the daemon loop runs at the end of each iteration.
     */
    public function drainQueue(): void
    {
        new ReflectionClass(DaemonManager::class)->getMethod('dispatchSignals')->invoke($this);
    }

    /**
     * @return list<string> Agent types the drain delivered to, in order
     */
    public function deliveredTo(): array
    {
        return $this->workerServer->deliveredAgentTypes;
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new DaemonManagerLostSignalLogTestRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DaemonManagerLostSignalLogTestAgentManagerDaemon();
    }
}

/**
 * Router answering with one declared route and nothing else, so both outcomes of the drain —
 * a signal that arrives and a signal that is lost — are reachable without a project topology.
 */
final class DaemonManagerLostSignalLogTestRouter extends SignalRouter
{
    public const string ROUTED_SIGNAL = 'hilos_routed_test_send';

    public const string AGENT_TYPE = 'lost_signal_test_agent';

    /**
     * @param SignalDTO $signal Signal being routed
     * @return list<AgentDestination> The one declared destination, or none
     */
    protected function additionalDestinations(SignalDTO $signal): array
    {
        return $signal->signalName->getName() === self::ROUTED_SIGNAL
            ? [new AgentDestination(self::AGENT_TYPE)]
            : [];
    }
}

final class DaemonManagerLostSignalLogTestAgentManagerDaemon extends AgentManagerDaemon
{
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}

/**
 * A worker server that records the handoff instead of starting a process: the drain looks one
 * up before it touches the queue, and the delivery step is not what these cases are about.
 */
final class DaemonManagerLostSignalLogTestWorkerServer extends WorkerServer
{
    /** @var list<string> Agent types the drain handed a signal to, in order */
    public array $deliveredAgentTypes = [];

    public function __construct()
    {
    }

    /**
     * @param string $agentType Agent type the signal was routed to
     * @param ?string $agentIndex Agent index for a pooled agent, or null
     * @param DaemonAgentMessageDTO $messageDto Signal wrapped for the worker
     */
    public function sendSignalToAgent(string $agentType, ?string $agentIndex, DaemonAgentMessageDTO $messageDto): void
    {
        $this->deliveredAgentTypes[] = $agentType;
    }

    protected function onStart(): void
    {
    }
}
