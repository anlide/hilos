<?php

declare(strict_types=1);

namespace Demo\Chat\Projection\Page;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Frontend\UserFrontendStateProjector;
use Demo\Chat\Hilos;
use Demo\Chat\Projection\Util\UserPresenceDeliveryBuilder;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Demo\Chat\Tables\TableChatContext;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Projection\PageProjection;
use Hilos\Core\Projection\ProjectionDelivery;
use Hilos\Core\Projection\Rule\JoinedProjectionRule;
use Hilos\Core\Projection\Rule\TableRule;
use Hilos\Core\Projection\SourceChange;
use Hilos\Core\Projection\SubscribeSnapshotAccumulator;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\DTO\FrontendChangesDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Projects user table and presence frontend state for the admin users page.
 */
final class AdminUsersPageProjection extends PageProjection
{
    /**
     * Returns the chat admin users page key.
     *
     * @return string Page key for the admin users route
     */
    public function page(): string
    {
        return PageConstants::ADMIN_USERS;
    }

    /**
     * Returns the subscribe snapshot signal for the admin users page.
     *
     * @return string Signal name for the initial admin users snapshot
     */
    public function subscribeSnapshotSignalName(): string
    {
        return ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_USERS;
    }

    /**
     * Registers admin user table mutations and presence update deliveries.
     *
     * @return iterable<TableRule|JoinedProjectionRule>
     */
    protected function rules(): iterable
    {
        yield new TableRule(
            tableKey: TableChatContext::adminUsers,
            triggers: [DbChatContext::users, RtChatContext::connections],
            wireSignalName: ChatSignalConstants::TABLE_MUTATION,
        );

        yield new JoinedProjectionRule(
            triggers: [RtChatContext::connections],
            snapshotChanges: fn(): FrontendChangesDTO => new FrontendChangesDTO(),
            broadcast: function (SourceChange $change, array $audienceAcceptKeys): iterable {
                $payload = UserPresenceDeliveryBuilder::buildForConnectionChange(
                    change: $change,
                    includeConnectionStats: true,
                );
                if ($payload === null) {
                    return;
                }
                foreach (array_values(array_unique($audienceAcceptKeys)) as $acceptKey) {
                    if ($acceptKey === '') {
                        continue;
                    }
                    yield new ProjectionDelivery(
                        ChatSignalConstants::USER_PRESENCE_UPDATE,
                        $payload,
                        $acceptKey,
                    );
                }
            },
        );
    }

    /**
     * Wraps the admin users table snapshot and full user frontend state.
     *
     * @param SubscribeSnapshotAccumulator $accumulator Accumulated table snapshot state
     * @param string $acceptKey Unused subscriber accept key; this snapshot is not connection-local
     * @param PageRouteParams $params Unused route params; this page has no params
     * @return ChatEventSignalDTO Admin users subscribe snapshot payload
     */
    protected function wrapSnapshot(
        SubscribeSnapshotAccumulator $accumulator,
        string $acceptKey,
        PageRouteParams $params,
    ): ?SignalDataInterface {
        $snapshot = $accumulator->getTableSnapshot(TableChatContext::adminUsers);
        $tables = $snapshot !== null ? [TableChatContext::adminUsers => $snapshot] : [];
        $frontend = Hilos::$db !== null
            ? UserFrontendStateProjector::fullForUsers(Hilos::$db->users, includeConnectionStats: true)
            : new FrontendChangesDTO();

        return new ChatEventSignalDTO(
            entities: new EntitiesChangesDTO(),
            tables: $tables,
            frontend: $frontend,
        );
    }
}
