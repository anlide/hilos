<?php

declare(strict_types=1);

namespace Hilos\Status\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;

/** Remove one subscription through the Status owner. */
final class StatusRemoveSubscriberSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string subscriberId = 'subscriberId';
    public const string replySignal = 'replySignal';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string action = 'action';
    public const string successMessage = 'successMessage';

    /**
     * @param int $subscriberId Persisted subscriber primary id
     * @param string $replySignal Gatekeeper reply signal
     * @param string $acceptKey Initiating connection
     * @param ?string $requestId Client correlation, null when untracked
     * @param string $action Initiating action name
     * @param ?string $successMessage Gatekeeper success sentence, or null
     * @throws InvalidFormatException When the target or handover fields are invalid
     */
    public function __construct(
        public readonly int $subscriberId,
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
    ) {
        if ($subscriberId <= 0 || trim($replySignal) === '' || trim($acceptKey) === '' || trim($action) === '') {
            throw new InvalidFormatException('Choose an subscriber and provide the handover reply, connection and action.');
        }
        if ($requestId !== null && (!mb_check_encoding($requestId, 'UTF-8') ||
            mb_strlen($requestId, 'UTF-8') > StatusAddUpdateSignalData::MAX_REQUEST_ID_LENGTH)) {
            throw new InvalidFormatException('The status request id must contain at most 128 characters.');
        }
    }

    /** @return array<string, mixed> Complete transport payload, including explicit nulls */
    public function toArray(): array
    {
        return [
            self::subscriberId => $this->subscriberId,
            self::replySignal => $this->replySignal,
            self::acceptKey => $this->acceptKey,
            self::requestId => $this->requestId,
            self::action => $this->action,
            self::successMessage => $this->successMessage,
        ];
    }

    /**
     * @param array<string, mixed> $data Complete transport payload
     * @return static Validated internal ask
     * @throws InvalidFormatException When a required key is missing, mistyped or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            subscriberId: self::requireInt($data, self::subscriberId),
            replySignal: self::requireString($data, self::replySignal),
            acceptKey: self::requireString($data, self::acceptKey),
            requestId: self::requireNullableString($data, self::requestId),
            action: self::requireString($data, self::action),
            successMessage: self::requireNullableString($data, self::successMessage),
        );
    }
}
