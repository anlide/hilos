<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router;

use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Core\Router\DTO\UserPresenceEmitPayload;
use Demo\Chat\Core\Router\DTO\UserPresenceSignalData;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\View\Collection\Events;
use Demo\Chat\Frontend\BotFrontendStateProjector;
use Demo\Chat\Frontend\UserFrontendStateProjector;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\BotAgentStatus as StateBotAgentStatus;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\EmitDbChangeSignalData;
use Hilos\Core\Router\DTO\EmitFanoutItem;
use Hilos\Core\Router\DTO\EmitRtChangeSignalData;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\DTO\FrontendChangesDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\EmitFanoutDelivery;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalMapperInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;

/**
 * Maps EMIT_* signals to concrete WebSocket payloads for the chat demo.
 *
 * {@see ChatSignalConstants::EMIT_CHAT_USER_ROW_UPDATED}: one pending table
 * mutation per table declared in {@see SignalRouter::getTableKeysForEvent()},
 * broadcast to every subscribed client except the initiator.
 *
 * When payloads differ per page, use {@see SignalRouter::getAcceptKeysForPage} and extra {@see EmitFanoutItem}
 * with {@see EmitFanoutDelivery::Single}, deduping against clients that already received the broadcast leg.
 */
final class ChatSignalMapper implements SignalMapperInterface
{
    /**
     * Creates a chat signal mapper with optional test doubles for routing and table context.
     *
     * @param ?SignalRouter $router Router used to resolve table event routes
     * @param ?TableContext $tableContext Table context used to build routed table mutation payloads
     */
    public function __construct(
        private readonly ?SignalRouter $router = null,
        private readonly ?TableContext $tableContext = null,
    ) {
    }

    /**
     * Maps DB emit signals to WebSocket fan-out payloads.
     *
     * @param SignalDTO $emit Internal DB emit signal
     * @return list<EmitFanoutItem>
     */
    public function mapDbEmit(SignalDTO $emit): array
    {
        $data = $emit->data;
        if (!$data instanceof EmitDbChangeSignalData) {
            return [];
        }

        return match ($emit->signalName->getName()) {
            ChatSignalConstants::EMIT_CHAT_EVENT_CREATED => $this->mapChatEventCreated($data),
            ChatSignalConstants::EMIT_CHAT_EVENTS_REPLACED => $this->mapChatEventCreated($data, replaceEvents: true),
            ChatSignalConstants::EMIT_CHAT_USER_ROW_UPDATED,
            ChatSignalConstants::EMIT_CHAT_BOT_ROW_CHANGED,
            ChatSignalConstants::EMIT_CHAT_MODERATOR_PROMPT_PIECE_ROW_CHANGED,
            ChatSignalConstants::EMIT_CHAT_SETTING_ROW_CHANGED => $this->mapTableMutationPending(
                $emit->signalName->getName(),
                $data,
            ),
            ChatSignalConstants::EMIT_CHAT_BOT_FRONTEND_UPDATED => $this->mapChatBotFrontendUpdated($data),
            default => [],
        };
    }

    /**
     * Maps runtime emit signals to WebSocket fan-out payloads.
     *
     * @param SignalDTO $emit Internal runtime emit signal
     * @return list<EmitFanoutItem>
     */
    public function mapRtEmit(SignalDTO $emit): array
    {
        $data = $emit->data;
        if (!$data instanceof EmitRtChangeSignalData) {
            return [];
        }

        return match ($emit->signalName->getName()) {
            ChatSignalConstants::EMIT_CHAT_USER_PRESENCE_UPDATED => $this->mapChatUserPresenceUpdated($data),
            ChatSignalConstants::EMIT_CHAT_BOT_AGENT_STATUS_UPDATED => $this->mapChatBotAgentStatusUpdated($data),
            HilosSignalConstants::EMIT_HILOS_GUARDIAN_AGENT_STATUS_UPDATED => $this->mapGuardianAgentStatusUpdated($data),
            default => [],
        };
    }

