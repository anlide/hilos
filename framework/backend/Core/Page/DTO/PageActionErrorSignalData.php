<?php

declare(strict_types=1);

namespace Hilos\Core\Page\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
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

    /** Wire key for the failure's class name, sent to an admin surface only. */
    public const string errorType = 'errorType';

    /** Wire key for the failure's own message, sent to an admin surface only. */
    public const string errorDetail = 'errorDetail';

    /**
     * @param string $action Action name that failed
     * @param string $reason Human-readable error message
     * @param ?string $requestId Client-minted request id to correlate the reply, or null for a legacy untracked error
     * @param ?string $errorCode Machine-readable error code (e.g. 'unauthorized'), or null for an unclassified failure
     * @param ?int $retryAfter Seconds to wait before retrying (rate_limited failures), or null when not throttled
     * @param ?string $errorType Class name of the failure the placeholder stands for, or null for anyone but an admin
     * @param ?string $errorDetail Original message of that failure, or null for anyone but an admin
     */
    public function __construct(
        public readonly string $action,
        public readonly string $reason,
        public readonly ?string $requestId = null,
        public readonly ?string $errorCode = null,
        public readonly ?int $retryAfter = null,
        public readonly ?string $errorType = null,
        public readonly ?string $errorDetail = null,
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

        if ($this->errorType !== null) {
            $result[self::errorType] = $this->errorType;
        }

        if ($this->errorDetail !== null) {
            $result[self::errorDetail] = $this->errorDetail;
        }

        return $result;
    }

    /**
     * The five optional fields are the ones {@see self::toArray()} omits when
     * they are null; the two the failure is described by are not optional, and a
     * frame without them is refused rather than turned into an error that names
     * no action and gives no reason.
     *
     * @param array<string, mixed> $data
     * @throws InvalidFormatException When the action or the reason is missing, or an optional field is of another type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            action: self::requireString($data, 'action'),
            reason: self::requireString($data, 'reason'),
            requestId: self::optionalString($data, SignalPayloadConstants::FIELD_REQUEST_ID),
            errorCode: self::optionalString($data, self::errorCode),
            retryAfter: self::optionalInt($data, self::retryAfter),
            errorType: self::optionalString($data, self::errorType),
            errorDetail: self::optionalString($data, self::errorDetail),
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
