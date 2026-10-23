<?php

declare(strict_types=1);

namespace Hilos\Status\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;

/** Append immutable team text to one published Status incident. */
final class StatusAddUpdateSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string incidentId = 'incidentId';
    public const string text = 'text';
    public const string replySignal = 'replySignal';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string action = 'action';
    public const string successMessage = 'successMessage';

    /** Correlation bound shared by the three internal Status operation asks. */
    public const int MAX_REQUEST_ID_LENGTH = 128;

    /**
     * @param int $incidentId Persisted incident primary id
     * @param string $text Plain text validated by the owner
     * @param string $replySignal Gatekeeper reply signal
     * @param string $acceptKey Initiating connection
     * @param ?string $requestId Client correlation, null when untracked
     * @param string $action Initiating action name
     * @param ?string $successMessage Gatekeeper success sentence, or null
     * @throws InvalidFormatException When the target or handover fields are invalid
     */
    public function __construct(
        public readonly int $incidentId,
        public readonly string $text,
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
    ) {
        if ($incidentId <= 0 || trim($replySignal) === '' || trim($acceptKey) === '' || trim($action) === '') {
            throw new InvalidFormatException('Choose an incident and provide the handover reply, connection and action.');
        }
        if ($requestId !== null && (!mb_check_encoding($requestId, 'UTF-8') ||
            mb_strlen($requestId, 'UTF-8') > self::MAX_REQUEST_ID_LENGTH)) {
            throw new InvalidFormatException('The status request id must contain at most 128 characters.');
        }
    }

    /** @return array<string, mixed> Complete transport payload, including explicit nulls */
    public function toArray(): array
    {
        return [
            self::incidentId => $this->incidentId,
            self::text => $this->text,
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
            incidentId: self::requireInt($data, self::incidentId),
            text: self::requireString($data, self::text),
            replySignal: self::requireString($data, self::replySignal),
            acceptKey: self::requireString($data, self::acceptKey),
            requestId: self::requireNullableString($data, self::requestId),
            action: self::requireString($data, self::action),
            successMessage: self::requireNullableString($data, self::successMessage),
        );
    }
}
