<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI;

use Hilos\Backup\BackupConstants;
use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\CLI\Commands\CommandExecution;
use Hilos\Core\CLI\Commands\CommandExecutionSite;
use Hilos\Core\CLI\Commands\CommandInterface;
use PHPUnit\Framework\TestCase;

/**
 * The guard behind the project's one rule for commands: the daemon does the work, and the CLI
 * process only initiates it and prints. Every registered command declares where its work happens;
 * a command added without a declaration cannot satisfy {@see CommandInterface} at all, and a
 * DEPARTURE added without a stated reason fails here.
 *
 * It reads {@see CliManager::executions()} rather than walking the class tree, because reading a
 * role off the hierarchy needs Reflection and this project forbids it (HIL-538). Asking the
 * registry for the whole map is also what lets the guard reach a registry the framework does not
 * own: a project subclass answers the same question, and the stub below proves it is asked.
 *
 * The suite runs without a database, like its sibling {@see CliManagerDatabaseGateTest}: building
 * a manager builds every registered command, so a constructor that reached for a connection would
 * fail here rather than on the machine whose MySQL is down.
 */
final class CommandExecutionRoleTest extends TestCase
{
    /** @var string Project command stub declaring a departure without saying why */
    public const string PROJECT_UNEXPLAINED_COMMAND = 'test:project:unexplained';

    public function testEveryRegisteredCommandDeclaresItsExecution(): void
    {
        $executions = new CliManager([])->executions();

        $this->assertNotSame([], $executions);
        foreach ($executions as $name => $execution) {
            $this->assertInstanceOf(CommandExecution::class, $execution, "{$name} declares no execution");
        }
    }

    public function testNoDepartureFromTheRuleGoesUnexplained(): void
    {
        $unexplained = self::unexplainedDepartures(new CliManager([])->executions());

        $this->assertSame([], $unexplained);
    }

    public function testTheDaemonRuleItselfCarriesNoReason(): void
    {
        $executions = new CliManager([])->executions();

        $this->assertSame(CommandExecutionSite::DAEMON, $executions[CliCommands::DAEMON_PING]->site);
        $this->assertNull($executions[CliCommands::DAEMON_PING]->reason);
    }

    public function testTheNamedExceptionsDeclareTheSiteTheyWereGiven(): void
    {
        $executions = new CliManager([])->executions();

        $this->assertSame(CommandExecutionSite::CLI_READ, $executions[CliCommands::BACKUP_VERIFY]->site);
        $this->assertSame(CommandExecutionSite::CLI_READ, $executions[CliCommands::DB_WAIT]->site);
        $this->assertSame(CommandExecutionSite::CLI_OFFLINE_WRITE, $executions[CliCommands::MIGRATION_UP]->site);
        $this->assertSame(CommandExecutionSite::CLI_OFFLINE_WRITE, $executions[CliCommands::DB_TEST_RESET]->site);
        // The five fixtures HIL-729 brought over from chat. They write from the CLI for the same
        // reason their neighbours above do - composer test:db-prepare runs them before the
        // stand's daemon exists - so they are pinned here rather than trusted to stay put.
        $this->assertSame(CommandExecutionSite::CLI_OFFLINE_WRITE, $executions[CliCommands::SESSION_TEST_EXPIRE]->site);
        $this->assertSame(CommandExecutionSite::CLI_OFFLINE_WRITE, $executions[CliCommands::ORPHAN_TEST_CREATE]->site);
        $this->assertSame(CommandExecutionSite::CLI_OFFLINE_WRITE, $executions[CliCommands::ORPHAN_TEST_DELETE]->site);
        $this->assertSame(
            CommandExecutionSite::CLI_OFFLINE_WRITE,
            $executions[CliCommands::ORPHAN_SETTING_TEST_CREATE]->site,
        );
        $this->assertSame(
            CommandExecutionSite::CLI_OFFLINE_WRITE,
            $executions[CliCommands::ORPHAN_SETTING_TEST_DELETE]->site,
        );
        // The framework's first two daemon-spawned commands, also from HIL-729. The backup agent
        // starts both itself, and a restore writes to the database with the daemon up, under
        // protected mode - declaring them anything else would put a gate in front of a process
        // that has no operator to read it.
        $this->assertSame(
            CommandExecutionSite::DAEMON_SPAWNED,
            $executions[BackupConstants::RUN_COMMAND]->site,
        );
        $this->assertSame(
            CommandExecutionSite::DAEMON_SPAWNED,
            $executions[BackupConstants::RESTORE_RUN_COMMAND]->site,
        );
    }

    public function testTheGuardReachesARegistryTheFrameworkDoesNotOwn(): void
    {
        $unexplained = self::unexplainedDepartures(self::managerWithUnexplainedProjectCommand()->executions());

        $this->assertSame([self::PROJECT_UNEXPLAINED_COMMAND], $unexplained);
    }

    /**
     * Names every command that departs from the daemon rule without stating why.
     *
     * This is the guard itself, in one place: the test above runs it over the real registry and
     * expects nothing, and the test below runs it over a registry seeded with one offender and
     * expects exactly that offender — so the empty result of the real run is known to mean
     * "nothing to find" rather than "nothing was looked at".
     *
     * @param array<string, CommandExecution> $executions Whole registry declaration map
     * @return list<string> Names whose departure carries no reason
     */
    private static function unexplainedDepartures(array $executions): array
    {
        $names = [];
        foreach ($executions as $name => $execution) {
            if ($execution->site === CommandExecutionSite::DAEMON) {
                continue;
            }
            if ($execution->reason === null || trim($execution->reason) === '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Builds a manager carrying a project command that departs from the rule and explains nothing.
     *
     * @return CliManager Manager whose registry holds the unexplained stub
     */
    private static function managerWithUnexplainedProjectCommand(): CliManager
    {
        return new class ([]) extends CliManager {
            protected function registerProjectCommands(): void
            {
                $this->addCommand(new class implements CommandInterface {
                    public function execute(array $options, array $args): int
                    {
                        return ExitCode::SUCCESS;
                    }

                    public function getName(): string
                    {
                        return CommandExecutionRoleTest::PROJECT_UNEXPLAINED_COMMAND;
                    }

                    public function execution(): CommandExecution
                    {
                        return CommandExecution::cliOfflineWrite('');
                    }

                    public function getDescription(): string
                    {
                        return 'Project command stub that departs from the rule without saying why';
                    }

                    public function getHelp(): string
                    {
                        return 'Project command stub that departs from the rule without saying why';
                    }
                });
            }
        };
    }
}
