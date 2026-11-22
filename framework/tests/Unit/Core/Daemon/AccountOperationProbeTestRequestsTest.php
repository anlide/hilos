<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Daemon;

use Hilos\Auth\AccountOperation\Testing\AccountOperationProbeRequest;
use Hilos\Auth\AccountOperation\Testing\AccountOperationProbeState;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Core\Daemon\AccountOperationProbeTestRequests;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\WorkerTestAccountOperationProbeDTO;
use Hilos\Socket\Worker\DTO\WorkerTestAccountOperationProbedDTO;
use PHPUnit\Framework\TestCase;

/** The original worker, its placement and the original deadline all have to agree. */
final class AccountOperationProbeTestRequestsTest extends TestCase
{
    private ?EnvAccessor $previousEnv;
    /** @var array<string, int> Current local placements */
    private array $placements = ['holder:42' => 3, 'holder:43' => -4];
    /** @var array<int, WorkerClient> Current transport channels */
    private array $workers = [];
    /** @var list<WorkerTestAccountOperationProbeDTO> Frames actually routed */
    private array $sent = [];
    /** @var list<CommandReplyDTO> Console outcomes */
    private array $replies = [];
    private AccountOperationProbeTestRequests $requests;

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        putenv('APP_ENV=test');
        Hilos::$env = new EnvAccessor();
        $agents = $this->createStub(AgentManagerDaemon::class);
        $agents->method('getAgentWorkerId')->willReturnCallback(fn (string $id): ?int => $this->placements[$id] ?? null);
        $this->workers = [3 => $this->createStub(WorkerClient::class), -4 => $this->createStub(WorkerClient::class)];
        foreach ($this->workers as $worker) {
            $worker->method('shouldClose')->willReturn(false);
        }
        $transport = $this->createStub(WorkerServer::class);
        $transport->method('sendTestAccountOperationProbe')->willReturnCallback(
            function (int $id, WorkerTestAccountOperationProbeDTO $dto): ?WorkerClient {
                $this->sent[] = $dto;
                return $this->workers[$id] ?? null;
            },
        );
        $this->requests = new AccountOperationProbeTestRequests($agents, static fn (): WorkerServer => $transport,
            function (CommandReplyDTO $reply): void {
                $this->replies[] = $reply;
            });
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
    }

    public function testOnlyTheOriginalConnectionAndCurrentPlacementCanAnswerBeforeTheDeadline(): void
    {
        $original = $this->workers[3];
        $this->requests->request($this->ask('valid'), 100.0);
        self::assertSame('valid', $this->sent[0]->correlationId);
        self::assertSame($this->ask('valid')->payload, $this->sent[0]->request->toArray());
        $this->requests->accept($this->workers[-4], $this->ack('valid'), 101.0);
        $this->requests->accept($original, $this->ack('unknown'), 101.0);
        self::assertSame([], $this->replies);
        $this->requests->accept($original, $this->ack('valid'), 101.0);
        self::assertSame(['armed' => false, 'matched' => 0, 'held' => null], $this->replies[0]->payload);
        self::assertTrue($this->replies[0]->isOk());
        $this->requests->accept($original, $this->ack('valid'), 101.0);
        self::assertCount(1, $this->replies);

        $this->requests->request($this->ask('moved'), 100.0);
        $this->placements['holder:42'] = -4;
        $this->requests->accept($this->workers[-4], $this->ack('moved'), 101.0);
        self::assertCount(1, $this->replies);
        $this->requests->accept($original, $this->ack('moved'), 101.0);
        self::assertFalse($this->replies[1]->isOk());
        self::assertSame('The account operation probe source no longer runs on the original worker.', $this->replies[1]->payload['message']);

        $this->placements['holder:42'] = 3;
        $this->requests->request($this->ask('reconnected'), 100.0);
        $this->workers[3] = $this->workers[-4];
        $this->requests->accept($this->workers[3], $this->ack('reconnected'), 101.0);
        self::assertCount(2, $this->replies, 'A reused signed worker id cannot rebind the original connection');
        $this->requests->abandon('reconnected');
        $this->requests->request($this->ask('late'), 100.0);
        $this->requests->accept($this->workers[3], $this->ack('late'), 100.0 + CommandChannelWindows::AGENT_WAIT_SECONDS);
        self::assertFalse($this->replies[2]->isOk());
        self::assertSame('Agent holder:42 did not acknowledge the account operation probe in time.', $this->replies[2]->payload['message']);

        $sent = count($this->sent);
        $this->requests->request(new CommandRequestDTO('invalid', CliCommands::ACCOUNT_TEST_OPERATION_PROBE, []), 100.0);
        $this->requests->request($this->ask('absent', 'holder:99'), 100.0);
        self::assertCount($sent, $this->sent);
        self::assertFalse($this->replies[3]->isOk());
        self::assertSame('No agent holder:99 runs on this node.', $this->replies[4]->payload['message']);
        putenv('APP_ENV=prod');
        Hilos::$env = new EnvAccessor();
        $this->requests->request($this->ask('production'), 100.0);
        self::assertSame(TestOnlyCommandOnProductionException::message(CliCommands::ACCOUNT_TEST_OPERATION_PROBE),
            $this->replies[5]->payload[CommandConstants::FIELD_MESSAGE]);
        self::assertCount($sent, $this->sent);
    }

    public function testGoneAndAbandonRemoveOnlyTheirOwnPendingRequests(): void
    {
        $original = $this->workers[3];
        $this->requests->request($this->ask('gone'), 100.0);
        $this->requests->request($this->ask('abandon'), 100.0);
        $this->requests->request($this->ask('other', 'holder:43'), 100.0);
        $this->requests->abandon('abandon');
        $this->requests->workerGone($original);
        self::assertSame(['gone'], array_column($this->replies, 'correlationId'));
        self::assertFalse($this->replies[0]->isOk());
        $this->requests->accept($original, $this->ack('gone'), 101.0);
        $this->requests->accept($original, $this->ack('abandon'), 101.0);
        $this->requests->accept($this->workers[-4], new WorkerTestAccountOperationProbedDTO('other', false, null, 'Source refused'), 101.0);
        self::assertSame('Source refused', $this->replies[1]->payload['message']);
        $this->requests->tick(INF);
        self::assertSame(['gone', 'other'], array_column($this->replies, 'correlationId'));
    }

    public function testExpiryConsumesEachWaitAtItsOriginalDeadlineOnce(): void
    {
        $this->requests->request($this->ask('123'), 100.0);
        $this->requests->request($this->ask('later', 'holder:43'), 101.0);
        $this->requests->tick(100.0 + CommandChannelWindows::AGENT_WAIT_SECONDS - 0.1);
        self::assertSame([], $this->replies);
        $this->requests->tick(100.0 + CommandChannelWindows::AGENT_WAIT_SECONDS);
        self::assertSame(['123'], array_column($this->replies, 'correlationId'));
        self::assertFalse($this->replies[0]->isOk());
        $this->requests->accept($this->workers[3], $this->ack('123'), 101.0);
        $this->requests->tick(INF);
        $this->requests->tick(INF);
        self::assertSame(['123', 'later'], array_column($this->replies, 'correlationId'));
        self::assertFalse($this->replies[1]->isOk());
    }

    /** @param string $id Console correlation
     * @param string $agent Existing source address
     * @return CommandRequestDTO Strict inspection request
     */
    private function ask(string $id, string $agent = 'holder:42'): CommandRequestDTO
    {
        return new CommandRequestDTO($id, CliCommands::ACCOUNT_TEST_OPERATION_PROBE,
            [AgentConstants::FIELD_AGENT_ID => $agent, AccountOperationProbeRequest::ACTION => 'inspect']);
    }

    /** @param string $id Console correlation
     * @return WorkerTestAccountOperationProbedDTO Successful worker snapshot
     */
    private function ack(string $id): WorkerTestAccountOperationProbedDTO
    {
        return new WorkerTestAccountOperationProbedDTO($id, true, new AccountOperationProbeState(false, 0, null), null);
    }
}
