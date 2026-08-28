<?php

declare(strict_types=1);

namespace Demo\Chat\CLI\Commands;

use Demo\Chat\Constants\ChatCliCommands;
use Demo\Chat\Constants\ChatCommandConstants;
use Hilos\Auth\Session\SessionRebindConstants;
use Hilos\Socket\Command\DTO\CommandReplyDTO;

/**
 * Starts impersonating a user on a session through the daemon command channel.
 *
 * The session must currently be an admin session; on success it acts as the
 * target user until impersonate:stop reverts it.
 */
final class ImpersonateStartCommand extends AbstractImpersonateCommand
{
    public function getName(): string
    {
        return ChatCliCommands::IMPERSONATE_START;
    }

    public function getDescription(): string
    {
        return 'Make an admin session act as another user through the daemon command channel';
    }

    public function getHelp(): string
    {
        return <<<HELP
Command: impersonate:start

Description:
  Make an admin session act as another user. Sends an impersonateStart command
  over the daemon command channel; the daemon routes it to the chat agent, which
  rebinds the session to the target user (every tab of that session updates to
  the target) and records the admin behind the takeover. The session must be an
  admin session and must not already be impersonating.

Usage:
  php cli.php impersonate:start <sessionToken> <targetUserId>

Examples:
  php cli.php impersonate:start 0123456789abcdef0123456789abcdef 5
HELP;
    }

    protected function commandName(): string
    {
        return ChatCommandConstants::IMPERSONATE_START;
    }

    protected function buildPayload(array $args): ?array
    {
        // external-boundary: a positional argument the operator may omit; the usage hint below rejects it
        $sessionToken = (string) ($args[0] ?? '');
        $targetUserId = (int) ($args[1] ?? 0);

        if ($sessionToken === '' || $targetUserId <= 0) {
            echo "A session token and a positive target user id are required "
                . "(e.g. {$this->getName()} <sessionToken> 5)\n";

            return null;
        }

        return [
            ChatCommandConstants::FIELD_SESSION_TOKEN => $sessionToken,
            ChatCommandConstants::FIELD_TARGET_USER_ID => $targetUserId,
        ];
    }

    /**
     * The reply is written by the sessions library that carried the rebind out, and its keys
     * are the frame's own field names (HIL-710): it reports the state read back off the row
     * rather than the state that was asked for.
     *
     * @param CommandReplyDTO $reply Successful reply from the daemon command channel
     * @return string Line shown to the operator
     */
    protected function describeSuccess(CommandReplyDTO $reply): string
    {
        // external-boundary: the daemon's reply payload is untyped on this side
        $effective = (int) ($reply->payload[SessionRebindConstants::FIELD_USER_ID] ?? 0);
        $impersonator = (int) ($reply->payload[SessionRebindConstants::FIELD_IMPERSONATOR_USER_ID] ?? 0);

        return "Reply (ok): admin #{$impersonator} is now impersonating user #{$effective}";
    }
}
