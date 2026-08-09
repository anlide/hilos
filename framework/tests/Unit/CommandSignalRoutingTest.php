<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\CommandReplyDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Database\Context\DbContext;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests SignalRouter routing for the CLI command channel signals.
 */
final class CommandSignalRoutingTest extends TestCase
{
    public function testCommandRequestRoutesToOwningAgent(): void
    {
        $destinations = new CommandRoutingTestRouter()->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::COMMAND_REQUEST),
            new SignalName('echo'),
            new CommandRequestDTO('corr-1', 'echo', ['message' => 'hi']),
        ));

        $this->assertEquals([new AgentDestination('chat')], $destinations);
    }

    public function testUnknownCommandRoutesNowhere(): void
    {
        $destinations = new CommandRoutingTestRouter()->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::COMMAND_REQUEST),
            new SignalName('nope'),
            new CommandRequestDTO('corr-2', 'nope', []),
        ));

        $this->assertSame([], $destinations);
    }

    public function testCommandReplyRoutesToHeldConnectionByCorrelationId(): void
    {
        $destinations = new SignalRouter()->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::COMMAND_REPLY),
            new SignalName('corr-3'),
            CommandReplyDTO::ok('corr-3', ['message' => 'pong']),
        ));

        $this->assertEquals([new CommandReplyDestination('corr-3')], $destinations);
    }

    public function testCommandReplyWithoutCorrelationIdRoutesNowhere(): void
    {
        $destinations = new SignalRouter()->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::COMMAND_REPLY),
            new SignalName(SignalTypeConstants::COMMAND_REPLY),
            CommandReplyDTO::ok('', []),
        ));

        $this->assertSame([], $destinations);
    }
}

/**
 * Test facade declaring an echo command owner, for COMMAND_REQUEST routing.
 */
final class CommandRoutingTestHilos extends \Hilos\Hilos
{
    /**
     * @return array<string, string> Agent type keyed by command name
     */
    public static function getCommandAgentRoutes(): array
    {
        return ['echo' => 'chat'];
    }

    protected static function createDb(): DbContext
    {
        throw new \LogicException('createDb is not used in the routing test');
    }
}

/**
 * Test router pinning the facade to the echo-command fixture.
 */
final class CommandRoutingTestRouter extends SignalRouter
{
    protected function hilosClass(): string
    {
        return CommandRoutingTestHilos::class;
    }
}
