<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Utils\Env;

/**
 * DB Wait Command.
 *
 * Waits for MySQL to become ready. Polls connection until success.
 * Uses DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD from environment.
 */
class DbWaitCommand implements CommandInterface
{
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
     */
    public function execute(array $options, array $args): int
    {
        $interval = isset($options['interval']) ? (int)$options['interval'] : self::DEFAULT_INTERVAL_SEC;
        $timeout = isset($options['timeout']) ? (int)$options['timeout'] : self::DEFAULT_TIMEOUT_SEC;

        $host = Env::get('DB_HOST', 'localhost');
        $port = (int) Env::get('DB_PORT', '3306');
        $user = Env::get('DB_USERNAME', 'root');
        $pass = Env::get('DB_PASSWORD', '');

        $start = time();
        $attempt = 0;

        while (true) {
            $attempt++;
            $conn = @mysqli_connect($host, $user, $pass, '', $port);
            if ($conn !== false) {
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