    /**
     * Builds a chat event fan-out from the persisted event row.
     *
     * @param EmitDbChangeSignalData $data Source event and delivery metadata from the internal emit signal
     * @param bool $replaceEvents Whether the event collection should be replaced on the frontend
     * @return list<EmitFanoutItem>
     * @throws ObjectGetIdStringNotImplementedException
     * @throws CollectionNotManualException
     */
    private function mapChatEventCreated(EmitDbChangeSignalData $data, bool $replaceEvents = false): array
    {
        if ($data->sourceEvent->sourceKey !== DbChatContext::events || Hilos::$db === null) {
            return [];
        }

        $eventId = (int)$data->sourceEvent->sourceRowKey;
        if ($eventId <= 0) {
            return [];
        }

        $event = Hilos::$db->events[$eventId] ?? null;
        if ($event === null) {
            return [];
        }

        return [
            new EmitFanoutItem(
                delivery: EmitFanoutDelivery::AllExcept,
                wireSignalName: ChatSignalConstants::NEW_EVENT,
                innerPayload: new ChatEventSignalDTO(
                    new EntitiesChangesDTO(
                        full: [DbChatContext::events => Events::fromSingleItem($event)],
                        replaceFullKeys: $replaceEvents ? [DbChatContext::events] : [],
                    ),
                    frontend: $this->frontendUpdatesForEventUser($event->type, $event->targetUserId),
                ),
                excludeAcceptKey: $data->excludeAcceptKey,
            ),
        ];
    }

    /**
     * Builds pending table mutation fan-out for a DB source event.
     *
     * @param string $eventKey Logical chat event name resolved through table routes
     * @param EmitDbChangeSignalData $data Source event and delivery metadata from the internal emit signal
     * @return list<EmitFanoutItem>
     */
    private function mapTableMutationPending(string $eventKey, EmitDbChangeSignalData $data): array
    {
        $router = $this->router ?? Hilos::$sr;
        $tableContext = $this->tableContext ?? Hilos::$table;
        if ($router === null || $tableContext === null) {
            return [];
        }

        $tableSignals = $tableContext->buildMutationSignalsForSourceEvent(
            $data->sourceEvent,
            $router->getTableKeysForEvent($eventKey),
        );

        $items = [];
        foreach ($tableSignals as $tableSignal) {
            $items[] = new EmitFanoutItem(
                delivery: EmitFanoutDelivery::AllExcept,
                wireSignalName: ChatSignalConstants::TABLE_MUTATION_PENDING,
                innerPayload: $tableSignal,
                excludeAcceptKey: $data->excludeAcceptKey,
            );
        }

        return $items;
    }

    /**
     * Builds the main-chat bot entity update fan-out from the persisted bot row.
     *
     * @param EmitDbChangeSignalData $data Source bot row event
     * @return list<EmitFanoutItem>
     */
    private function mapChatBotFrontendUpdated(EmitDbChangeSignalData $data): array
    {
        if ($data->sourceEvent->sourceKey !== DbChatContext::bots || Hilos::$db === null) {
            return [];
        }

        $botId = (int)$data->sourceEvent->sourceRowKey;
        if ($botId <= 0) {
            return [];
        }

        $bot = Hilos::$db->bots[$botId] ?? null;
        if ($bot === null) {
            return [];
        }

        return [
            new EmitFanoutItem(
                delivery: EmitFanoutDelivery::AllExcept,
                wireSignalName: ChatSignalConstants::BOT_UPDATED,
                innerPayload: new ChatEventSignalDTO(new EntitiesChangesDTO(updates: [
                    DbChatContext::bots => [$bot->toArray(toFrontend: true)],
                ])),
                excludeAcceptKey: $data->excludeAcceptKey,
            ),
        ];
    }

    /**
     * Builds page-scoped user presence fan-out from the daemon-side subscription registry.
     *
     * @param EmitRtChangeSignalData $data Runtime emit payload from BotAgent
     * @return list<EmitFanoutItem>
     */
    private function mapChatUserPresenceUpdated(EmitRtChangeSignalData $data): array
    {
        if ($data->collectionKey !== RtChatContext::connections) {
            return [];
        }

        $router = $this->router ?? Hilos::$sr;
        if ($router === null) {
            return [];
        }

        $payload = UserPresenceEmitPayload::fromArray($data->payload);
        if ($payload->userId <= 0) {
            return [];
        }

        $items = [];
        $this->appendPresenceUpdatesForPageSubscribers(
            $items,
            $router,
            PageConstants::MAIN,
            $payload->frontend(),
            $data->excludeAcceptKey,
        );
        $this->appendPresenceUpdatesForPageSubscribers(
            $items,
            $router,
            PageConstants::ADMIN_USERS,
            $payload->statsFrontend(),
            $data->excludeAcceptKey,
        );
        $this->appendPresenceUpdatesForPageSubscribers(
            $items,
            $router,
            HilosPageConstants::HILOS_USERS,
            $payload->statsFrontend(),
            $data->excludeAcceptKey,
        );
        $this->appendPresenceUpdatesForPageSubscribers(
            $items,
            $router,
            HilosPageConstants::HILOS_USER,
            $payload->statsFrontend(),
            $data->excludeAcceptKey,
            HilosPageRouteParams::HILOS_USER_USER_ID,
            (string) $payload->userId,
        );

        return $items;
    }

