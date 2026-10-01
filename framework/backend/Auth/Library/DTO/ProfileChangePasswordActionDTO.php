<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * The final password-change submit; the password is preserved byte for byte (HIL-300).
 *
 * It carries no code: the proof that the account's address answered is the session's record of
 * the matched code (HIL-1182), read by the handler.
 */
final class ProfileChangePasswordActionDTO extends ActionPayloadDTO
{
    public const string NEW_PASSWORD = 'newPassword';
    public const string SIGN_OUT_OTHERS = 'signOutOthers';

    public const array SECRET_FIELDS = [self::NEW_PASSWORD];

    /**
     * @param string $newPassword New password to set
     * @param bool $signOutOthers Whether to end the person's other sessions
     */
    public function __construct(
        public readonly string $newPassword,
        public readonly bool $signOutOthers,
    ) {
    }

    /** @return string Action name */
    public function getAction(): string
    {
        return HilosSignalConstants::PROFILE_CHANGE_PASSWORD;
    }

    /**
     * @param array<string, mixed> $data Submitted payload
     * @return static Parsed submit
     * @throws InvalidFormatException When a required member is absent or mistyped
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, self::NEW_PASSWORD),
            self::requireBool($data, self::SIGN_OUT_OTHERS),
        );
    }

    /** @return array{newPassword: string, signOutOthers: bool} Submitted payload */
    public function toArray(): array
    {
        return [
            self::NEW_PASSWORD => $this->newPassword,
            self::SIGN_OUT_OTHERS => $this->signOutOthers,
        ];
    }

    /** @return bool Whether a new password was supplied */
    public function isValid(): bool
    {
        return $this->newPassword !== '';
    }
}
