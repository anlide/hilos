<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\StepUp\StepUpConfirmations;
use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Users\Agent\AbstractUserAgent;

/**
 * Sessions library → the person's agent: credit an operation to a blocked person whose sign-in was refused (HIL-1407).
 *
 * What {@see HilosSignalConstants::HILOS_USER_STEP_UP_CREDIT} carries. {@see AbstractSessionsLibraryAgent}
 * refused the sign-in and judged that its proof confirms the copy of the person's data;
 * {@see AbstractUserAgent} records the confirmation ({@see StepUpConfirmations::record()}) and sends
 * this request back inside {@see UserStepUpCreditDoneSignalData}. Not an ask of the handover form
 * ({@see HandoverAskInterface}): the holder answered the browser already and resumes nothing, and
 * no table shows a confirmation, so nobody waits on a stamp of the write.
 */
final class UserStepUpCreditSignalData extends BaseDTO implements SignalDataInterface
{
    public const string userId = 'userId';
    public const string sessionTokenHash = 'sessionTokenHash';
    public const string operation = 'operation';
    public const string replySignal = 'replySignal';

    /**
     * @param int $userId Person credited, and the index of the agent the request is for
     * @param string $sessionTokenHash Hash of the session token of the browser the sign-in was refused in
     * @param string $operation Protected operation credited
     * @param string $replySignal Agent signal the agent answers the holder under
     * @throws InvalidFormatException When the person id is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $sessionTokenHash,
        public readonly string $operation,
        public readonly string $replySignal,
    ) {
        if ($userId <= 0) {
            throw new InvalidFormatException('User id must be positive');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Credit request
     * @throws InvalidFormatException When the person, the browser, the operation or the reply name is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            sessionTokenHash: self::requireString($data, self::sessionTokenHash),
            operation: self::requireString($data, self::operation),
            replySignal: self::requireString($data, self::replySignal),
        );
    }

    /** @return array<string, int|string> Transport payload */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::sessionTokenHash => $this->sessionTokenHash,
            self::operation => $this->operation,
            self::replySignal => $this->replySignal,
        ];
    }
}