    /**
     * Builds bot lifecycle fan-out from runtime bot status changes.
     *
     * @param EmitRtChangeSignalData $data Runtime emit payload from ChatAgent
     * @return list<EmitFanoutItem>
     */
    private function mapChatBotAgentStatusUpdated(EmitRtChangeSignalData $data): array
    {
        if ($data->collectionKey !== RtChatContext::botAgentStatuses) {
            return [];
        }

        $status = (string)($data->payload[StateBotAgentStatus::status] ?? '');
        $wireSignalName = match ($status) {
            StateBotAgentStatus::STATUS_JOINED => ChatSignalConstants::BOT_JOINED,
            StateBotAgentStatus::STATUS_LEFT => ChatSignalConstants::BOT_LEFT,
            default => null,
        };
        if ($wireSignalName === null) {
            return [];
        }

        $botId = (int)$data->stateId;
        $entities = new EntitiesChangesDTO();
        if ($botId > 0 && Hilos::$db !== null) {
            $bot = Hilos::$db->bots[$botId] ?? null;
            if ($bot !== null) {
                $entities = new EntitiesChangesDTO(updates: [
                    DbChatContext::bots => [$bot->toArray(toFrontend: true)],
                ]);
            }
        }

        return [
            new EmitFanoutItem(
                delivery: EmitFanoutDelivery::AllExcept,
                wireSignalName: $wireSignalName,
                innerPayload: new ChatEventSignalDTO(
                    $entities,
                    frontend: BotFrontendStateProjector::updatesForBotStatus($botId, $status),
                ),
                excludeAcceptKey: $data->excludeAcceptKey,
            ),
        ];
    }

    /**
     * Builds Hilos guardian status fan-out from runtime guardian status changes.
     *
     * @param EmitRtChangeSignalData $data Runtime emit payload from the guardian agent
     * @return list<EmitFanoutItem>
     */
    private function mapGuardianAgentStatusUpdated(EmitRtChangeSignalData $data): array
    {
        if ($data->collectionKey !== RtChatContext::guardianAgentStatuses) {
            return [];
        }

        $agentId = (string)($data->payload['agentId'] ?? $data->stateId);
        $status = (string)($data->payload['status'] ?? '');
        if ($agentId === '' || $status === '') {
            return [];
        }

        return [
            new EmitFanoutItem(
                delivery: EmitFanoutDelivery::AllExcept,
                wireSignalName: HilosSignalConstants::GUARDIAN_AGENT_STATUS_UPDATE,
                innerPayload: new SignalData([
                    'agentId' => $agentId,
                    'status' => $status,
                ]),
                excludeAcceptKey: $data->excludeAcceptKey,
            ),
        ];
    }

    /**
     * Builds user frontend updates for chat event types that change public user state.
     *
     * @param string $eventType Chat event type
     * @param ?int $userId Event user id
     * @return ?FrontendChangesDTO Frontend state update, or null when the event does not affect user frontend state
     */
    private function frontendUpdatesForEventUser(string $eventType, ?int $userId): ?FrontendChangesDTO
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

    /**
     * Appends one single-target user presence message per matching page subscriber.
     *
     * @param list<EmitFanoutItem> $items Fan-out items being assembled
     * @param SignalRouter $router Daemon-side router with current subscriptions
     * @param string $page Page contract key
     * @param FrontendChangesDTO $frontend Frontend state update for this page contract
     * @param ?string $excludeAcceptKey Optional connection to skip
     * @param ?string $paramKey Optional route param filter key
     * @param ?string $paramValue Optional route param filter value
     */
    private function appendPresenceUpdatesForPageSubscribers(
        array &$items,
        SignalRouter $router,
        string $page,
        FrontendChangesDTO $frontend,
        ?string $excludeAcceptKey,
        ?string $paramKey = null,
        ?string $paramValue = null,
    ): void {
        foreach ($router->getAcceptKeysForPage($page, $paramKey, $paramValue) as $targetAcceptKey) {
            if ($targetAcceptKey === $excludeAcceptKey) {
                continue;
            }

            $items[] = new EmitFanoutItem(
                delivery: EmitFanoutDelivery::Single,
                wireSignalName: ChatSignalConstants::USER_PRESENCE_UPDATE,
                innerPayload: UserPresenceSignalData::fromFrontendChanges($frontend),
                targetAcceptKey: $targetAcceptKey,
            );
        }
    }
}
