<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos\Users;

use Demo\Polls\Database\PollsDbContext;
use Demo\Polls\Browser\PollsBrowserRef;
use Demo\Polls\Browser\PollsBrowserSource;
use Demo\Polls\Constants\AgentType;
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
 * Polls implementation of the Hilos user-detail page.
 *
 * Subscription snapshots are browser-config driven. The rename submitted on the card is
 * forwarded by {@see AbstractHilosUserPage} to the framework's users library, which writes the
 * name, its journal row and the notice to the renamed person (HIL-1195).
 */
final class UserPage extends AbstractHilosUserPage
{
    /** @var list<string> The person this page is about */
    public const array READS_DB = [PollsDbContext::users];

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
                BrowserGuardKey::SOURCE => PollsBrowserSource::DB_USERS,
                BrowserGuardKey::KEY => PollsBrowserRef::HILOS_USER_ID,
                BrowserGuardKey::ERROR => BrowserSubscriptionError::NOT_FOUND,
            ],
        ],
    ];
}
