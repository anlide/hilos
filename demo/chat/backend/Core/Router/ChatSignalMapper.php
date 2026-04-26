<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Hilos;
use Hilos\Core\Router\DTO\EmitDbChangeSignalData;
use Hilos\Core\Router\DTO\EmitFanoutItem;
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
        return [];
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
}
