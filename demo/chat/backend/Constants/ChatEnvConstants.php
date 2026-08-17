<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

/**
 * Chat demo project-specific environment keys.
 */
final class ChatEnvConstants
{
    public const string CHAT_FILES_QUARANTINE_DIR = 'CHAT_FILES_QUARANTINE_DIR';

    public const string CHAT_FILES_PUBLISHED_DIR = 'CHAT_FILES_PUBLISHED_DIR';

    /**
     * Internal nginx location prefix for X-Accel-Redirect file serving. Empty
     * means the daemon streams attachment bytes itself (dev, no web server in
     * front); a non-empty prefix delegates streaming to nginx (test/prod).
     */
    public const string CHAT_FILES_XACCEL_LOCATION = 'CHAT_FILES_XACCEL_LOCATION';

    /**
     * Secret for signing the stateless OAuth `state` token (HMAC-bound to the
     * session, expiring). Env-only; the dev/e2e default is non-empty so the
     * offline stub flow works, but a real deployment must override it.
     */
    public const string OAUTH_STATE_SECRET = 'OAUTH_STATE_SECRET';

    /** GitHub OAuth client id. Empty selects the offline stub provider (dev/e2e). */
    public const string OAUTH_GITHUB_CLIENT_ID = 'OAUTH_GITHUB_CLIENT_ID';

    /** GitHub OAuth client secret (env-only). Empty selects the offline stub provider. */
    public const string OAUTH_GITHUB_CLIENT_SECRET = 'OAUTH_GITHUB_CLIENT_SECRET';

    /** Google OAuth client id. Empty selects the offline stub provider (dev/e2e). */
    public const string OAUTH_GOOGLE_CLIENT_ID = 'OAUTH_GOOGLE_CLIENT_ID';

    /** Google OAuth client secret (env-only). Empty selects the offline stub provider. */
    public const string OAUTH_GOOGLE_CLIENT_SECRET = 'OAUTH_GOOGLE_CLIENT_SECRET';

    /**
     * SPA callback URL every provider redirects back to after authorization.
     *
     * One key for all of them, not one per provider: the address belongs to this
     * application, which has a single `/auth/callback` route, and which provider
     * is coming back is read from the browser's own sessionStorage rather than
     * from the address it lands on.
     */
    public const string OAUTH_REDIRECT_URI = 'OAUTH_REDIRECT_URI';
}
