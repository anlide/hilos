<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Core\Daemon\CronFireTestRequests;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\WorkerTestCronFireDTO;
use Hilos\Socket\Worker\DTO\WorkerTestCronFiredDTO;
use Hilos\Socket\Worker\DTO\WorkerTestCronScheduleDTO;
use Hilos\Socket\Worker\DTO\WorkerTestCronScheduleResultDTO;
use Hilos\Core\Daemon\Cron\CronScheduleTestOperation;
use LogicException;
use PHPUnit\Framework\TestCase;
use Socket;

/**
 * The master holds an ask to fire an agent's cron rule until the connection it went out on answers,
 * and ends the wait on that answer, the agent wait window, the worker's departure or the caller's.
 *
 * Every case names APP_ENV explicitly: the master re-asks the non-production gate at its own door.
 */
final class CronFireTestRequestsTest extends TestCase
{
    private const string AGENT = 'mailer';
    private const string RULE = 'nightly-digest';
    private const int WORKER_ID = 3;

    private ?EnvAccessor $previousEnv;
    private string|false $previousAppEnv;
    /** @var list<Socket> Open test sockets */
    private array $sockets = [];
    private CronFireAgentRoster $agents;
    private CronFireWorkerTransport $transport;
    /** @var list<CommandReplyDTO> */
    private array $replies = [];
    private CronFireTestRequests $requests;

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousAppEnv = getenv('APP_ENV');
        $this->environment('test');
        $this->agents = new CronFireAgentRoster();
        $this->agents->workerIds[self::AGENT] = self::WORKER_ID;
        $this->transport = new CronFireWorkerTransport();
        $this->transport->live[self::WORKER_ID] = $this->worker();
        $this->requests = new CronFireTestRequests(
            $this->agents,
            fn (): WorkerServer => $this->transport,
            function (CommandReplyDTO $reply): void {
                $this->replies[] = $reply;
            },
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        Hilos::$env = $this->previousEnv;
        putenv($this->previousAppEnv === false ? 'APP_ENV' : 'APP_ENV=' . $this->previousAppEnv);
        parent::tearDown();
    }

    public function testProductionIsRefusedAtTheMasterDoor(): void
    {
        $this->environment('prod');

        $this->requests->request($this->ask('c-1'), self::AGENT, self::RULE, 100.0);

        $this->assertRefused('c-1', TestOnlyCommandOnProductionException::message(CliCommands::CRON_TEST_FIRE));
        self::assertSame([], $this->transport->sent);
    }

    public function testAnAgentTheRosterDoesNotPlaceOnThisNodeIsRefused(): void
    {
        $this->requests->request($this->ask('c-2'), 'auditor', self::RULE, 100.0);

        $this->assertRefused('c-2', 'No agent auditor runs on this node.');
        self::assertSame([], $this->transport->sent);
    }

    public function testAnAgentWhoseWorkerIsNotLiveIsRefused(): void
    {
        $this->transport->live = [];

        $this->requests->request($this->ask('c-3'), self::AGENT, self::RULE, 100.0);

        $this->assertRefused('c-3', 'No agent mailer runs on this node.');
        self::assertCount(1, $this->transport->sent, 'The live-worker check is the transport\'s');
    }

    public function testAFailedSendIsARefusal(): void
    {
        $this->transport->failure = new LogicException('The link to worker 3 broke');

        $this->requests->request($this->ask('c-4'), self::AGENT, self::RULE, 100.0);

        $this->assertRefused('c-4', 'The link to worker 3 broke');
        $this->requests->tick(INF);
        self::assertCount(1, $this->replies);
    }

