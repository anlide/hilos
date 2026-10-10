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
 * Users library → the person's agent: check a second-factor code, the check being the write (HIL-1406).
 *
 * What {@see HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE} carries, for every place a code
 * proves the person: the code step of a sign-in, starting another app from the profile, showing and
 * renewing the backup codes, and the confirmation of an operation. {@see AbstractUsersLibraryAgent}
 * judged what lies outside the person's set - the wait on the browser, the confirmation of the
 * operation; {@see AbstractUserAgent} checks the code, because an app code takes its step, a
 * backup code burns and a wrong one is counted in that very check. A code that confirms an
 * operation and proves the person is recorded as the confirmation in the same turn, on the browser
 * the hash names (HIL-1407); a missed one records nothing. The ask comes back inside
 * {@see UserSecondFactorProveDoneSignalData}, and its action tells the library which of the places
 * it continues.
 */
final class UserSecondFactorProveSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string userId = 'userId';
    public const string code = 'code';
    public const string backupCode = 'backupCode';
    public const string cancelReset = 'cancelReset';
    public const string trustDevice = 'trustDevice';
    public const string operation = 'operation';
    public const string sessionTokenHash = 'sessionTokenHash';
    public const string replySignal = 'replySignal';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string action = 'action';
    public const string successMessage = 'successMessage';

    /**
     * @param int $userId Person whose code it is, and the index of the agent the ask is for
     * @param string $code Code as typed
     * @param bool $backupCode Whether it is a backup code rather than an app code
     * @param bool $cancelReset Whether a right code cancels the removal that stands - the code step of a sign-in alone
     * @param bool $trustDevice Whether the person asked not to be asked again on this browser; false off the sign-in
     * @param ?string $operation Protected operation the code confirms, or null everywhere else
     * @param ?string $sessionTokenHash Hash of the session token of the browser the operation is confirmed in, or null everywhere else
     * @param string $replySignal Agent signal the agent answers the library under
     * @param string $acceptKey Accept key of the connection that asked, and the origin of the write
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Sentence to speak on success, always null: the library answers once it has continued
     * @throws InvalidFormatException When the person id is not positive, or only one of the operation and the browser is named
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $code,
        public readonly bool $backupCode,
        public readonly bool $cancelReset,
        public readonly bool $trustDevice,
        public readonly ?string $operation,
        public readonly ?string $sessionTokenHash,
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
    ) {
        if ($userId <= 0) {
            throw new InvalidFormatException('User id must be positive');
        }
        if (($operation === null) !== ($sessionTokenHash === null)) {
            throw new InvalidFormatException('A confirmed operation and its browser are named together');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Code check ask
     * @throws InvalidFormatException When the person, the code, the flags or the waiting submit is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            code: self::requireString($data, self::code),
            backupCode: self::requireBool($data, self::backupCode),
            cancelReset: self::requireBool($data, self::cancelReset),
            trustDevice: self::requireBool($data, self::trustDevice),
            operation: self::optionalString($data, self::operation),
            sessionTokenHash: self::optionalString($data, self::sessionTokenHash),
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
            self::code => $this->code,
            self::backupCode => $this->backupCode,
            self::cancelReset => $this->cancelReset,
            self::trustDevice => $this->trustDevice,
            self::operation => $this->operation,
            self::sessionTokenHash => $this->sessionTokenHash,
            self::replySignal => $this->replySignal,
            self::acceptKey => $this->acceptKey,
            self::requestId => $this->requestId,
            self::action => $this->action,
            self::successMessage => $this->successMessage,
        ];
    }
}
