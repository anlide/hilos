<?php

declare(strict_types=1);

namespace Hilos\Auth\Impersonation\DTO;

use Hilos\Auth\Impersonation\ImpersonationSettings;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;

/**
 * The impersonation settings a person's card is drawn from (HIL-1170).
 *
 * Whether the card has the section at all, the words of its row and its confirmation - only look,
 * with or without the administrator's own rights - and which people the button is switched off
 * for. They arrive in the card's first answer, the way the deletion's grace period does; later
 * setting changes arrive live as {@see ImpersonationPolicySignalData} (HIL-1307). They are settings
 * of the installation, not facts about the person.
 */
final readonly class ImpersonationCardSettings
{
    /** Wire key: whether impersonation exists in the product. */
    public const string allowed = 'allowed';

    /** Wire key: what may be done inside, one of {@see ImpersonationSettings::SCOPE_VALUES}. */
    public const string scope = 'scope';

    /** Wire key: whether the administrator carries their own admin rights inside. */
    public const string carryAdmin = 'carryAdmin';

    /** Wire key: whether a blocked person may be taken over. */
    public const string blocked = 'blocked';

    /** Wire key: whether a frozen person may be taken over. */
    public const string frozen = 'frozen';

    /** Wire key: whether another administrator may be taken over. */
    public const string equal = 'equal';

    /**
     * @param bool $allowed Whether impersonation exists in the product
     * @param string $scope What may be done inside, one of {@see ImpersonationSettings::SCOPE_VALUES}
     * @param bool $carryAdmin Whether the administrator carries their own admin rights inside
     * @param bool $blocked Whether a blocked person may be taken over
     * @param bool $frozen Whether a frozen person may be taken over
     * @param bool $equal Whether another administrator may be taken over
     */
    public function __construct(
        public bool $allowed,
        public string $scope,
        public bool $carryAdmin,
        public bool $blocked,
        public bool $frozen,
        public bool $equal,
    ) {
    }

    /**
     * Reads the settings in force now.
     *
     * @return self The card's settings
     * @throws DatabaseException When a stored setting cannot be read
     * @throws SettingException When the setting catalog or a value is invalid
     */
    public static function current(): self
    {
        return new self(
            allowed: ImpersonationSettings::isAllowed(),
            scope: ImpersonationSettings::scope(),
            carryAdmin: ImpersonationSettings::carriesAdmin(),
            blocked: ImpersonationSettings::allowsBlocked(),
            frozen: ImpersonationSettings::allowsFrozen(),
            equal: ImpersonationSettings::allowsEqual(),
        );
    }

    /**
     * @return array{allowed: bool, scope: string, carryAdmin: bool, blocked: bool, frozen: bool, equal: bool} Wire shape
     */
    public function toArray(): array
    {
        return [
            self::allowed => $this->allowed,
            self::scope => $this->scope,
            self::carryAdmin => $this->carryAdmin,
            self::blocked => $this->blocked,
            self::frozen => $this->frozen,
            self::equal => $this->equal,
        ];
    }
}
