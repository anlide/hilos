<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\CLI\ChatCliManager;
use Demo\Chat\CLI\Commands\SendPauseTestEndCommand;
use Demo\Chat\CLI\DTO\SendPauseEndCommandData;
use Demo\Chat\Constants\ChatCommandConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Runtime\View\Item\ChatUserState;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Agent\Exception\InvalidCommandPayloadException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Feature\Definition\ModerationFeature;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Command\TestOnlyCommandRegistry;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The test-only end of the send pause: one person's last accepted send, aged through the ordinary command router. */
final class SendPauseEndCommandTest extends TestCase
{
    public function testAgesTheLastSendByExactlyTheRateLimitPeriodAndKeepsItASend(): void
    {
        $this->withRuntime(function (): void {
            $this->recordSend(7);
            $this->recordSend(8);
            $neighbour = Hilos::$rt->userStates[8]->lastOutboundSubmittedAt;
            $this->drain();
            $earliest = microtime(true);
            $reply = $this->endPause(7);
            $latest = microtime(true);

            $this->assertTrue($reply->isOk());
            $this->assertSame(['userId' => 7], $reply->payload);
            $aged = Hilos::$rt->userStates[7]->lastOutboundSubmittedAt;
            $this->assertGreaterThanOrEqual($earliest - ChatUserState::MESSAGE_RATE_LIMIT_SECONDS, $aged);
            $this->assertLessThanOrEqual($latest - ChatUserState::MESSAGE_RATE_LIMIT_SECONDS, $aged);
            $this->assertGreaterThan(0.0, $aged, 'The moment stays a moment of sending: only its time moves');
            $this->assertGreaterThanOrEqual(
                ChatUserState::MESSAGE_RATE_LIMIT_SECONDS - ChatUserState::MESSAGE_RATE_LIMIT_TOLERANCE_SECONDS,
                microtime(true) - $aged,
                'MainPage accepts the next message by its own rule',
            );
            $this->assertSame($neighbour, Hilos::$rt->userStates[8]->lastOutboundSubmittedAt);
        });
    }

    public function testEndingAnEndedPauseIsStillAnAnswerForAPersonWhoSent(): void
    {
        $this->withRuntime(function (): void {
            $this->recordSend(7);
            $this->drain();
            $this->assertTrue($this->endPause(7)->isOk());
            $this->assertTrue($this->endPause(7)->isOk());
            $this->assertGreaterThan(0.0, Hilos::$rt->userStates[7]->lastOutboundSubmittedAt);
        });
    }

    public function testNoRowAndNothingSentAreRefusedWithoutWrites(): void
    {
        $this->withRuntime(function (): void {
            $reply = $this->endPause(9);
            $this->assertFalse($reply->isOk());
            $this->assertSame('No send pause to end for user 9', $reply->payload[CommandConstants::FIELD_MESSAGE]);
            $this->assertNull(Hilos::$rt->userStates[9]);

            Hilos::$rt->userStates->actions->ensure(9);
            $this->drain();
            $reply = $this->endPause(9);
            $this->assertFalse($reply->isOk());
            $this->assertSame('No send pause to end for user 9', $reply->payload[CommandConstants::FIELD_MESSAGE]);
            $this->assertSame(0.0, Hilos::$rt->userStates[9]->lastOutboundSubmittedAt);
        });
    }

    public function testProductionRefusesWithTheGateTextAndWritesNothing(): void
    {
        $env = Hilos::$env;
        $this->withRuntime(function () use ($env): void {
            $this->recordSend(7);
            $this->drain();
            $sent = Hilos::$rt->userStates[7]->lastOutboundSubmittedAt;
            Hilos::$env = null;
            try {
                $reply = $this->endPause(7);
            } finally {
                Hilos::$env = $env;
            }
            $this->assertFalse($reply->isOk());
            $this->assertSame('Send pause end is test-only', $reply->payload[CommandConstants::FIELD_MESSAGE]);
            $this->assertSame($sent, Hilos::$rt->userStates[7]->lastOutboundSubmittedAt);
        });
    }

    /** @return iterable<string, array{array<string, mixed>}> Malformed coordinates */
    public static function malformed(): iterable
    {
        yield 'missing' => [[]];
        yield 'zero' => [['userId' => 0]];
        yield 'negative' => [['userId' => -3]];
        foreach (['7', null, true, 7.5, [7]] as $index => $value) {
            yield 'type ' . $index => [['userId' => $value]];
        }
    }

