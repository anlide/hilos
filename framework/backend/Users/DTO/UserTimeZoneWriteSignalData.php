<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * The coordinator hands a person's requested time zone to the person's agent.
 *
 * SCAFFOLD: The internal write/done handover is implemented and tested. Keep this API until
 * HIL-1593 adds the first browser caller.
 */
final class UserTimeZoneWriteSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string userId = 'userId';
    public const string timeZone = 'timeZone';
    public const string replySignal = 'replySignal';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string action = 'action';
    public const string successMessage = 'successMessage';

    /**
     * @param int $userId Person whose time zone is being written
     * @param ?string $timeZone Requested identifier or explicit null; the owner validates it
     * @param string $replySignal Signal the coordinator receives the answer under
     * @param string $acceptKey Connection that submitted the choice
     * @param ?string $requestId Client request id, or null when untracked
     * @param string $action Browser action name the acknowledgement is addressed to
     * @param ?string $successMessage Sentence to speak on success
     * @throws InvalidFormatException When the person id is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly ?string $timeZone,
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
    ) {
        if ($userId <= 0) {
            throw new InvalidFormatException('User id must be positive');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Restored time-zone write ask
     * @throws InvalidFormatException When required fields are absent or malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            timeZone: self::requireNullableString($data, self::timeZone),
            replySignal: self::requireString($data, self::replySignal),
            acceptKey: self::requireString($data, self::acceptKey),
            requestId: self::optionalString($data, self::requestId),
            action: self::requireString($data, self::action),
            successMessage: self::optionalString($data, self::successMessage),
        );
    }

    /** @return array<string, int|string|null> Transport payload */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::timeZone => $this->timeZone,
            self::replySignal => $this->replySignal,
            self::acceptKey => $this->acceptKey,
            self::requestId => $this->requestId,
            self::action => $this->action,
            self::successMessage => $this->successMessage,
        ];
    }
}
