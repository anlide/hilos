<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\AgentRestartCommand;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use Hilos\Core\CLI\Commands\CommandExecution;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Operator command parsing and rendering at its injectable channel boundary. */
final class AgentRestartCommandTest extends TestCase
{
    public function testMissingIdStopsBeforeTheChannel(): void
    {
        $sent = false;
        $command = new AgentRestartCommand(static function () use (&$sent): CommandChannelResult {
            $sent = true;
            return CommandChannelResult::timedOut('unused');
        });

        [$code, $output] = $this->runCommand($command, [], []);

        self::assertSame(ExitCode::INVALID_ARGUMENT, $code);
        self::assertStringContainsString('Usage: php cli.php agent:restart', $output);
        self::assertFalse($sent);
        self::assertEquals(CommandExecution::daemon(), $command->execution());
    }

    public function testIndexedIdAndNodeProduceTheAgreedPayloadAndSuccessLine(): void
    {
        $sent = [];
        $command = new AgentRestartCommand(static function (string $name, array $payload) use (&$sent): CommandChannelResult {
            $sent = [$name, $payload];
            return CommandChannelResult::replied(CommandReplyDTO::ok('c1', [
                CommandConstants::FIELD_AGENT_ID => 'room:42',
                CommandConstants::FIELD_NODE_ID => 'n2',
            ]), 'daemon:1');
        });

        [$code, $output] = $this->runCommand($command, ['node' => 'n2'], ['room:42']);

        self::assertSame(ExitCode::SUCCESS, $code);
        self::assertSame("Agent room:42 restarted on node n2\n", $output);
        self::assertSame([CliCommands::AGENT_RESTART, [
            CommandConstants::FIELD_AGENT_TYPE => 'room',
            CommandConstants::FIELD_AGENT_INDEX => '42',
            CommandConstants::FIELD_NODE_ID => 'n2',
        ]], $sent);
    }

    public function testSingletonCarriesExplicitNulls(): void
    {
        $sent = [];
        $command = new AgentRestartCommand(static function (string $name, array $payload) use (&$sent): CommandChannelResult {
            $sent = $payload;
            return CommandChannelResult::replied(CommandReplyDTO::ok('c1', [
                CommandConstants::FIELD_AGENT_ID => 'worker',
                CommandConstants::FIELD_NODE_ID => 'standalone',
            ]), 'daemon:1');
        });

        $this->runCommand($command, [], ['worker']);

        self::assertSame(null, $sent[CommandConstants::FIELD_AGENT_INDEX]);
        self::assertSame(null, $sent[CommandConstants::FIELD_NODE_ID]);
    }

    public function testRefusalAndChannelFailureUseTheSharedWording(): void
    {
        $refused = new AgentRestartCommand(static fn (): CommandChannelResult => CommandChannelResult::replied(
            CommandReplyDTO::error('c1', 'Agent worker is not running anywhere'), 'daemon:1',
        ));
        [$refusalCode, $refusalOutput] = $this->runCommand($refused, [], ['worker']);
        self::assertSame(ExitCode::ERROR, $refusalCode);
        self::assertSame('', $refusalOutput);
        self::assertSame(
            'Refused: Agent worker is not running anywhere',
            new ReflectionMethod($refused, 'refusalText')->invoke($refused, CommandReplyDTO::error('c1', 'Agent worker is not running anywhere')),
        );

        $silent = new AgentRestartCommand(static fn (): CommandChannelResult => CommandChannelResult::timedOut('daemon:1'));
        [$failureCode, $failureOutput] = $this->runCommand($silent, [], ['worker']);
        self::assertSame(ExitCode::ERROR, $failureCode);
        self::assertSame('', $failureOutput);
        self::assertSame(
            'The daemon did not answer agent:restart within 15s',
            new ReflectionMethod($silent, 'channelFailureText')->invoke(
                $silent,
                CommandChannelResult::timedOut('daemon:1'),
                CliCommands::AGENT_RESTART,
            ),
        );
    }

    /**
     * @param AgentRestartCommand $command Command under test
     * @param array<string, mixed> $options Parsed options
     * @param list<string> $args Positional arguments
     * @return array{0: int, 1: string} Exit code and stdout
     */
    private function runCommand(AgentRestartCommand $command, array $options, array $args): array
    {
        ob_start();
        $code = $command->execute($options, $args);
        return [$code, (string)ob_get_clean()];
    }
}
