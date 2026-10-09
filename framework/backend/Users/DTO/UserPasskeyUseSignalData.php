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
 * Users library → the person's agent: a passkey signed, record its counter and its use (HIL-1405).
 *
 * What {@see HilosSignalConstants::HILOS_USER_PASSKEY_USE} carries, for a sign-in by passkey and
 * for a protected step confirmed with one alike: the write is the same.
 * {@see AbstractUsersLibraryAgent} checked the signature and the counter; {@see AbstractUserAgent}
 * checks the counter once more against the stored one before writing it, because two assertions of
 * a cloned key could both pass the library's check before either is written. The ask comes back
 * inside {@see UserPasskeyUseDoneSignalData}, and the operation tells the library which of the two
 * it continues.
 */
final class UserPasskeyUseSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string userId = 'userId';
    public const string passkeyId = 'passkeyId';
    public const string signCount = 'signCount';
    public const string operation = 'operation';
    public const string replySignal = 'replySignal';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string action = 'action';
    public const string successMessage = 'successMessage';

    /**
     * @param int $userId Owner of the key, and the index of the agent the ask is for
     * @param int $passkeyId Row of the key in hilos_passkey_credential
     * @param int $signCount Signature counter the authenticator reported
     * @param ?string $operation Protected operation the key confirms, or null for a sign-in
     * @param string $replySignal Agent signal the agent answers the library under
     * @param string $acceptKey Accept key of the connection that asked, and the origin of the write
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Sentence to speak on success, always null: the library answers once it has continued
     * @throws InvalidFormatException When the person id is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $passkeyId,
        public readonly int $signCount,
        public readonly ?string $operation,
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
     * @return static Passkey use ask
     * @throws InvalidFormatException When the person, the key, the counter or the waiting submit is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            passkeyId: self::requireInt($data, self::passkeyId),
            signCount: self::requireInt($data, self::signCount),
            operation: self::optionalString($data, self::operation),
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
            self::passkeyId => $this->passkeyId,
            self::signCount => $this->signCount,
            self::operation => $this->operation,
            self::replySignal => $this->replySignal,
            self::acceptKey => $this->acceptKey,
            self::requestId => $this->requestId,
            self::action => $this->action,
            self::successMessage => $this->successMessage,
        ];
    }
}
