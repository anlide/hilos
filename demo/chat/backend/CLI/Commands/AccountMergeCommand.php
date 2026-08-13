<?php

declare(strict_types=1);

namespace Demo\Chat\CLI\Commands;

use Demo\Chat\Constants\ChatCliCommands;
use Demo\Chat\Constants\ChatCommandConstants;
use Hilos\API\AsyncCommandClient;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Constants\TimeConstants;
use Hilos\Core\CLI\Commands\CommandInterface;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Utils\Helpers\RandomHelper;
use Throwable;

/**
 * Merges one populated account into another through the daemon command channel (HIL-378).
 *
 * Sends an `accountMerge` command over the daemon command channel; the daemon
 * parks the connection, routes it to the chat agent (which re-points the loser's
 * identities and messages to the survivor, tombstones the loser, and forces its
 * live sessions to log out — all in one transaction), and writes the reply back.
 * Prints the transfer counts. Real operator command — not test-only.
 */
final class AccountMergeCommand implements CommandInterface
{
    /** @var float Wall-clock wait budget for a reply in milliseconds */
    private const float MAX_WAIT_MS = 5000.0;

    /** @var int Poll sleep between ticks in microseconds */
    private const int POLL_INTERVAL_US = 10000;

    public function getName(): string
    {
        return ChatCliCommands::ACCOUNT_MERGE;
    }

    public function getDescription(): string
    {
        return 'Merge one populated account into another through the daemon command channel';
    }

    public function getHelp(): string
    {
        return <<<HELP
Command: account:merge

Description:
  Merge one populated account (the loser) into another (the survivor). Sends an
  accountMerge command over the daemon command channel; the daemon routes it to
  the chat agent, which re-points the loser's sign-in identities and chat messages
  to the survivor, tombstones the loser (merged_into + login closed), and forces
  the loser's live sessions to log out. The survivor keeps its own profile. The
  whole transfer runs in one transaction; on any failure nothing is written.

Usage:
  php cli.php account:merge <survivorId> <loserId>

Examples:
  php cli.php account:merge 3 7
HELP;
    }

    /**
     * Sends the account-merge command through the channel and prints the outcome.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args: [0] survivor id, [1] loser id
     * @return int Exit code (0 on success)
     */
    public function execute(array $options, array $args): int
    {
        $survivorId = (int) ($args[0] ?? 0);
        $loserId = (int) ($args[1] ?? 0);
        if ($survivorId <= 0 || $loserId <= 0) {
            echo "Two positive user ids are required (e.g. {$this->getName()} <survivorId> <loserId>)\n";
            return ExitCode::INVALID_ARGUMENT;
        }

        echo "Merging user #{$loserId} into #{$survivorId}...\n";

        try {
            $reply = $this->sendMerge($survivorId, $loserId);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }

        if ($reply === null) {
            echo "No reply from daemon (is it running?)\n";
            return ExitCode::ERROR;
        }

        if (!$reply->isOk()) {
            $detail = (string) ($reply->payload[CommandConstants::FIELD_MESSAGE] ?? 'unknown error');
            echo "Command failed: {$detail}\n";
            return ExitCode::ERROR;
        }

        $identities = (int) ($reply->payload[ChatCommandConstants::FIELD_IDENTITIES_MOVED] ?? 0);
        $messages = (int) ($reply->payload[ChatCommandConstants::FIELD_MESSAGES_MOVED] ?? 0);
        echo "Reply (ok): merged user #{$loserId} into #{$survivorId} "
            . "({$identities} identities, {$messages} messages moved)\n";

        return ExitCode::SUCCESS;
    }

    /**
     * Sends the account-merge command over the channel and waits for the reply.
     *
     * @param int $survivorId Survivor user id that absorbs the loser
     * @param int $loserId Loser user id folded into the survivor
     * @return ?CommandReplyDTO Reply, or null on timeout / transport failure
     * @throws EnvException When daemon host/port env values are missing or invalid
     */
    private function sendMerge(int $survivorId, int $loserId): ?CommandReplyDTO
    {
        $host = Hilos::$env[EnvConstants::HILOS_DAEMON_HOST];
        $port = Hilos::$env->int(EnvConstants::COMMAND_PORT);

        $client = new AsyncCommandClient($host, $port);
        $request = new CommandRequestDTO(
            correlationId: RandomHelper::hex(8),
            command: ChatCommandConstants::ACCOUNT_MERGE,
            payload: [
                ChatCommandConstants::FIELD_SURVIVOR_USER_ID => $survivorId,
                ChatCommandConstants::FIELD_LOSER_USER_ID => $loserId,
            ],
        );

        try {
            $client->startRequest($request);

            $startedAtMs = microtime(true) * TimeConstants::MS_PER_SECOND;
            while (!$client->hasResult()) {
                if ((microtime(true) * TimeConstants::MS_PER_SECOND - $startedAtMs) > self::MAX_WAIT_MS) {
                    return null;
                }

                $client->tick();
                usleep(self::POLL_INTERVAL_US);
            }

            return $client->consumeResult();
        } catch (Throwable) {
            return null;
        }
    }
}
