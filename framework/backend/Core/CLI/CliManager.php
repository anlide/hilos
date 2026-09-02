<?php

declare(strict_types=1);

namespace Hilos\Core\CLI;

use Hilos\Backup\BackupConstants;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\AccountMergeCommand;
use Hilos\Core\CLI\Commands\AdminCreateCommand;
use Hilos\Core\CLI\Commands\AdminGrantCommand;
use Hilos\Core\CLI\Commands\AdminRevokeCommand;
use Hilos\Core\CLI\Commands\BackupTestAgeCommand;
use Hilos\Core\CLI\Commands\BackupTestPruneCommand;
use Hilos\Core\CLI\Commands\BackupTestShipCommand;
use Hilos\Core\CLI\Commands\BackupTestRunScheduleCommand;
use Hilos\Core\CLI\Commands\BackupRestoreCommand;
use Hilos\Core\CLI\Commands\BackupRestoreRunCommand;
use Hilos\Core\CLI\Commands\BackupRunCommand;
use Hilos\Core\CLI\Commands\BackupVerifyCommand;
use Hilos\Core\CLI\Commands\ClusterNodesCommand;
use Hilos\Core\CLI\Commands\ClusterReloadCommand;
use Hilos\Core\CLI\Commands\ClusterTestAgentPlaceCommand;
use Hilos\Core\CLI\Commands\ClusterTestClientAttachCommand;
use Hilos\Core\CLI\Commands\ClusterTestClientDetachCommand;
use Hilos\Core\CLI\Commands\ClusterTestClientFanoutCommand;
use Hilos\Core\CLI\Commands\ClusterTestClientSendCommand;
use Hilos\Core\CLI\Commands\ClusterTestDbAnnounceCommand;
use Hilos\Core\CLI\Commands\ClusterTestDbReadCommand;
use Hilos\Core\CLI\Commands\ClusterTestDbWriteCommand;
use Hilos\Core\CLI\Commands\ClusterTestInspectCommand;
use Hilos\Core\CLI\Commands\CommandExecution;
use Hilos\Core\CLI\Commands\CommandTestEchoCommand;
use Hilos\Core\CLI\Commands\CommandInterface;
use Hilos\Core\CLI\Commands\ConnectionTestDropCommand;
use Hilos\Core\CLI\Commands\DatabaseFreeCommand;
use Hilos\Core\CLI\Commands\DbSchemaStatusCommand;
use Hilos\Core\CLI\Commands\DbTestResetCommand;
use Hilos\Core\CLI\Commands\DbWaitCommand;
use Hilos\Core\CLI\Commands\HelpCommand;
use Hilos\Core\CLI\Commands\ImpersonateStartCommand;
use Hilos\Core\CLI\Commands\ImpersonateStopCommand;
use Hilos\Core\CLI\Commands\LlmPingCommand;
use Hilos\Core\CLI\Commands\MigrationDownCommand;
use Hilos\Core\CLI\Commands\MigrationRetryCommand;
use Hilos\Core\CLI\Commands\MigrationStatusCommand;
use Hilos\Core\CLI\Commands\MigrationUpCommand;
use Hilos\Core\CLI\Commands\NotificationTestEmitCommand;
use Hilos\Core\CLI\Commands\OrphanSettingTestCreateCommand;
use Hilos\Core\CLI\Commands\OrphanSettingTestDeleteCommand;
use Hilos\Core\CLI\Commands\OrphanTestCreateCommand;
use Hilos\Core\CLI\Commands\OrphanTestDeleteCommand;
use Hilos\Core\CLI\Commands\SeedApplyCommand;
use Hilos\Core\CLI\Commands\MonitorCommand;
use Hilos\Core\CLI\Commands\PingCommand;
use Hilos\Core\CLI\Commands\ProtectedModeCloseCommand;
use Hilos\Core\CLI\Commands\ProtectedModeOpenCommand;
use Hilos\Core\CLI\Commands\ProtectedModePassCommand;
use Hilos\Core\CLI\Commands\ProtectedModeTestCloseCommand;
use Hilos\Core\CLI\Commands\ProtectedModeTestEnterCommand;
use Hilos\Core\CLI\Commands\ProtectedModeTestInspectCommand;
use Hilos\Core\CLI\Commands\ProtectedModeTestLeaveCommand;
use Hilos\Core\CLI\Commands\ProtectedModeTestOpenCommand;
use Hilos\Core\CLI\Commands\ProtectedModeTestPassCommand;
use Hilos\Core\CLI\Commands\SessionTestExpireCommand;
use Hilos\Core\CLI\Commands\StatusCommand;
use Hilos\Core\CLI\Commands\ThrottleTestResetCommand;
use Hilos\Core\CLI\Commands\UserTestSeedCommand;
use Hilos\Core\CLI\Commands\VerificationTestExpireCommand;
use Hilos\Database\DatabaseException;
use Throwable;

