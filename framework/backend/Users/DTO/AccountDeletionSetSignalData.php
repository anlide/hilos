<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * Hilos user page → users library: change scheduled account deletion.
 *
 * The page keeps the ADMIN gate; the library judges the write and returns its outcome to the
 * waiting submit. The receipt also attributes the write through HandoverAskInterface.
 */
final class AccountDeletionSetSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string userId = 'userId';
    public const string scheduled = 'scheduled';
    public const string replySignal = 'replySignal';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string action = 'action';
    public const string successMessage = 'successMessage';

    /**
     * @param int $userId Target account id
     * @param bool $scheduled Requested state
     * @param string $replySignal Agent signal for the library's answer
     * @param string $acceptKey Initiating connection accept key
     * @param ?string $requestId Tracked request id, or null when untracked
     * @param string $action Browser action to answer
     * @param ?string $successMessage Initial success text, null until the library answers
     * @throws InvalidFormatException When the account id is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly bool $scheduled,
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
     * @return static Account lifecycle handover request
     * @throws InvalidFormatException When the target, state or waiting submit is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            scheduled: self::requireBool($data, self::scheduled),
            replySignal: self::requireString($data, self::replySignal),
            acceptKey: self::requireString($data, self::acceptKey),
            requestId: self::optionalString($data, self::requestId),
            action: self::requireString($data, self::action),
            successMessage: self::optionalString($data, self::successMessage),
        );
    }

    /** @return array<string, int|bool|string|null> Transport payload */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::scheduled => $this->scheduled,
            self::replySignal => $this->replySignal,
            self::acceptKey => $this->acceptKey,
            self::requestId => $this->requestId,
            self::action => $this->action,
            self::successMessage => $this->successMessage,
        ];
    }
}