    public function testTheArmingGoesToTheAgentsWorkerAndItsOutcomeAnswersOnce(): void
    {
        $this->requests->request($this->ask('c-5'), self::AGENT, self::RULE, 100.0);

        self::assertCount(1, $this->transport->sent);
        [$workerId, $frame] = $this->transport->sent[0];
        self::assertSame(self::WORKER_ID, $workerId);
        self::assertSame(['c-5', self::AGENT, self::RULE], [$frame->correlationId, $frame->agentId, $frame->ruleName]);
        self::assertSame([], $this->replies);

        $worker = $this->transport->live[self::WORKER_ID];
        $this->requests->accept($worker, new WorkerTestCronFiredDTO('c-5', true, 1_767_225_600_000, null), 101.0);

        self::assertCount(1, $this->replies);
        self::assertSame('c-5', $this->replies[0]->correlationId);
        self::assertTrue($this->replies[0]->isOk());
        self::assertSame([CommandConstants::FIELD_FIRED_AT => 1_767_225_600_000], $this->replies[0]->payload);

        $this->requests->accept($worker, new WorkerTestCronFiredDTO('c-5', true, 1_767_225_600_000, null), 101.5);
        $this->requests->tick(INF);
        self::assertCount(1, $this->replies, 'A duplicate cannot answer twice, nor can the window');
    }

    public function testTheWorkersRefusalAnswersTheConsoleInItsOwnWords(): void
    {
        $this->requests->request($this->ask('c-6'), self::AGENT, self::RULE, 100.0);

        $this->requests->accept(
            $this->transport->live[self::WORKER_ID],
            new WorkerTestCronFiredDTO('c-6', false, null, 'Agent mailer holds no cron rule nightly-digest.'),
            101.0,
        );

        $this->assertRefused('c-6', 'Agent mailer holds no cron rule nightly-digest.');
    }

    public function testUnknownAndForeignOutcomesLeaveTheWaitPending(): void
    {
        $this->requests->request($this->ask('c-7'), self::AGENT, self::RULE, 100.0);
        $foreign = $this->worker();

        $this->requests->accept($foreign, new WorkerTestCronFiredDTO('c-7', true, 1_000, null), 101.0);
        $this->requests->accept(
            $this->transport->live[self::WORKER_ID],
            new WorkerTestCronFiredDTO('other-correlation', true, 1_000, null),
            101.0,
        );
        self::assertSame([], $this->replies);

        $this->requests->accept($this->transport->live[self::WORKER_ID], new WorkerTestCronFiredDTO('c-7', true, 2_000, null), 101.0);
        self::assertCount(1, $this->replies);
        self::assertSame([CommandConstants::FIELD_FIRED_AT => 2_000], $this->replies[0]->payload);
    }

    public function testAnUncheckedRuleEndsAtTheAgentWindowAndALateOutcomeIsIgnored(): void
    {
        $this->requests->request($this->ask('c-8'), self::AGENT, self::RULE, 100.0);

        $this->requests->tick(100.0 + CommandChannelWindows::AGENT_WAIT_SECONDS - 0.1);
        self::assertSame([], $this->replies);

        $this->requests->tick(100.0 + CommandChannelWindows::AGENT_WAIT_SECONDS);
        $this->assertRefused('c-8', 'Agent mailer did not check cron rule nightly-digest in time.');

        $this->requests->accept($this->transport->live[self::WORKER_ID], new WorkerTestCronFiredDTO('c-8', true, 1_000, null), 200.0);
        self::assertCount(1, $this->replies);
    }

    public function testAnOutcomePastTheDeadlineIsRefusedBeforeTheNextTick(): void
    {
        $this->requests->request($this->ask('c-9'), self::AGENT, self::RULE, 100.0);

        $this->requests->accept(
            $this->transport->live[self::WORKER_ID],
            new WorkerTestCronFiredDTO('c-9', true, 1_000, null),
            100.0 + CommandChannelWindows::AGENT_WAIT_SECONDS,
        );

        $this->assertRefused('c-9', 'Agent mailer did not check cron rule nightly-digest in time.');
    }

