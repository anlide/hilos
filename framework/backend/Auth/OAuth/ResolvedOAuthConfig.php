<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

/**
 * ResolvedOAuthConfig - an OAuth provider field's effective value and source (HIL-286).
 *
 * The output of {@see OAuthConfigResolver} for one field of one provider, or for the
 * shared return address: the field name, where the effective value came from, the value,
 * and whether it resolved to anything at all. The value is always null for the client
 * secret - a secret is never sent to the browser - and {@see $isSet} carries its
 * set/not-set state instead.
 */
final readonly class ResolvedOAuthConfig
{
    /**
     * @param string $field Field name this resolution is for (an {@see OAuthConfigField} value, or the return address)
     * @param OAuthConfigSource $source Where the effective value came from
     * @param ?string $value Effective value, or null for the client secret
     * @param bool $isSet Whether the effective value is non-empty
     */
    public function __construct(
        public string $field,
        public OAuthConfigSource $source,
        public ?string $value,
        public bool $isSet,
    ) {
    }
}
