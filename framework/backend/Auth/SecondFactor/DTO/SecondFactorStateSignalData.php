<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor\DTO;

use Hilos\Auth\SecondFactor\SecondFactorStateProjector;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * The profile's second-factor section, whole, as one person's tabs draw it (HIL-494).
 *
 * Built by {@see SecondFactorStateProjector}; rides the security page's subscription payload
 * for the first render and the person's group for every change after it. Whole rather than a
 * diff: the section is small, and a tab that missed one change must not draw the next one on
 * top of a picture that is no longer true. Moments are server epoch ms.
 */
final class SecondFactorStateSignalData extends BaseDTO implements SignalDataInterface
{
    public const string authenticators = 'authenticators';
    public const string id = 'id';
    public const string label = 'label';
    public const string createdAt = 'createdAt';
    public const string lastUsedAt = 'lastUsedAt';
    public const string backupCodesLeft = 'backupCodesLeft';
    public const string backupCodesTotal = 'backupCodesTotal';
    public const string required = 'required';
    public const string resetWait = 'resetWait';
    public const string days = 'days';
    public const string pendingDays = 'pendingDays';
    public const string pendingFrom = 'pendingFrom';
    public const string defaultDays = 'defaultDays';
    public const string minDays = 'minDays';
    public const string maxDays = 'maxDays';
    public const string reset = 'reset';
    public const string requestedAt = 'requestedAt';
    public const string effectiveAt = 'effectiveAt';

    /**
     * @param list<array{id: int, label: string, createdAt: int, lastUsedAt: ?int}> $authenticators Confirmed apps
     * @param int $backupCodesLeft Unused backup codes of the person's set
     * @param int $backupCodesTotal Codes in the person's set
     * @param bool $required Whether the administrator requires a second factor of this person
     * @param array{days: int, pendingDays: ?int, pendingFrom: ?int, defaultDays: int, minDays: int, maxDays: int} $resetWait
     *     The person's removal wait and the administrator's bounds
     * @param ?array{requestedAt: int, effectiveAt: int} $reset The standing removal, or null when none stands
     */
    public function __construct(
        public readonly array $authenticators,
        public readonly int $backupCodesLeft,
        public readonly int $backupCodesTotal,
        public readonly bool $required,
        public readonly array $resetWait,
        public readonly ?array $reset,
    ) {
    }

    /**
     * @return array<string, mixed> Wire form
     */
    public function toArray(): array
    {
        return [
            self::authenticators => $this->authenticators,
            self::backupCodesLeft => $this->backupCodesLeft,
            self::backupCodesTotal => $this->backupCodesTotal,
            self::required => $this->required,
            self::resetWait => $this->resetWait,
            self::reset => $this->reset,
        ];
    }

    /**
     * @param array<string, mixed> $data Wire form
     * @return static Restored payload
     * @throws InvalidFormatException When a member is absent or of another type
     */
    public static function fromArray(array $data): static
    {
        $authenticators = [];
        foreach (self::requireArray($data, self::authenticators) as $entry) {
            if (!is_array($entry)) {
                throw new InvalidFormatException('Second-factor state carries an authenticator that is not a map');
            }
            $authenticators[] = [
                self::id => self::requireInt($entry, self::id),
                self::label => self::requireString($entry, self::label),
                self::createdAt => self::requireInt($entry, self::createdAt),
                self::lastUsedAt => self::optionalInt($entry, self::lastUsedAt),
            ];
        }
        $wait = self::requireArray($data, self::resetWait);
        $reset = self::optionalArray($data, self::reset);

        return new static(
            $authenticators,
            self::requireInt($data, self::backupCodesLeft),
            self::requireInt($data, self::backupCodesTotal),
            self::requireBool($data, self::required),
            [
                self::days => self::requireInt($wait, self::days),
                self::pendingDays => self::optionalInt($wait, self::pendingDays),
                self::pendingFrom => self::optionalInt($wait, self::pendingFrom),
                self::defaultDays => self::requireInt($wait, self::defaultDays),
                self::minDays => self::requireInt($wait, self::minDays),
                self::maxDays => self::requireInt($wait, self::maxDays),
            ],
            $reset === null ? null : [
                self::requestedAt => self::requireInt($reset, self::requestedAt),
                self::effectiveAt => self::requireInt($reset, self::effectiveAt),
            ],
        );
    }
}
