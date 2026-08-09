<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification;

use Hilos\Constants\AppEnv;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
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
 * normally sends it is not on the path. These tests pin the two refusals that need no
 * database: a production-like environment, and a command the agent does not own. The emit
 * itself needs real tables and is exercised by the audit run.
 */
final class NotificationTestEmitCommandRouteTest extends TestCase
{
    /** @var ?EnvAccessor Env accessor to restore after the test */
    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$env = $this->previousEnv;
        putenv('APP_ENV');

        parent::tearDown();
    }

    public function testRefusesEmitOnAProductionLikeEnvironment(): void
    {
        putenv('APP_ENV=' . AppEnv::PROD->value);
        Hilos::$env = new EnvAccessor();

        $this->sendCommand(CliCommands::NOTIFICATION_TEST_EMIT);

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk(), 'A production-like node refuses the test-only emit');
        self::assertStringContainsString('test-only', (string)$reply->payload[CommandConstants::FIELD_MESSAGE]);
    }

    public function testRefusesEmitOnStagingToo(): void
    {
        // Staging counts as production-like, and it is the environment where a stray
        // command would reach real addresses while looking harmless.
        putenv('APP_ENV=' . AppEnv::STAGING->value);
        Hilos::$env = new EnvAccessor();

        $this->sendCommand(CliCommands::NOTIFICATION_TEST_EMIT);

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk(), 'Staging refuses the test-only emit');
        // Asserted on the message, not merely on the failure: without the guard the emit
        // would still fail here, on the missing notifier, and the test would prove nothing.
        self::assertStringContainsString('test-only', (string)$reply->payload[CommandConstants::FIELD_MESSAGE]);
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
