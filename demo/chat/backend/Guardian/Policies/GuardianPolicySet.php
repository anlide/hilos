<?php

declare(strict_types=1);

namespace Demo\Chat\Guardian\Policies;

/**
 * GuardianPolicySet - Default policy configurations for guardian agents.
 */
final class GuardianPolicySet
{
    /**
     * Default policy for ops guardian agent.
     *
     * @return array<string, mixed> Policy config (name, cooldownSec, allowedCategories)
     */
    public static function opsPolicy(): array
    {
        return [
            'name' => 'ops_default',
            'cooldownSec' => 10,
            'allowedCategories' => ['logs', 'security'],
        ];
    }

    /**
     * Default policy for chat situation guardian agent.
     *
     * @return array<string, mixed> Policy config (name, cooldownSec, allowedCategories)
     */
    public static function chatSituationPolicy(): array
    {
        return [
            'name' => 'chat_situation_default',
            'cooldownSec' => 10,
            'allowedCategories' => ['business'],
        ];
    }
}
