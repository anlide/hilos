<?php

declare(strict_types=1);

namespace Hilos\StandGateway;

/**
 * OAuthGrant - what one person granted at the consent screen (HIL-923).
 *
 * One type lies under both the code and the token, because the exchange does not create a right -
 * it MOVES one: the code is spent and the same grant is put under the token that replaces it. That
 * is also what makes the checks of the exchange expressible at all, since every one of them - the
 * client, the callback address - compares the call against what the screen already carried.
 *
 * The profile is kept as the value of its case rather than as {@see OAuthProfile} itself, because a
 * grant goes through the store's JSON; the resident turns it back into a case where it needs one.
 */
final readonly class OAuthGrant
{
    /** Stored key of the profile the grant was given at. */
    public const string FIELD_PROFILE = 'profile';

    /** Stored key of the account the grant was given for. */
    public const string FIELD_SUBJECT = 'subject';

    /** Stored key of the client the grant was given to. */
    public const string FIELD_CLIENT_ID = 'clientId';

    /** Stored key of the callback address the grant was given for. */
    public const string FIELD_REDIRECT_URI = 'redirectUri';

    /** Stored key of the scope the grant covers. */
    public const string FIELD_SCOPE = 'scope';

    /** Stored key of the moment the grant was given. */
    public const string FIELD_ISSUED_AT = 'issuedAt';

    /**
     * Creates a grant.
     *
     * @param string $profile Value of the {@see OAuthProfile} case the grant was given at
     * @param string $subject Id of the account the person chose
     * @param string $clientId Client the consent screen named
     * @param string $redirectUri Callback address the consent screen carried
     * @param string $scope Scope the consent screen asked for
     * @param int $issuedAt Unix time the grant was given, which is what its life is counted from
     */
    public function __construct(
        public string $profile,
        public string $subject,
        public string $clientId,
        public string $redirectUri,
        public string $scope,
        public int $issuedAt,
    ) {
    }

    /**
     * Rebuilds a grant from the form the store keeps it in.
     *
     * @param array{
     *     profile: string,
     *     subject: string,
     *     clientId: string,
     *     redirectUri: string,
     *     scope: string,
     *     issuedAt: int
     * } $stored Grant as {@see toArray()} wrote it
     * @return self Stored grant
     */
    public static function fromArray(array $stored): self
    {
        return new self(
            profile: $stored[self::FIELD_PROFILE],
            subject: $stored[self::FIELD_SUBJECT],
            clientId: $stored[self::FIELD_CLIENT_ID],
            redirectUri: $stored[self::FIELD_REDIRECT_URI],
            scope: $stored[self::FIELD_SCOPE],
            issuedAt: $stored[self::FIELD_ISSUED_AT],
        );
    }

    /**
     * The form the store keeps a grant in.
     *
     * @return array{
     *     profile: string,
     *     subject: string,
     *     clientId: string,
     *     redirectUri: string,
     *     scope: string,
     *     issuedAt: int
     * } Stored grant
     */
    public function toArray(): array
    {
        return [
            self::FIELD_PROFILE => $this->profile,
            self::FIELD_SUBJECT => $this->subject,
            self::FIELD_CLIENT_ID => $this->clientId,
            self::FIELD_REDIRECT_URI => $this->redirectUri,
            self::FIELD_SCOPE => $this->scope,
            self::FIELD_ISSUED_AT => $this->issuedAt,
        ];
    }
}