    public function testAWorkerThatWentDownEndsOnlyItsOwnWaits(): void
    {
        $this->agents->workerIds['auditor'] = -4;
        $this->transport->live[-4] = $this->worker();
        $this->requests->request($this->ask('c-10'), self::AGENT, self::RULE, 100.0);
        $this->requests->request($this->ask('c-11'), 'auditor', 'weekly-sweep', 100.0);

        $this->requests->workerGone($this->transport->live[self::WORKER_ID]);

        $this->assertRefused('c-10', 'The worker running agent mailer went down before the rule fired.');
        $this->requests->accept($this->transport->live[-4], new WorkerTestCronFiredDTO('c-11', true, 3_000, null), 101.0);
        self::assertCount(2, $this->replies);
        self::assertSame('c-11', $this->replies[1]->correlationId);
        self::assertTrue($this->replies[1]->isOk());
    }

    public function testAnOutcomeOverAClosingConnectionIsTheWorkerGoingDown(): void
    {
        $this->requests->request($this->ask('c-12'), self::AGENT, self::RULE, 100.0);
        $worker = $this->transport->live[self::WORKER_ID];
        $worker->markShouldClose();

        $this->requests->accept($worker, new WorkerTestCronFiredDTO('c-12', true, 1_000, null), 101.0);

        $this->assertRefused('c-12', 'The worker running agent mailer went down before the rule fired.');
    }

    public function testACallerWhoLeftIsNeitherAnsweredNorTimedOut(): void
    {
        $this->requests->request($this->ask('c-13'), self::AGENT, self::RULE, 100.0);

        $this->requests->abandon('c-13');
        $this->requests->accept($this->transport->live[self::WORKER_ID], new WorkerTestCronFiredDTO('c-13', true, 1_000, null), 101.0);
        $this->requests->workerGone($this->transport->live[self::WORKER_ID]);
        $this->requests->tick(INF);

        self::assertSame([], $this->replies);
    }

    /** A correlation of digits only is kept under an int key; the window and the departure still name it. */
    public function testADigitOnlyCorrelationIsAnsweredByTheWindowAndTheDeparture(): void
    {
        $this->requests->request($this->ask('1234567890123456'), self::AGENT, self::RULE, 100.0);
        $this->requests->tick(INF);
        $this->assertRefused('1234567890123456', 'Agent mailer did not check cron rule nightly-digest in time.');

        $this->replies = [];
        $this->requests->request($this->ask('6543210987654321'), self::AGENT, self::RULE, 100.0);
        $this->requests->workerGone($this->transport->live[self::WORKER_ID]);
        $this->assertRefused('6543210987654321', 'The worker running agent mailer went down before the rule fired.');
    }

    public function testScheduleAcknowledgementsRequireTheExactConnectionAndMatchingOutcomeKind(): void
    {
        $schedule = new WorkerTestCronScheduleDTO('s-1', self::AGENT, self::RULE, CronScheduleTestOperation::Pause, 'pause-1');
        $this->requests->request($this->ask('s-1'), self::AGENT, self::RULE, 100.0, $schedule);
        $worker = $this->transport->live[self::WORKER_ID];
        self::assertSame($schedule, $this->transport->sent[0][1]);
        $this->requests->accept($this->worker(), new WorkerTestCronScheduleResultDTO('s-1', true, null), 101.0);
        $this->requests->accept($worker, new WorkerTestCronFiredDTO('s-1', true, 1000, null), 101.0);
        self::assertSame([], $this->replies);
        $this->requests->accept($worker, new WorkerTestCronScheduleResultDTO('s-1', true, null), 101.0);
        self::assertSame(['pauseId' => 'pause-1'], $this->replies[0]->payload);
        $this->requests->accept($worker, new WorkerTestCronScheduleResultDTO('s-1', true, null), 101.0);
        $this->requests->tick(INF);
        self::assertCount(1, $this->replies);
    }

