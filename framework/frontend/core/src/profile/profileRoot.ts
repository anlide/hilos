// The profile root (HIL-1169): the page every project mounts at /profile. The
// framework draws it — the person's own line, the Account rows, a row per
// section of the catalog with its live summary, the danger zone — and the
// project hands it only what the framework cannot know: the person's name, its
// own rename if it has one, and the lists behind the summaries only it keeps.
//
// The summaries of the framework's own sections come from the answer of the
// page (notifications, two-step verification, agreements, the data copy) and
// stay live through their stores; a summary whose list the project did not hand
// over is empty.
import { type ActionLifecycle } from '../connection/actionLifecycle.js'
import { type HilosConnection } from '../connection/HilosConnection.js'
import {
  createHilosLegalAgreementsStore,
  describeHilosLegalAgreements,
} from '../legal/legalAgreements.js'
import {
  describeHilosNotificationChannels,
  hilosNotificationPreferences,
  startHilosNotificationPreferences,
} from '../notifications/notificationPreferences.js'
import { HilosPages } from '../routing/hilosPages.js'
import { sessionAuthMethods, sessionUserId } from '../session/sessionScope.js'
import { type ScopeManager } from '../state/ScopeManager.js'
import { computedSignal, type ReadonlySignal } from '../state/signal.js'
import {
  createHilosDataExportStore,
  describeHilosDataExport,
  profileDataExportNode,
} from './dataExport.js'
import {
  describeHilosProfileSignInMethods,
  hilosProfilePasswordState,
  type HilosProfileSignInMethod,
} from './profileSignInMethods.js'
import { type HilosProfileRename } from './profileRename.js'
import { createHilosSecondFactorStore } from './secondFactor.js'

/** The connection, scopes and actions the profile root and its windows run over. */
export interface HilosProfilePageContext {
  /** The connection the sections' frames arrive on. */
  readonly connection: HilosConnection
  /** The scope manager whose page data carries the first copies. */
  readonly scopes: ScopeManager
  /** The action lifecycle the windows dispatch over. */
  readonly actions: ActionLifecycle
}

/** What only the project knows about the person, handed to the profile root. */
export interface HilosProfileBinding {
  /** The person's name, '' while unknown; the page shows a placeholder until it arrives. */
  readonly name: ReadonlySignal<string>
  /** The project's own rename, or null — Name then shows without Change. */
  readonly rename: HilosProfileRename | null
  /** The account's ways in, or null — no Email row and an empty summary then. */
  readonly signInMethods: ReadonlySignal<
    readonly HilosProfileSignInMethod[]
  > | null
  /** How many sign-ins are active, or null for an empty summary. */
  readonly sessionCount: ReadonlySignal<number> | null
  /** How many devices take push, or null for an empty summary. */
  readonly deviceCount: ReadonlySignal<number> | null
}

/** The profile root's words, as the chat profile had them. */
export const HILOS_PROFILE_ROOT_COPY = {
  account: 'Account',
  name: 'Name',
  email: 'Email',
  verified: '{address} · verified',
  change: 'Change',
  sections: 'Sections',
  open: 'Open',
  loading: 'Loading profile…',
} as const

/** The prefix every profile section's page key carries. */
const SECTION_PAGE_PREFIX = 'hilos_profile_'

/**
 * The icon of a section row; a project's own section under the profile gets a folder.
 *
 * @param page The section's page key.
 */
export function hilosProfileSectionIcon(page: string): string {
  switch (page) {
    case HilosPages.PROFILE_SIGN_IN:
      return 'bi-key'
    case HilosPages.PROFILE_NOTIFICATIONS:
      return 'bi-bell'
    case HilosPages.PROFILE_SESSIONS:
      return 'bi-laptop'
    case HilosPages.PROFILE_DEVICES:
      return 'bi-broadcast'
    case HilosPages.PROFILE_AGREEMENTS:
      return 'bi-file-earmark-check'
    case HilosPages.PROFILE_DATA:
      return 'bi-download'
    case HilosPages.PROFILE_SECURITY:
      return 'bi-shield-lock'
    default:
      return 'bi-folder'
  }
}

