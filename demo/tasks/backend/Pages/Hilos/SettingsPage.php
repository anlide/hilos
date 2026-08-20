<?php

declare(strict_types=1);

namespace Demo\Tasks\Pages\Hilos;

use Demo\Tasks\Constants\AgentType;
use Hilos\Pages\AbstractHilosSettingsPage;

/**
 * Activates the framework Hilos settings page for the tasks demo.
 *
 * The table, subscribe signal, action DTOs, and the add/update/delete lifecycle
 * are framework-owned ({@see AbstractHilosSettingsPage}); the demo binds only the
 * subscription owner. The catalog is bound to the settings facade (Hilos::$setting).
 */
final class SettingsPage extends AbstractHilosSettingsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
