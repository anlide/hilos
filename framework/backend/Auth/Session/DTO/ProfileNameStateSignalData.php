<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Runtime\State\Item\HilosProfileNameChange;

/**
 * Complete public snapshot for connections of one authenticated session, or its clearing frame.
 *
 */
final class ProfileNameStateSignalData extends BaseDTO implements SignalDataInterface
{
    public const string USER_ID = 'userId';
    public const string CHANGE = 'change';
    public const string REQUEST_ID = 'requestId';
    public const string NAME = 'name';
    public const string PHASE = 'phase';
    public const string REFUSAL = 'refusal';

    /** @var ?array{requestId: string, name: string, phase: string, refusal: ?string} Public fields only */
    public readonly ?array $change;

    /**
     * @param ?int $userId Current session person, or null on identity loss
     * @param ?array<string, mixed> $change Current attempt, or null when none exists
     * @throws InvalidFormatException When the attempt is malformed or contradicts the identity or refusal
     */
    public function __construct(public readonly ?int $userId, ?array $change)
    {
        if ($change === null) {
            $this->change = null;
            return;
        }
        if ($userId === null) {
            throw new InvalidFormatException('A name-change snapshot must identify the person whose attempt it carries.');
        }
        $phase = self::requireString($change, self::PHASE);
        $refusal = self::requireNullableString($change, self::REFUSAL);
        if (!in_array($phase, [
            HilosProfileNameChange::PHASE_CHECKING,
            HilosProfileNameChange::PHASE_APPLYING,
            HilosProfileNameChange::PHASE_REFUSED,
            HilosProfileNameChange::PHASE_DONE,
        ], true)) {
            throw new InvalidFormatException('A name-change snapshot must say checking, applying, refused or done.');
        }
        if (($phase === HilosProfileNameChange::PHASE_REFUSED) !== ($refusal !== null) || $refusal === '') {
            throw new InvalidFormatException('Only a refused name-change snapshot carries a refusal sentence.');
        }
        $this->change = [
            self::REQUEST_ID => self::requireString($change, self::REQUEST_ID),
            self::NAME => self::requireString($change, self::NAME),
            self::PHASE => $phase,
            self::REFUSAL => $refusal,
        ];
    }

    /** @return array<string, mixed> Public snapshot without internal session or timing fields */
    public function toArray(): array
    {
        return [self::USER_ID => $this->userId, self::CHANGE => $this->change];
    }

    /**
     * @param array<string, mixed> $data Public signal payload
     * @return static Complete public snapshot
     * @throws InvalidFormatException When fields are missing, mistyped or contradictory
     */
    public static function fromArray(array $data): static
    {
        if (!array_key_exists(self::USER_ID, $data)) {
            throw new InvalidFormatException('A name-change snapshot must carry a person id or an explicit null.');
        }

        return new static(
            self::optionalInt($data, self::USER_ID),
            self::requireNullableArray($data, self::CHANGE),
        );
    }
}
