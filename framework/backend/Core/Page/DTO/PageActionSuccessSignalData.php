<?php

declare(strict_types=1);

namespace Hilos\Core\Page\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
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
 * when absent the frontend shows no success toast at all. It may also
 * carry an optional domain $reply (the array form of the ActionReplyDTO the
 * handler returned) that resolves the caller's request with typed data; the
 * frame stays flat and the reply is absent entirely when the handler returned
 * null.
 */
final class PageActionSuccessSignalData extends BaseDTO implements SignalDataInterface, WebSocketEnvelopeAware
{
    /**
     * @param string $action Action name that committed
     * @param string $requestId Client-minted request id echoed to correlate the reply
     * @param ?string $message Backend-authored success sentence for the frontend toast, or null for no success toast
     * @param ?array<string, mixed> $reply Domain reply payload the handler returned, or null when the action answered with nothing
     */
    public function __construct(
        public readonly string $action,
        public readonly string $requestId,
        public readonly ?string $message = null,
        public readonly ?array $reply = null,
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
        if ($this->reply !== null) {
            $data[SignalPayloadConstants::FIELD_REPLY] = $this->reply;
        }

        return $data;
    }

    /**
     * The reply is what correlates the confirmation with the request that is
     * waiting for it, so a frame carrying neither the action nor the request id
     * confirms nothing and is refused. The sentence and the domain reply are the
     * two {@see self::toArray()} omits when absent.
     *
     * @param array<string, mixed> $data
     * @throws InvalidFormatException When the action or the request id is missing, or an optional field is of another type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            action: self::requireString($data, SignalPayloadConstants::FIELD_ACTION),
            requestId: self::requireString($data, SignalPayloadConstants::FIELD_REQUEST_ID),
            message: self::optionalString($data, SignalPayloadConstants::FIELD_MESSAGE),
            reply: self::optionalArray($data, SignalPayloadConstants::FIELD_REPLY),
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
