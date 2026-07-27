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
 * The real state transition is delivered over the page's browser payload; this
 * reply releases the action's loading state and resolves its request on the
 * client, correlated by the echoed requestId. It may also carry an optional
 * backend-authored outcome sentence ($message) that the frontend surfaces as a
 * success toast — the domain text lives on the backend because Hilos i18n does;
 * when absent the frontend falls back to a generic client string.
 */
final class PageActionSuccessSignalData extends BaseDTO implements SignalDataInterface, WebSocketEnvelopeAware
{
    /**
     * @param string $action Action name that committed
     * @param string $requestId Client-minted request id echoed to correlate the reply
     * @param ?string $message Backend-authored success sentence for the frontend toast, or null for the generic fallback
     */
    public function __construct(
        public readonly string $action,
        public readonly string $requestId,
        public readonly ?string $message = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            SignalPayloadConstants::FIELD_ACTION => $this->action,
            SignalPayloadConstants::FIELD_REQUEST_ID => $this->requestId,
        ];
        if ($this->message !== null) {
            $data[SignalPayloadConstants::FIELD_MESSAGE] = $this->message;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        $message = $data[SignalPayloadConstants::FIELD_MESSAGE] ?? null;

        return new static(
            action: (string)($data[SignalPayloadConstants::FIELD_ACTION] ?? ''),
            requestId: (string)($data[SignalPayloadConstants::FIELD_REQUEST_ID] ?? ''),
            message: $message !== null ? (string)$message : null,
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
