<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Pages\Hilos\Users;

use Demo\RobloxGameServer\Browser\RobloxGameServerBrowserRef;
use Demo\RobloxGameServer\Browser\RobloxGameServerBrowserSource;
use Demo\RobloxGameServer\Constants\AgentType;
use Demo\RobloxGameServer\Database\RobloxGameServerDbContext;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserSubscriptionError;
use Hilos\Pages\Users\AbstractHilosUserPage;

/**
 * This demo's implementation of the Hilos user-detail page.
 *
 * Subscription snapshots are browser-config driven. The rename submitted on the card is
 * forwarded by {@see AbstractHilosUserPage} to the framework's users library, which writes the
 * name, its journal row and the notice to the renamed person (HIL-1195).
 */
final class UserPage extends AbstractHilosUserPage
{
    /** @var list<string> The person this page is about, and the sessions its sessions tile reads */
    public const array READS_DB = [RobloxGameServerDbContext::users, RobloxGameServerDbContext::sessions];

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_USER,
        BrowserConfigKey::PARAMS => [
            HilosPageRouteParams::HILOS_USER_USER_ID => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserConfigKey::GUARDS => [
            [
                BrowserGuardKey::TYPE => BrowserGuardType::DB_EXISTS,
                BrowserGuardKey::SOURCE => RobloxGameServerBrowserSource::DB_USERS,
                BrowserGuardKey::KEY => RobloxGameServerBrowserRef::HILOS_USER_ID,
                BrowserGuardKey::ERROR => BrowserSubscriptionError::NOT_FOUND,
            ],
        ],
    ];
}
