<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\DTO\UserPresenceEmitPayload;
use Demo\Chat\Core\Router\DTO\UserPresenceSignalData;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Router\DTO\EmitDbChangeSignalData;
use Hilos\Core\Router\DTO\EmitFanoutItem;
use Hilos\Core\Router\DTO\EmitRtChangeSignalData;
use Hilos\Core\Router\DTO\FrontendChangesDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\EmitFanoutDelivery;
use Hilos\Core\Router\SignalMapperInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Table\Context\TableContext;

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
            ChatSignalConstants::EMIT_CHAT_USER_ROW_UPDATED => $this->mapChatUserRowUpdated(
                $emit->signalName->getName(),
                $data,
            ),
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
            default => [],
        };
    }

    /**
     * Builds pending table mutation fan-out for a chat user row event.
     *
     * @param string $eventKey Logical chat event name resolved through table routes
     * @param EmitDbChangeSignalData $data Source event and delivery metadata from the internal emit signal
     * @return list<EmitFanoutItem>
     */
    private function mapChatUserRowUpdated(string $eventKey, EmitDbChangeSignalData $data): array
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
     * Builds page-scoped user presence fan-out from the daemon-side subscription registry.
     *
     * @param EmitRtChangeSignalData $data Runtime emit payload from ChatAgent
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
