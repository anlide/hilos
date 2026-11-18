<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Closure;
use Hilos\Auth\AccountOperation\AccountOperationAnswerKind;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Auth\AccountOperation\Testing\AccountErasureProbe;
use Hilos\Auth\AccountOperation\Testing\AccountErasureProbeAction;
use Hilos\Auth\AccountOperation\Testing\AccountErasureProbeFrameKind;
use Hilos\Auth\AccountOperation\Testing\AccountErasureProbeRequest;
use Hilos\Auth\AccountOperation\Testing\AccountErasureProbeState;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AgentId;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Constants\CliCommands;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerTestAccountErasureProbeDTO;
use Hilos\Socket\Worker\DTO\WorkerTestAccountErasureProbedDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Model and real IPC registry coverage; the connected worker sender is covered separately. */
final class AccountErasureProbeTest extends TestCase
{
    /** A raw worker frame meets the production gate before looking up or mutating any local source. */
    public function testRawWorkerEntranceRefusesProductionBeforeTouchingTheProbe(): void
    {
        $previousEnv = Hilos::$env;
        $previousRouter = Hilos::$sr;
        putenv('APP_ENV=prod');
        Hilos::$env = new EnvAccessor();
        $agents = $this->createMock(AgentManager::class);
        $agents->expects(self::never())->method('getAgent');
        $client = $this->createMock(WorkerDaemonClient::class);
        $client->expects(self::once())->method('send')->with(self::callback(static function ($ack): bool {
            self::assertInstanceOf(WorkerTestAccountErasureProbedDTO::class, $ack);
            self::assertSame('raw-production', $ack->correlationId);
            self::assertFalse($ack->ok);
            self::assertNull($ack->state);
            self::assertSame(TestOnlyCommandOnProductionException::message(CliCommands::ACCOUNT_TEST_ERASURE_PROBE), $ack->error);
            return true;
        }));
        try {
            $worker = new class ($agents, $client) extends WorkerManager {
                /** @param AgentManager $agents Untouched local roster
                 * @param WorkerDaemonClient $client Captured control outcome
                 */
                public function __construct(private readonly AgentManager $agents, WorkerDaemonClient $client)
                {
                    parent::__construct(1);
                    $this->daemonClient = $client;
                }

                /** @return AgentManager Local roster which the production refusal must not query */
                protected function createAgentManager(): AgentManager
                {
                    return $this->agents;
                }

                /** @return SignalRouter Worker router, without starting a process */
                protected function createSignalRouter(): SignalRouter
                {
                    return new SignalRouter();
                }
            };
            $worker->handleDaemonMessage(new WorkerTestAccountErasureProbeDTO('raw-production',
                new AccountErasureProbeRequest('holder:42', AccountErasureProbeAction::Arm, AccountErasureProbeFrameKind::Ask)));
        } finally {
            Hilos::$env = $previousEnv;
            Hilos::$sr = $previousRouter;
        }
    }

