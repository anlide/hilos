<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Operations;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Operations\AbstractHilosOperationsPage;

/**
 * OperationsPage - Hilos maintenance operations page implementation for demo.
 */
final class OperationsPage extends AbstractHilosOperationsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
