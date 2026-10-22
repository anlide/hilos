<?php

declare(strict_types=1);

namespace Hilos\Moderation\Library;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * ModerationRulesLibraryAgentDaemon - daemon proxy for the moderation rules library (HIL-1624).
 *
 * Places {@see ModerationRulesLibraryAgent} as a cluster-wide monopolistic singleton: monopolistic
 * because the rules table has one writer, and a second process writing it would be a second owner.
 * Where that process runs is the placement policy's to say ({@see AgentPlacement::POLICY} in the
 * project's registry entry), and the scope stays unwritten there because CLUSTER is its default.
 */
final class ModerationRulesLibraryAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_MODERATION_RULES_LIBRARY;

    /**
     * The library is the single writer of the moderation rules table.
     *
     * @return bool True because a rule row may be written in one process only
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
