<?php

declare(strict_types=1);

namespace Demo\Chat\Projection\Page;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Projection\PageProjection;
use Hilos\Core\Projection\Rule\TableRule;
use Hilos\Core\Projection\SubscribeSnapshotAccumulator;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Projects moderator prompt table state for the admin moderator page.
 */
final class AdminModeratorPageProjection extends PageProjection
{
    /**
     * Returns the chat admin moderator page key.
     *
     * @return string Page key for the admin moderator route
     */
    public function page(): string
    {
        return PageConstants::ADMIN_MODERATOR;
    }

    /**
     * Returns the subscribe snapshot signal for the admin moderator page.
     *
     * @return string Signal name for the initial moderator table snapshot
     */
    public function subscribeSnapshotSignalName(): string
    {
        return ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_MODERATOR;
    }

    /**
     * Registers moderator prompt table mutation projection for this page.
     *
     * @return iterable<TableRule>
     */
    protected function rules(): iterable
    {
        yield new TableRule(
            tableKey: ChatTableContext::moderatorPromptPieces,
            triggers: [ChatDbContext::moderatorPromptPieces],
            wireSignalName: ChatSignalConstants::TABLE_MUTATION,
        );
    }

    /**
     * Wraps the moderator prompt table snapshot.
     *
     * @param SubscribeSnapshotAccumulator $accumulator Accumulated table snapshot state
     * @param string $acceptKey Unused subscriber accept key; this snapshot is not connection-local
     * @param PageRouteParams $params Unused route params; this page has no params
     * @return ChatEventSignalDTO Admin moderator subscribe snapshot payload
     */
    protected function wrapSnapshot(
        SubscribeSnapshotAccumulator $accumulator,
        string $acceptKey,
        PageRouteParams $params,
    ): ?SignalDataInterface {
        $snapshot = $accumulator->getTableSnapshot(ChatTableContext::moderatorPromptPieces);
        $tables = $snapshot !== null ? [ChatTableContext::moderatorPromptPieces => $snapshot] : [];

        return new ChatEventSignalDTO(
            entities: new EntitiesChangesDTO(),
            tables: $tables,
        );
    }
}
