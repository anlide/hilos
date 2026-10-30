import {
  pageIdentity,
  type HilosPageIdentity,
} from '../admin/identity/hilosPageIdentity.js'
import { bindPageInstanceName } from '../admin/identity/hilosPageInstanceName.js'
import { HilosPages } from '../routing/hilosPages.js'
import { sessionUserName, sessionUserPhoto } from '../session/sessionScope.js'
import {
  computedSignal,
  type ReadonlySignal,
  type Unsubscribe,
} from '../state/signal.js'
import { createHilosProfileSignInMethods } from './profileIdentities.js'
import { type HilosProfilePageContext } from './profileRoot.js'
import {
  hilosProfilePasswordState,
  isHilosProfilePasskeyOnly,
} from './profileSignInMethods.js'
import { createHilosSecondFactorStore } from './secondFactor.js'

const NO_HIDDEN_SECTIONS: readonly string[] = []
const SECURITY_HIDDEN: readonly string[] = [HilosPages.PROFILE_SECURITY]

/** The shared profile head's page-scoped presentation and live account facts. */
export interface HilosProfileHeadStore {
  /** The person's current name; empty while the session has none. */
  readonly name: ReadonlySignal<string>
  /** Whether this project enabled changing the person's photo. */
  readonly photoEnabled: ReadonlySignal<boolean>
  /** The current published photo URL, or null. */
  readonly photo: ReadonlySignal<string | null>
  /** The account's verified address, or null when none is confirmed. */
  readonly verifiedEmail: ReadonlySignal<string | null>
  /** Whether passkeys are the only way in; false before the list arrives. */
  readonly passkeyOnly: ReadonlySignal<boolean>
  /** Profile sections inapplicable to the current second-factor state. */
  readonly hiddenSections: ReadonlySignal<readonly string[]>
  /** The current page's head and eligible tabs, absent before its answer. */
  readonly identity: ReadonlySignal<HilosPageIdentity | undefined>
  /** Listen for second-factor changes and bind the live name to the owner. */
  start(): void
  /**
   * Reclaim the started head's live name without restarting its listeners.
   * Inactive heads stay inactive; dispose releases the reclaimed binding.
   */
  claimNameBinding(): void
  /** Release this head's listeners and name binding; setup may run again. */
  dispose(): void
}

/**
 * Create one profile head over the current page, session and existing live sources.
 *
 * @param context The profile page's connection, scopes and actions.
 * @param name The project's name binding, defaulting to the session name.
 */
export function createHilosProfileHeadStore(
  context: HilosProfilePageContext,
  name: ReadonlySignal<string> = sessionUserName(context.scopes),
): HilosProfileHeadStore {
  const secondFactor = createHilosSecondFactorStore(context)
  const methods = createHilosProfileSignInMethods(context.scopes)
  const currentIdentity = pageIdentity(context.scopes)
  const photoEnabled = context.scopes.pageDataSignal('profilePhoto')
  let stopName: Unsubscribe | null = null

  const hiddenSections = computedSignal(() => {
    const security = secondFactor.state.get()

    return security !== null &&
      !security.enabled &&
      security.authenticators.length === 0 &&
      security.reset === null
      ? SECURITY_HIDDEN
      : NO_HIDDEN_SECTIONS
  })

  return {
    name,
    photoEnabled: computedSignal(() => photoEnabled.get() === true),
    photo: sessionUserPhoto(context.scopes),
    verifiedEmail: computedSignal(
      () => hilosProfilePasswordState(methods.get()).verifiedEmail,
    ),
    passkeyOnly: computedSignal(() => isHilosProfilePasskeyOnly(methods.get())),
    hiddenSections,
    identity: computedSignal(() => {
      const current = currentIdentity.get()
      if (current?.pageTabs === undefined) return current
      const hidden = hiddenSections.get()

      return {
        ...current,
        pageTabs: current.pageTabs.filter((tab) => !hidden.includes(tab.page)),
      }
    }),
    start() {
      if (stopName !== null) return
      stopName = bindPageInstanceName(context.scopes, HilosPages.PROFILE, name)
      secondFactor.start()
    },
    claimNameBinding() {
      if (stopName === null) return
      stopName = bindPageInstanceName(context.scopes, HilosPages.PROFILE, name)
    },
    dispose() {
      stopName?.()
      stopName = null
      secondFactor.dispose()
    },
  }
}