    /** @param array<string, mixed> $payload Invalid command payload */
    #[DataProvider('malformed')]
    public function testGenericHydrationRefusesMalformedCoordinates(array $payload): void
    {
        $this->expectException(InvalidCommandPayloadException::class);
        new ChatSignalRouter()->createCommandPayloadDTO(ChatCommandConstants::SEND_PAUSE_TEST_END,
            new CommandRequestDTO('bad', ChatCommandConstants::SEND_PAUSE_TEST_END, $payload));
    }

    public function testProjectCliAndDtoDeclareTheExactTestOnlyNameAndFlag(): void
    {
        $manager = new ChatCliManager([]);
        $this->assertTrue($manager->hasCommand(ChatCommandConstants::SEND_PAUSE_TEST_END));
        $this->assertFalse($manager->requiresDatabase(ChatCommandConstants::SEND_PAUSE_TEST_END));
        $this->assertTrue(TestOnlyCommandRegistry::isTestOnly(ChatCommandConstants::SEND_PAUSE_TEST_END));
        $this->assertSame('test:chat:end-send-pause', new SendPauseTestEndCommand()->getName());
        $this->assertSame(['user-id'], SendPauseTestEndCommand::OPTIONS);
        $dto = new SendPauseEndCommandData(7);
        $this->assertSame(['userId' => 7], SendPauseEndCommandData::fromJson($dto->toJson())->toArray());
        $this->expectOutputRegex('/--user-id=<userId>/');
        $this->assertSame(2, new SendPauseTestEndCommand()->execute([], []));
    }

    /** @param mixed $userId Unusable value of the option */
    #[DataProvider('unusableOptionValues')]
    public function testUnusableOptionValueIsRefusedBeforeAnyRequest(mixed $userId): void
    {
        $this->expectOutputRegex('/--user-id=<userId>/');
        $this->assertSame(2, new SendPauseTestEndCommand()->execute(['user-id' => $userId], []));
    }

    /** @return iterable<string, array{mixed}> Option values that are not a positive whole number */
    public static function unusableOptionValues(): iterable
    {
        foreach (['0', '-3', 'abc', '7.5', '', true] as $index => $value) {
            yield 'value ' . $index => [$value];
        }
    }

    /**
     * @param callable(): void $assertions Assertions over an isolated, genuinely mounted per-user state collection
     */
    private function withRuntime(callable $assertions): void
    {
        $rt = Hilos::$rt;
        $sr = Hilos::$sr;
        $agentId = ExecutionContext::currentAgentId();
        try {
            SourceChangeBus::reset();
            SourceChangeBus::subscribe(new ViewCacheSubscriber());
            Hilos::$rt = new ChatRtContext();
            Hilos::$rt->mountFeatureRuntime([new ModerationFeature()]);
            Hilos::$rt->configure();
            Hilos::$rt->bindStateCollectionNames();
            Hilos::$sr = new ChatSignalRouter();
            RtTruthSourceRegistry::register(ChatRtContext::userStates, TruthSourceKeys::all(), 'send-pause-fixture');
            ExecutionContext::setCurrentAgentId('send-pause-fixture');
            $assertions();
        } finally {
            SourceChangeBus::reset();
            RtTruthSourceRegistry::unregisterAgent('send-pause-fixture');
            ExecutionContext::setCurrentAgentId($agentId);
            Hilos::$rt = $rt;
            Hilos::$sr = $sr;
        }
    }

    /** @param int $userId Person who has just sent a message */
    private function recordSend(int $userId): void
    {
        Hilos::$rt->userStates->actions->ensure($userId)->actions->recordOutboundSubmission();
    }

    /**
     * @param int $userId Person whose send pause ends
     * @return CommandReplyDTO The single reply, found behind the row's own sync announcements
     */
    private function endPause(int $userId): CommandReplyDTO
    {
        $request = new CommandRequestDTO('end-pause', ChatCommandConstants::SEND_PAUSE_TEST_END, ['userId' => $userId]);
        $parsed = Hilos::$sr->createCommandPayloadDTO($request->command, $request);
        $this->assertInstanceOf(SendPauseEndCommandData::class, $parsed->parsedPayload);
        new ChatAgent()->onSignalCommand($parsed, 'command', $request->command);
        $replies = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof CommandReplyDTO) {
                $replies[] = $signal->data;
            }
        }
        $this->assertCount(1, $replies);
        $this->assertSame('end-pause', $replies[0]->correlationId);

        return $replies[0];
    }

    /** Clears fixture mutation announcements before the command's own output. */
    private function drain(): void
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
    }
}
