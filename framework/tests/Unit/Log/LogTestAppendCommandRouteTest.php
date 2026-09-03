<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Log\LogCommandConstants;
use Hilos\Log\LogStoreAgent;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Command\TestOnlyCommandRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the agent side of the test:log:append command route (HIL-395).
 *
 * The route is declared on the agent that owns the node's log directory, and is answered by
 * whatever reaches the unauthenticated command socket - the CLI class that normally sends it is
 * not on the path. These tests pin what the end-to-end scenario cannot see: that the route
 * declares itself test-only (the socket refuses it on that declaration alone), that a command
 * this agent does not own is answered rather than dropped, and that a request the agent cannot
 * honour is refused with a reason. The writing itself is what the scenario checks, because only
 * there does a line go the whole way - agent prints, master files, follower sees.
 */
final class LogTestAppendCommandRouteTest extends TestCase
{
    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;

        parent::tearDown();
    }

    /**
     * The environment refusal lives on the socket (HIL-742), so what has to be pinned here is
     * the DECLARATION that puts it there: rename the append without the prefix and it becomes
     * reachable on a production node, and no test of this handler would notice, because the
     * handler is exactly where the check stopped being written.
     */
    public function testTheAppendRouteDeclaresItselfTestOnly(): void
    {
        self::assertContains(CliCommands::LOG_TEST_APPEND, LogStoreAgent::AGENT_COMMANDS);
        self::assertTrue(TestOnlyCommandRegistry::isTestOnly(CliCommands::LOG_TEST_APPEND));
    }

    public function testAnswersAnUnownedCommandWithAnError(): void
    {
        $this->sendCommand('logs:no-such-command', []);

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk());
        self::assertStringContainsString('Unknown command', (string)$reply->payload[CommandConstants::FIELD_MESSAGE]);
    }

    public function testRefusesAnAppendWithoutAMessage(): void
    {
        $this->sendCommand(CliCommands::LOG_TEST_APPEND, [
            CommandConstants::FIELD_MESSAGE => '',
            LogCommandConstants::FIELD_COUNT => 1,
        ]);

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk());
        self::assertStringContainsString('non-empty message', (string)$reply->payload[CommandConstants::FIELD_MESSAGE]);
    }

    /**
     * @param mixed $count Line count as it could arrive on the wire
     */
    #[DataProvider('countsOutsideTheAllowedRange')]
    public function testRefusesACountOutsideTheAllowedRange(mixed $count): void
    {
        $this->sendCommand(CliCommands::LOG_TEST_APPEND, [
            CommandConstants::FIELD_MESSAGE => 'probe',
            LogCommandConstants::FIELD_COUNT => $count,
        ]);

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk());
        self::assertStringContainsString('between 1 and', (string)$reply->payload[CommandConstants::FIELD_MESSAGE]);
    }

    /**
     * Counts the agent has to refuse, each for its own reason.
     *
     * @return array<string, array{mixed}> Named cases: the count as the wire could carry it
     */
    public static function countsOutsideTheAllowedRange(): array
    {
        return [
            'below the floor' => [0],
            'negative' => [-1],
            'above the ceiling' => [501],
            'not a number at all' => ['80'],
        ];
    }

    /**
     * Drives one command through the agent under test.
     *
     * @param string $command Command-channel wire name to send
     * @param array<string, mixed> $payload Request payload delivered to the agent
     */
    private function sendCommand(string $command, array $payload): void
    {
        new LogStoreAgent()->onSignalCommand(
            new CommandRequestDTO(correlationId: 'corr-1', command: $command, payload: $payload),
            '',
            '',
        );
    }

    /**
     * Takes the single reply the agent queued and fails the test when it queued none.
     *
     * @return CommandReplyDTO The queued reply
     */
    private function consumeReply(): CommandReplyDTO
    {
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal, 'Every command branch answers exactly once');
        self::assertInstanceOf(CommandReplyDTO::class, $signal->data);
        self::assertNull(Hilos::$sr->getNextQueuedSignal(), 'No branch answers twice');

        return $signal->data;
    }
}
