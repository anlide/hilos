<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

/**
 * OAuthConfigField - the fields of one OAuth provider an administrator configures (HIL-286).
 *
 * The value of a case is the name the field travels under on the wire and the name of
 * its column in hilos_oauth_provider. Everything else a provider runs on - its
 * endpoints and its userinfo field map - is the recipe's
 * ({@see OAuthProviderPreset}, {@see OAuthProviderConfig}) and is shown, not edited.
 */
enum OAuthConfigField: string
{
    case CLIENT_ID = 'client_id';

    case CLIENT_SECRET = 'client_secret';

    case SCOPE = 'scope';

    /**
     * Human label of the field on the provider screen.
     *
     * @return string Field label
     */
    public function label(): string
    {
        return match ($this) {
            self::CLIENT_ID => 'Client ID',
            self::CLIENT_SECRET => 'Client secret',
            self::SCOPE => 'Scope',
        };
    }

    /**
     * Whether the field is write-only: accepted and replaced, never read back out.
     *
     * @return bool True for the client secret
     */
    public function isSecret(): bool
    {
        return $this === self::CLIENT_SECRET;
    }

    /**
     * Whether a provider cannot sign anyone in while this field resolves empty.
     *
     * @return bool True for the client id and the client secret
     */
    public function isRequired(): bool
    {
        return $this !== self::SCOPE;
    }
}
