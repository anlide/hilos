<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor\DTO;

use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * What a second-factor screen of the sign-in surface needs beyond its step (HIL-494).
 *
 * Rides an {@see AuthFlowOutcome} under `secondFactor`. The code step reads the first two
 * members: how many days the "don't ask again on this device" checkbox promises (null - the
 * administrator offers no trust, and the checkbox is not drawn), and the moment a removal of
 * the factor already asked for takes effect (null - none is asked for, and the link to ask is
 * drawn instead). Enrolment on the way in reads the other two: the secret and its otpauth
 * address once, when the enrolment starts, and the backup codes once, when it is confirmed.
 * Both are answered exactly once and kept nowhere a reload reads.
 */
final class SecondFactorStepData extends BaseDTO
{
    public const string trustDeviceDays = 'trustDeviceDays';
    public const string resetEffectiveAt = 'resetEffectiveAt';
    public const string setup = 'setup';
    public const string secret = 'secret';
    public const string otpauthUri = 'otpauthUri';
    public const string backupCodes = 'backupCodes';

    /**
     * @param ?int $trustDeviceDays Days a trusted browser skips the step, or null when no trust is offered
     * @param ?int $resetEffectiveAt Server moment a removal already asked for takes effect, in epoch ms, or null
     * @param ?string $secret Base32 secret of an enrolment just started, or null outside that answer
     * @param ?string $otpauthUri otpauth address of that secret, or null outside that answer
     * @param ?list<string> $backupCodes Backup codes of an enrolment just confirmed, in display form, or null
     */
    public function __construct(
        public readonly ?int $trustDeviceDays,
        public readonly ?int $resetEffectiveAt,
        public readonly ?string $secret = null,
        public readonly ?string $otpauthUri = null,
        public readonly ?array $backupCodes = null,
    ) {
    }

    /**
     * @return array<string, mixed> Wire form; `setup` and `backupCodes` only when present
     */
    public function toArray(): array
    {
        $data = [
            self::trustDeviceDays => $this->trustDeviceDays,
            self::resetEffectiveAt => $this->resetEffectiveAt,
        ];
        if ($this->secret !== null && $this->otpauthUri !== null) {
            $data[self::setup] = [self::secret => $this->secret, self::otpauthUri => $this->otpauthUri];
        }
        if ($this->backupCodes !== null) {
            $data[self::backupCodes] = $this->backupCodes;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data Wire form
     * @return static Restored step data
     * @throws InvalidFormatException When a present member is of another type
     */
    public static function fromArray(array $data): static
    {
        $setup = self::optionalArray($data, self::setup);

        return new static(
            self::optionalInt($data, self::trustDeviceDays),
            self::optionalInt($data, self::resetEffectiveAt),
            $setup === null ? null : self::requireString($setup, self::secret),
            $setup === null ? null : self::requireString($setup, self::otpauthUri),
            self::optionalStringList($data, self::backupCodes),
        );
    }
}
