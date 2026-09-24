// The routes an auth flow comes BACK on (HIL-409): a magic link opened from a mail
// client, a provider's redirect after an OAuth consent screen, and the "it was not
// me" link of a second-factor removal (HIL-494). All are entered by something
// outside the app — an email, a provider — so both halves have to agree on the
// path, and until now the frontend carried it as a literal while the backend
// carried its own default.
//
// They are paths and not page keys: nothing subscribes here. Each is a relay that
// dispatches once and says or navigates on, which is why none appears in HilosPages.
// A deployment that serves them elsewhere overrides the backend redirect/link
// settings and registers its own routes; these are what the framework's own defaults
// point at.

/**
 * Where a magic-link email lands (backend default:
 * `EnvCatalogStub` `HILOS_AUTH_MAGIC_LINK_URL`).
 */
export const AUTH_MAGIC_LINK_PATH = '/auth/magic'

/**
 * Where an OAuth provider redirects back to (the path a project's
 * `OAUTH_REDIRECT_URI` ends in; the demos default to `/auth/callback`).
 */
export const AUTH_OAUTH_CALLBACK_PATH = '/auth/callback'

/**
 * Where the "it was not me" link of a second-factor removal lands (HIL-494;
 * backend default: `EnvCatalogStub` `HILOS_SECOND_FACTOR_CANCEL_URL`). It works
 * without signing in: the token in the link is the whole proof.
 */
export const AUTH_SECOND_FACTOR_CANCEL_PATH = '/auth/second-factor/cancel'
