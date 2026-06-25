<?php

declare(strict_types=1);

namespace Demo\Chat\CLI\Commands;

use Demo\Chat\Constants\ChatCommandConstants;
use Hilos\API\AsyncCommandClient;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\CommandInterface;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Shared base for the admin grant/revoke CLI commands.
 *
 * Sends a `setAdmin` command over the daemon command channel; the daemon parks
 * the connection, routes it to the chat agent (which flips the target user's
 * admin flag and fans the change out to the users list), and writes the reply
 * back. Subclasses only declare the admin flag they set and their command
 * identity. Real operator command — not test-only.
 */
abstract class AbstractSetAdminCommand implements CommandInterface
{
    /** @var float Wall-clock wait budget for a reply in milliseconds */
    private const float MAX_WAIT_MS = 5000.0;

    /** @var int Poll sleep between ticks in microseconds */
    private const int POLL_INTERVAL_US = 10000;

    /**
     * The admin flag this command sets on the target user.
     *
     * @return bool True to grant admin, false to revoke it
     */
    abstract protected function targetAdmin(): bool;

    /**
     * Sets the target user's admin flag through the daemon command channel.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args; the first is the target user id
     * @return int Exit code (0 on success)
     */
    public function execute(array $options, array $args): int
    {
        $userId = (int) ($args[0] ?? 0);
        if ($userId <= 0) {
            echo "A positive user id is required (e.g. {$this->getName()} 5)\n";
            return ExitCode::INVALID_ARGUMENT;
        }

        $verb = $this->targetAdmin() ? 'Granting admin to' : 'Revoking admin from';
        echo "{$verb} user #{$userId}...\n";

        try {
            $reply = $this->sendSetAdmin($userId);
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

        $state = ((bool) ($reply->payload[ChatCommandConstants::FIELD_ADMIN] ?? false)) ? 'admin' : 'not admin';
        echo "Reply (ok): user #{$userId} is now {$state}\n";

        return ExitCode::SUCCESS;
    }

    /**
     * Sends the set-admin command over the channel and waits for the reply.
     *
     * @param int $userId Target user id
     * @return ?CommandReplyDTO Reply, or null on timeout / transport failure
     * @throws EnvException When daemon host/port env values are missing or invalid
     */
    private function sendSetAdmin(int $userId): ?CommandReplyDTO
    {
        $host = Hilos::$env[EnvConstants::HILOS_DAEMON_HOST];
        $port = Hilos::$env->int(EnvConstants::COMMAND_PORT);

        $client = new AsyncCommandClient($host, $port);
        $request = new CommandRequestDTO(
            correlationId: RandomHelper::hex(8),
            command: ChatCommandConstants::SET_ADMIN,
            payload: [
                ChatCommandConstants::FIELD_USER_ID => $userId,
                ChatCommandConstants::FIELD_ADMIN => $this->targetAdmin(),
            ],
        );

        try {
            $client->startRequest($request);

            $startedAtMs = microtime(true) * 1000;
            while (!$client->hasResult()) {
                if ((microtime(true) * 1000 - $startedAtMs) > self::MAX_WAIT_MS) {
                    return null;
                }

                $client->tick();
                usleep(self::POLL_INTERVAL_US);
            }

            return $client->consumeResult();
        } catch (\Throwable) {
            return null;
        }
    }
}
