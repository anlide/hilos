<?php

declare(strict_types=1);

namespace Demo\Chat\Guardian\Policies;

final class GuardianPolicySet
{
    /**
     * @return array<string, mixed>
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
     * @return array<string, mixed>
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
