<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\AbstractCommandChannelTestCommand;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Tests the latch that keeps a test-only CLI class from sending a name nobody gates (HIL-566).
 *
 * The pairing it guards has no other check: "this CLI class is test-only" is a fact about the
 * class hierarchy, and reading that needs Reflection, which this project forbids. So the latch
 * is what turns a forgotten flag into a failing run instead of an open door on a live node -
 * and a latch nothing exercises is exactly as good as no latch.
 */
final class CommandChannelTestCommandLatchTest extends TestCase
{
    /** @var ?EnvAccessor Previous env accessor to restore after the test */
    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        putenv('APP_ENV=test');
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        if ($this->previousEnv !== null) {
            Hilos::$env = $this->previousEnv;
        }
        putenv('APP_ENV=test');
        putenv('HILOS_DAEMON_HOST');
        putenv('COMMAND_PORT');

        parent::tearDown();
    }

    public function testAWireNameWithoutTheTestOnlyPrefixIsRefusedBeforeTheSocketIsOpened(): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('backup:prune');

        new LatchFixtureCommand('backup:prune')->execute([], []);
    }

    /**
     * The refusal is about the NAME, not about this installation: a prefixed name passes the
     * latch even where no agent of this project declares it, and fails later on the transport
     * instead - which is the honest account of a project that owns no agent for the command.
     */
    public function testAPrefixedNameGetsPastTheLatchAndOnlyThenMeetsTheTransport(): void
    {
        // A port nothing listens on: the point is which refusal arrives, not that one does.
        putenv('HILOS_DAEMON_HOST=127.0.0.1');
        putenv('COMMAND_PORT=1');
        Hilos::$env = new EnvAccessor();

        $this->assertSame(
            ExitCode::ERROR,
            new LatchFixtureCommand(CommandConstants::TEST_ONLY_PREFIX . 'nobody:declares:this')->execute([], []),
        );
    }
}

/**
 * Channel test command that sends whatever wire name the test hands it.
 */
final class LatchFixtureCommand extends AbstractCommandChannelTestCommand
{
    /**
     * @param string $wireName Command name this fixture puts on the wire
     */
    public function __construct(private readonly string $wireName)
    {
    }

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name
     */
    public function getName(): string
    {
        return 'test:latch-fixture';
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Latch fixture';
    }

    /**
     * Returns full help text.
     *
     * @return string Help text
     */
    public function getHelp(): string
    {
        return 'Latch fixture';
    }

    /**
     * Sends the fixture's wire name and reports whether a reply came back.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code
     * @throws CommandException When the wire name does not carry the test-only prefix
     */
    protected function run(array $options, array $args): int
    {
        return $this->sendCommand($this->wireName, [])->reply === null ? ExitCode::ERROR : ExitCode::SUCCESS;
    }
}
