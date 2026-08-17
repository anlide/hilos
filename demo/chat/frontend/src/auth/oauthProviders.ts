// The OAuth providers this demo has wired, in one place (HIL-419). The backend
// half of the same declaration is ChatOAuthConfig's enabled list, and the two are
// kept in the same order on purpose: the registry hands its keys back as
// configured, so this order is the order the buttons appear in — on the sign-in
// surface and in the profile's "Link an account" alike.
//
// Declared here and not derived from the server because until HIL-427 there is
// nothing to derive it from: the enabled set becomes a settings-owned registry
// there, and this module is what it will replace.

/** One provider a person can sign in with or attach to their account. */
export interface OAuthProviderOption {
  /** The provider key as the backend registry stores it, e.g. `oauth:github`. */
  readonly key: string
  /** The button caption shown to the person, e.g. `Continue with GitHub`. */
  readonly label: string
}

/** The providers this demo offers, in the order their buttons are shown. */
export const OAUTH_PROVIDERS: readonly OAuthProviderOption[] = [
  { key: 'oauth:github', label: 'Continue with GitHub' },
  { key: 'oauth:google', label: 'Continue with Google' },
]
