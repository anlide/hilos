<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification;

use Hilos\Constants\AppEnv;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Users\DTO\UserNotificationTestCleanupSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use Hilos\Hilos;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Command\TestOnlyCommandRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the agent side of the test:notification:emit command route (HIL-514, HIL-771).
 *
 * The route is declared on the notifications library, which every project declaring the
 * notifications feature registers, and is answered by whatever reaches the unauthenticated
 * command socket - the CLI class that normally sends it is not on the path. These tests pin
 * what needs no database: that the route declares itself test-only (the socket refuses it on
 * that declaration alone), and that a command the agent does not own is answered rather than
 * dropped. The emit itself needs real tables and is exercised by the audit run.
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
     * here is the DECLARATION that puts it there: rename the emit without the prefix and it
     * becomes reachable on a production node, and no test of this handler would notice, because
     * the handler is exactly where the check stopped being written.
     */
    public function testTheEmitRouteDeclaresItselfTestOnly(): void
    {
        self::assertContains(
            CliCommands::NOTIFICATION_TEST_EMIT,
            AbstractNotificationsLibraryAgent::AGENT_COMMANDS,
        );
        self::assertTrue(TestOnlyCommandRegistry::isTestOnly(CliCommands::NOTIFICATION_TEST_EMIT));
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

    public function testInspectAndCleanupDeclareThemselvesTestOnly(): void
    {
        foreach ([CliCommands::NOTIFICATION_TEST_INSPECT, CliCommands::NOTIFICATION_TEST_CLEANUP] as $command) {
            self::assertContains($command, AbstractNotificationsLibraryAgent::AGENT_COMMANDS);
            self::assertTrue(TestOnlyCommandRegistry::isTestOnly($command));
        }
    }

    public function testInspectAndCleanupRefuseProductionAndMissingEnvironmentBeforeReading(): void
    {
        foreach ([AppEnv::PROD->value, null] as $environment) {
            Hilos::$env = $environment === null ? null : new EnvAccessor();
            if ($environment !== null) {
                putenv('APP_ENV=' . $environment);
            }
            foreach ([CliCommands::NOTIFICATION_TEST_INSPECT, CliCommands::NOTIFICATION_TEST_CLEANUP] as $command) {
                $this->sendCommand($command, ['userId' => 1, 'title' => 'Owned']);
                self::assertFalse($this->consumeReply()->isOk());
            }
        }
    }

    public function testInspectRefusesInvalidSubjectsWithoutReadingTheDatabase(): void
    {
        $this->assertInvalidSubjects(CliCommands::NOTIFICATION_TEST_INSPECT);
    }

    public function testCleanupRefusesInvalidSubjectsWithoutWritingTheDatabase(): void
    {
        $this->assertInvalidSubjects(CliCommands::NOTIFICATION_TEST_CLEANUP);
    }

    public function testCleanupSignalRoundTripsOnlyItsFourFields(): void
    {
        $payload = ['userId' => 1, 'notificationId' => 2, 'title' => 'Owned', 'correlationId' => 'test-correlation'];
        self::assertSame($payload, UserNotificationTestCleanupSignalData::fromArray($payload)->toArray());
    }

    /** @return array<string, array{array<string, mixed>}> Mistyped or missing signal fields */
    public static function invalidCleanupPayloads(): array
    {
        $valid = ['userId' => 1, 'notificationId' => 2, 'title' => 'Owned', 'correlationId' => 'test-correlation'];
        $cases = [];
        foreach (['userId', 'notificationId'] as $field) {
            foreach ([0, -1, true, 1.5, '1', null] as $index => $value) {
                $cases[$field . $index] = [array_replace($valid, [$field => $value])];
            }
        }
        foreach (['title', 'correlationId'] as $field) {
            $cases[$field] = [array_diff_key($valid, [$field => true])];
        }
        $cases['empty correlation'] = [array_replace($valid, ['correlationId' => ''])];
        $cases['unexpected'] = [$valid + ['acceptKey' => 'browser']];

        return $cases;
    }

    /** @param array<string, mixed> $payload Invalid cleanup signal */
    #[DataProvider('invalidCleanupPayloads')]
    public function testCleanupSignalRefusesMalformedFields(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        UserNotificationTestCleanupSignalData::fromArray($payload);
    }

    /** @param string $command Test subject command under test */
    private function assertInvalidSubjects(string $command): void
    {
        putenv('APP_ENV=' . AppEnv::TEST->value);
        Hilos::$env = new EnvAccessor();
        foreach ([true, 1.5, '1', 0, -1, null] as $userId) {
            $this->sendCommand($command, ['userId' => $userId, 'title' => 'Owned']);
            self::assertFalse($this->consumeReply()->isOk());
        }
        foreach ([[], ['userId' => 1], ['userId' => 1, 'title' => false], ['userId' => 1, 'title' => 'Owned', 'extra' => true]] as $payload) {
            $this->sendCommand($command, $payload);
            self::assertFalse($this->consumeReply()->isOk());
        }
    }

    /**
     * Drives one command through the agent under test.
     *
     * @param string $command Command-channel wire name to send
     * @param array<string, mixed> $payload Command fields
     */
    private function sendCommand(string $command, array $payload = []): void
    {
        new NotificationCommandRouteTestAgent()->onSignalCommand(
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

/**
 * Concrete notifications library under test: the abstract one carries the route and the
 * handler, and a project subclass adds nothing to either.
 */
final class NotificationCommandRouteTestAgent extends AbstractNotificationsLibraryAgent
{
}
