<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosGuardianAgent;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalData;
use Hilos\Pages\DTO\HilosGuardianAgentPageSubscribeParams;
use LogicException;

/**
 * AbstractHilosGuardianAgentPage - Abstract base for Hilos guardian AI agent page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Guardian\GuardianAgentPage).
 * Parses the `agentId` route param into {@see HilosGuardianAgentPageSubscribeParams}
 * before dispatching to {@see self::onHilosGuardianAgentSubscribe()}.
 */
abstract class AbstractHilosGuardianAgentPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_GUARDIAN_AGENT;

    /**
     * Parses route params into {@see HilosGuardianAgentPageSubscribeParams} and delegates to
     * {@see self::onHilosGuardianAgentSubscribe()}. Final: subclasses customize the subscribe
     * behavior by overriding the typed hook, not this method.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Route params for the page subscription
     * @throws MissingPageRouteParamException When `agentId` is absent or empty
     * @throws InvalidPageRouteParamException Reserved for future typed constraints on the id
     */
    final public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->onHilosGuardianAgentSubscribe(
            $acceptKey,
            HilosGuardianAgentPageSubscribeParams::fromPageRouteParams($params),
        );
    }

    /**
     * Handle subscribe for a specific guardian agent.
     *
     * Default sends the current guardian run statuses plus the requested `agentId`
     * echo back to the subscriber. Override when a project needs a richer payload.
     *
     * @param string $acceptKey WebSocket accept key
     * @param HilosGuardianAgentPageSubscribeParams $params Parsed subscribe params
     */
    protected function onHilosGuardianAgentSubscribe(
        string $acceptKey,
        HilosGuardianAgentPageSubscribeParams $params,
    ): void {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_GUARDIAN_AGENT,
            $acceptKey,
            new SignalData([
                'agentId' => $params->agentId,
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
            throw new LogicException('Guardian agent page requires AbstractHilosGuardianAgent.');
        }

        return $this->agent;
    }
}
