<?php

declare(strict_types=1);

namespace Hilos\Pages\Maintenance;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/** Cluster-only maintenance reports, delivered by the ordinary page and table subscription. */
abstract class AbstractHilosMaintenanceNodesPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_MAINTENANCE_NODES;
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_INDEX;
    public const PageReach REACH = PageReach::ROUTE;
    public const array ACTIONS = [];
    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MAINTENANCE,
    ];

    /**
     * @param PageRouteParams $ownerParams Maintenance route parameters, unused
     * @return bool Whether this installation has cluster mode enabled
     */
    public static function isTabApplicable(PageRouteParams $ownerParams): bool
    {
        try {
            return Hilos::$cluster?->isEnabled() === true;
        } catch (EnvException) {
            return false;
        }
    }

    /**
     * @param string $acceptKey Subscribing connection, unused
     * @param PageRouteParams $params Maintenance route parameters
     * @throws PageResourceNotFoundException When this installation is standalone
     */
    protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        if (!self::isTabApplicable($params)) {
            throw new PageResourceNotFoundException('Cluster nodes are unavailable on this installation');
        }
    }
}
