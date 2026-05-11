<?php

declare(strict_types=1);

namespace Demo\Chat\Projection\Page;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Frontend\BotFrontendStateProjector;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\TableChatContext;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Projection\PageProjection;
use Hilos\Core\Projection\Rule\TableRule;
use Hilos\Core\Projection\SubscribeSnapshotAccumulator;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\DTO\FrontendChangesDTO;
use Hilos\Core\Router\SignalDataInterface;

final class AdminBotsPageProjection extends PageProjection
{
    public function page(): string
    {
        return PageConstants::ADMIN_BOTS;
    }

    public function subscribeSnapshotSignalName(): string
    {
        return ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_BOTS;
    }

    protected function rules(): iterable
    {
        yield new TableRule(
            tableKey: TableChatContext::bots,
            triggers: [DbChatContext::bots],
            wireSignalName: ChatSignalConstants::TABLE_MUTATION,
        );
    }

    protected function wrapSnapshot(
        SubscribeSnapshotAccumulator $accumulator,
        string $acceptKey,
        PageRouteParams $params,
    ): ?SignalDataInterface {
        $snapshot = $accumulator->getTableSnapshot(TableChatContext::bots);
        $tables = $snapshot !== null ? [TableChatContext::bots => $snapshot] : [];
        $frontend = Hilos::$db !== null
            ? BotFrontendStateProjector::fullForBots(Hilos::$db->bots)
            : new FrontendChangesDTO();

        return new ChatEventSignalDTO(
            entities: new EntitiesChangesDTO(),
            tables: $tables,
            frontend: $frontend,
        );
    }
}
