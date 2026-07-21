<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

use Hilos\Auth\OAuth\Exception\OAuthProviderException;

/**
 * An OAuth provider that resolves the code in-process, with no HTTP (HIL-281).
 *
 * The dev/e2e seam: it lets the auth e2e run offline with no real provider
 * round-trip (mirrors the verification layer's dev-stub deliverer). The async
 * agent detects this variant and calls {@see resolve()} directly instead of
 * driving sockets. {@see StubOAuthProvider} is the framework implementation.
 */
interface OfflineOAuthProvider extends OAuthProviderInterface
{
    /**
     * Resolves the authorization code to an account identity without any I/O.
     *
     * @param string $code Authorization code returned to the SPA callback
     * @return OAuthUserInfo Resolved subject/email
     * @throws OAuthProviderException When the code cannot be resolved
     */
    public function resolve(string $code): OAuthUserInfo;
}
