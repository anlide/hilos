<?php

declare(strict_types=1);

namespace Demo\Chat\Frontend;

use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Hilos;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\HilosException;

/**
 * Builds the main chat subscription payload from DB, frontend projections, and socket-local state.
 */
final class MainPageSubscriptionProjector
{
    /**
     * Builds the initial main-page wire payload for one WebSocket connection.
     *
     * @param string $acceptKey Runtime connection key whose session-local fields are included
     * @return ChatEventSignalDTO Main-page subscription signal data
     * @throws HilosException On database, runtime, or truth source failure
     */
    public static function forAcceptKey(string $acceptKey): ChatEventSignalDTO
    {
        return new ChatEventSignalDTO(
            new EntitiesChangesDTO(
                full: [
                    DbChatContext::bots => Hilos::$db->bots->activeOnly,
                    DbChatContext::events => Hilos::$db->events,
                    DbChatContext::users => Hilos::$rt->connections->relevantUsers,
                ],
            ),
            selfConnection: SelfConnectionProjector::forConnection(Hilos::$rt->connections[$acceptKey]),
            frontend: AttachmentDraftFrontendStateProjector::appendFullForConnection(
                BotFrontendStateProjector::appendFullForBots(
                    UserFrontendStateProjector::fullForUsers(
                        Hilos::$rt->connections->relevantUsers,
                        includePublicUsers: false,
                    ),
                    Hilos::$db->bots->activeOnly,
                ),
                Hilos::$rt->connections[$acceptKey],
            ),
        );
    }
}
