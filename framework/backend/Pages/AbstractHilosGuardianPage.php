<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosGuardianAgent;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;
use LogicException;

/**
 * AbstractHilosGuardianPage - Abstract base for Hilos guardian page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\GuardianPage).
 */
abstract class AbstractHilosGuardianPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_GUARDIAN;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param array<string, string> $params Page params from route (e.g. ['id' => '123'])
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_GUARDIAN,
            $acceptKey,
            new SignalData([
                'guardianAgentStatuses' => $this->getGuardianAgent()->getGuardianRunStatuses(),
            ]),
        );
    }

    /**
     * Get the guardian agent backing this page.
     *
     * @return AbstractHilosGuardianAgent Guardian agent instance
     */
    protected function getGuardianAgent(): AbstractHilosGuardianAgent
    {
        if (!$this->agent instanceof AbstractHilosGuardianAgent) {
            throw new LogicException('Guardian page requires AbstractHilosGuardianAgent.');
        }

        return $this->agent;
    }
}
