<?php

declare(strict_types=1);

namespace Demo\Chat\Projection\Util;

use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\View\Collection\Events;
use Demo\Chat\Frontend\UserFrontendStateProjector;
use Demo\Chat\Hilos;
use Hilos\Core\Projection\ProjectionDelivery;
use Hilos\Core\Projection\SourceChange;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\DTO\FrontendChangesDTO;

/**
 * Builds ChatSignalConstants::NEW_EVENT deliveries for a chat event id, shared
 * by the global events broadcast and the eventAttachments-driven retry.
 */
final class ChatEventBroadcastBuilder
{
    /**
     * Builds addressed new-event deliveries for one persisted chat event.
     *
     * @param int $eventId Persisted chat event id
     * @param list<string> $audienceAcceptKeys Accept keys that should receive the event
     * @return iterable<ProjectionDelivery> New-event deliveries for the non-empty audience
     */
    public static function forEventId(int $eventId, array $audienceAcceptKeys): iterable
    {
        if ($eventId <= 0 || Hilos::$db === null) {
            return;
        }
        $event = Hilos::$db->events[$eventId] ?? null;
        if ($event === null) {
            return;
        }

        $payload = new ChatEventSignalDTO(
            entities: new EntitiesChangesDTO(
                full: [DbChatContext::events => Events::fromSingleItem($event)],
                replaceFullKeys: $event->type === ChatEventType::CHAT_CLEARED->value ? [DbChatContext::events] : [],
            ),
            frontend: self::frontendUpdatesForEventUser(
                $event->type,
                $event->eventUserRegistration?->targetUserId ?? $event->eventUserRename?->targetUserId,
            ),
        );

        foreach (array_values(array_unique($audienceAcceptKeys)) as $acceptKey) {
            if ($acceptKey === '') {
                continue;
            }
            yield new ProjectionDelivery(ChatSignalConstants::NEW_EVENT, $payload, $acceptKey);
        }
    }

    /**
     * Resolves the eventId an attachment change refers to, falling back to the
     * persisted attachment row when the diff did not include `event_id`.
     *
     * @param SourceChange $change Event-attachment source change
     * @return int Referenced event id, or 0 when it cannot be resolved
     */
    public static function eventIdFromAttachmentChange(SourceChange $change): int
    {
        $eventId = (int)($change->row['event_id'] ?? $change->row['eventId'] ?? 0);
        if ($eventId > 0) {
            return $eventId;
        }

        if (Hilos::$db === null) {
            return 0;
        }

        return (int)(Hilos::$db->eventAttachments[(int)$change->sourceId]?->eventId ?? 0);
    }

    /**
     * Builds frontend user updates needed by event types that change user state.
     *
     * @param string $eventType Chat event type value
     * @param ?int $userId User id referenced by the event, if any
     * @return ?FrontendChangesDTO User frontend updates, or null when none are needed
     */
    private static function frontendUpdatesForEventUser(string $eventType, ?int $userId): ?FrontendChangesDTO
    {
        if (
            $userId === null
            || !in_array($eventType, [
                ChatEventType::USER_REGISTERED->value,
                ChatEventType::USER_RENAMED->value,
                ChatEventType::USER_RENAMED_BY_ADMIN->value,
            ], true)
            || Hilos::$db === null
        ) {
            return null;
        }

        $user = Hilos::$db->users[$userId] ?? null;
        if ($user === null) {
            return null;
        }

        return UserFrontendStateProjector::updatesForUser($user, includePublicUser: true);
    }
}
