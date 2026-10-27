<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Runtime\State\Item\HilosProfileNameChange;

/**
 * Users library → session holder: the addressed attempt reached a new phase.
 *
 */
final class ProfileNameAdvanceSignalData extends BaseDTO implements SignalDataInterface
{
    public const string SESSION_TOKEN_HASH = 'sessionTokenHash';
    public const string REQUEST_ID = 'requestId';
    public const string PHASE = 'phase';
    public const string REFUSAL = 'refusal';

    /**
     * @param string $sessionTokenHash Hash addressing the session's attempt
     * @param string $requestId Accepted attempt identifier
     * @param string $phase Applying, refused or done phase
     * @param ?string $refusal Server refusal sentence, or null
     * @throws InvalidFormatException When the phase is unknown or disagrees with the refusal
     */
    public function __construct(
        public readonly string $sessionTokenHash,
        public readonly string $requestId,
        public readonly string $phase,
        public readonly ?string $refusal,
    ) {
        if (!in_array($phase, [
            HilosProfileNameChange::PHASE_APPLYING,
            HilosProfileNameChange::PHASE_REFUSED,
            HilosProfileNameChange::PHASE_DONE,
        ], true)) {
            throw new InvalidFormatException('A name-change advance must say applying, refused or done.');
        }
        if (($phase === HilosProfileNameChange::PHASE_REFUSED) !== ($refusal !== null) || $refusal === '') {
            throw new InvalidFormatException('Only a refused name-change advance carries a refusal sentence.');
        }
    }

    /** @return array<string, mixed> Internal signal payload */
    public function toArray(): array
    {
        return [
            self::SESSION_TOKEN_HASH => $this->sessionTokenHash,
            self::REQUEST_ID => $this->requestId,
            self::PHASE => $this->phase,
            self::REFUSAL => $this->refusal,
        ];
    }

    /**
     * @param array<string, mixed> $data Internal signal payload
     * @return static Validated phase report
     * @throws InvalidFormatException When fields are missing, mistyped or contradictory
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, self::SESSION_TOKEN_HASH),
            self::requireString($data, self::REQUEST_ID),
            self::requireString($data, self::PHASE),
            self::requireNullableString($data, self::REFUSAL),
        );
    }
}
