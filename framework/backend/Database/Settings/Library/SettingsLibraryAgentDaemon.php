<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Library;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * SettingsLibraryAgentDaemon - daemon proxy for the settings library (HIL-946).
 *
 * Places {@see SettingsLibraryAgent} as a cluster-wide monopolistic singleton: monopolistic
 * because the settings collection has one writer and a second process writing it would be the
 * very state this leaf was opened to end. Where that one process runs is the placement policy's
 * to say ({@see AgentPlacement::POLICY} in a project's registry entry) and not the leader's,
 * because an entity library is placed rather than pinned - the rule is
 * docs/agents/architecture/entity-libraries.md, "Placement Is Two Axes, Not One Flag". Neither
 * axis is this daemon's to answer: both belong to that entry, where the scope one stays
 * unwritten because CLUSTER is already its default.
 *
 * Concrete where the other libraries are abstract, and registered under
 * {@see HilosAgentType::HILOS_SETTINGS_LIBRARY} without a subclass: what makes those abstract is
 * a project hook inside them, and settings have none - the collection is declared
 * unconditionally, the catalog comes off the facade, and the table is the framework's.
 */
final class SettingsLibraryAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_SETTINGS_LIBRARY;

    /**
     * The library is the single writer of the settings collection.
     *
     * @return bool True because a settings row may be written in one process only
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
