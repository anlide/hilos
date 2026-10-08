<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Hilos\DaemonNodeAgent;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\Config\PageAgentIndexKey;
use Hilos\Core\Page\Config\PageAgentIndexSource;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Pages\Daemon\DTO\HilosDaemonNodeSubscribeParams;

/** Node environment page served by its node's replica or the section fallback. */
abstract class AbstractHilosDaemonEnvPage extends AbstractHilosDaemonNodePage
{
    public const string PAGE = HilosPageConstants::HILOS_DAEMON_ENV;

    public const PageReach REACH = PageReach::ROUTE;

    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_DAEMON_NODE;

    public const array SUBSCRIPTION_AGENT_INDEX = [
        PageAgentIndexKey::SOURCE => PageAgentIndexSource::NODE_PARAM,
        PageAgentIndexKey::PARAM => HilosPageRouteParams::HILOS_DAEMON_NODE_ID,
        PageAgentIndexKey::FALLBACK_AGENT_TYPE => HilosAgentType::HILOS_DAEMON,
    ];

    public const string ENVIRONMENT = 'environment';
    public const string NODE_SILENT = 'nodeSilent';

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_DAEMON_ENV,
    ];

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route params naming the node
     * @return PagePayload Complete node environment or a silent-node answer
     * @throws MissingPageRouteParamException When the node id is absent
     * @throws EnvException When cluster mode cannot be read
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        $nodeId = HilosDaemonNodeSubscribeParams::fromPageRouteParams($params)->nodeId;
        $agent = $this->getAgent();
        $reading = $agent instanceof DaemonNodeAgent ? $agent->environmentReading() : null;
        $ownerAnswers = $reading !== null && $reading->nodeId === $nodeId;

        return new PagePayload(data: [
            self::ENVIRONMENT => $ownerAnswers
                ? $reading->view(Hilos::$browser?->isAdminViewModeViewer(static::class, $acceptKey) === true,
                    Hilos::$cluster?->isEnabled() === true)
                : null,
            self::NODE_SILENT => !$ownerAnswers,
        ]);
    }

    /** @return array<string, WireField> Values are already masked by the node owner */
    protected function dataFields(): array
    {
        return [
            self::ENVIRONMENT => WireField::notPersonal(),
            self::NODE_SILENT => WireField::notPersonal(),
        ];
    }

    /** The node owner serves this page; it does not watch the section mirror. */
    protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
    }

    /** This page has no section-mirror viewer lease to release. */
    public function onUnsubscribe(string $acceptKey): void
    {
    }
}
