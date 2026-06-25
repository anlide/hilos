<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

/**
 * ChatCommandConstants - chat command-channel command names.
 *
 * Names of commands the chat daemon accepts over the CLI command socket channel,
 * routed to an agent via Hilos::getCommandAgentRoutes().
 */
final class ChatCommandConstants
{
    /** @var string Echo command: routed to the chat agent, which echoes the request payload back */
    public const string ECHO = 'echo';

    /** @var string Set-admin command: routed to the chat agent, which flips a user's admin flag */
    public const string SET_ADMIN = 'setAdmin';

    /** @var string Payload key: target user id for the set-admin command */
    public const string FIELD_USER_ID = 'userId';

    /** @var string Payload key: new admin flag for the set-admin command */
    public const string FIELD_ADMIN = 'admin';
}
