<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\StepUp\StepUpConfirmations;
use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Users\Agent\AbstractUserAgent;

/**
 * Users library → the confirming person's agent: record the confirmation a password or a code proved (HIL-1407).
 *
 * What {@see HilosSignalConstants::HILOS_USER_STEP_UP_RECORD} carries. {@see AbstractUsersLibraryAgent}
 * checked the password or the code from a letter or a message; {@see AbstractUserAgent} records the
 * confirmation ({@see StepUpConfirmations::record()}) and sends this ask back inside
 * {@see UserStepUpRecordDoneSignalData}, on which the browser action is answered. The browser is
 * named by the hash of its session token: the token itself does not leave the library.
 */
final class UserStepUpRecordSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string userId = 'userId';
    public const string sessionTokenHash = 'sessionTokenHash';
    public const string operation = 'operation';
    public const string replySignal = 'replySignal';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string action = 'action';
    public const string successMessage = 'successMessage';

    /**
     * @param int $userId Person who confirmed, and the index of the agent the ask is for
     * @param string $sessionTokenHash Hash of the session token of the browser the operation is confirmed in
     * @param string $operation Protected operation confirmed
     * @param string $replySignal Agent signal the agent answers the library under
     * @param string $acceptKey Accept key of the connection that asked, and the origin of the write
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Sentence to speak on success, always null: the library answers once it has continued
     * @throws InvalidFormatException When the person id is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $sessionTokenHash,
        public readonly string $operation,
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
     * @return static Confirmation record ask
     * @throws InvalidFormatException When the person, the browser, the operation or the waiting submit is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            sessionTokenHash: self::requireString($data, self::sessionTokenHash),
            operation: self::requireString($data, self::operation),
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
            self::sessionTokenHash => $this->sessionTokenHash,
            self::operation => $this->operation,
            self::replySignal => $this->replySignal,
            self::acceptKey => $this->acceptKey,
            self::requestId => $this->requestId,
            self::action => $this->action,
            self::successMessage => $this->successMessage,
        ];
    }
}
