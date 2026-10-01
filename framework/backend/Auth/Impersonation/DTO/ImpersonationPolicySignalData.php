<?php

declare(strict_types=1);

namespace Hilos\Auth\Impersonation\DTO;

use Hilos\Auth\Impersonation\ImpersonationSettings;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;

/**
 * The impersonation settings an open tab draws a takeover by, sent on the handshake and to every connection when they change (HIL-1170).
 *
 * Two of the seven and no more: whether inside a takeover the administrator only looks - the strip
 * says so and the page's buttons stand switched off - and whether the administrator carries their
 * own admin rights inside - the gear and the admin routes open. Both decide what a tab already
 * open inside a takeover shows, so a change reaches it without a reload. The other five are read by
 * the server at the start of a takeover or on an action, and no screen redraws by them.
 */
final class ImpersonationPolicySignalData extends BaseDTO implements SignalDataInterface
{
    public const string viewOnly = 'viewOnly';
    public const string carryAdmin = 'carryAdmin';

    /**
     * @param bool $viewOnly Whether inside a takeover the administrator only looks
     * @param bool $carryAdmin Whether the administrator carries their own admin rights inside
     */
    public function __construct(
        public readonly bool $viewOnly,
        public readonly bool $carryAdmin,
    ) {
    }

    /**
     * Builds the signal from the settings in force.
     *
     * @return self Signal payload
     * @throws DatabaseException When a stored setting cannot be read
     * @throws SettingException When the setting catalog or a value is invalid
     */
    public static function current(): self
    {
        return new self(ImpersonationSettings::isViewOnly(), ImpersonationSettings::carriesAdmin());
    }

    /**
     * @return array{viewOnly: bool, carryAdmin: bool} Wire form
     */
    public function toArray(): array
    {
        return [
            self::viewOnly => $this->viewOnly,
            self::carryAdmin => $this->carryAdmin,
        ];
    }

    /**
     * @param array<string, mixed> $data Wire form
     * @return static Restored payload
     * @throws InvalidFormatException When a member is absent or of another type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireBool($data, self::viewOnly),
            self::requireBool($data, self::carryAdmin),
        );
    }
}
