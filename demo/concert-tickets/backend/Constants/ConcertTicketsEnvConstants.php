<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Constants;

/**
 * Concert Tickets demo project-specific environment keys.
 *
 * The OAuth names are literally the ones the tasks, chat and polls demos use: these
 * describe the same external provider, and demos spelling one provider's credentials
 * differently would cost every reader a comparison.
 */
final class ConcertTicketsEnvConstants
{
    /** Published files directory override; empty uses data/files/published. */
    public const string CONCERT_TICKETS_FILES_PUBLISHED_DIR = 'CONCERT_TICKETS_FILES_PUBLISHED_DIR';

    /**
     * Secret for signing the stateless OAuth `state` token (HMAC-bound to the
     * session, expiring). Env-only; the dev/e2e default is non-empty so a stand
     * signs in with no setup, but a real deployment must override it.
     */
    public const string OAUTH_STATE_SECRET = 'OAUTH_STATE_SECRET';

    /** GitHub OAuth client id. Empty leaves GitHub out - no icon, no sign-in. */
    public const string OAUTH_GITHUB_CLIENT_ID = 'OAUTH_GITHUB_CLIENT_ID';

    /** GitHub OAuth client secret (env-only). Empty leaves GitHub out. */
    public const string OAUTH_GITHUB_CLIENT_SECRET = 'OAUTH_GITHUB_CLIENT_SECRET';

    /**
     * SPA callback URL the provider redirects back to after authorization.
     *
     * One key for every provider, not one per provider: the address belongs to this
     * application, which has a single `/auth/callback` route, and which provider
     * is coming back is read from the browser's own sessionStorage rather than
     * from the address it lands on.
     */
    public const string OAUTH_REDIRECT_URI = 'OAUTH_REDIRECT_URI';
}
