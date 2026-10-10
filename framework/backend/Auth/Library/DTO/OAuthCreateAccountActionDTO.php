<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Auth\Registration\RegistrationConsent;
use Hilos\Auth\Registration\RegistrationThemePick;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * The consent submit that completes a provider's first sign-in (HIL-1235).
 * The guest's theme pick is written into the account when this submit creates one (HIL-1427).
 */
final class OAuthCreateAccountActionDTO extends ActionPayloadDTO
{
    public const string accountToken = 'accountToken';
    public const array SECRET_FIELDS = [self::accountToken];

    /**
     * @param string $accountToken Signed first sign-in capability
     * @param ?array<string, string> $acceptedRevisions Revisions accepted on the consent step
     * @param ?string $themePick Guest browser's choice, or null when they never picked
     */
    public function __construct(
        public readonly string $accountToken,
        public readonly ?array $acceptedRevisions,
        public readonly ?string $themePick = null,
    ) {
    }

    /** @return string Action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_OAUTH_CREATE_ACCOUNT;
    }

    /**
     * @param array<string, mixed> $data Action payload
     * @return static Parsed action
     * @throws InvalidFormatException When the account token is absent or it or the theme choice has the wrong type
     * @throws ValidationException When the acceptance map or theme choice is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            accountToken: self::requireString($data, self::accountToken),
            acceptedRevisions: RegistrationConsent::readPayload(
                self::optionalArray($data, RegistrationConsent::PAYLOAD_KEY),
            ),
            themePick: RegistrationThemePick::readPayload(self::optionalString($data, RegistrationThemePick::PAYLOAD_KEY)),
        );
    }

    /** @return array<string, mixed> Action payload */
    public function toArray(): array
    {
        return [
            self::accountToken => $this->accountToken,
            RegistrationConsent::PAYLOAD_KEY => $this->acceptedRevisions,
            RegistrationThemePick::PAYLOAD_KEY => $this->themePick,
        ];
    }
}
