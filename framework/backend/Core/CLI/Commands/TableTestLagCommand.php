<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/**
 * TableTestLagCommand - hold table windows and facet counts back by an artificial lag (HIL-1020).
 *
 * The row skeleton of a browser table stands up only when a changed window is late, and the
 * counts beside the filter options travel after the window; on a development box both answers
 * arrive in tens of milliseconds, so neither state can be seen or tested. This command slows the
 * two answers down, each by its own number of milliseconds, until it is called again.
 *
 * A test-only command (extends {@see TestOnlyCommand}, so it refuses on a production-like env)
 * and database-free by contract: the master answers from its own runtime state and writes the
 * node's lag row, so the CLI process has nothing to read or write.
 */
class TableTestLagCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /** @var list<string> Option names this command reads, without the leading dashes */
    public const array OPTIONS = [
        self::OPTION_WINDOW,
        self::OPTION_FACETS,
    ];

    /** Option naming the window lag in milliseconds. */
    private const string OPTION_WINDOW = 'window';

    /** Option naming the facet count lag in milliseconds. */
    private const string OPTION_FACETS = 'facets';

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:table:lag)
     */
    public function getName(): string
    {
        return CliCommands::TABLE_TEST_LAG;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Hold table windows and facet counts back by an artificial lag (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:table:lag

Description:
  Hold this node's browser tables back by an artificial lag, so the states a slow
  answer produces can be seen: the row skeleton while a changed window is late, and
  the filter options without their counts while the counts are late. The window lag
  holds an incoming viewport change; the facets lag holds the facet counts. Each call
  sets the whole state: an option left out is a lag turned off, and a call without
  options takes both off. Refuses on a production-like environment.

Options:
  --window=<ms>   Milliseconds a changed table window is held back (default: 0)
  --facets=<ms>   Milliseconds facet counts are held back (default: 0)

Usage:
  php cli.php test:table:lag [--window=<ms>] [--facets=<ms>]

Examples:
  php cli.php test:table:lag --window=60000
  php cli.php test:table:lag --facets=1500
  php cli.php test:table:lag
HELP;
    }

    /**
     * Validates both lags, sends them to the master, and prints the state it wrote.
     *
     * @param array<string, mixed> $options Parsed options: --window, --facets
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        // external-boundary: the operator's command line; absent means that lag is off
        $windowLagMs = $this->lagMilliseconds(self::OPTION_WINDOW, $options[self::OPTION_WINDOW] ?? '0');
        if ($windowLagMs === null) {
            return ExitCode::ERROR;
        }

        // external-boundary: the operator's command line; absent means that lag is off
        $facetsLagMs = $this->lagMilliseconds(self::OPTION_FACETS, $options[self::OPTION_FACETS] ?? '0');
        if ($facetsLagMs === null) {
            return ExitCode::ERROR;
        }

        $payload = [
            CommandConstants::FIELD_WINDOW_MS => $windowLagMs,
            CommandConstants::FIELD_FACETS_MS => $facetsLagMs,
        ];

        try {
            $result = $this->sendCommand(CliCommands::TABLE_TEST_LAG, $payload);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";

            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, CliCommands::TABLE_TEST_LAG);
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }

        // Both fields are written on every successful reply by the master; a reply missing one is
        // an incomplete answer, and printing it would report a lag nobody set.
        $windowMs = $reply->payload[CommandConstants::FIELD_WINDOW_MS] ?? null;
        $facetsMs = $reply->payload[CommandConstants::FIELD_FACETS_MS] ?? null;
        if (!is_int($windowMs) || !is_int($facetsMs)) {
            echo "Command failed: the reply names no table lag\n";

            return ExitCode::ERROR;
        }

        echo $windowMs === 0 && $facetsMs === 0
            ? "Table lag off\n"
            : "Table lag: window {$windowMs} ms, facets {$facetsMs} ms\n";

        return ExitCode::SUCCESS;
    }

    /**
     * Reads one lag option as a whole number of milliseconds, 0 or more.
     *
     * Prints the refusal the operator sees and returns null when the value is not that number.
     * Absence is not a refusal: the caller passes the off default before this is asked.
     *
     * @param string $option Option name as typed, without the leading dashes
     * @param mixed $raw Operator value, or the off default when the option was left out
     * @return ?int Milliseconds, or null when the value is refused
     */
    private function lagMilliseconds(string $option, mixed $raw): ?int
    {
        if (!is_string($raw) || preg_match('/^\d+$/', $raw) !== 1) {
            echo "Error: --{$option} must be a whole number of milliseconds, 0 or more\n";

            return null;
        }

        return (int)$raw;
    }
}
