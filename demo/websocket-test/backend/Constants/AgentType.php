<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Constants;

/**
 * AgentType - Agent type constants for chat demo
 *
 * Defines agent type identifiers used in the chat demo project.
 */
class AgentType
{
    /** @var string Chat agent type (monopolistic) */
    public const string CHAT = 'chat';

    /** @var string User agent type (regular) */
    public const string SESSION = 'session';
}

