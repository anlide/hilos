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
 * Users library → the person's agent: disconnect an app, the last one taking the factor with it (HIL-1406).
 *
 * What {@see HilosSignalConstants::HILOS_USER_SECOND_FACTOR_REMOVE} carries.
 * {@see AbstractUsersLibraryAgent} only read the person off the session; whether the app is the
 * person's, whether it is the last and whether an administrator requires the factor are asked by
 * {@see AbstractUserAgent} in the turn that deletes, so two tabs taking off the last two apps at
 * once cannot both see "not the last". The ask comes back inside
 * {@see UserSecondFactorRemoveDoneSignalData}.
 */
final class UserSecondFactorRemoveSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string userId = 'userId';
    public const string authenticatorId = 'authenticatorId';
    public const string proofCode = 'proofCode';
    public const string proofBackup = 'proofBackup';
    public const string replySignal = 'replySignal';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string action = 'action';
    public const string successMessage = 'successMessage';

    /**
     * @param int $userId Person whose app it is, and the index of the agent the ask is for
     * @param int $authenticatorId App to disconnect
     * @param string $proofCode Code proving the person, as typed
     * @param bool $proofBackup Whether the proof is a backup code rather than an app code
     * @param string $replySignal Agent signal the agent answers the library under
     * @param string $acceptKey Accept key of the connection that asked, and the origin of the write
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Sentence to speak on success, always null: the library answers once it has continued
     * @throws InvalidFormatException When the person id is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $authenticatorId,
        public readonly string $proofCode,
        public readonly bool $proofBackup,
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
     * @return static App removal ask
     * @throws InvalidFormatException When the person, the app, the proof or the waiting submit is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            authenticatorId: self::requireInt($data, self::authenticatorId),
            proofCode: self::requireString($data, self::proofCode),
            proofBackup: self::requireBool($data, self::proofBackup),
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
            self::authenticatorId => $this->authenticatorId,
            self::proofCode => $this->proofCode,
            self::proofBackup => $this->proofBackup,
            self::replySignal => $this->replySignal,
            self::acceptKey => $this->acceptKey,
            self::requestId => $this->requestId,
            self::action => $this->action,
            self::successMessage => $this->successMessage,
        ];
    }
}