    /** Every selector matters; unrelated traffic passes and the first exact match occupies one slot. */
    public function testBoundedCapturePreservesTheWholeEnvelopeAndPassesOtherFrames(): void
    {
        foreach (AccountErasureProbeFrameKind::cases() as $kind) {
            $probe = new AccountErasureProbe();
            $source = $this->source('holder:42');
            $probe->arm(new AccountErasureProbeRequest('holder:42', AccountErasureProbeAction::Arm, $kind, 7, 'parts', 11), $source);
            $matching = self::frame($kind);
            $signal = $matching->signal;
            $others = [
                self::frame($kind, operationId: 8),
                self::frame($kind, stage: 'another-stage'),
                self::frame($kind, assignmentId: 12),
                self::frame($kind, agentId: 'holder:43'),
                self::frame($kind === AccountErasureProbeFrameKind::Ask
                    ? AccountErasureProbeFrameKind::Answer : AccountErasureProbeFrameKind::Ask),
                new WorkerAgentMessageDTO('holder:42', new SignalDTO(
                    new SignalSource(SignalSource::WORKER, 'holder', '42'),
                    $signal->signalType, $signal->signalName, $signal->data,
                )),
                new WorkerAgentMessageDTO('holder:42', new SignalDTO(
                    new SignalSource(SignalSource::AGENT, 'holder', '43'),
                    $signal->signalType, $signal->signalName, $signal->data,
                )),
                new WorkerAgentMessageDTO('holder:42', new SignalDTO(
                    $signal->signalSource, new SignalType(SignalTypeConstants::CRON), $signal->signalName, $signal->data,
                )),
                new WorkerAgentMessageDTO('holder:42', new SignalDTO(
                    $signal->signalSource, $signal->signalType, $signal->signalName, new AgentSignalData(new SignalData([])),
                )),
                new WorkerAgentMessageDTO('holder:42', new SignalDTO(
                    $signal->signalSource, $signal->signalType, $signal->signalName, $signal->data->data,
                )),
            ];
            foreach ($others as $other) {
                self::assertSame($other, $probe->intercept($other, $source));
            }
            self::assertSame($matching, $probe->intercept($matching, null));
            self::assertSame($matching, $probe->intercept($matching, $this->source('holder:42')));
            self::assertSame(['armed' => true, 'matched' => 0, 'held' => null], $probe->inspect()->toArray());
            self::assertNull($probe->intercept($matching, $source));
            self::assertSame($matching, $probe->inspect()->held);
            self::assertSame(['armed' => false, 'matched' => 1, 'held' => $matching->toArray()], $probe->inspect()->toArray());
            self::assertSame($matching, $probe->intercept($matching, $source));
            self::assertSame(1, $probe->inspect()->matched);
            self::refuses(fn() => $probe->arm(new AccountErasureProbeRequest('holder:42', AccountErasureProbeAction::Arm, $kind), $source));
            self::assertSame($matching, $probe->release('holder:42', $source));
            self::refuses(fn() => $probe->release('holder:42', $source));
        }
    }

    /** Omitted selectors match freely; discard and clear consume only their declared local state. */
    public function testDiscardClearAndLossOfTheOriginalSource(): void
    {
        $probe = new AccountErasureProbe();
        $source = $this->source('holder');
        $arm = new AccountErasureProbeRequest('holder', AccountErasureProbeAction::Arm, AccountErasureProbeFrameKind::Answer);
        $frame = self::frame(AccountErasureProbeFrameKind::Answer, 'holder', 91, 'intermediate', 92);
        $probe->arm($arm, $source);
        self::refuses(fn() => $probe->arm($arm, $source));
        self::assertNull($probe->intercept($frame, $source));
        $snapshot = $probe->inspect();
        $probe->discard();
        self::assertSame($frame, $snapshot->held);
        self::assertSame(['armed' => false, 'matched' => 1, 'held' => null], $probe->inspect()->toArray());
        self::refuses(fn() => $probe->release('holder', $source));
        self::refuses(fn() => $probe->discard());

        foreach ([null, $this->source('holder')] as $goneOrReplaced) {
            $probe->arm($arm, $source);
            self::assertNull($probe->intercept($frame, $source));
            self::refuses(fn() => $probe->release('foreign', $source));
            self::assertSame($frame, $probe->inspect()->held);
            self::refuses(fn() => $probe->release('holder', $goneOrReplaced));
            self::assertNull($probe->inspect()->held);
            self::refuses(fn() => $probe->release('holder', $source));
        }
        self::assertSame(3, $probe->inspect()->matched);
        $probe->arm($arm, $source);
        $probe->clear();
        self::assertSame($frame, $probe->intercept($frame, $source));
        $probe->arm($arm, $source);
        $probe->intercept($frame, $source);
        $probe->clear();
        self::assertSame(['armed' => false, 'matched' => 0, 'held' => null], $probe->inspect()->toArray());
        self::refuses(fn() => $probe->release('holder', $source));
        self::refuses(fn() => $probe->arm($arm, $this->source('foreign')));
    }

