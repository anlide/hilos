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
 * The impersonation settings an open tab draws a takeover and card by, sent on the handshake and to
 * every connection when they change (HIL-1170, HIL-1307).
 *
 * All seven settings of the installation in one snapshot: whether takeover is allowed in the product,
 * whether the administrator only looks (viewOnly representing scope='view'), whether the takeover carries
 * the administrator's own rights (carryAdmin), whether account access is permitted, and whether blocked,
 * frozen or equal accounts may be taken over. An open card and an open takeover react without a reload.
 */
final class ImpersonationPolicySignalData extends BaseDTO implements SignalDataInterface
{
    public const string viewOnly = 'viewOnly';
    public const string carryAdmin = 'carryAdmin';
    public const string allowed = 'allowed';
    public const string accountAccess = 'accountAccess';
    public const string blocked = 'blocked';
    public const string frozen = 'frozen';
    public const string equal = 'equal';

    /**
     * @param bool $viewOnly Whether inside a takeover the administrator only looks
     * @param bool $carryAdmin Whether the administrator carries their own admin rights inside
     * @param bool $allowed Whether impersonation exists in the product
     * @param bool $accountAccess Whether the sign-in of the account taken over may be touched
     * @param bool $blocked Whether a blocked person may be taken over
     * @param bool $frozen Whether a frozen person may be taken over
     * @param bool $equal Whether another administrator may be taken over
     */
    public function __construct(
        public readonly bool $viewOnly,
        public readonly bool $carryAdmin,
        public readonly bool $allowed,
        public readonly bool $accountAccess,
        public readonly bool $blocked,
        public readonly bool $frozen,
        public readonly bool $equal,
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
        return new self(
            ImpersonationSettings::isViewOnly(),
            ImpersonationSettings::carriesAdmin(),
            ImpersonationSettings::isAllowed(),
            ImpersonationSettings::allowsAccountAccess(),
            ImpersonationSettings::allowsBlocked(),
            ImpersonationSettings::allowsFrozen(),
            ImpersonationSettings::allowsEqual(),
        );
    }

    /**
     * @return array{viewOnly: bool, carryAdmin: bool, allowed: bool, accountAccess: bool, blocked: bool, frozen: bool, equal: bool} Wire form
     */
    public function toArray(): array
    {
        return [
            self::viewOnly => $this->viewOnly,
            self::carryAdmin => $this->carryAdmin,
            self::allowed => $this->allowed,
            self::accountAccess => $this->accountAccess,
            self::blocked => $this->blocked,
            self::frozen => $this->frozen,
            self::equal => $this->equal,
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
            self::requireBool($data, self::allowed),
            self::requireBool($data, self::accountAccess),
            self::requireBool($data, self::blocked),
            self::requireBool($data, self::frozen),
            self::requireBool($data, self::equal),
        );
    }
}
