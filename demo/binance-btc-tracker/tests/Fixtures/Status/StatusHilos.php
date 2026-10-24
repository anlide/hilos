<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Tests\Fixtures\Status;

use Demo\BinanceBtcTracker\Hilos;
use Hilos\Constants\HilosAgentType;

/**
 * E2E-only component catalog: the existing isolated settings worker is the fault target.
 * The production catalog, agents, routes and truth-source declarations are inherited intact.
 */
final class StatusHilos extends Hilos
{
    protected const array STATUS_COMPONENTS = [
        'settings' => [
            'name' => 'Settings library',
            'description' => 'The isolated real worker observed by the Status fault scenario.',
            'agentType' => HilosAgentType::HILOS_SETTINGS_LIBRARY,
        ],
    ];
}
