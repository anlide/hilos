<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

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

    /** @var string Bot agent type (regular) */
    public const string BOT = 'bot';

    /** @var string Moderator agent type (regular) */
    public const string MODERATOR = 'moderator';

    /** @var string Chat context analyzer agent type (monopolistic) */
    public const string CHAT_CONTEXT_ANALYZER = 'chat_context_analyzer';

    /** @var string Guardian ops agent type (monopolistic) */
    public const string GUARDIAN_OPS = 'guardian_ops';

    /** @var string Chat situation guardian agent type (monopolistic) */
    public const string CHAT_SITUATION_GUARDIAN = 'chat_situation_guardian';
}
