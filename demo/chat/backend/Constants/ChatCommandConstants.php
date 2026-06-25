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
}
