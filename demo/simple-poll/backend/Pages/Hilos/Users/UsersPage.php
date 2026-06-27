<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Pages\Hilos\Users;

use Demo\SimplePoll\Constants\AgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Pages\Users\AbstractHilosUsersPage;

/**
 * UsersPage - simple-poll Hilos users list page.
 *
 * Browser subscription emits the Hilos users table rows and incremental row
 * changes through the page browser payload.
 */
final class UsersPage extends AbstractHilosUsersPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_USERS,
    ];
}
