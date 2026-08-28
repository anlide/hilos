// The OAuth providers this demo has wired, in one place (HIL-419). The backend
// half of the same declaration is TasksOAuthConfig's enabled list, and the two
// are kept in the same order on purpose: the registry hands its keys back as
// configured, so this order is the order the buttons appear in.
//
// Declared here and not derived from the server because until HIL-427 there is
// nothing to derive it from: the enabled set becomes a settings-owned registry
// there, and this module is what it will replace.
import { type HilosOAuthProviderOption } from '@hilos/core'

/** The providers this demo offers, in the order their buttons are shown. */
export const OAUTH_PROVIDERS: readonly HilosOAuthProviderOption[] = [
  { key: 'oauth:github', label: 'Continue with GitHub', name: 'GitHub' },
  { key: 'oauth:google', label: 'Continue with Google', name: 'Google' },
]
