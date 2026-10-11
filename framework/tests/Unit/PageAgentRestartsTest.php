<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Daemon\AgentRestartDoor;
use Hilos\Core\Daemon\AgentRestarts;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\MasterSignalSender;
use Hilos\Core\Daemon\PageAgentRestarts;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\DaemonSection\DTO\DaemonAgentRestartRequestSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Command\CommandReplyOrigins;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Tests\Unit\Fixtures\PageAgentRestartTestManager;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Web waiting is independent of the existing restart's lifetime and CLI callers. */
final class PageAgentRestartsTest extends TestCase
{
    private ?ClusterContext $previousCluster;
    private ?SignalRouter $previousRouter;
    private ?EnvAccessor $previousEnv;
    private ?RtContext $previousRt;
    /** @var class-string<Hilos> */
    private string $previousApp;
    /** @var array<string, string|false> */
    private array $previousClusterEnv = [];
    private PageAgentRestarts $bridge;
    /** @var list<CommandRequestDTO> */
    private array $requests = [];
    /** @var list<HandoverAnswerSignalData> */
    private array $answers = [];
    /** @var list<string> */
    private array $abandoned = [];

    protected function setUp(): void
    {
        $this->previousCluster = Hilos::$cluster;
        $this->previousRouter = Hilos::$sr;
        $this->previousEnv = Hilos::$env;
        $this->previousRt = Hilos::$rt;
        $this->previousApp = Hilos::appClass();
        Hilos::$rt = null;
        foreach (['CLUSTER_ENABLED', 'CLUSTER_NODE_ID', 'CLUSTER_NODE_ROLE'] as $key) {
            $this->previousClusterEnv[$key] = getenv($key);
        }
        Hilos::$cluster = null;
        $door = $this->createMock(AgentRestartDoor::class);
        $door->method('requestAgentRestart')->willReturnCallback(function (CommandRequestDTO $request): void {
            $this->requests[] = $request;
        });
        $sender = $this->createMock(MasterSignalSender::class);
        $sender->method('sendToAgent')->willReturnCallback(
            function (string $type, ?string $index, string $name, SignalDataInterface $data): void {
                $this->assertSame('daemon_pages', $type);
                $this->assertSame('2', $index);
                $this->assertSame(HilosSignalConstants::DAEMON_AGENT_RESTART_DONE, $name);
                $this->assertInstanceOf(HandoverAnswerSignalData::class, $data);
                $this->answers[] = $data;
            },
        );
        $this->bridge = new PageAgentRestarts($door, $sender, function (string $id): void {
            $this->abandoned[] = $id;
        });
    }

