<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Cluster\ClusterCommandConstants;
use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\ClusterNodesCommand;
use Hilos\Core\CLI\Commands\ClusterReloadCommand;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Cluster commands print both the stable id and the daemon's current node name. */
final class ClusterCommandsTest extends TestCase
{
    /**
     * @return list<array{0: class-string<ClusterNodesCommandDouble|ClusterReloadCommandDouble>, 1: string, 2: string, 3: bool}> Commands and headings
     */
    public static function commands(): array
    {
        return [
            [ClusterNodesCommandDouble::class, CliCommands::CLUSTER_NODES, '', false],
            [
                ClusterReloadCommandDouble::class,
                CliCommands::CLUSTER_RELOAD,
                "Cluster config reloaded; local node changed and was re-announced.\n",
                true,
            ],
            [
                ClusterReloadCommandDouble::class,
                CliCommands::CLUSTER_RELOAD,
                "Cluster config reloaded; local node unchanged.\n",
                false,
            ],
        ];
    }

    /**
     * @param class-string<ClusterNodesCommandDouble|ClusterReloadCommandDouble> $commandClass Command double
     * @param string $wireName Command-channel name
     * @param string $prefix Expected outcome heading
     * @param bool $changed Whether the daemon applied a configuration change
     */
    #[DataProvider('commands')]
    public function testCommandsPrintNamesBesideIds(string $commandClass, string $wireName, string $prefix, bool $changed): void
    {
        $command = new $commandClass(CommandChannelResult::replied(
            CommandReplyDTO::ok('cluster-command', [
                ClusterCommandConstants::FIELD_ENABLED => true,
                ClusterCommandConstants::FIELD_CHANGED => $changed,
                ClusterCommandConstants::FIELD_NODES => [
                    [
                        ClusterCommandConstants::FIELD_NODE_ID => 'node-a',
                        ClusterCommandConstants::FIELD_NODE_NAME => 'Главный узел',
                        ClusterCommandConstants::FIELD_NODE_ROLE => 'master',
                        ClusterCommandConstants::FIELD_NODE_ONLINE => true,
                        ClusterCommandConstants::FIELD_NODE_CAPABILITIES => ['gpu', 'fast-disk'],
                    ],
                    [
                        ClusterCommandConstants::FIELD_NODE_ID => 'node-b',
                        ClusterCommandConstants::FIELD_NODE_NAME => 'Offline worker',
                        ClusterCommandConstants::FIELD_NODE_ROLE => 'slave',
                        ClusterCommandConstants::FIELD_NODE_ONLINE => false,
                        ClusterCommandConstants::FIELD_NODE_CAPABILITIES => [],
                    ],
                ],
            ]),
            '127.0.0.1:8094',
        ));

        $this->expectOutputString(
            $prefix . "Cluster nodes (2):\n"
            . "  node-a  \"Главный узел\"  role=master  online  capabilities=gpu,fast-disk\n"
            . "  node-b  \"Offline worker\"  role=slave  offline  capabilities=-\n",
        );
        self::assertSame(ExitCode::SUCCESS, $command->execute([], []));
        self::assertSame([[$wireName, [], null]], $command->calls);
    }
}

/** Supplies the daemon reply without opening a command socket. */
trait ClusterCommandDoubleTrait
{
    /** @var list<array{0: string, 1: array<string, mixed>, 2: ?float}> Requests sent by the command */
    public array $calls = [];

    /** @param CommandChannelResult $result Reply the daemon supplied */
    public function __construct(private readonly CommandChannelResult $result)
    {
    }

    /**
     * @param string $command Command-channel name
     * @param array<string, mixed> $payload Request payload
     * @param ?float $waitSeconds Optional wait budget
     * @return CommandChannelResult Configured daemon reply
     */
    protected function sendCommand(string $command, array $payload, ?float $waitSeconds = null): CommandChannelResult
    {
        $this->calls[] = [$command, $payload, $waitSeconds];

        return $this->result;
    }
}

/** Supplies the cluster:nodes reply. */
final class ClusterNodesCommandDouble extends ClusterNodesCommand
{
    use ClusterCommandDoubleTrait;
}

/** Supplies the cluster:reload reply. */
final class ClusterReloadCommandDouble extends ClusterReloadCommand
{
    use ClusterCommandDoubleTrait;
}
