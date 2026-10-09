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
 * Users library → the person's agent: store a fresh hash of the password that just signed in (HIL-1405).
 *
 * What {@see HilosSignalConstants::HILOS_USER_PASSWORD_REHASH} carries. The library checked the
 * password and found its hash written under parameters that are no longer current;
 * {@see AbstractUserAgent} writes the new hash to the named password row and sends this ask back
 * inside {@see UserPasswordRehashDoneSignalData}, and the sign-in goes on only then.
 *
 * The hash travels, never the password: {@see AbstractUsersLibraryAgent} computes it where the
 * password arrived, and the password does not leave that process.
 */
final class UserPasswordRehashSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string userId = 'userId';
    public const string identityId = 'identityId';
    public const string passwordHash = 'passwordHash';
    public const string replySignal = 'replySignal';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string action = 'action';
    public const string successMessage = 'successMessage';

    /**
     * @param int $userId Person whose row is written, and the index of the agent the ask is for
     * @param int $identityId Password row the hash is written to
     * @param string $passwordHash New hash of the password, minted where the password arrived
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
     * @return static Rehash ask
     * @throws InvalidFormatException When the person, the row, the hash or the waiting submit is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            identityId: self::requireInt($data, self::identityId),
            passwordHash: self::requireString($data, self::passwordHash),
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
            self::identityId => $this->identityId,
            self::passwordHash => $this->passwordHash,
            self::replySignal => $this->replySignal,
            self::acceptKey => $this->acceptKey,
            self::requestId => $this->requestId,
            self::action => $this->action,
            self::successMessage => $this->successMessage,
        ];
    }
}
