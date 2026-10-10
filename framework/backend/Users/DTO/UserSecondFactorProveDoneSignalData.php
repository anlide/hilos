<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * The person's agent → users library: the code proved the person, or why not (HIL-1406).
 *
 * What {@see HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE_DONE} carries. The ask comes back
 * whole, so {@see AbstractUsersLibraryAgent} continues the action it names from the frame alone.
 * Beside the refusal - the three fields of {@see ActionRefusal} - ride the facts the library acts
 * on: a code checked and missed is told to the session holder on the sign-in step, a miss that put
 * the lock is mailed, and a removal this proof canceled is mailed too.
 */
final class UserSecondFactorProveDoneSignalData extends BaseDTO implements SignalDataInterface
{
    public const string ask = 'ask';
    public const string missed = 'missed';
    public const string lockMisses = 'lockMisses';
    public const string lockUntil = 'lockUntil';
    public const string resetCanceled = 'resetCanceled';
    public const string error = 'error';
    public const string errorType = 'errorType';
    public const string errorDetail = 'errorDetail';

    /**
     * @param UserSecondFactorProveSignalData $ask The ask being answered, untouched
     * @param bool $missed Whether the code was checked and matched nothing
     * @param ?int $lockMisses Wrong app codes the lock was put on, when this miss put it; null otherwise
     * @param ?int $lockUntil End of that lock (unix seconds), when this miss put it; null otherwise
     * @param bool $resetCanceled Whether the proof canceled the removal that stood
     * @param ?string $error Why the code proved nothing, or null when it proved the person
     * @param ?string $errorType Class name of the failure the refusal stands for, or null when nothing was held back
     * @param ?string $errorDetail Original message of that failure, or null when nothing was held back
     */
    public function __construct(
        public readonly UserSecondFactorProveSignalData $ask,
        public readonly bool $missed,
        public readonly ?int $lockMisses,
        public readonly ?int $lockUntil,
        public readonly bool $resetCanceled,
        public readonly ?string $error,
        public readonly ?string $errorType,
        public readonly ?string $errorDetail,
    ) {
    }

    /**
     * @param UserSecondFactorProveSignalData $ask The ask being answered
     * @param ActionRefusal $refusal Why nothing was checked
     * @return self Answer carrying the ask back, with no miss and no lock
     */
    public static function refused(UserSecondFactorProveSignalData $ask, ActionRefusal $refusal): self
    {
        return new self($ask, false, null, null, false, $refusal->reason, $refusal->errorType, $refusal->errorDetail);
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Code check answer
     * @throws InvalidFormatException When the ask or the outcome fields are malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            ask: UserSecondFactorProveSignalData::fromArray(self::requireArray($data, self::ask)),
            missed: self::requireBool($data, self::missed),
            lockMisses: self::optionalInt($data, self::lockMisses),
            lockUntil: self::optionalInt($data, self::lockUntil),
            resetCanceled: self::requireBool($data, self::resetCanceled),
            error: self::optionalString($data, self::error),
            errorType: self::optionalString($data, self::errorType),
            errorDetail: self::optionalString($data, self::errorDetail),
        );
    }

    /** @return array<string, mixed> Transport payload */
    public function toArray(): array
    {
        return [
            self::ask => $this->ask->toArray(),
            self::missed => $this->missed,
            self::lockMisses => $this->lockMisses,
            self::lockUntil => $this->lockUntil,
            self::resetCanceled => $this->resetCanceled,
            self::error => $this->error,
            self::errorType => $this->errorType,
            self::errorDetail => $this->errorDetail,
        ];
    }
}
