<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Agents\LibraryAgent;
use Demo\Chat\CLI\ChatCliManager;
use Demo\Chat\CLI\Commands\BotTestAnswerNowCommand;
use Demo\Chat\CLI\DTO\BotAnswerNowCommandData;
use Demo\Chat\Constants\ChatCommandConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Agent\Exception\InvalidCommandPayloadException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Command\TestOnlyCommandRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The test-only answer-now command of one bot: its CLI half, its payload, and the refusals the library gives before it reads bots.
 *
 * Whether the bot is active is read from the database, which this suite does not have; those answers are covered by
 * BotAgentRefreshTest in the integration suite.
 */
final class BotAnswerNowCommandTest extends TestCase
{
    public function testProductionRefusesWithTheGateTextAndSignalsNoBot(): void
    {
        $env = Hilos::$env;
        $this->withRouter(function () use ($env): void {
            Hilos::$env = null;
            try {
                $replies = $this->dispatch(new CommandRequestDTO('answer', ChatCommandConstants::BOT_TEST_ANSWER_NOW, ['botId' => 3]));
            } finally {
                Hilos::$env = $env;
            }
            $this->assertSame([['answer', false, 'Bot answer-now is test-only']], $replies);
        });
    }

    public function testACommandTheLibraryDoesNotOwnIsRefusedByName(): void
    {
        $this->withRouter(function (): void {
            $replies = $this->dispatch(new CommandRequestDTO('other', 'test:chat:unknown'));
            $this->assertSame([['other', false, 'Unknown command: test:chat:unknown']], $replies);
        });
    }

    /** @return iterable<string, array{array<string, mixed>}> Malformed coordinates */
    public static function malformed(): iterable
    {
        yield 'missing' => [[]];
        yield 'zero' => [['botId' => 0]];
        yield 'negative' => [['botId' => -3]];
        foreach (['3', null, true, 3.5, [3]] as $index => $value) {
            yield 'type ' . $index => [['botId' => $value]];
        }
    }

    /** @param array<string, mixed> $payload Invalid command payload */
    #[DataProvider('malformed')]
    public function testGenericHydrationRefusesMalformedCoordinates(array $payload): void
    {
        $this->expectException(InvalidCommandPayloadException::class);
        new ChatSignalRouter()->createCommandPayloadDTO(ChatCommandConstants::BOT_TEST_ANSWER_NOW,
            new CommandRequestDTO('bad', ChatCommandConstants::BOT_TEST_ANSWER_NOW, $payload));
    }

    public function testProjectCliAndDtoDeclareTheExactTestOnlyNameAndFlag(): void
    {
        $manager = new ChatCliManager([]);
        $this->assertTrue($manager->hasCommand(ChatCommandConstants::BOT_TEST_ANSWER_NOW));
        $this->assertFalse($manager->requiresDatabase(ChatCommandConstants::BOT_TEST_ANSWER_NOW));
        $this->assertTrue(TestOnlyCommandRegistry::isTestOnly(ChatCommandConstants::BOT_TEST_ANSWER_NOW));
        $this->assertSame('test:chat:bot-answer-now', new BotTestAnswerNowCommand()->getName());
        $this->assertSame(['bot-id'], BotTestAnswerNowCommand::OPTIONS);
        $dto = new BotAnswerNowCommandData(3);
        $this->assertSame(['botId' => 3], BotAnswerNowCommandData::fromJson($dto->toJson())->toArray());
        $this->expectOutputRegex('/--bot-id=<botId>/');
        $this->assertSame(2, new BotTestAnswerNowCommand()->execute([], []));
    }

    /** @param mixed $botId Unusable value of the option */
    #[DataProvider('unusableOptionValues')]
    public function testUnusableOptionValueIsRefusedBeforeAnyRequest(mixed $botId): void
    {
        $this->expectOutputRegex('/--bot-id=<botId>/');
        $this->assertSame(2, new BotTestAnswerNowCommand()->execute(['bot-id' => $botId], []));
    }

    /** @return iterable<string, array{mixed}> Option values that are not a positive whole number */
    public static function unusableOptionValues(): iterable
    {
        foreach (['0', '-3', 'abc', '3.5', '', true] as $index => $value) {
            yield 'value ' . $index => [$value];
        }
    }

    /**
     * @param callable(): void $assertions Assertions over an isolated signal queue
     */
    private function withRouter(callable $assertions): void
    {
        $sr = Hilos::$sr;
        try {
            Hilos::$sr = new ChatSignalRouter();
            $assertions();
        } finally {
            Hilos::$sr = $sr;
        }
    }

    /**
     * @param CommandRequestDTO $request Command as the channel delivers it
     * @return list<array{string, bool, string}> Correlation id, outcome and refusal text of every queued frame, all of them replies
     */
    private function dispatch(CommandRequestDTO $request): array
    {
        new LibraryAgent()->onSignalCommand(Hilos::$sr->createCommandPayloadDTO($request->command, $request), 'command', $request->command);
        $replies = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $this->assertInstanceOf(CommandReplyDTO::class, $signal->data, 'A refusal queues its reply and nothing else');
            $replies[] = [$signal->data->correlationId, $signal->data->isOk(), $signal->data->payload[CommandConstants::FIELD_MESSAGE]];
        }

        return $replies;
    }
}
