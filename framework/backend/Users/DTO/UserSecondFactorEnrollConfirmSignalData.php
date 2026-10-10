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
 * Users library → the person's agent: confirm the unfinished enrolment with its first code (HIL-1406).
 *
 * What {@see HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_CONFIRM} carries, for an app
 * connected on the way in and one connected from the profile. {@see AbstractUsersLibraryAgent}
 * judged the wait or the confirmation of the operation and started the enrolment;
 * {@see AbstractUserAgent} checks that the enrolment still stands, is the one the form names, and
 * that the code takes its first step, then confirms the app. The ask comes back inside
 * {@see UserSecondFactorEnrollConfirmDoneSignalData}.
 */
final class UserSecondFactorEnrollConfirmSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string userId = 'userId';
    public const string authenticatorId = 'authenticatorId';
    public const string code = 'code';
    public const string label = 'label';
    public const string replySignal = 'replySignal';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string action = 'action';
    public const string successMessage = 'successMessage';

    /**
     * @param int $userId Person enrolling, and the index of the agent the ask is for
     * @param ?int $authenticatorId Enrolment the profile form names, or null on the way in, where the unfinished one is meant
     * @param string $code First code from the app
     * @param string $label Name the person gave the app, or empty for the default
     * @param string $replySignal Agent signal the agent answers the library under
     * @param string $acceptKey Accept key of the connection that asked, and the origin of the write
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Sentence to speak on success, always null: the library answers once it has continued
     * @throws InvalidFormatException When the person id is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly ?int $authenticatorId,
        public readonly string $code,
        public readonly string $label,
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
     * @return static Enrolment confirmation ask
     * @throws InvalidFormatException When the person, the code, the name or the waiting submit is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            authenticatorId: self::optionalInt($data, self::authenticatorId),
            code: self::requireString($data, self::code),
            label: self::requireString($data, self::label),
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
            self::authenticatorId => $this->authenticatorId,
            self::code => $this->code,
            self::label => $this->label,
            self::replySignal => $this->replySignal,
            self::acceptKey => $this->acceptKey,
            self::requestId => $this->requestId,
            self::action => $this->action,
            self::successMessage => $this->successMessage,
        ];
    }
}
