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

final class AdminUsersPageProjection extends PageProjection
{
    public function page(): string
    {
        return PageConstants::ADMIN_USERS;
    }

    public function subscribeSnapshotSignalName(): string
    {
        return ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_USERS;
    }

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
