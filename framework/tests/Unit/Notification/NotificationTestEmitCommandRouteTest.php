<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification;

use Hilos\Constants\AppEnv;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Agent\Config\AgentCommandConfigKey;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the agent side of the test:notification:emit command route (HIL-514).
 *
 * The route is declared on the abstract index agent, so it exists in every project and is
 * answered by whatever reaches the unauthenticated command socket - the CLI class that
 * normally sends it is not on the path. These tests pin what needs no database: that the route
 * declares itself test-only (the socket refuses it on that declaration alone), and that a
 * command the agent does not own is answered rather than dropped. The emit itself needs real
 * tables and is exercised by the audit run.
 */
final class NotificationTestEmitCommandRouteTest extends TestCase
{
    /** @var ?EnvAccessor Env accessor to restore after the test */
    private ?EnvAccessor $previousEnv = null;

    /** @var string|false APP_ENV the suite runs under, put back so this file does not decide what the next one reads */
    private string|false $previousAppEnv = false;

    protected function setUp(): void
    {
        $this->previousAppEnv = getenv('APP_ENV');
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$env = $this->previousEnv;
        $this->previousAppEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->previousAppEnv);

        parent::tearDown();
    }

    /**
     * The environment refusal itself moved to the socket (HIL-566), so what has to be pinned
     * here is the DECLARATION that puts it there: drop the flag and the emit becomes reachable
     * on a production node, and no test of this handler would notice, because the handler is
     * exactly where the check stopped being written.
     */
    public function testTheEmitRouteDeclaresItselfTestOnly(): void
    {
        self::assertSame(
            [AgentCommandConfigKey::TEST_ONLY => true],
            AbstractHilosIndexAgent::AGENT_COMMANDS[CliCommands::NOTIFICATION_TEST_EMIT] ?? null,
        );
    }

    public function testAnswersAnUnownedCommandWithAnError(): void
    {
        putenv('APP_ENV=' . AppEnv::TEST->value);
        Hilos::$env = new EnvAccessor();

        $this->sendCommand('notifications:no-such-command');

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk());
        self::assertStringContainsString('Unknown command', (string)$reply->payload[CommandConstants::FIELD_MESSAGE]);
    }

    /**
     * Drives one command through the agent under test.
     *
     * @param string $command Command-channel wire name to send
     */
    private function sendCommand(string $command): void
    {
        new NotificationCommandRouteTestAgent()->onSignalCommand(
            new CommandRequestDTO(correlationId: 'corr-1', command: $command),
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

/**
 * Concrete index agent under test: the abstract one carries the route and the handler, and
 * a project subclass adds nothing to either.
 */
final class NotificationCommandRouteTestAgent extends AbstractHilosIndexAgent
{
    public function onStop(): void
    {
    }
}
