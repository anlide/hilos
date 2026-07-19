<?php

declare(strict_types=1);

namespace Demo\Chat\CLI\Commands;

use Demo\Chat\Constants\ChatCliCommands;
use Demo\Chat\Constants\ChatCommandConstants;
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

    protected function describeSuccess(CommandReplyDTO $reply): string
    {
        $effective = (int) ($reply->payload[ChatCommandConstants::FIELD_EFFECTIVE_USER_ID] ?? 0);
        $impersonator = (int) ($reply->payload[ChatCommandConstants::FIELD_IMPERSONATOR] ?? 0);

        return "Reply (ok): admin #{$impersonator} is now impersonating user #{$effective}";
    }
}
