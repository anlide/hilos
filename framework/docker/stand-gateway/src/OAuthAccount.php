<?php

declare(strict_types=1);

namespace Hilos\StandGateway;

/**
 * OAuthAccount - one account a spec declared to exist at a provider (HIL-923).
 *
 * A fact about the PROVIDER'S WORLD and not about any login in progress: declaring it says "an
 * account with this id exists over there", which a spec may do at any moment before the consent
 * screen's button is pressed. That is why there is no race between opening the provider's window
 * and arranging what it shows.
 *
 * The id is what every provider is honest about and nothing else is: GitHub carries a number and
 * Google a string of digits, so a declaration carries digits and each profile then hands them out
 * in its own type. A name or an email the declaration leaves out is an account that HAS none - the
 * case a real provider produces and HIL-573 was found in - and the login is never one of those: the
 * resident fills it in when a spec did not, because a real GitHub account always has one and making
 * a spec invent it would demand what the provider does not let anyone choose.
 */
final readonly class OAuthAccount
{
    /** Declaration key naming the provider the account lives at. */
    public const string FIELD_PROFILE = 'profile';

    /** Declaration and stored key of the account's immutable id at the provider. */
    public const string FIELD_SUBJECT = 'subject';

    /** Declaration and stored key of the account's login handle. */
    public const string FIELD_LOGIN = 'login';

    /** Declaration and stored key of the account's display name. */
    public const string FIELD_NAME = 'name';

    /** Declaration and stored key of the account's email. */
    public const string FIELD_EMAIL = 'email';

    /** Every key a declaration may carry. */
    public const array FIELDS = [
        self::FIELD_PROFILE,
        self::FIELD_SUBJECT,
        self::FIELD_LOGIN,
        self::FIELD_NAME,
        self::FIELD_EMAIL,
    ];

    /**
     * Creates an account of the provider's world.
     *
     * @param string $subject Immutable id at the provider, a non-empty run of digits
     * @param string $login Login handle, never empty
     * @param ?string $name Display name, null when the account has none
     * @param ?string $email Email, null when the account has none
     */
    public function __construct(
        public string $subject,
        public string $login,
        public ?string $name,
        public ?string $email,
    ) {
    }

    /**
     * Rebuilds an account from the form the store keeps it in.
     *
     * @param array{subject: string, login: string, name: ?string, email: ?string} $stored Account as {@see toArray()} wrote it
     * @return self Stored account
     */
    public static function fromArray(array $stored): self
    {
        return new self(
            subject: $stored[self::FIELD_SUBJECT],
            login: $stored[self::FIELD_LOGIN],
            name: $stored[self::FIELD_NAME],
            email: $stored[self::FIELD_EMAIL],
        );
    }

    /**
     * The form the store keeps an account in.
     *
     * @return array{subject: string, login: string, name: ?string, email: ?string} Stored account
     */
    public function toArray(): array
    {
        return [
            self::FIELD_SUBJECT => $this->subject,
            self::FIELD_LOGIN => $this->login,
            self::FIELD_NAME => $this->name,
            self::FIELD_EMAIL => $this->email,
        ];
    }
}