/**
 * CliManager - Main CLI management class.
 *
 * Parses the command name, positional arguments and `--key=value` / `--flag`
 * options from argv, then routes execution to the matching registered command.
 * Each command receives the parsed options and arguments through its execute().
 */
class CliManager
{
    /** @var string Option prefix */
    private const string OPTION_PREFIX = '--';

    /** @var list<string> command line arguments */
    private array $argv;

    /** @var ?string current command name */
    private ?string $command = null;

    /** @var list<string> parsed positional arguments */
    private array $args = [];

    /** @var array<string, mixed> parsed options (--key=value or --flag) */
    private array $options = [];

    /** @var array<string, CommandInterface> Registered commands */
    private array $commands = [];

    /**
     * Initializes CLI manager with command line arguments.
     *
     * @param list<string> $argv Command line arguments (from global $argv)
     */
    public function __construct(array $argv)
    {
        $this->argv = $argv;
        $this->registerCommands();
        $this->parseArguments();
    }

    /**
     * Registers available commands.
     *
     * Initializes command instances and maps them to command names.
     */
    private function registerCommands(): void
    {
        $this->commands[CliCommands::DAEMON_STATUS] = new StatusCommand();
        $this->commands[CliCommands::DAEMON_MONITOR] = new MonitorCommand();
        $this->commands[CliCommands::DAEMON_PING] = new PingCommand();
        $this->commands[CliCommands::CLUSTER_NODES] = new ClusterNodesCommand();
        $this->commands[CliCommands::CLUSTER_RELOAD] = new ClusterReloadCommand();
        $this->commands[CliCommands::CLUSTER_TEST_INSPECT] = new ClusterTestInspectCommand();
        $this->commands[CliCommands::CLUSTER_TEST_CLIENT_ATTACH] = new ClusterTestClientAttachCommand();
        $this->commands[CliCommands::CLUSTER_TEST_CLIENT_DETACH] = new ClusterTestClientDetachCommand();
        $this->commands[CliCommands::CLUSTER_TEST_CLIENT_SEND] = new ClusterTestClientSendCommand();
        $this->commands[CliCommands::CLUSTER_TEST_CLIENT_FANOUT] = new ClusterTestClientFanoutCommand();
        $this->commands[CliCommands::CLUSTER_TEST_DB_ANNOUNCE] = new ClusterTestDbAnnounceCommand();
        $this->commands[CliCommands::CLUSTER_TEST_DB_WRITE] = new ClusterTestDbWriteCommand();
        $this->commands[CliCommands::CLUSTER_TEST_DB_READ] = new ClusterTestDbReadCommand();
        $this->commands[CliCommands::CLUSTER_TEST_AGENT_PLACE] = new ClusterTestAgentPlaceCommand();
        $this->commands[CliCommands::MIGRATION_UP] = new MigrationUpCommand();
        $this->commands[CliCommands::MIGRATION_DOWN] = new MigrationDownCommand();
        $this->commands[CliCommands::MIGRATION_STATUS] = new MigrationStatusCommand();
        $this->commands[CliCommands::MIGRATION_RETRY] = new MigrationRetryCommand();
        $this->commands[CliCommands::SEED_APPLY] = new SeedApplyCommand();
        $this->commands[CliCommands::DB_SCHEMA_STATUS] = new DbSchemaStatusCommand();
        $this->commands[CliCommands::DB_WAIT] = new DbWaitCommand();
        $this->commands[CliCommands::DB_TEST_RESET] = new DbTestResetCommand();
        $this->commands[CliCommands::VERIFICATION_TEST_EXPIRE] = new VerificationTestExpireCommand();
        $this->commands[CliCommands::SESSION_TEST_EXPIRE] = new SessionTestExpireCommand();
        $this->commands[CliCommands::ORPHAN_TEST_CREATE] = new OrphanTestCreateCommand();
        $this->commands[CliCommands::ORPHAN_TEST_DELETE] = new OrphanTestDeleteCommand();
        $this->commands[CliCommands::ORPHAN_SETTING_TEST_CREATE] = new OrphanSettingTestCreateCommand();
        $this->commands[CliCommands::ORPHAN_SETTING_TEST_DELETE] = new OrphanSettingTestDeleteCommand();
        $this->commands[CliCommands::USER_TEST_SEED] = new UserTestSeedCommand();
        $this->commands[CliCommands::NOTIFICATION_TEST_EMIT] = new NotificationTestEmitCommand();
        $this->commands[CliCommands::COMMAND_TEST_ECHO] = new CommandTestEchoCommand();
        $this->commands[CliCommands::ADMIN_GRANT] = new AdminGrantCommand();
        $this->commands[CliCommands::ADMIN_REVOKE] = new AdminRevokeCommand();
        $this->commands[CliCommands::ADMIN_CREATE] = new AdminCreateCommand();
        $this->commands[CliCommands::IMPERSONATE_START] = new ImpersonateStartCommand();
        $this->commands[CliCommands::IMPERSONATE_STOP] = new ImpersonateStopCommand();
        $this->commands[CliCommands::ACCOUNT_MERGE] = new AccountMergeCommand();
        $this->commands[CliCommands::THROTTLE_TEST_RESET] = new ThrottleTestResetCommand();
        $this->commands[CliCommands::BACKUP_VERIFY] = new BackupVerifyCommand();
        $this->commands[CliCommands::BACKUP_RESTORE] = new BackupRestoreCommand();
        $this->commands[BackupConstants::RUN_COMMAND] = new BackupRunCommand();
        $this->commands[BackupConstants::RESTORE_RUN_COMMAND] = new BackupRestoreRunCommand();
        $this->commands[CliCommands::BACKUP_TEST_AGE] = new BackupTestAgeCommand();
        $this->commands[CliCommands::BACKUP_TEST_PRUNE] = new BackupTestPruneCommand();
        $this->commands[CliCommands::BACKUP_TEST_SHIP] = new BackupTestShipCommand();
        $this->commands[CliCommands::BACKUP_TEST_RUN_SCHEDULE] = new BackupTestRunScheduleCommand();
        $this->commands[CliCommands::CONNECTION_TEST_DROP] = new ConnectionTestDropCommand();
        $this->commands[CliCommands::PROTECTED_MODE_TEST_INSPECT] = new ProtectedModeTestInspectCommand();
        $this->commands[CliCommands::PROTECTED_MODE_TEST_ENTER] = new ProtectedModeTestEnterCommand();
        $this->commands[CliCommands::PROTECTED_MODE_TEST_LEAVE] = new ProtectedModeTestLeaveCommand();
        $this->commands[CliCommands::PROTECTED_MODE_TEST_OPEN] = new ProtectedModeTestOpenCommand();
        $this->commands[CliCommands::PROTECTED_MODE_TEST_PASS] = new ProtectedModeTestPassCommand();
        $this->commands[CliCommands::PROTECTED_MODE_TEST_CLOSE] = new ProtectedModeTestCloseCommand();
        $this->commands[CliCommands::PROTECTED_MODE_PASS] = new ProtectedModePassCommand();
        $this->commands[CliCommands::PROTECTED_MODE_OPEN] = new ProtectedModeOpenCommand();
        $this->commands[CliCommands::PROTECTED_MODE_CLOSE] = new ProtectedModeCloseCommand();
        $this->commands[CliCommands::LLM_PING] = new LlmPingCommand();

        $this->registerProjectCommands();

        $this->commands[CliCommands::HELP] = new HelpCommand($this->commands);
    }

