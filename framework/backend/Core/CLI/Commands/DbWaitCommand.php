<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Database\DatabaseSql;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use mysqli_sql_exception;

/**
 * DB Wait Command.
 *
 * Waits for MySQL to become ready. Polls connection until success.
 * Uses DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD from environment.
 *
 * Database-free by contract: the poll is the command's own connect, and it has to work
 * before the server answers at all — a bootstrap connect would fail first and there would
 * be nothing left to wait for.
 */
class DbWaitCommand implements CommandInterface, DatabaseFreeCommand
{
    /** @var list<string> Option names this command reads, without the leading dashes */
    public const array OPTIONS = [
        self::OPTION_INTERVAL,
        self::OPTION_TIMEOUT,
    ];

    private const string OPTION_INTERVAL = 'interval';

    private const string OPTION_TIMEOUT = 'timeout';

    /** @var int Poll interval in seconds */
    private const int DEFAULT_INTERVAL_SEC = 2;

    /** @var int Max wait time in seconds */
    private const int DEFAULT_TIMEOUT_SEC = 60;

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (e.g. db:wait)
     */
    public function getName(): string
    {
        return CliCommands::DB_WAIT;
    }

    /**
     * Declares the departure: this reading happens in the CLI process, for the reason it names.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::cliRead(
            'polls a database that is still coming up, which is exactly when nothing - the daemon included - can reach it',
        );
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Wait for MySQL to become ready';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: db:wait

Description:
  Polls MySQL connection until it becomes available.
  Useful for Docker startup when MySQL may not be ready immediately.

Usage:
  php cli.php db:wait [options]

Options:
  --interval=<sec>   Poll interval in seconds (default: 2)
  --timeout=<sec>    Max wait time in seconds (default: 60)

Examples:
  php cli.php db:wait
  php cli.php db:wait --timeout=120
HELP;
    }

    /**
     * Polls MySQL connection until ready or timeout.
     *
     * @param array<string, mixed> $options Parsed options (interval, timeout)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     * @throws EnvException If a database connection env value is missing or invalid
     */
    public function execute(array $options, array $args): int
    {
        $interval = isset($options[self::OPTION_INTERVAL]) ? (int)$options[self::OPTION_INTERVAL] : self::DEFAULT_INTERVAL_SEC;
        $timeout = isset($options[self::OPTION_TIMEOUT]) ? (int)$options[self::OPTION_TIMEOUT] : self::DEFAULT_TIMEOUT_SEC;

        $host = Hilos::$env[EnvConstants::DB_HOST]->string();
        $port = Hilos::$env[EnvConstants::DB_PORT]->int();
        $user = Hilos::$env[EnvConstants::DB_USERNAME]->string();
        $pass = Hilos::$env[EnvConstants::DB_PASSWORD]->string();

        $start = time();
        $attempt = 0;

        while (true) {
            $attempt++;
            $conn = null;
            try {
                $conn = mysqli_connect($host, $user, $pass, '', $port);
                mysqli_query($conn, DatabaseSql::SESSION_TIME_ZONE_UTC);
            } catch (mysqli_sql_exception) {
                if ($conn !== null) {
                    mysqli_close($conn);
                    $conn = null;
                }
                // Not up yet, or the session clock could not be set: the timeout below decides
            }

            // Ready means the server accepted a link and its session clock is UTC.
            // A failed SET is closed above and does not count as ready.
            if ($conn !== null) {
                mysqli_close($conn);
                echo "MySQL is ready ({$attempt} attempt(s))\n";
                return ExitCode::SUCCESS;
            }

            if (time() - $start >= $timeout) {
                fwrite(STDERR, "Timeout: MySQL not ready after {$timeout}s\n");
                return ExitCode::ERROR;
            }

            sleep($interval);
        }
    }
}
