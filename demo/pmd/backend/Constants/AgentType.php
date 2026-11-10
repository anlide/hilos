<?php

declare(strict_types=1);

namespace Demo\Pmd\Constants;

use Hilos\Constants\HilosAgentType;

/** Agent type bindings for PMD. */
final class AgentType
{
    public const string PMD = 'pmd';
    public const string HILOS_INDEX = HilosAgentType::HILOS_INDEX;
    public const string HILOS_ANALYTICS = HilosAgentType::HILOS_ANALYTICS;
    public const string HILOS_NOTIFICATIONS_LIBRARY = HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY;
}
