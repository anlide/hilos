<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Users\Agent\AbstractUserAgent;

/**
 * Users library → the person's agent: move the account's sign-in rows to a new address (HIL-1405).
 *
 * What {@see HilosSignalConstants::HILOS_USER_EMAIL_CHANGE} carries.
 * {@see AbstractUsersLibraryAgent} proved both addresses and spent their codes;
 * {@see AbstractUserAgent} moves every password and sign-in-link row in one transaction and sends
 * this ask back inside {@see UserEmailChangeDoneSignalData}, an address another account took in
 * between included. The notices to both addresses are the library's, once the move is written.
 */
final class UserEmailChangeSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string userId = 'userId';
    public const string from = 'from';
    public const string to = 'to';
    public const string replySignal = 'replySignal';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string action = 'action';
    public const string successMessage = 'successMessage';

    /**
     * @param int $userId Person whose row is written, and the index of the agent the ask is for
     * @param string $from Lowercased address the account holds now
     * @param string $to Lowercased address it moves to
     * @param string $replySignal Agent signal the agent answers the library under
     * @param string $acceptKey Accept key of the connection that asked, and the origin of the write
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Sentence to speak on success, always null: the library answers once it has continued
     * @throws InvalidFormatException When the person id is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $from,
        public readonly string $to,
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
     * @return static Email change ask
     * @throws InvalidFormatException When the person, an address or the waiting submit is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            from: self::requireString($data, self::from),
            to: self::requireString($data, self::to),
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
            self::from => $this->from,
            self::to => $this->to,
            self::replySignal => $this->replySignal,
            self::acceptKey => $this->acceptKey,
            self::requestId => $this->requestId,
            self::action => $this->action,
            self::successMessage => $this->successMessage,
        ];
    }
}
