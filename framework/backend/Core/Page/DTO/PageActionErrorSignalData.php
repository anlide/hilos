<?php

declare(strict_types=1);

namespace Hilos\Core\Page\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\WebSocketEnvelopeAware;

/**
 * PageActionErrorSignalData - Error signal data for page action failures.
 *
 * Sent to the initiating WebSocket connection when AbstractPage::onAction()
 * throws: the framework reply for a tracked action (carrying the `requestId`
 * to correlate it), or the legacy untracked error when the page does not
 * override onActionException() and no requestId was supplied.
 */
class PageActionErrorSignalData extends BaseDTO implements SignalDataInterface, WebSocketEnvelopeAware
{
    /** Wire key for the machine-readable error code (mirrors subscription_page_error). */
    public const string errorCode = 'errorCode';

    /** Wire key for the throttle retry-after hint in seconds (rate_limited failures only). */
    public const string retryAfter = 'retryAfter';

    /**
     * @param string $action Action name that failed
     * @param string $reason Human-readable error message
     * @param ?string $requestId Client-minted request id to correlate the reply, or null for a legacy untracked error
     * @param ?string $errorCode Machine-readable error code (e.g. 'unauthorized'), or null for an unclassified failure
     * @param ?int $retryAfter Seconds to wait before retrying (rate_limited failures), or null when not throttled
     */
    public function __construct(
        public readonly string $action,
        public readonly string $reason,
        public readonly ?string $requestId = null,
        public readonly ?string $errorCode = null,
        public readonly ?int $retryAfter = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'action' => $this->action,
            'reason' => $this->reason,
        ];

        if ($this->requestId !== null) {
            $result[SignalPayloadConstants::FIELD_REQUEST_ID] = $this->requestId;
        }

        if ($this->errorCode !== null) {
            $result[self::errorCode] = $this->errorCode;
        }

        if ($this->retryAfter !== null) {
            $result[self::retryAfter] = $this->retryAfter;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        return new static(
            action: (string)($data['action'] ?? ''),
            reason: (string)($data['reason'] ?? ''),
            requestId: isset($data[SignalPayloadConstants::FIELD_REQUEST_ID]) && is_string($data[SignalPayloadConstants::FIELD_REQUEST_ID])
                ? $data[SignalPayloadConstants::FIELD_REQUEST_ID]
                : null,
            errorCode: isset($data[self::errorCode]) && is_string($data[self::errorCode])
                ? $data[self::errorCode]
                : null,
            retryAfter: isset($data[self::retryAfter]) && is_int($data[self::retryAfter])
                ? $data[self::retryAfter]
                : null,
        );
    }

    public function getEnvelopeOutcome(): ?string
    {
        return 'fail';
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