    public function testScheduleWaitsShareDeadlineDisconnectAndAbandonCleanup(): void
    {
        $worker = $this->transport->live[self::WORKER_ID];
        foreach (['timeout', 'disconnect', 'abandon'] as $end) {
            $schedule = new WorkerTestCronScheduleDTO($end, self::AGENT, self::RULE, CronScheduleTestOperation::CancelPause, 'pause-1');
            $this->requests->request($this->ask($end), self::AGENT, self::RULE, 100.0, $schedule);
            if ($end === 'timeout') {
                $this->requests->tick(100.0 + CommandChannelWindows::AGENT_WAIT_SECONDS);
                $this->assertRefused($end, 'Agent mailer did not acknowledge cron schedule nightly-digest in time.');
            } elseif ($end === 'disconnect') {
                $this->requests->workerGone($worker);
                $this->assertRefused($end, 'The worker running agent mailer went down before the schedule change was acknowledged.');
            } else {
                $this->requests->abandon($end);
                self::assertSame([], $this->replies);
            }
            $this->replies = [];
            $this->requests->accept($worker, new WorkerTestCronScheduleResultDTO($end, true, null), 101.0);
            $this->requests->tick(INF);
            self::assertSame([], $this->replies);
        }
    }

    /**
     * @param string $correlationId Console correlation
     * @return CommandRequestDTO The ask as the command channel hands it to the master
     */
    private function ask(string $correlationId): CommandRequestDTO
    {
        return new CommandRequestDTO($correlationId, CliCommands::CRON_TEST_FIRE, [
            CommandConstants::FIELD_AGENT_ID => self::AGENT,
            CommandConstants::FIELD_RULE_NAME => self::RULE,
        ]);
    }

    /** @param string $env Environment of the master under test */
    private function environment(string $env): void
    {
        putenv('APP_ENV=' . $env);
        Hilos::$env = new EnvAccessor();
    }

    /** @return WorkerClient A registered-looking connection over a local socket, without a worker process */
    private function worker(): WorkerClient
    {
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        array_push($this->sockets, ...$pair);
        return new WorkerClient($pair[0], $this->agents, $this->transport);
    }

    /**
     * @param string $correlationId Ask answered last
     * @param string $reason Exact refusal expected on the console
     */
    private function assertRefused(string $correlationId, string $reason): void
    {
        self::assertCount(1, $this->replies);
        self::assertSame($correlationId, $this->replies[0]->correlationId);
        self::assertFalse($this->replies[0]->isOk());
        self::assertSame($reason, $this->replies[0]->payload[CommandConstants::FIELD_MESSAGE] ?? null);
    }
}

final class CronFireAgentRoster extends AgentManagerDaemon
{
    /** @var array<string, int> Signed worker id of each agent placed on this node */
    public array $workerIds = [];

    /**
     * @param string $agentId Agent ID
     * @return ?int Signed worker id, or null for an agent not on this node
     */
    public function getAgentWorkerId(string $agentId): ?int
    {
        return $this->workerIds[$agentId] ?? null;
    }

    /**
     * @param string $agentType Unused agent type
     * @param ?string $agentIndex Unused instance index
     * @return AgentDaemonInterface Never returned
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new LogicException('The cron fire test starts no agents');
    }
}

final class CronFireWorkerTransport extends WorkerServer
{
    /** @var array<int, WorkerClient> Live connection of each worker */
    public array $live = [];

    /** @var list<array{int, WorkerTestCronFireDTO|WorkerTestCronScheduleDTO}> Test control frames handed to the transport */
    public array $sent = [];

    public ?LogicException $failure = null;

    /** Bypasses process startup; the test supplies the connections. */
    public function __construct()
    {
    }

    /**
     * @param int $workerId Signed worker id
     * @param WorkerTestCronFireDTO $request Arming to queue
     * @return ?WorkerClient Connection used, or null when the worker is not live
     * @throws LogicException When the test breaks the link
     */
    public function sendTestCronFire(int $workerId, WorkerTestCronFireDTO $request): ?WorkerClient
    {
        $this->sent[] = [$workerId, $request];
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return $this->live[$workerId] ?? null;
    }

    /**
     * @param int $workerId Signed worker id
     * @param WorkerTestCronScheduleDTO $request Schedule change to queue
     * @return ?WorkerClient Exact connection used
     */
    public function sendTestCronSchedule(int $workerId, WorkerTestCronScheduleDTO $request): ?WorkerClient
    {
        $this->sent[] = [$workerId, $request];
        return $this->live[$workerId] ?? null;
    }

    protected function onStart(): void
    {
    }
}