    /** Every action and both IPC directions hydrate through the real JSON registry. */
    public function testRequestAndWorkerIpcRoundtripWithoutInventingAbsentSelectors(): void
    {
        self::assertSame(['arm', 'inspect', 'release', 'discard', 'replay', 'expire-idle', 'clear'],
            array_column(AccountErasureProbeAction::cases(), 'value'));
        self::assertSame(['ask', 'answer'], array_column(AccountErasureProbeFrameKind::cases(), 'value'));
        foreach (AccountErasureProbeAction::cases() as $action) {
            $wire = ['agentId' => 'holder:42', 'action' => $action->value];
            if ($action === AccountErasureProbeAction::Arm) {
                $wire += ['frameKind' => 'ask'];
            } elseif ($action === AccountErasureProbeAction::Replay) {
                $wire += ['frame' => self::frame(AccountErasureProbeFrameKind::Ask)->toArray(), 'copies' => 2];
            }
            $request = AccountErasureProbeRequest::fromArray($wire);
            self::assertSame($wire, $request->toArray());
            $ipc = new WorkerTestAccountErasureProbeDTO('probe-1', $request);
            $payload = $wire;
            unset($payload['agentId']);
            self::assertSame([
                'type' => 'test_account_erasure_probe', 'correlationId' => 'probe-1', 'agentId' => 'holder:42', 'request' => $payload,
            ], $ipc->toArray());
            $restored = WorkerDTO::factoryWorkerDTO($ipc->toJson());
            self::assertInstanceOf(WorkerTestAccountErasureProbeDTO::class, $restored);
            self::assertSame($ipc->toArray(), $restored->toArray());
            self::assertSame('test_account_erasure_probe', $restored->getType());
        }
        $selected = ['agentId' => 'holder:42', 'action' => 'arm', 'frameKind' => 'answer',
            'operationId' => 7, 'stage' => 'parts', 'assignmentId' => 11];
        self::assertSame($selected, AccountErasureProbeRequest::fromArray($selected)->toArray());
        foreach ([null, self::frame(AccountErasureProbeFrameKind::Ask), self::frame(AccountErasureProbeFrameKind::Answer)] as $held) {
            $state = new AccountErasureProbeState(false, $held === null ? 0 : 1, $held);
            $ipc = new WorkerTestAccountErasureProbedDTO('probe-1', true, $state, null);
            self::assertSame([
                'type' => 'test_account_erasure_probed', 'correlationId' => 'probe-1', 'ok' => true,
                'state' => $state->toArray(), 'error' => null,
            ], $ipc->toArray());
            $restored = WorkerDTO::factoryWorkerDTO($ipc->toJson());
            self::assertInstanceOf(WorkerTestAccountErasureProbedDTO::class, $restored);
            self::assertSame($ipc->toArray(), $restored->toArray());
            self::assertSame('test_account_erasure_probed', $restored->getType());
        }
        $failure = new WorkerTestAccountErasureProbedDTO('probe-1', false, null, 'Local source is gone.');
        self::assertSame($failure->toArray(), WorkerDTO::factoryWorkerDTO($failure->toJson())->toArray());
        self::refuses(fn() => new WorkerTestAccountErasureProbeDTO('', AccountErasureProbeRequest::fromArray($selected)));
        self::refuses(fn() => new WorkerTestAccountErasureProbedDTO('probe-1', true, null, null));
        self::refuses(fn() => new WorkerTestAccountErasureProbedDTO('probe-1', false, null, ''));
    }

    /** @param array<string, mixed> $wire Invalid command request */
    #[DataProvider('invalidRequests')]
    public function testRequestGuardsRejectMalformedTypesAndCombinations(array $wire): void
    {
        $this->expectException(InvalidFormatException::class);
        AccountErasureProbeRequest::fromArray($wire);
    }

