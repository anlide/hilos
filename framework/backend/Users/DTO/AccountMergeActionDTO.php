<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Users\SecondFactorFate;

/**
 * Payload for merging another account into the user displayed on the Hilos user page (HIL-411).
 *
 * Both ids travel explicitly so the library can judge the pair at the write boundary. The
 * password fate is optional because a pair with at most one password needs no decision.
 */
final class AccountMergeActionDTO extends ActionPayloadDTO
{
    public const string survivorUserId = 'survivorUserId';
    public const string loserUserId = 'loserUserId';
    public const string passwordFate = 'passwordFate';
    public const string secondFactorFate = 'secondFactorFate';
    public const string expectedSurvivorHasSecondFactor = 'expectedSurvivorHasSecondFactor';
    public const string expectedLoserHasSecondFactor = 'expectedLoserHasSecondFactor';

    public const array SECRET_FIELDS = [];

    /**
     * @param int $survivorUserId User id that absorbs the other account
     * @param int $loserUserId User id folded into the survivor
     * @param ?PasswordFate $passwordFate Whose password remains, or null when no choice was named
     * @param ?SecondFactorFate $secondFactorFate Protection to preserve, or null when no choice is needed
     * @param bool $expectedSurvivorHasSecondFactor Confirmed-app presence shown for the survivor
     * @param bool $expectedLoserHasSecondFactor Confirmed-app presence shown for the loser
     */
    public function __construct(
        public readonly int $survivorUserId,
        public readonly int $loserUserId,
        public readonly ?PasswordFate $passwordFate,
        public readonly ?SecondFactorFate $secondFactorFate,
        public readonly bool $expectedSurvivorHasSecondFactor,
        public readonly bool $expectedLoserHasSecondFactor,
    ) {
    }

    /** @return string Action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_USER_MERGE;
    }

    /**
     * @param array<string, mixed> $data Raw payload, optionally wrapped in the action-data envelope
     * @return static Account-merge action payload
     * @throws InvalidFormatException When either id is missing or the password fate is unknown
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        $namedFate = self::optionalString($inner, self::passwordFate);
        $passwordFate = $namedFate === null ? null : PasswordFate::tryFrom($namedFate);
        if ($namedFate !== null && $passwordFate === null) {
            throw new InvalidFormatException('Unknown account-merge password fate');
        }

        $namedSecondFactor = self::optionalString($inner, self::secondFactorFate);
        $secondFactorFate = $namedSecondFactor === null ? null : SecondFactorFate::tryFrom($namedSecondFactor);
        if ($namedSecondFactor !== null && $secondFactorFate === null) {
            throw new InvalidFormatException('Unknown account-merge second-factor fate');
        }

        return new static(
            survivorUserId: self::requireInt($inner, self::survivorUserId),
            loserUserId: self::requireInt($inner, self::loserUserId),
            passwordFate: $passwordFate,
            secondFactorFate: $secondFactorFate,
            expectedSurvivorHasSecondFactor: self::requireBool($inner, self::expectedSurvivorHasSecondFactor),
            expectedLoserHasSecondFactor: self::requireBool($inner, self::expectedLoserHasSecondFactor),
        );
    }

    /** @return array<string, int|string|bool> Account ids, protection snapshot and named choices */
    public function toArray(): array
    {
        $data = [
            self::survivorUserId => $this->survivorUserId,
            self::loserUserId => $this->loserUserId,
            self::expectedSurvivorHasSecondFactor => $this->expectedSurvivorHasSecondFactor,
            self::expectedLoserHasSecondFactor => $this->expectedLoserHasSecondFactor,
        ];
        if ($this->passwordFate !== null) {
            $data[self::passwordFate] = $this->passwordFate->value;
        }

        if ($this->secondFactorFate !== null) {
            $data[self::secondFactorFate] = $this->secondFactorFate->value;
        }

        return $data;
    }
}
