// The two routes an auth flow comes BACK on (HIL-409): a magic link opened from a
// mail client, and a provider's redirect after an OAuth consent screen. Both are
// entered by something outside the app — an email, a provider — so both halves have
// to agree on the path, and until now the frontend carried it as a literal while the
// backend carried its own default.
//
// They are paths and not page keys: nothing subscribes here. Each is a relay that
// dispatches once and navigates away, which is why neither appears in HilosPages.
// A deployment that serves them elsewhere overrides the backend redirect/link
// settings and registers its own routes; these are what the framework's own defaults
// point at.

/**
 * Where a magic-link email lands (backend default:
 * `EnvCatalogStub` `HILOS_AUTH_MAGIC_LINK_URL`).
 */
export const AUTH_MAGIC_LINK_PATH = '/auth/magic'

/**
 * Where an OAuth provider redirects back to (backend default:
 * `StubOAuthProvider` `$redirectUri`).
 */
export const AUTH_OAUTH_CALLBACK_PATH = '/auth/callback'
