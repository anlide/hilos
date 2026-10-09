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
 * Users library → the person's agent: store the password changed in the profile (HIL-1405).
 *
 * What {@see HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE} carries.
 * {@see AbstractUsersLibraryAgent} checked the step, the proof and the password rule and spent the
 * code; {@see AbstractUserAgent} writes the hash and sends this ask back inside
 * {@see UserPasswordChangeDoneSignalData}. The last two fields of the subject are what the library
 * does once the password is written, carried so it holds nothing between the hops.
 */
final class UserPasswordChangeSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string userId = 'userId';
    public const string identityId = 'identityId';
    public const string passwordHash = 'passwordHash';
    public const string signOutOthers = 'signOutOthers';
    public const string flowOpen = 'flowOpen';
    public const string replySignal = 'replySignal';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string action = 'action';
    public const string successMessage = 'successMessage';

    /**
     * @param int $userId Person whose row is written, and the index of the agent the ask is for
     * @param int $identityId Password row of the account
     * @param string $passwordHash New hash of the password, minted where the password arrived
     * @param bool $signOutOthers Whether the person asked to end their other sessions
     * @param bool $flowOpen Whether the change ran through a code step, which is closed after the write
     * @param string $replySignal Agent signal the agent answers the library under
     * @param string $acceptKey Accept key of the connection that asked, and the origin of the write
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Sentence to speak on success, always null: the library answers once it has continued
     * @throws InvalidFormatException When the person id is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $identityId,
        public readonly string $passwordHash,
        public readonly bool $signOutOthers,
        public readonly bool $flowOpen,
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
     * @return static Password change ask
     * @throws InvalidFormatException When the person, the row, the hash, a flag or the waiting submit is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            identityId: self::requireInt($data, self::identityId),
            passwordHash: self::requireString($data, self::passwordHash),
            signOutOthers: self::requireBool($data, self::signOutOthers),
            flowOpen: self::requireBool($data, self::flowOpen),
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
            self::identityId => $this->identityId,
            self::passwordHash => $this->passwordHash,
            self::signOutOthers => $this->signOutOthers,
            self::flowOpen => $this->flowOpen,
            self::replySignal => $this->replySignal,
            self::acceptKey => $this->acceptKey,
            self::requestId => $this->requestId,
            self::action => $this->action,
            self::successMessage => $this->successMessage,
        ];
    }
}
