<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Environment\Exception\EnvException;
use Hilos\Notification\NotificationCommandConstants;
use Hilos\Notification\NotificationSeverity;

/**
 * NotificationTestEmitCommand - emit one durable notification through the live daemon (test-only).
 *
 * Until now the only way to start the notification tract was the admin "test send" on the
 * channel page, so every end-to-end check of storage, preferences, and channel delivery had
 * to go through a browser. This drives {@see AbstractHilosIndexAgent} over the command
 * channel instead: the agent emits the draft from a worker (where the signal router exists,
 * so the in-app fan and the channel deliver-signals happen exactly as in production) and
 * answers with the notification id and the channels that actually got a delivery row.
 *
 * That reply is what makes the command a verdict rather than a trigger: an empty channel
 * list means nothing was queued, while a named channel with no artifact on disk means the
 * queue was fine and the channel agent is at fault. Test-only ({@see TestOnlyCommand} via
 * {@see AbstractCommandChannelTestCommand}) on both sides - the agent refuses the same
 * command again, because the route it answers is reachable without this class - and
 * database-free: every row this command causes is written by the agent, so the CLI process
 * itself needs no connection.
 */
class NotificationTestEmitCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /** Separator between channel names inside the --channels option value. */
    private const string CHANNEL_SEPARATOR = ',';

    /** Separator between values enumerated in help and error text for a human to read. */
    private const string LIST_SEPARATOR = ', ';

    /** Rendered instead of an empty channel list, so "nothing queued" cannot read as truncated output. */
    private const string NO_CHANNELS = 'none';

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:notification:emit)
     */
    public function getName(): string
    {
        return CliCommands::NOTIFICATION_TEST_EMIT;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Emit one notification to a user through the live daemon (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        $severities = implode(self::LIST_SEPARATOR, NotificationSeverity::ALL);

        return <<<HELP
Command: {$this->getName()} <userId> <type> <title> [--body=<t>] [--severity=<s>] [--channels=<a,b>]

Description:
  Emit one durable notification to a user through the running daemon, so the whole
  tract - the notification row, the live in-app signal, and channel delivery - runs
  without a browser. Replies with the persisted notification id and the channels that
  received a delivery row. An empty channel list means nothing was queued at all.
  Refuses on a production-like environment.

Arguments:
  <userId>   Recipient user id (positive integer)
  <type>     Machine notification type, e.g. audit.check
  <title>    Rendered title

Options:
  --body=<t>       Rendered body (default: none)
  --severity=<s>   One of: {$severities} (default: info)
  --channels=<a,b> Restrict delivery to these channels (default: every enabled channel)

Usage:
  php cli.php {$this->getName()} 1 audit.check "Audit probe"
  php cli.php {$this->getName()} 1 audit.check "Audit probe" --body=hello --severity=warning
  php cli.php {$this->getName()} 1 audit.check "Audit probe" --channels=email,sms
HELP;
    }

    /**
     * Splits a --channels option value into channel names, dropping blanks.
     *
     * @param string $raw Raw option value, e.g. `email, sms`
     * @return list<string> Channel names in the order given, without empties
     */
    public static function parseChannelList(string $raw): array
    {
        $names = [];
        foreach (explode(self::CHANNEL_SEPARATOR, $raw) as $name) {
            $name = trim($name);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Validates the draft arguments, sends the emit command, and prints the outcome.
     *
     * @param array<string, mixed> $options Parsed options: --body, --severity, --channels
     * @param list<string> $args Positional args: [0] userId, [1] type, [2] title
     * @return int Exit code (0 on success)
     */
    protected function run(array $options, array $args): int
    {
        $userIdArg = $args[0] ?? '';
        if (preg_match('/^\d+$/', $userIdArg) !== 1 || (int)$userIdArg <= 0) {
            echo "Usage: {$this->getName()} <userId> <type> <title> [--body=<t>] [--severity=<s>]"
                . " [--channels=<a,b>]  (userId: positive integer)\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        $type = $args[1] ?? '';
        if ($type === '') {
            echo "Argument <type> must be a non-empty notification type.\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        $title = $args[2] ?? '';
        if ($title === '') {
            echo "Argument <title> must be a non-empty title.\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        $payload = [
            NotificationCommandConstants::FIELD_USER_ID => (int)$userIdArg,
            NotificationCommandConstants::FIELD_TYPE => $type,
            NotificationCommandConstants::FIELD_TITLE => $title,
        ];

        if (isset($options['body'])) {
            $body = $options['body'];
            if (!is_string($body) || $body === '') {
                echo "Option --body must be a non-empty string.\n";

                return ExitCode::INVALID_ARGUMENT;
            }
            $payload[NotificationCommandConstants::FIELD_BODY] = $body;
        }

        if (isset($options['severity'])) {
            $severity = $options['severity'];
            // Rejected here rather than left to the emit seam, which silently falls back to
            // info: a fixture that asked for a severity must not pass with another one.
            if (!is_string($severity) || !NotificationSeverity::isValid($severity)) {
                $allowed = implode(self::LIST_SEPARATOR, NotificationSeverity::ALL);
                echo "Option --severity must be one of: {$allowed}.\n";

                return ExitCode::INVALID_ARGUMENT;
            }
            $payload[NotificationCommandConstants::FIELD_SEVERITY] = $severity;
        }

        if (isset($options['channels'])) {
            $channels = $options['channels'];
            $names = is_string($channels) ? self::parseChannelList($channels) : [];
            if ($names === []) {
                echo "Option --channels must name at least one channel.\n";

                return ExitCode::INVALID_ARGUMENT;
            }
            $payload[NotificationCommandConstants::FIELD_CHANNELS] = $names;
        }

        try {
            $reply = $this->sendCommand($this->getName(), $payload);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";

            return ExitCode::CONFIG_ERROR;
        }

        if ($reply === null) {
            echo "No reply from daemon (is it running?)\n";

            return ExitCode::ERROR;
        }

        if (!$reply->isOk()) {
            $detail = (string)($reply->payload[CommandConstants::FIELD_MESSAGE] ?? 'unknown error');
            echo "Command failed: {$detail}\n";

            return ExitCode::ERROR;
        }

        $id = (int)($reply->payload[NotificationCommandConstants::FIELD_NOTIFICATION_ID] ?? 0);
        $queued = $reply->payload[NotificationCommandConstants::FIELD_QUEUED_CHANNELS] ?? [];
        $names = is_array($queued) ? array_map(strval(...), $queued) : [];
        $rendered = $names === [] ? self::NO_CHANNELS : implode(self::LIST_SEPARATOR, $names);
        echo "Emitted notification {$id} (queued channels: {$rendered})\n";

        return ExitCode::SUCCESS;
    }
}