    /**
     * Registers project-specific commands.
     *
     * Override point for a project: a CliManager subclass overrides this and calls
     * addCommand() for each project command. The default registers none.
     *
     * A registered command's constructor must not touch the database or Hilos state. The
     * whole registry is built before the CLI bootstrap connects — it is
     * {@see requiresDatabase()}, asked of the built registry, that decides whether the
     * bootstrap connects at all.
     */
    protected function registerProjectCommands(): void
    {
    }

    /**
     * Tells whether a command name is registered, framework or project.
     *
     * Asked by the feature activation check rather than by the routing above it, which resolves
     * a name through {@see self::$commands} directly: a feature driven by a CLI command
     * (backup:run is spawned by the backup supervisor) is only half-activated when the project
     * never registered it, and that gap otherwise surfaces as a child process exiting with
     * "unknown command" hours later.
     *
     * @param string $name Command name to look for
     * @return bool True when a command is registered under that name
     */
    public function hasCommand(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    /**
     * Answers the CLI bootstrap's one question about a command: connect the database first,
     * or leave it alone. The registry itself stays private — only the yes/no leaves, so the
     * marker knowledge stays with the registry owner.
     *
     * An unregistered name answers false, so a typo reaches the "Unknown command" reply
     * instead of dying earlier in a connection failure.
     *
     * @param ?string $command Command name from argv, or null when none was named
     * @return bool True when the bootstrap must connect before running the command
     */
    public function requiresDatabase(?string $command): bool
    {
        $name = $command ?? CliCommands::HELP;
        if (!isset($this->commands[$name])) {
            return false;
        }

        return !($this->commands[$name] instanceof DatabaseFreeCommand);
    }

    /**
     * Hands out what every registered command declares about where its work happens.
     *
     * The registry answers for itself, framework and project commands alike, because nothing
     * else can: the execution site is a per-command declaration, and collecting it any other
     * way would mean walking the class tree with Reflection, which this project forbids
     * (HIL-538). The guard test asks this, and so does anything that needs the whole picture
     * rather than one command's answer.
     *
     * @return array<string, CommandExecution> Execution declaration per registered command name
     */
    public function executions(): array
    {
        $executions = [];
        foreach ($this->commands as $name => $command) {
            $executions[$name] = $command->execution();
        }

        return $executions;
    }

    /**
     * Hands out which class answers each registered command name.
     *
     * The registry answers for itself here too, and for the same reason {@see executions()}
     * does: framework and project commands alike are registered by instance, so nothing
     * outside can list them without walking the class tree with Reflection, which this
     * project forbids (HIL-538).
     *
     * The map is what the test-only name guard needs and nothing else asks for: it holds the
     * direction the wire cannot check - a class that refuses on a production-like environment
     * must be named with the {@see CommandConstants::TEST_ONLY_PREFIX} prefix - and answering
     * it means putting a class beside its name. The judgement stays with the guard: this
     * hands over classes, not verdicts.
     *
     * @return array<string, class-string<CommandInterface>> Answering class per registered command name
     */
    public function commandClasses(): array
    {
        $classes = [];
        foreach ($this->commands as $name => $command) {
            $classes[$name] = $command::class;
        }

        return $classes;
    }

    /**
     * Reports where the named command's work happens, so the CLI spine can gate on it.
     *
     * An unregistered name answers null, the same way {@see requiresDatabase()} answers false
     * for one: a typo has to reach the "Unknown command" reply rather than die in a gate that
     * has no command to judge.
     *
     * @param ?string $command Command name from argv, or null when none was named
     * @return ?CommandExecution Declaration of the named command, or null when it is not registered
     */
    public function execution(?string $command): ?CommandExecution
    {
        $name = $command ?? CliCommands::HELP;

        return ($this->commands[$name] ?? null)?->execution();
    }

    /**
     * Registers a command under its own name.
     *
     * @param CommandInterface $command Command to register
     */
    protected function addCommand(CommandInterface $command): void
    {
        $this->commands[$command->getName()] = $command;
    }

    /**
     * Runs CLI manager.
     *
     * Main entry point for CLI execution. Parses command and routes
     * to appropriate handler. Displays help if no command provided.
     * Handles exceptions and displays user-friendly error messages.
     *
     * @return int Exit code (0 = success, 1 = error)
     */
    public function run(): int
    {
        try {
            // Show help if no command provided
            if ($this->command === null) {
                return $this->commands[CliCommands::HELP]->execute($this->options, $this->args);
            }

            // Execute command if registered
            if (isset($this->commands[$this->command])) {
                return $this->commands[$this->command]->execute($this->options, $this->args);
            }

            // Handle unknown command
            return $this->handleUnknownCommand();
        } catch (DatabaseException $e) {
            // Handle database exceptions with detailed error information
            echo "\n✗ Database Error\n";
            echo "Error: {$e->getMessage()}\n";
            
            if ($e->getMysqlErrorCode() > 0) {
                echo "MySQL Error Code: {$e->getMysqlErrorCode()}\n";
                echo "MySQL Error: {$e->getMysqlErrorMessage()}\n";
            }
            
            if ($e->getQuery()) {
                echo "Query: {$e->getQuery()}\n";
            }
            
            echo "\n";
            return ExitCode::ERROR;
        } catch (Throwable $e) {
            // Handle unexpected errors
            echo "\n✗ Unexpected Error\n";
            echo "Error: {$e->getMessage()}\n";
            echo "File: {$e->getFile()}:{$e->getLine()}\n\n";
            return ExitCode::ERROR;
        }
    }

    /**
     * Parses command line arguments.
     *
     * Extracts command, positional arguments and options from argv.
     * Supports formats:
     * - --key=value (option with value)
     * - --flag (boolean flag)
     * - positional arguments
     */
    private function parseArguments(): void
    {
        // Skip script name (argv[0])
        $args = array_slice($this->argv, 1);

        // First argument is command (if not an option)
        if (count($args) > 0 && !str_starts_with($args[0], self::OPTION_PREFIX)) {
            $this->command = array_shift($args);
        }

        // Parse remaining arguments
        foreach ($args as $arg) {
            if (preg_match('/^' . preg_quote(self::OPTION_PREFIX) . '([^=]+)=(.+)$/', $arg, $matches)) {
                // Option with value: --key=value
                $this->options[$matches[1]] = $matches[2];
            } elseif (preg_match('/^' . preg_quote(self::OPTION_PREFIX) . '(.+)$/', $arg, $matches)) {
                // Boolean flag: --flag
                $this->options[$matches[1]] = true;
            } else {
                // Positional argument
                $this->args[] = $arg;
            }
        }
    }

    /**
     * Handles unknown command.
     *
     * Displays error message and help information for invalid commands.
     *
     * @return int Exit code (1)
     */
    private function handleUnknownCommand(): int
    {
        echo sprintf('Unknown command: %s', $this->command) . "\n";
        $this->commands[CliCommands::HELP]->execute($this->options, $this->args);
        return ExitCode::ERROR;
    }
}
