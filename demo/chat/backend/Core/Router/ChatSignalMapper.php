<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router;

use Demo\Chat\Constants\ChatSignalConstants;
use Hilos\Core\Router\DTO\EmitDbChangeSignalData;
use Hilos\Core\Router\DTO\EmitFanoutItem;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\EmitFanoutDelivery;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalMapperInterface;

/**
 * Maps EMIT_* signals to concrete WebSocket payloads for the chat demo.
 *
 * {@see ChatSignalConstants::EMIT_CHAT_USER_ROW_UPDATED}: one {@see ChatSignalConstants::TABLE_MUTATION}
 * broadcast to every subscribed client except the initiator.
 * The mapper preserves the source table key embedded in {@see EmitDbChangeSignalData},
 * so user-related emits can target `adminUsers` or `hilosUsers` independently.
 *
 * When payloads differ per page, use {@see SignalRouter::getAcceptKeysForPage} and extra {@see EmitFanoutItem}
 * with {@see EmitFanoutDelivery::Single}, deduping against clients that already received the broadcast leg.
 */
final class ChatSignalMapper implements SignalMapperInterface
{
    public function mapDbEmit(SignalDTO $emit): array
    {
        $data = $emit->data;
        if (!$data instanceof EmitDbChangeSignalData) {
            return [];
        }

        return match ($emit->signalName->getName()) {
            ChatSignalConstants::EMIT_CHAT_USER_ROW_UPDATED => $this->mapChatUserRowUpdated($data),
            default => [],
        };
    }

    public function mapRtEmit(SignalDTO $emit): array
    {
        return [];
    }

    /**
     * @return list<EmitFanoutItem>
     */
    private function mapChatUserRowUpdated(EmitDbChangeSignalData $data): array
    {
        $tableMutation = $data->toTableMutationSignalData();

        return [
            new EmitFanoutItem(
                delivery: EmitFanoutDelivery::AllExcept,
                wireSignalName: ChatSignalConstants::TABLE_MUTATION,
                innerPayload: $tableMutation,
                excludeAcceptKey: $data->excludeAcceptKey,
            ),
        ];
    }
}
