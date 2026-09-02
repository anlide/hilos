<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\CLI\Commands\CommandExecution;
use Hilos\Core\CLI\Commands\CommandInterface;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Socket\Command\TestOnlyCommandRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The guard behind the project's one declaration of test-only: the name says it (HIL-742).
 *
 * A command is test-only when it is named with {@see CommandConstants::TEST_ONLY_PREFIX}, and
 * that single fact used to be three - the prefix, a flag in the owning agent's entry, and a
 * list of the names the master answers itself - checked by two half guards that between them
 * left the `cli-offline-write` role uncovered. Both commands that lived in that gap were named
 * without the prefix and nothing said so.
 *
 * With one declaration there is no pairing left to sew, and what remains is the two directions
 * the name and the behaviour can disagree in. Both are checked here:
 * a name with the prefix whose class does not refuse on a production-like environment is a
 * promise nothing keeps, and a class that does refuse under a name without the prefix is
 * refused by a gate no reader of that name would expect.
 *
 * It reads {@see CliManager::commandClasses()} rather than walking the class tree, because
 * reading a role off the hierarchy needs Reflection and this project forbids it (HIL-538).
 * Asking the registry is also what lets the guard reach a registry the framework does not own:
 * a project subclass answers the same question, and the stub below proves it is asked.
 *
 * What it deliberately does not cover is a wire name that reaches no registry at all -
 * `backup:restore-request` and `backup:restore-status` have no terminal half. Those are held by
 * the latch in `AbstractCommandChannelTestCommand::sendCommand()`, which refuses an unprefixed
 * name at the moment it is put on the wire.
 *
 * The suite runs without a database, like its siblings {@see CommandExecutionRoleTest} and
 * {@see CliManagerDatabaseGateTest}: building a manager builds every registered command, so a
 * constructor reaching for a connection would fail here rather than on the machine whose MySQL
 * is down.
 */
final class TestOnlyNameContractTest extends TestCase
{
    /** @var string Project command stub that refuses on production under an unprefixed name */
    public const string PROJECT_UNPREFIXED_COMMAND = 'project:refuses:unnamed';

    public function testEveryRegisteredCommandsNameAgreesWithItsRefusal(): void
    {
        $disagreements = self::disagreements(new CliManager([])->commandClasses());

        $this->assertSame([], $disagreements);
    }

    public function testTheRegistryIsActuallyReadAndCarriesBothKinds(): void
    {
        $classes = new CliManager([])->commandClasses();

        $this->assertArrayHasKey(CliCommands::DB_TEST_RESET, $classes);
        $this->assertTrue(is_subclass_of($classes[CliCommands::DB_TEST_RESET], TestOnlyCommand::class));
        $this->assertArrayHasKey(CliCommands::DAEMON_PING, $classes);
        $this->assertFalse(is_subclass_of($classes[CliCommands::DAEMON_PING], TestOnlyCommand::class));
    }

    public function testThePrefixIsWhatTheSocketGateReadsToo(): void
    {
        $this->assertTrue(TestOnlyCommandRegistry::isTestOnly(CliCommands::DB_TEST_RESET));
        $this->assertFalse(TestOnlyCommandRegistry::isTestOnly(CliCommands::DAEMON_PING));
    }

    public function testTheGuardReachesARegistryTheFrameworkDoesNotOwn(): void
    {
        $disagreements = self::disagreements(self::managerWithUnprefixedProjectCommand()->commandClasses());

        $this->assertSame(
            [self::PROJECT_UNPREFIXED_COMMAND . ' (' . self::projectCommandClass() . ') refuses on a'
                . ' production-like environment but is not named ' . CommandConstants::TEST_ONLY_PREFIX . '*'],
            $disagreements,
        );
    }

    /**
     * Names every registered command whose name and refusal do not say the same thing.
     *
     * This is the guard itself, in one place: the first test runs it over the real registry and
     * expects nothing, and the last runs it over a registry seeded with one offender and expects
     * exactly that offender — so the empty result of the real run is known to mean "nothing to
     * find" rather than "nothing was looked at".
     *
     * @param array<string, class-string<CommandInterface>> $classes Whole registry class map
     * @return list<string> One sentence per disagreement, naming the command and its class
     */
    private static function disagreements(array $classes): array
    {
        $found = [];
        foreach ($classes as $name => $class) {
            $named = str_starts_with($name, CommandConstants::TEST_ONLY_PREFIX);
            $refuses = is_subclass_of($class, TestOnlyCommand::class);
            if ($named && !$refuses) {
                $found[] = "{$name} ({$class}) is named " . CommandConstants::TEST_ONLY_PREFIX
                    . '* but does not refuse on a production-like environment';
            }
            if (!$named && $refuses) {
                $found[] = "{$name} ({$class}) refuses on a production-like environment but is not named "
                    . CommandConstants::TEST_ONLY_PREFIX . '*';
            }
        }

        return $found;
    }

    /**
     * Builds a manager carrying a project command that refuses on production under a plain name.
     *
     * @return CliManager Manager whose registry holds the unprefixed stub
     */
    private static function managerWithUnprefixedProjectCommand(): CliManager
    {
        return new class ([]) extends CliManager {
            protected function registerProjectCommands(): void
            {
                $this->addCommand(new class extends TestOnlyCommand {
                    protected function run(array $options, array $args): int
                    {
                        return ExitCode::SUCCESS;
                    }

                    public function getName(): string
                    {
                        return TestOnlyNameContractTest::PROJECT_UNPREFIXED_COMMAND;
                    }

                    public function execution(): CommandExecution
                    {
                        return CommandExecution::daemon();
                    }

                    public function getDescription(): string
                    {
                        return 'Project command stub that refuses on production under a plain name';
                    }

                    public function getHelp(): string
                    {
                        return 'Project command stub that refuses on production under a plain name';
                    }
                });
            }
        };
    }

    /**
     * Reports the runtime class name of the stub, which is anonymous and so cannot be written out.
     *
     * @return class-string<CommandInterface> Class answering the unprefixed project command
     */
    private static function projectCommandClass(): string
    {
        return self::managerWithUnprefixedProjectCommand()->commandClasses()[self::PROJECT_UNPREFIXED_COMMAND];
    }
}