    /** @return iterable<string, array{array<string, mixed>}> Strict request boundary refusals */
    public static function invalidRequests(): iterable
    {
        $arm = ['agentId' => 'holder:42', 'action' => 'arm', 'frameKind' => 'ask'];
        foreach (['agentId', 'action', 'frameKind'] as $key) {
            $missing = $arm;
            unset($missing[$key]);
            yield "missing $key" => [$missing];
            foreach ([null, '', 1, true, []] as $index => $value) {
                yield "$key type $index" => [array_replace($arm, [$key => $value])];
            }
        }
        yield 'unknown action' => [array_replace($arm, ['action' => 'unknown'])];
        yield 'unknown kind' => [array_replace($arm, ['frameKind' => 'unknown'])];
        yield 'unknown key' => [$arm + ['unexpected' => true]];
        foreach (['operationId', 'assignmentId'] as $key) {
            foreach ([null, 0, -1, '7', 7.5, true, []] as $index => $value) {
                yield "$key value $index" => [$arm + [$key => $value]];
            }
        }
        foreach ([null, '', 1, true, []] as $index => $value) {
            yield "stage $index" => [$arm + ['stage' => $value]];
        }
        foreach (['inspect', 'release', 'discard', 'expire-idle', 'clear', 'replay'] as $action) {
            yield "selectors on $action" => [array_replace($arm, ['action' => $action])];
        }
        $replay = ['agentId' => 'holder:42', 'action' => 'replay',
            'frame' => self::frame(AccountErasureProbeFrameKind::Ask)->toArray(), 'copies' => 1];
        foreach (['frame', 'copies'] as $key) {
            $missing = $replay;
            unset($missing[$key]);
            yield "replay missing $key" => [$missing];
        }
        foreach ([null, 0, 3, '1', 1.0, true, []] as $index => $value) {
            yield "copies $index" => [array_replace($replay, ['copies' => $value])];
        }
        foreach ([null, false, 'frame'] as $index => $value) {
            yield "frame type $index" => [array_replace($replay, ['frame' => $value])];
        }
        foreach (['arm', 'inspect', 'release', 'discard', 'expire-idle', 'clear'] as $action) {
            yield "replay fields on $action" => [array_replace($replay, ['action' => $action])];
        }
    }

