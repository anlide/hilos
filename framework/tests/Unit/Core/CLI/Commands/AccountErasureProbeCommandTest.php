<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Auth\AccountOperation\Testing\AccountErasureProbeRequest;
use Hilos\Auth\AccountOperation\Testing\AccountErasureProbeState;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\CLI\Commands\AccountErasureProbeCommand;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** CLI maps argv into the strict request before it opens the command channel. */
final class AccountErasureProbeCommandTest extends TestCase
{
    private ?EnvAccessor $previousEnv;

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        putenv('APP_ENV=test');
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
    }

    public function testEveryActionMapsToTheTypedRequestAndPrintsOneJsonState(): void
    {
        $frame = new WorkerAgentMessageDTO('holder:42', new SignalDTO(
            new SignalSource(SignalSource::AGENT, 'holder', '42'), new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName('fixture-operation'), new AgentSignalData(new AccountOperationAskSignalData(7, 'parts', 11, '42'), 91),
            ['trace' => 'probe-cli'],
        ));
        $cases = [
            ['arm', ['frame-kind' => 'answer', 'operation-id' => '7', 'stage' => 'parts', 'assignment-id' => '11'],
                ['frameKind' => 'answer', 'operationId' => 7, 'stage' => 'parts', 'assignmentId' => 11]],
            ['arm', ['frame-kind' => 'ask'], ['frameKind' => 'ask']],
            ['replay', ['frame-json' => $frame->toJson(), 'copies' => '2'], ['frame' => $frame->toArray(), 'copies' => 2]],
            ['inspect', [], []], ['release', [], []], ['discard', [], []], ['clear', [], []], ['expire-idle', [], []],
        ];
        $printed = '';
        foreach ($cases as [$action, $options, $fields]) {
            $command = new AccountErasureProbeCommandSpy();
            self::assertSame(ExitCode::SUCCESS, $command->execute($options, ['holder:42', $action]));
            self::assertSame(CliCommands::ACCOUNT_TEST_ERASURE_PROBE, $command->sentCommand);
            self::assertSame([AgentConstants::FIELD_AGENT_ID => 'holder:42', AccountErasureProbeRequest::ACTION => $action] + $fields,
                $command->sentPayload);
            $printed .= "{\"armed\":false,\"matched\":0,\"held\":null}\n";
        }
        $this->expectOutputString($printed);
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> Invalid operator inputs */
    public static function invalidArgv(): iterable
    {
        yield 'missing' => [[], []];
        yield 'extra positional' => [[], ['holder:42', 'inspect', 'extra']];
        yield 'unknown action' => [[], ['holder:42', 'unknown']];
        yield 'empty address' => [[], ['', 'inspect']];
        yield 'missing kind' => [[], ['holder:42', 'arm']];
        yield 'invalid kind' => [['frame-kind' => 'other'], ['holder:42', 'arm']];
        yield 'zero id' => [['frame-kind' => 'ask', 'operation-id' => '0'], ['holder:42', 'arm']];
        yield 'fractional id' => [['frame-kind' => 'ask', 'assignment-id' => '1.5'], ['holder:42', 'arm']];
        yield 'overflow id' => [['frame-kind' => 'ask', 'operation-id' => str_repeat('9', 30)], ['holder:42', 'arm']];
        yield 'selectors on inspect' => [['stage' => 'parts'], ['holder:42', 'inspect']];
        yield 'missing replay frame' => [['copies' => '2'], ['holder:42', 'replay']];
        yield 'malformed replay' => [['frame-json' => '{', 'copies' => '1'], ['holder:42', 'replay']];
        yield 'scalar replay' => [['frame-json' => '7', 'copies' => '1'], ['holder:42', 'replay']];
        yield 'untyped replay' => [['frame-json' => '{}', 'copies' => '2'], ['holder:42', 'replay']];
        yield 'copies on clear' => [['copies' => '1'], ['holder:42', 'clear']];
        yield 'valueless option' => [['frame-kind' => true], ['holder:42', 'arm']];
    }

    /** The CLI spine refuses an undeclared option before it can reach this command or its channel. */
    public function testUnknownOptionIsRefusedAtTheCliEntrance(): void
    {
        $manager = new class (['cli.php', CliCommands::ACCOUNT_TEST_ERASURE_PROBE, 'holder:42', 'inspect', '--other=1']) extends CliManager {
            public ?string $error = null;

            /** @param string $text Refusal sent to stderr by the real option gate */
            protected function writeToStandardError(string $text): void
            {
                $this->error = $text;
            }
        };
        self::assertSame(ExitCode::INVALID_ARGUMENT, $manager->answerWithoutRunning());
        self::assertStringContainsString('Unknown option --other', $manager->error);
        self::assertStringContainsString(CliCommands::ACCOUNT_TEST_ERASURE_PROBE, $manager->error);
    }

    /** @param array<string, mixed> $options Parsed options
     * @param list<string> $args Positional arguments
     */
    #[DataProvider('invalidArgv')]
    public function testInvalidArgvNeverOpensTheChannel(array $options, array $args): void
    {
        $command = new AccountErasureProbeCommandSpy();
        $this->expectOutputRegex('/Usage:/');
        self::assertSame(ExitCode::INVALID_ARGUMENT, $command->execute($options, $args));
        self::assertNull($command->sentCommand);
    }
}

final class AccountErasureProbeCommandSpy extends AccountErasureProbeCommand
{
    public ?string $sentCommand = null;
    /** @var array<string, mixed> Payload handed to the channel */
    public array $sentPayload = [];

    /** @param string $command Wire command
     * @param array<string, mixed> $payload Typed request at the channel boundary
     * @param ?float $waitSeconds Unused inherited wait override
     * @return CommandChannelResult Successful empty snapshot
     */
    protected function sendCommand(string $command, array $payload, ?float $waitSeconds = null): CommandChannelResult
    {
        $this->sentCommand = $command;
        $this->sentPayload = $payload;
        return CommandChannelResult::replied(CommandReplyDTO::ok('probe', new AccountErasureProbeState(false, 0, null)->toArray()),
            '127.0.0.1:1');
    }
}
