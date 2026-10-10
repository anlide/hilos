<?php

declare(strict_types=1);

namespace Hilos\Pages\Maintenance\DTO;

use Hilos\AdminViewMode\WireField;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Current protected-mode state for the Maintenance section (server to browser).
 *
 * Every field describes the freeze or its verifier circle, without identifying a person.
 */
final class HilosMaintenanceStateSignalData extends BaseDTO implements SignalDataInterface
{
    public const string phase = 'phase';
    public const string operation = 'operation';
    public const string manual = 'manual';
    public const string startedAt = 'startedAt';
    public const string enteredFromBrowser = 'enteredFromBrowser';
    public const string passCount = 'passCount';
    public const string circleNamed = 'circleNamed';
    public const string circleAdmitted = 'circleAdmitted';

    /**
     * @param string $phase Protected-mode phase from the runtime row
     * @param ?string $operation Operation name, or null when inactive
     * @param bool $manual Whether this is a manual maintenance window
     * @param ?string $startedAt ISO-8601 time the freeze began, or null when inactive
     * @param bool $enteredFromBrowser Whether a browser session requested the freeze
     * @param int $passCount Passes issued in the current verification window
     * @param int $circleNamed People named in the captured verifier circle
     * @param int $circleAdmitted Browsers admitted by that circle
     */
    public function __construct(
        public readonly string $phase,
        public readonly ?string $operation,
        public readonly bool $manual,
        public readonly ?string $startedAt,
        public readonly bool $enteredFromBrowser,
        public readonly int $passCount,
        public readonly int $circleNamed,
        public readonly int $circleAdmitted,
    ) {
    }

    /**
     * @return array<string, mixed> State as sent to the browser
     */
    public function toArray(): array
    {
        return [
            self::phase => $this->phase,
            self::operation => $this->operation,
            self::manual => $this->manual,
            self::startedAt => $this->startedAt,
            self::enteredFromBrowser => $this->enteredFromBrowser,
            self::passCount => $this->passCount,
            self::circleNamed => $this->circleNamed,
            self::circleAdmitted => $this->circleAdmitted,
        ];
    }

    /**
     * @return array<string, WireField> Non-personal classification of every frame field
     */
    public static function wireFields(): array
    {
        return [
            self::phase => WireField::notPersonal(),
            self::operation => WireField::notPersonal(),
            self::manual => WireField::notPersonal(),
            self::startedAt => WireField::notPersonal(),
            self::enteredFromBrowser => WireField::notPersonal(),
            self::passCount => WireField::notPersonal(),
            self::circleNamed => WireField::notPersonal(),
            self::circleAdmitted => WireField::notPersonal(),
        ];
    }

    /**
     * @param array<string, mixed> $data Wire form of the section state
     * @return static Restored section state
     * @throws InvalidFormatException When a required field is missing or has the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            phase: self::requireString($data, self::phase),
            operation: self::optionalString($data, self::operation),
            manual: self::requireBool($data, self::manual),
            startedAt: self::optionalString($data, self::startedAt),
            enteredFromBrowser: self::requireBool($data, self::enteredFromBrowser),
            passCount: self::requireInt($data, self::passCount),
            circleNamed: self::requireInt($data, self::circleNamed),
            circleAdmitted: self::requireInt($data, self::circleAdmitted),
        );
    }
}
