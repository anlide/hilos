<?php

declare(strict_types=1);

namespace Hilos\Core\Page\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\WebSocketEnvelopeAware;

/**
 * PageActionSuccessSignalData - the framework reply confirming a tracked page
 * action committed.
 *
 * Sent to the initiating WebSocket connection after AbstractPage::onAction()
 * returns without throwing, when the action carried a client-minted requestId.
 * It carries no domain body — the real state transition is delivered over the
 * page's browser payload; this reply only releases the action's loading state
 * and resolves its request on the client, correlated by the echoed requestId.
 */
final class PageActionSuccessSignalData extends BaseDTO implements SignalDataInterface, WebSocketEnvelopeAware
{
    /**
     * @param string $action Action name that committed
     * @param string $requestId Client-minted request id echoed to correlate the reply
     */
    public function __construct(
        public readonly string $action,
        public readonly string $requestId,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            SignalPayloadConstants::FIELD_REQUEST_ID => $this->requestId,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        return new static(
            action: (string)($data['action'] ?? ''),
            requestId: (string)($data[SignalPayloadConstants::FIELD_REQUEST_ID] ?? ''),
        );
    }

    public function getEnvelopeOutcome(): ?string
    {
        return 'success';
    }

    public function getEnvelopeRequestId(): ?string
    {
        return $this->requestId;
    }

    public function getEnvelopeTime(): ?int
    {
        return null;
    }
}
