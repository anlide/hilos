<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;

/** Caller → personal owner: add verified identity material while this session still belongs to the person. */
final class UserIdentityAddSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string userId = 'userId';
    public const string sessionId = 'sessionId';
    public const string identity = 'identity';
    public const string tripKeyHash = 'tripKeyHash';
    public const string replySignal = 'replySignal';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string action = 'action';
    public const string successMessage = 'successMessage';

    /**
     * @param int $userId Personal owner and routing index
     * @param int $sessionId Session that must still be signed in as the person
     * @param IdentityAddData $identity Verified final material
     * @param ?string $tripKeyHash OAuth trip being completed, or null for a library action
     * @param string $replySignal One of the two identity-add completion signals
     * @param string $acceptKey Original connection
     * @param ?string $requestId Original tracked submit, if any
     * @param string $action Server-known action to continue
     * @param ?string $successMessage Always null; completion belongs to the caller
     * @throws InvalidFormatException When the addressee, session or reply contract is invalid
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $sessionId,
        public readonly IdentityAddData $identity,
        public readonly ?string $tripKeyHash,
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
    ) {
        if ($userId <= 0 || $sessionId <= 0 || $successMessage !== null || !in_array($replySignal, [
            HilosSignalConstants::HILOS_USER_IDENTITY_ADD_DONE,
            HilosSignalConstants::HILOS_OAUTH_IDENTITY_ADD_DONE,
        ], true)) {
            throw new InvalidFormatException('Invalid identity addition addressee, session or reply');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Identity addition ask
     * @throws InvalidFormatException When a required field or the reply contract is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            sessionId: self::requireInt($data, self::sessionId),
            identity: IdentityAddData::fromArray(self::requireArray($data, self::identity)),
            tripKeyHash: self::requireNullableString($data, self::tripKeyHash),
            replySignal: self::requireString($data, self::replySignal),
            acceptKey: self::requireString($data, self::acceptKey),
            requestId: self::requireNullableString($data, self::requestId),
            action: self::requireString($data, self::action),
            successMessage: self::requireNullableString($data, self::successMessage),
        );
    }

    /** @return array<string, mixed> Transport payload */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::sessionId => $this->sessionId,
            self::identity => $this->identity->toArray(),
            self::tripKeyHash => $this->tripKeyHash,
            self::replySignal => $this->replySignal,
            self::acceptKey => $this->acceptKey,
            self::requestId => $this->requestId,
            self::action => $this->action,
            self::successMessage => $this->successMessage,
        ];
    }
}
