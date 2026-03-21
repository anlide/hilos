<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;

/**
 * AbstractHilosGuardianAgentPage - Abstract base for Hilos guardian AI agent page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Guardian\GuardianAgentPage).
 */
abstract class AbstractHilosGuardianAgentPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_GUARDIAN_AGENT;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param array<string, string> $params Page params from route (e.g. ['agentId' => 'static_analysis'])
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $agentId = $params['agentId'] ?? '';

        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_GUARDIAN_AGENT,
            $acceptKey,
            new SignalData([
                'agentId' => $agentId,
            ]),
        );
    }
}
