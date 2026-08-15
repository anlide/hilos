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
 */
final class StubOAuthProvider implements OfflineOAuthProvider
{
    public const string DEFAULT_KEY = 'oauth:stub';
    public const string DEFAULT_CODE = 'stub';

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
     * @param string $code Authorization code returned to the SPA callback
     * @return OAuthUserInfo Resolved subject/email
     * @throws OAuthProviderException When the code is empty
     */
    public function resolve(string $code): OAuthUserInfo
    {
        if ($code === '') {
            throw new OAuthProviderException('OAuth stub code is empty');
        }

        $subject = 'stub:' . $code;
        $email = $code . '@stub.local';

        return new OAuthUserInfo($subject, $email);
    }
}
