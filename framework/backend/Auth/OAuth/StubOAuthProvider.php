<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

use Hilos\Auth\OAuth\Exception\OAuthProviderException;

/**
 * An offline OAuth provider for dev and e2e (HIL-281).
 *
 * The dev-stub that lets the auth e2e drive the whole login flow with no real
 * provider round-trip (mirrors the verification layer's LogVerificationDeliverer).
 * Its authorize URL redirects straight back to the SPA callback carrying a canned
 * code, and {@see resolve()} maps that code to a deterministic account so a test
 * can log in as distinct identities by varying the code. No network, no sockets.
 *
 * The code also carries the withholding markers a real provider expresses by
 * omitting a userinfo field: a code containing {@see MARKER_NO_EMAIL} resolves
 * with no email, one containing {@see MARKER_NO_NAME} with no name (HIL-573).
 * They are read as substrings, not as whole codes, so one code asks for both.
 */
final class StubOAuthProvider implements OfflineOAuthProvider
{
    public const string DEFAULT_KEY = 'oauth:stub';
    public const string DEFAULT_CODE = 'stub';

    /** Substring of the code asking the stub to resolve with no email. */
    public const string MARKER_NO_EMAIL = 'noemail';

    /** Substring of the code asking the stub to resolve with no name. */
    public const string MARKER_NO_NAME = 'noname';

    /** Prefix of the display name the stub derives from the code. */
    public const string NAME_PREFIX = 'stub-';

    /**
     * @param string $key Provider key this stub answers to
     * @param string $redirectUri SPA callback the authorize URL bounces back to
     * @param string $defaultCode Code carried by the bounced authorize URL
     */
    public function __construct(
        private readonly string $key = self::DEFAULT_KEY,
        private readonly string $redirectUri = '/auth/callback',
        private readonly string $defaultCode = self::DEFAULT_CODE,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function buildAuthorizeUrl(string $state): string
    {
        $query = http_build_query(
            [
                'code' => $this->defaultCode,
                'state' => $state,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );

        $separator = str_contains($this->redirectUri, '?') ? '&' : '?';

        return $this->redirectUri . $separator . $query;
    }

    /**
     * Resolves the canned account, honouring the same output contract a real provider does.
     *
     * The address is lowercased like {@see GenericOAuthProvider} lowercases the one
     * it reads: normalization belongs to the provider (HIL-573), and the code that
     * feeds it comes off a client-supplied callback query, so a mixed-case one would
     * otherwise reach the account resolver, which takes a lowercased address and does
     * not normalize. The subject keeps the code verbatim — it is an identity, not an
     * address — so two codes differing only in case remain two accounts sharing one
     * address, which is the collision the dev flow wants to be able to stage.
     *
     * @param string $code Authorization code returned to the SPA callback
     * @return OAuthUserInfo Resolved subject, email and name
     * @throws OAuthProviderException When the code is empty
     */
    public function resolve(string $code): OAuthUserInfo
    {
        if ($code === '') {
            throw new OAuthProviderException('OAuth stub code is empty');
        }

        return new OAuthUserInfo(
            'stub:' . $code,
            str_contains($code, self::MARKER_NO_EMAIL) ? null : mb_strtolower($code) . '@stub.local',
            str_contains($code, self::MARKER_NO_NAME) ? null : self::NAME_PREFIX . $code,
        );
    }
}