/**
 * The base of a section row's `data-id`s (`<id>-summary`, `<id>-open`).
 *
 * @param page The section's page key.
 */
export function hilosProfileSectionId(page: string): string {
  return `profile-${page.replace(SECTION_PAGE_PREFIX, '').replaceAll('_', '-')}`
}

/** The profile root's live state: who is signed in, the rows' summaries and the verified address. */
export interface HilosProfileRootStore {
  /**
   * Whether a person is signed in; without one the page draws only its
   * placeholder, until the shell's sign-in surface takes its place.
   */
  readonly signedIn: ReadonlySignal<boolean>
  /** Summary per section page key; a key absent here has none. */
  readonly summaries: ReadonlySignal<Readonly<Record<string, string>>>
  /** The account's verified address, or null — no Email row then. */
  readonly verifiedEmail: ReadonlySignal<string | null>
  /** Start taking the sections from the page's answer and their frames. */
  start(): void
  /** Stop taking them. */
  dispose(): void
}

/**
 * Create the profile root's state over the framework's section stores and the project's binding.
 *
 * @param context The page's connection, scopes and actions.
 * @param binding What the project handed the page.
 */
export function createHilosProfileRootStore(
  context: HilosProfilePageContext,
  binding: HilosProfileBinding,
): HilosProfileRootStore {
  const secondFactor = createHilosSecondFactorStore(context)
  const agreements = createHilosLegalAgreementsStore(context)
  const dataExport = createHilosDataExportStore(
    context.connection,
    profileDataExportNode(context.scopes),
  )
  const offered = sessionAuthMethods(context.scopes)
  const userId = sessionUserId(context.scopes)
  let stopPreferences: (() => void) | null = null
  let stopAgreements: (() => void) | null = null

  const summaries = computedSignal(() => {
    const methods = binding.signInMethods?.get()
    const sessions = binding.sessionCount?.get()
    const devices = binding.deviceCount?.get()
    const summary: Record<string, string> = {
      [HilosPages.PROFILE_NOTIFICATIONS]: describeHilosNotificationChannels(
        hilosNotificationPreferences.channels.get(),
      ),
      [HilosPages.PROFILE_AGREEMENTS]: describeHilosLegalAgreements(
        agreements.state.get(),
      ),
      [HilosPages.PROFILE_DATA]: describeHilosDataExport(
        dataExport.state.get(),
      ),
      [HilosPages.PROFILE_SECURITY]: `Two-step verification is ${
        secondFactor.state.get()?.authenticators.length ? 'on' : 'off'
      }`,
    }
    if (methods !== undefined)
      summary[HilosPages.PROFILE_SIGN_IN] = describeHilosProfileSignInMethods(
        methods,
        offered.get(),
      )
    if (sessions !== undefined)
      summary[HilosPages.PROFILE_SESSIONS] =
        sessions === 1 ? '1 active sign-in' : `${sessions} active sign-ins`
    if (devices !== undefined)
      summary[HilosPages.PROFILE_DEVICES] = `${devices} subscribed to push`
    return summary
  })

  return {
    signedIn: computedSignal(() => userId.get() !== null),
    summaries,
    verifiedEmail: computedSignal(() => {
      const methods = binding.signInMethods?.get()
      return methods === undefined
        ? null
        : hilosProfilePasswordState(methods).verifiedEmail
    }),
    start() {
      stopPreferences ??= startHilosNotificationPreferences(context)
      secondFactor.start()
      stopAgreements ??= agreements.start()
      dataExport.start()
    },
    dispose() {
      stopPreferences?.()
      stopPreferences = null
      secondFactor.dispose()
      stopAgreements?.()
      stopAgreements = null
      dataExport.dispose()
    },
  }
}
