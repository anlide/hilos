// What a project hands the framework's sign-in surface (HIL-409): where the data
// lives, and how codes and legal links are named in this deployment. Everything
// else — the machine, the wire, the screens, the copy — is the framework's.
//
// The shape mirrors `HilosSettingsContext`: the same {connection, scopes, actions}
// triple, plus the declarations only the project can make. Which ways in the
// surface offers is NOT one of them any more (HIL-427): the installation's set
// arrives from the server in the session scope (`sessionAuthMethods`, the enabled
// methods it can serve — HIL-1080), an administrator narrows it from the admin,
// and the machine builds the buttons from it — a project that listed descriptors
// here would be a second opinion on a set it no longer owns.
//
// `pendingAck` and `pendingAuthStep` are deliberately NOT here either: the surface
// derives both from `scopes` through the framework's own session factories, so a
// project cannot hand in a stale copy of state the framework already owns.
import { ActionLifecycle } from '../connection/actionLifecycle.js'
import { type HilosConnection } from '../connection/HilosConnection.js'
import { type AuthMethodEntry } from '../session/sessionScope.js'
import { type ScopeManager } from '../state/ScopeManager.js'
import { OAUTH_METHOD_PREFIX, type CodeChannelDescriptor } from './authFlow.js'

/** One OAuth provider a person can sign in with or attach to their account. */
export interface HilosOAuthProviderOption {
  /** The provider key as the backend registry stores it, e.g. `oauth:github`. */
  readonly key: string
  /** The button caption shown to the person, e.g. `Continue with GitHub`. */
  readonly label: string
  /**
   * The provider's short name, e.g. `GitHub`, for copy that names it in a
   * sentence — the "Waiting for GitHub" heading a trip shows (HIL-633). The
   * server's, from the project's provider directory, rather than derived from
   * the key, because deriving it would impose our casing on somebody else's brand.
   */
  readonly name: string
}

/**
 * The OAuth providers of an offered method set, as options for a button row
 * (HIL-427) — the sign-in icons and the profile's "Link an account" read the
 * same set, so a provider switched off or left without its client pair
 * (HIL-1080) leaves both.
 *
 * @param entries The offered methods, in button order.
 * @returns The providers among them, in the same order.
 */
export function oauthProviderOptionsFor(
  entries: readonly AuthMethodEntry[],
): readonly HilosOAuthProviderOption[] {
  const options: HilosOAuthProviderOption[] = []
  for (const entry of entries) {
    if (entry.key.startsWith(OAUTH_METHOD_PREFIX)) {
      const name = entry.name ?? entry.key
      options.push({ key: entry.key, label: `Continue with ${name}`, name })
    }
  }

  return options
}

/**
 * The project-supplied context the sign-in surface and its wire read from.
 *
 * Built by {@link createHilosAuthContext}.
 */
export interface HilosAuthContext {
  /** The connection the surface's inbound flow signals arrive on. */
  readonly connection: HilosConnection
  /** The scope manager owning the session scope the surface reads pending state from. */
  readonly scopes: ScopeManager
  /** The action lifecycle every auth command dispatches over. */
  readonly actions: ActionLifecycle
  /** The project's ORDERED code delivery channels (HIL-492); may be empty. */
  readonly channels: readonly CodeChannelDescriptor[]
  /** Where this deployment serves the terms the consent screen links to. */
  readonly termsPath: string
  /** Where this deployment serves the privacy policy the consent screen links to. */
  readonly privacyPath: string
}

/**
 * Build the auth context.
 *
 * It used to refuse a context declaring no method at all; that check left with
 * the declaration (HIL-427). The set is the installation's now, and the rule
 * that keeps it from being empty is the setting's own — the backend refuses a
 * write that would switch the last method off, whichever door it comes through.
 *
 * @param context The project's declarations and stores.
 * @returns The same context.
 */
export function createHilosAuthContext(
  context: HilosAuthContext,
): HilosAuthContext {
  return context
}
