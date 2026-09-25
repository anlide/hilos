<?php

declare(strict_types=1);

namespace Hilos\Pages\Maintenance;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;

/**
 * AbstractHilosMaintenancePage - Abstract base for the Hilos maintenance section (HIL-1119).
 *
 * The first admin section with no on-switch: the freeze under it is core and unconditional, so a
 * project activates the section by registering its page and binding {@see HilosVerifierCircleTable}
 * to it in the topology, with no line in FEATURES (docs/agents/architecture/admin-features.md).
 *
 * The page declares no actions and no signals of its own. Its one block today is the verifier
 * circle, and the circle's window rides the page response by itself because the table is bound
 * to the page. The page is served by the hilos index agent ({@see AbstractHilosIndexAgent}),
 * which is also the one that owns the circle.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Maintenance\MaintenancePage).
 */
abstract class AbstractHilosMaintenancePage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_MAINTENANCE;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MAINTENANCE,
    ];
}
