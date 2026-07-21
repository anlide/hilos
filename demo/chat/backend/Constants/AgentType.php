<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

use Hilos\Constants\HilosAgentType;

/**
 * AgentType - Agent type constants for chat demo.
 *
 * Defines agent type identifiers used in the chat demo project.
 * Hilos-level agent types are inherited from HilosAgentType.
 */
final class AgentType
{
    /** @var string Chat agent type (monopolistic) */
    public const string CHAT = 'chat';

    /** @var string Library agent type (monopolistic) */
    public const string LIBRARY = 'library';

    /** @var string User agent type (regular) */
    public const string SESSION = 'session';

    /** @var string Bot agent type (regular) */
    public const string BOT = 'bot';

    /** @var string Moderator agent type (regular) */
    public const string MODERATOR = 'moderator';

    /** @var string Chat context analyzer agent type (monopolistic) */
    public const string CHAT_CONTEXT_ANALYZER = 'chat_context_analyzer';

    /** @var string Hilos index agent type (dashboard, settings, i18n) */
    public const string HILOS_INDEX = HilosAgentType::HILOS_INDEX;

    /** @var string Hilos guardian agent type */
    public const string HILOS_GUARDIAN = HilosAgentType::HILOS_GUARDIAN;

    /** @var string Hilos analytics agent type */
    public const string HILOS_ANALYTICS = HilosAgentType::HILOS_ANALYTICS;

    /** @var string Hilos logs overview agent type */
    public const string HILOS_LOGS = HilosAgentType::HILOS_LOGS;

    /** @var string Hilos backup agent type (monopolistic) */
    public const string HILOS_BACKUP = HilosAgentType::HILOS_BACKUP;

    /** @var string Hilos OAuth login agent type (monopolistic, leader-pinned) */
    public const string HILOS_OAUTH = HilosAgentType::HILOS_OAUTH;
}