    protected function tearDown(): void
    {
        Hilos::$cluster = $this->previousCluster;
        Hilos::$sr = $this->previousRouter;
        Hilos::$env = $this->previousEnv;
        Hilos::$rt = $this->previousRt;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
        foreach ($this->previousClusterEnv as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
        parent::tearDown();
    }

    /** Membership checks leave the request available for completion with the command's actual identity. */
    public function testLocalSuccessUsesTheCommandResultIdentityAndKeepsBrowserCorrelation(): void
    {
        $this->bridge->request($this->ask(), 100.0);
        $request = $this->requests[0];
        $this->assertSame(CliCommands::AGENT_RESTART, $request->command);
        $this->assertStringStartsWith('web-agent-restart-', $request->correlationId);
        $this->assertNotSame('submit-1', $request->correlationId);
        $this->assertSame([
            CommandConstants::FIELD_AGENT_TYPE => 'room',
            CommandConstants::FIELD_AGENT_INDEX => '12',
            CommandConstants::FIELD_NODE_ID => null,
        ], $request->payload);
        $this->assertNull($request->originNodeId);
        $reply = CommandReplyDTO::ok($request->correlationId, [
            CommandConstants::FIELD_AGENT_ID => 'room:12',
            CommandConstants::FIELD_NODE_ID => 'node-b',
        ]);
        $this->assertTrue($this->bridge->hasWaiter($reply->correlationId));
        $this->assertTrue($this->bridge->hasWaiter($reply->correlationId));
        $this->assertSame([], $this->answers);
        $this->assertSame([], $this->abandoned);
        $this->bridge->acceptReply($reply);
        $answer = $this->answers[0];
        $this->assertSame('accept-1', $answer->acceptKey);
        $this->assertSame('submit-1', $answer->requestId);
        $this->assertSame(HilosSignalConstants::HILOS_DAEMON_AGENT_RESTART, $answer->action);
        $this->assertSame('Agent room:12 restarted on node node-b.', $answer->successMessage);
        $this->assertNull($answer->error);
        $this->assertNull($answer->errorType);
        $this->assertNull($answer->errorDetail);
        $this->assertFalse($this->bridge->hasWaiter($reply->correlationId));
        $this->bridge->acceptReply($reply);
        $this->assertCount(1, $this->answers);
    }

    /** The target node and the reply address belong to different nodes in a cluster. */
    public function testClusterRequestStampsItsOwnOriginAndNamesTheViewedNode(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-origin');
        putenv('CLUSTER_NODE_ROLE=master');
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();
        $this->bridge->request($this->ask(), 100.0);
        $this->assertSame('node-origin', $this->requests[0]->originNodeId);
        $this->assertSame('node-a', $this->requests[0]->payload[CommandConstants::FIELD_NODE_ID]);
        $this->assertTrue($this->bridge->hasWaiter($this->requests[0]->correlationId));
        $this->bridge->acceptReply(CommandReplyDTO::error(
            $this->requests[0]->correlationId, 'Node node-a is silent',
        ));
        $this->assertFalse($this->bridge->hasWaiter($this->requests[0]->correlationId));
        $this->assertSame('Node node-a is silent', $this->answers[0]->error);
    }

    /** A synchronous refusal completes the wait before the restart request call returns. */
    public function testImmediateRefusalFindsTheAlreadyRegisteredWaiter(): void
    {
        $door = $this->createMock(AgentRestartDoor::class);
        $sender = $this->createMock(MasterSignalSender::class);
        $sender->expects($this->once())->method('sendToAgent')->with(
            'daemon_pages', '2', HilosSignalConstants::DAEMON_AGENT_RESTART_DONE,
            $this->callback(static fn (SignalDataInterface $data): bool => $data instanceof HandoverAnswerSignalData
                && $data->error === 'No such agent'),
        );
        $bridge = new PageAgentRestarts($door, $sender, static function (string $id): void {});
        $door->method('requestAgentRestart')->willReturnCallback(function (CommandRequestDTO $request) use ($bridge): void {
            $this->assertTrue($bridge->hasWaiter($request->correlationId));
            $bridge->acceptReply(CommandReplyDTO::error($request->correlationId, 'No such agent'));
            $this->assertFalse($bridge->hasWaiter($request->correlationId));
        });
        $bridge->request($this->ask());
    }

    /** The restart's own refusal consumes the web wait, so its later deadline owes no timeout. */
    public function testTheExistingThirteenSecondRefusalEndsOnlyThisWebWait(): void
    {
        $this->bridge->request($this->ask(), 100.0);
        $this->bridge->tick(112.999);
        $this->assertSame([], $this->answers);
        $this->assertTrue($this->bridge->hasWaiter($this->requests[0]->correlationId));
        $this->bridge->acceptReply(CommandReplyDTO::error(
            $this->requests[0]->correlationId, 'Agent room:12 is not up yet after 13s; the restart goes on',
        ));
        $this->assertFalse($this->bridge->hasWaiter($this->requests[0]->correlationId));
        $this->bridge->tick(130.0);
        $this->assertSame([], $this->abandoned);
        $this->assertCount(1, $this->answers);
        $this->assertSame('Agent room:12 is not up yet after 13s; the restart goes on', $this->answers[0]->error);
    }

    /** Reusing browser correlation after a timeout never lets the expired command answer the new press. */
    public function testThirtySecondBoundaryAbandonsTheWaitAndALateReplyCannotAnswerANewPress(): void
    {
        $this->bridge->request($this->ask(), 100.0);
        $oldId = $this->requests[0]->correlationId;
        $this->bridge->tick(129.999);
        $this->assertSame([], $this->answers);
        $this->assertTrue($this->bridge->hasWaiter($oldId));
        $this->bridge->tick(130.0);
        $this->assertFalse($this->bridge->hasWaiter($oldId));
        $this->assertSame([$oldId], $this->abandoned);
        $this->assertSame(
            'The daemon did not answer agent:restart within 30s; check the agent state before trying again.',
            $this->answers[0]->error,
        );
        $this->bridge->request($this->ask(), 130.0);
        $this->assertNotSame($oldId, $this->requests[1]->correlationId);
        $this->assertFalse($this->bridge->hasWaiter($oldId));
        $this->assertTrue($this->bridge->hasWaiter($this->requests[1]->correlationId));
        $this->bridge->acceptReply(CommandReplyDTO::error($oldId, 'late'));
        $this->assertTrue($this->bridge->hasWaiter($this->requests[1]->correlationId));
        $this->assertCount(1, $this->answers);
        $this->bridge->tick(160.0);
        $this->bridge->tick(200.0);
        $this->assertCount(2, $this->answers);
        $this->assertCount(2, $this->abandoned);
    }

    /** Only registered command correlation belongs to the bridge, even with a matching browser id or prefix. */
    public function testCliCorrelationAndUnknownWebCorrelationAreNeverIntercepted(): void
    {
        $this->bridge->request($this->ask(), 100.0);
        $this->assertFalse($this->bridge->hasWaiter('submit-1'));
        $this->assertFalse($this->bridge->hasWaiter('web-agent-restart-unknown'));
        $this->bridge->acceptReply(CommandReplyDTO::error('submit-1', 'CLI'));
        $this->bridge->acceptReply(CommandReplyDTO::error('web-agent-restart-unknown', 'unknown'));
        $this->assertTrue($this->bridge->hasWaiter($this->requests[0]->correlationId));
        $this->assertSame([], $this->answers);
    }

    /** Looking up either concurrent wait preserves both until their own outcome or deadline. */
    public function testConcurrentRequestsRetainTheirOwnReturnAddressesAndDeadlines(): void
    {
        $this->bridge->request($this->ask(), 100.0);
        $this->bridge->request(new DaemonAgentRestartRequestSignalData(
            'room:13', 'node-a', 'accept-2', null, 'daemon_pages', '2',
        ), 110.0);
        $this->assertNotSame($this->requests[0]->correlationId, $this->requests[1]->correlationId);
        $this->assertTrue($this->bridge->hasWaiter($this->requests[0]->correlationId));
        $this->assertTrue($this->bridge->hasWaiter($this->requests[1]->correlationId));
        $this->bridge->tick(130.0);
        $this->assertCount(1, $this->answers);
        $this->assertFalse($this->bridge->hasWaiter($this->requests[0]->correlationId));
        $this->assertTrue($this->bridge->hasWaiter($this->requests[1]->correlationId));
        $this->bridge->acceptReply(CommandReplyDTO::error($this->requests[1]->correlationId, 'second refusal'));
        $this->assertFalse($this->bridge->hasWaiter($this->requests[1]->correlationId));
        $this->assertSame('accept-2', $this->answers[1]->acceptKey);
        $this->assertNull($this->answers[1]->requestId);
        $this->bridge->tick(140.0);
        $this->assertCount(1, $this->abandoned);
    }

    /** A malformed command outcome still owes the page its deadline answer rather than consuming the wait. */
    public function testMalformedSuccessAndRefusalRetainTheWaitUntilItsDeadline(): void
    {
        $this->bridge->request($this->ask(), 100.0);
        $id = $this->requests[0]->correlationId;
        foreach ([CommandConstants::STATUS_OK, CommandConstants::STATUS_ERROR] as $status) {
            $this->assertTrue($this->bridge->hasWaiter($id));
            $this->bridge->acceptReply(new CommandReplyDTO($id, $status, []));
            $this->assertTrue($this->bridge->hasWaiter($id));
            $this->assertSame([], $this->answers);
            $this->assertSame([], $this->abandoned);
        }
        $this->bridge->tick(129.999);
        $this->assertTrue($this->bridge->hasWaiter($id));
        $this->assertSame([], $this->answers);
        $this->bridge->tick(130.0);
        $this->assertFalse($this->bridge->hasWaiter($id));
        $this->assertSame([$id], $this->abandoned);
        $this->assertCount(1, $this->answers);
        $this->assertSame(
            'The daemon did not answer agent:restart within 30s; check the agent state before trying again.',
            $this->answers[0]->error,
        );
    }

    public function testBothDaemonReplyEntrancesReachWebWithoutAConsoleSocket(): void
    {
        foreach ([false, true] as $forwarded) {
            $manager = new PageAgentRestartTestManager();
            $manager->requestPageAgentRestart($this->ask());
            $request = $manager->requests[0];
            $signal = $this->commandReply(CommandReplyDTO::error($request->correlationId, 'master refusal'));
            if ($forwarded) {
                $manager->deliverCommandReply($signal);
            } else {
                Hilos::$sr->queueSignal($signal->signalSource, $signal->signalType, $signal->signalName, $signal->data);
                $manager->drain();
            }
            $this->assertCount(1, $manager->answers);
            $this->assertSame('master refusal', $manager->answers[0]->error);
        }
    }

    public function testDaemonRetainsCliDeliveryAndPeerOriginTakesPriorityOverTheWebMap(): void
    {
        $manager = new PageAgentRestartTestManager(withConsole: true);
        $manager->requestPageAgentRestart($this->ask());
        $request = $manager->requests[0];
        $reply = CommandReplyDTO::error($request->correlationId, 'remote');
        $origins = new ReflectionProperty(DaemonManager::class, 'commandReplyOrigins')->getValue($manager);
        $this->assertInstanceOf(CommandReplyOrigins::class, $origins);
        $origins->note($request->correlationId, 'node-origin', microtime(true));
        $signal = $this->commandReply($reply);
        Hilos::$sr->queueSignal($signal->signalSource, $signal->signalType, $signal->signalName, $signal->data);
        $manager->drain();
        $this->assertSame([], $manager->answers);
        $this->assertSame([], $manager->console->replies);
        $manager->deliverCommandReply($signal);
        $this->assertCount(1, $manager->answers);
        foreach ([false, true] as $forwarded) {
            $cli = $this->commandReply(CommandReplyDTO::error('cli-correlation', 'cli result'));
            if ($forwarded) {
                $manager->deliverCommandReply($cli);
            } else {
                Hilos::$sr->queueSignal($cli->signalSource, $cli->signalType, $cli->signalName, $cli->data);
                $manager->drain();
            }
        }
        $this->assertCount(2, $manager->console->replies);
        $this->assertCount(1, $manager->answers);
    }

    /** The bridge consumes only the caller's wait while the existing restart still owns its running work. */
    public function testExistingRestartThirteenSecondReplyTravelsThroughTheBridgeWhileItsMarkRemains(): void
    {
        $restarts = $this->realRestart();
        $door = $this->createMock(AgentRestartDoor::class);
        $door->method('requestAgentRestart')->willReturnCallback(static function (CommandRequestDTO $request) use ($restarts): void {
            $restarts->request($request, 100.0);
        });
        $sender = $this->createMock(MasterSignalSender::class);
        $sender->expects($this->once())->method('sendToAgent')->with(
            'daemon_pages', '2', HilosSignalConstants::DAEMON_AGENT_RESTART_DONE,
            $this->callback(static fn (SignalDataInterface $done): bool => $done instanceof HandoverAnswerSignalData
                && $done->error === 'Agent room:12 is not up yet after 13s; the restart goes on'),
        );
        $bridge = new PageAgentRestarts($door, $sender, $restarts->onCommandAbandoned(...));
        $bridge->request($this->ask(), 100.0);
        $restarts->tick(113.0);
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertInstanceOf(CommandReplyDTO::class, $signal->data);
        $this->assertTrue($bridge->hasWaiter($signal->data->correlationId));
        $bridge->acceptReply($signal->data);
        $this->assertFalse($bridge->hasWaiter($signal->data->correlationId));
        $this->assertCount(1, $restarts->restartingAgents());
        $restarts->onAgentStarted('room:12');
        $this->assertSame([], $restarts->restartingAgents());
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testWebTimeoutAbandonsTheActualWaiterAndNeverCancelsTheRestart(): void
    {
        $restarts = $this->realRestart();
        $door = $this->createMock(AgentRestartDoor::class);
        $door->method('requestAgentRestart')->willReturnCallback(static function (CommandRequestDTO $request) use ($restarts): void {
            $restarts->request($request, 100.0);
        });
        $sender = $this->createMock(MasterSignalSender::class);
        $sender->expects($this->once())->method('sendToAgent');
        $bridge = new PageAgentRestarts($door, $sender, $restarts->onCommandAbandoned(...));
        $bridge->request($this->ask(), 100.0);
        $bridge->tick(130.0);
        $this->assertCount(1, $restarts->restartingAgents());
        $restarts->tick(131.0);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal(), 'The abandoned restart waiter owes no command reply');
        $restarts->onAgentStarted('room:12');
        $this->assertSame([], $restarts->restartingAgents());
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    private function realRestart(): AgentRestarts
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, PageAgentRestartRunTestHilos::class);
        Hilos::$sr = new SignalRouter();
        $agents = $this->createMock(AgentManagerDaemon::class);
        $agents->method('hasAgent')->willReturn(true);
        $agents->method('isAgentStarted')->willReturn(true);
        $worker = $this->createMock(WorkerServer::class);
        $worker->method('placedWorkerId')->willReturn(1);
        $worker->expects($this->once())->method('restartAgentHere')->with('room', '12', false);
        return new AgentRestarts($agents, static fn (): WorkerServer => $worker, new CommandReplyOrigins());
    }

    private function ask(): DaemonAgentRestartRequestSignalData
    {
        return new DaemonAgentRestartRequestSignalData('room:12', 'node-a', 'accept-1', 'submit-1', 'daemon_pages', '2');
    }

    private function commandReply(CommandReplyDTO $reply): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::COMMAND_REPLY),
            new SignalName($reply->correlationId), $reply,
        );
    }
}

abstract class PageAgentRestartRunTestHilos extends Hilos
{
    public const array AGENTS = ['room' => [AgentRegistryKey::INDEXED => true]];
}
