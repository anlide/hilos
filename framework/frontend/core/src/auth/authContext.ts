// What a project hands the framework's sign-in surface (HIL-409): where the data
// lives, and which ways in this deployment offers. Everything else — the machine,
// the wire, the screens, the copy — is the framework's.
//
// The shape mirrors `HilosSettingsContext`: the same {connection, scopes, actions}
// triple, plus the four declarations only the project can make. The method registry
// is the extension point that replaces flags and options — a deployment differs by
// what it declares here, never by a switch on the surface (HIL-423 made the surface
// method-agnostic, and this is the other half of that).
//
// `pendingAck` and `pendingRegistration` are deliberately NOT here: the surface
// derives both from `scopes` through the framework's own session factories, so a
// project cannot hand in a stale copy of state the framework already owns.
import { ActionLifecycle } from '../connection/actionLifecycle.js'
import { type HilosConnection } from '../connection/HilosConnection.js'
import { type ScopeManager } from '../state/ScopeManager.js'
import {
  type AuthFlowMethodDescriptor,
  type CodeChannelDescriptor,
} from './authFlow.js'

/** One OAuth provider a person can sign in with or attach to their account. */
export interface HilosOAuthProviderOption {
  /** The provider key as the backend registry stores it, e.g. `oauth:github`. */
  readonly key: string
  /** The button caption shown to the person, e.g. `Continue with GitHub`. */
  readonly label: string
  /**
   * The provider's short name, e.g. `GitHub`, for copy that names it in a
   * sentence — the "Waiting for GitHub" heading a trip shows (HIL-633). Declared
   * rather than derived from the key, because deriving it would impose our casing
   * on somebody else's brand.
   */
  readonly name: string
}

/**
 * The project-supplied context the sign-in surface and its wire read from.
 *
 * Built by {@link createHilosAuthContext}, which is what refuses a registry with
 * no way in at all.
 */
export interface HilosAuthContext {
  /** The connection the surface's inbound flow signals arrive on. */
  readonly connection: HilosConnection
  /** The scope manager owning the session scope the surface reads pending state from. */
  readonly scopes: ScopeManager
  /** The action lifecycle every auth command dispatches over. */
  readonly actions: ActionLifecycle
  /**
   * The project's ORDERED enabled methods: the identifier method first (it owns
   * the shared field), then the icon methods in the order their buttons appear.
   */
  readonly methods: readonly AuthFlowMethodDescriptor[]
  /** The project's ORDERED code delivery channels (HIL-492); may be empty. */
  readonly channels: readonly CodeChannelDescriptor[]
  /** The OAuth providers this deployment wired, in button order; may be empty. */
  readonly oauthProviders: readonly HilosOAuthProviderOption[]
  /** Where this deployment serves the terms the consent screen links to. */
  readonly termsPath: string
  /** Where this deployment serves the privacy policy the consent screen links to. */
  readonly privacyPath: string
}

/**
 * Build the auth context, refusing one that declares no method at all.
 *
 * A surface with an empty registry renders a screen nobody can sign in from, and
 * it would render it silently — the field would be there, the button would be
 * there, and every path out of it would be missing. That is a wiring mistake, and
 * a wiring mistake belongs at startup where whoever made it is watching, not at
 * the moment a person tries to sign in. Nothing else is checked here: a method
 * declared without its backend handler answers an ordinary action error, and an
 * approximate guard around it would only hide which half is missing.
 *
 * @param context The project's declarations and stores.
 * @returns The same context, once it is usable.
 * @throws Error When the method registry is empty.
 */
export function createHilosAuthContext(
  context: HilosAuthContext,
): HilosAuthContext {
  if (context.methods.length === 0) {
    throw new Error(
      'The Hilos auth surface needs at least one method: declare the project methods in its auth context.',
    )
  }

  return context
}