    /** @param array<string, mixed> $wire Invalid worker envelope */
    #[DataProvider('invalidIpc')]
    public function testRealWorkerRegistryRejectsMalformedEnvelopesAndState(array $wire): void
    {
        $this->expectException(InvalidFormatException::class);
        WorkerDTO::factoryWorkerDTO(json_encode($wire, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{array<string, mixed>}> Request and reply IPC guards */
    public static function invalidIpc(): iterable
    {
        $request = ['type' => 'test_account_erasure_probe', 'correlationId' => 'probe-1', 'agentId' => 'holder',
            'request' => ['action' => 'inspect']];
        $reply = ['type' => 'test_account_erasure_probed', 'correlationId' => 'probe-1', 'ok' => true,
            'state' => ['armed' => false, 'matched' => 0, 'held' => null], 'error' => null];
        foreach (['request' => $request, 'reply' => $reply] as $direction => $frame) {
            foreach (array_keys($frame) as $key) {
                $missing = $frame;
                unset($missing[$key]);
                yield "$direction missing $key" => [$missing];
            }
            yield "$direction unknown field" => [$frame + ['unknown' => true]];
            foreach ([null, '', 1, true, []] as $index => $value) {
                yield "$direction correlation $index" => [array_replace($frame, ['correlationId' => $value])];
            }
        }
        foreach ([null, '', 1, true, []] as $index => $value) {
            yield "source $index" => [array_replace($request, ['agentId' => $value])];
        }
        yield 'nested source' => [array_replace($request, ['request' => ['action' => 'inspect', 'agentId' => 'foreign']])];
        yield 'request not object' => [array_replace($request, ['request' => 'inspect'])];
        foreach ([null, 0, 'true', []] as $index => $value) {
            yield "ok type $index" => [array_replace($reply, ['ok' => $value])];
        }
        yield 'success without state' => [array_replace($reply, ['state' => null])];
        yield 'state wrong type' => [array_replace($reply, ['state' => false])];
        yield 'success with error' => [array_replace($reply, ['error' => 'Failure'])];
        yield 'failure with state' => [array_replace($reply, ['ok' => false, 'error' => 'Failure'])];
        foreach ([null, '', ' ', 1, false, []] as $index => $value) {
            yield "failure error $index" => [array_replace($reply, ['ok' => false, 'state' => null, 'error' => $value])];
        }
        foreach (['armed', 'matched', 'held'] as $key) {
            $state = $reply['state'];
            unset($state[$key]);
            yield "state missing $key" => [array_replace($reply, ['state' => $state])];
        }
        foreach ([['armed' => 1], ['matched' => -1], ['matched' => '0'], ['held' => false], ['unknown' => 1]] as $index => $patch) {
            yield "state invalid $index" => [array_replace($reply, ['state' => array_replace($reply['state'], $patch)])];
        }
        $held = self::frame(AccountErasureProbeFrameKind::Ask)->toArray();
        foreach ([['armed' => true, 'matched' => 1], ['armed' => false, 'matched' => 0]] as $index => $state) {
            yield "state inconsistent $index" => [array_replace($reply, ['state' => $state + ['held' => $held]])];
        }
    }

    /** Replays preserve receipts and metadata, require a live matching source, and do not alter the capture. */
    public function testReplayUsesCompleteCopiesAndRefusesForeignOrLossyFrames(): void
    {
        $probe = new AccountErasureProbe();
        $source = $this->source('holder:42');
        foreach (AccountErasureProbeFrameKind::cases() as $kind) {
            $frame = self::frame($kind);
            foreach ([1, 2] as $copies) {
                $request = new AccountErasureProbeRequest('holder:42', AccountErasureProbeAction::Replay, frame: $frame, copies: $copies);
                $restored = WorkerDTO::factoryWorkerDTO(new WorkerTestAccountErasureProbeDTO('replay-1', $request)->toJson());
                self::assertInstanceOf(WorkerTestAccountErasureProbeDTO::class, $restored);
                $replayed = $probe->replay($restored->request, $source);
                self::assertCount($copies, $replayed);
                foreach ($replayed as $copy) {
                    self::assertSame($frame->toArray(), $copy->toArray());
                    self::assertSame(91, $copy->signal->data->receiptId);
                    self::assertSame(['trace' => 'erasure-fixture', 'sequence' => 9], $copy->signal->meta);
                }
                self::refuses(fn() => $probe->replay($request, null));
                self::refuses(fn() => $probe->replay($request, $this->source('foreign')));
            }
        }
        self::assertSame(['armed' => false, 'matched' => 0, 'held' => null], $probe->inspect()->toArray());
        $held = self::frame(AccountErasureProbeFrameKind::Ask);
        $probe->arm(new AccountErasureProbeRequest(
            'holder:42', AccountErasureProbeAction::Arm, AccountErasureProbeFrameKind::Ask,
        ), $source);
        $probe->intercept($held, $source);
        $probe->replay(new AccountErasureProbeRequest(
            'holder:42', AccountErasureProbeAction::Replay, frame: self::frame(AccountErasureProbeFrameKind::Answer), copies: 1,
        ), $source);
        self::assertSame($held, $probe->inspect()->held);
        self::assertSame(1, $probe->inspect()->matched);

        $valid = self::frame(AccountErasureProbeFrameKind::Ask)->toArray();
        $forgeries = [
            array_replace_recursive($valid, ['agentId' => 'foreign']),
            array_replace_recursive($valid, ['type' => 'other-worker-type']),
            array_replace_recursive($valid, ['signal' => ['signalSource' => ['source' => SignalSource::WORKER]]]),
            array_replace_recursive($valid, ['signal' => ['signalSource' => ['type' => 'foreign']]]),
            array_replace_recursive($valid, ['signal' => ['signalSource' => ['index' => '43']]]),
            array_replace_recursive($valid, ['signal' => ['signalType' => SignalTypeConstants::CRON]]),
            array_replace_recursive($valid, ['signal' => ['dataType' => SignalData::class]]),
            array_replace_recursive($valid, ['signal' => ['data' => ['dataType' => SignalData::class]]]),
            array_replace_recursive($valid, ['signal' => ['data' => ['receipt' => '91']]]),
            array_replace_recursive($valid, ['signal' => ['data' => ['data' => ['operationId' => '7']]]]),
            array_replace_recursive($valid, ['signal' => ['data' => ['data' => ['assignmentId' => null]]]]),
            array_replace_recursive($valid, ['signal' => ['data' => ['data' => ['operationId' => 0]]]]),
            array_replace_recursive($valid, ['signal' => ['data' => ['data' => ['assignmentId' => -1]]]]),
            array_replace_recursive($valid, ['signal' => ['data' => ['data' => ['stage' => '']]]]),
            $valid + ['extra' => true],
        ];
        $withoutMeta = $valid;
        unset($withoutMeta['signal']['meta']);
        $forgeries[] = $withoutMeta;
        foreach ($forgeries as $forged) {
            self::refuses(fn() => AccountErasureProbeRequest::fromArray([
                'agentId' => 'holder:42', 'action' => 'replay', 'frame' => $forged, 'copies' => 1,
            ]));
        }
        self::refuses(fn() => new AccountErasureProbeRequest(
            'foreign', AccountErasureProbeAction::Replay, frame: self::frame(AccountErasureProbeFrameKind::Ask), copies: 1,
        ));
        $singleton = self::frame(AccountErasureProbeFrameKind::Answer, 'holder');
        self::assertSame($singleton->toArray(), AccountErasureProbeRequest::restoreFrame($singleton->toArray())->toArray());
        $reordered = array_reverse($valid, true);
        self::assertSame($valid, AccountErasureProbeRequest::restoreFrame($reordered)->toArray());
    }

    /**
     * @param AccountErasureProbeFrameKind $kind Payload family
     * @param string $agentId Fixture source address
     * @param int $operationId Operation selector value
     * @param string $stage Stage selector value
     * @param int $assignmentId Assignment selector value
     * @return WorkerAgentMessageDTO Full envelope with receipt and signal metadata
     */
    private static function frame(
        AccountErasureProbeFrameKind $kind,
        string $agentId = 'holder:42',
        int $operationId = 7,
        string $stage = 'parts',
        int $assignmentId = 11,
    ): WorkerAgentMessageDTO {
        $identity = AgentId::fromId($agentId);
        $payload = $kind === AccountErasureProbeFrameKind::Ask
            ? new AccountOperationAskSignalData($operationId, $stage, $assignmentId, '42')
            : new AccountOperationAnswerSignalData($operationId, $stage, $assignmentId, AccountOperationAnswerKind::Waits);
        return new WorkerAgentMessageDTO($agentId, new SignalDTO(
            new SignalSource(SignalSource::AGENT, $identity->type, $identity->index),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName('fixture-account-operation'),
            new AgentSignalData($payload, 91),
            ['trace' => 'erasure-fixture', 'sequence' => 9],
        ));
    }

    /**
     * @param Closure(): mixed $operation Operation expected to reject its precondition
     */
    private static function refuses(Closure $operation): void
    {
        try {
            $operation();
            self::fail('Expected an erasure probe refusal');
        } catch (InvalidFormatException $error) {
            self::assertNotSame('', $error->getMessage());
        }
    }

    /**
     * @param string $agentId Address of a distinct local incarnation
     * @return AgentInterface Source identity, with no daemon or runtime initialization
     */
    private function source(string $agentId): AgentInterface
    {
        $source = $this->createStub(AgentInterface::class);
        $source->method('getId')->willReturn($agentId);
        return $source;
    }
}
