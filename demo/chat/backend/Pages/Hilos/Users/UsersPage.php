<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Users;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\DTO\UsersPageResponseSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\DatabaseException;
use Hilos\Pages\Users\AbstractHilosUsersPage;

/**
 * UsersPage - Hilos users list page implementation for demo.
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

    /**
     * Sends the Hilos users scope payload snapshot to the subscribing client.
     *
     * Replaces the legacy browser-table subscription default: the page answers
     * with the page_response signal carrying the read-only users-table
     * snapshot in the scope-payload wire form.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Route params from page subscription
     * @throws DatabaseException When loading the user snapshot fails
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            ChatSignalConstants::PAGE_RESPONSE,
            $acceptKey,
            new UsersPageResponseSignalData(self::PAGE, Hilos::$db->users->snapshotRows()),
        );
    }
}
