// The profile Overview owns Account and deletion; its shared head owns identity
// and catalog navigation on every profile page.
import { type ActionLifecycle } from '../connection/actionLifecycle.js'
import { type HilosConnection } from '../connection/HilosConnection.js'
import { HilosPages } from '../routing/hilosPages.js'
import { sessionUserId } from '../session/sessionScope.js'
import { type ScopeManager } from '../state/ScopeManager.js'
import { computedSignal, type ReadonlySignal } from '../state/signal.js'
import { THEME_COPY, THEME_POSITIONS } from '../theme/themeChoice.js'
import { hilosThemeChoice } from '../theme/themeState.js'
import {
  hilosBrowserTimeZone,
  hilosOwnTimeZone,
  hilosTimeZone,
} from '../timeZone/timeZoneState.js'
import {
  createHilosProfileHeadStore,
  type HilosProfileHeadStore,
} from './profileHead.js'
import { type HilosProfileRename } from './profileRename.js'

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
  /** Retained for compatibility; Overview no longer reads session counts. */
  readonly sessionCount?: ReadonlySignal<number> | null
  /** Retained for compatibility; Overview no longer reads device counts. */
  readonly deviceCount?: ReadonlySignal<number> | null
}

/** The profile root's words, as the chat profile had them. */
export const HILOS_PROFILE_ROOT_COPY = {
  account: 'Account',
  name: 'Name',
  email: 'Email',
  timeZone: 'Time zone',
  theme: 'Theme',
  automatic: 'automatic',
  verified: '{address} · verified',
  change: 'Change',
  sections: 'Sections',
  open: 'Open',
  loading: 'Loading profile…',
  passkeyOnly:
    'Only passkeys can sign you in: access cannot be restored automatically',
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

/** The profile Overview's account facts and its caller-owned shared head. */
export interface HilosProfileRootStore {
  /** The shared head borrowed by the view; this root owns its lifetime. */
  readonly head: HilosProfileHeadStore
  /**
   * Whether a person is signed in; without one the page draws only its
   * placeholder, until the shell's sign-in surface takes its place.
   */
  readonly signedIn: ReadonlySignal<boolean>
  /** Whether this project enabled changing the person's photo. */
  readonly photoEnabled: ReadonlySignal<boolean>
  /** Current published photo URL from the session identity, or null. */
  readonly photo: ReadonlySignal<string | null>
  /** Stable empty map retained for compatibility; no summary sources are read. */
  readonly summaries: ReadonlySignal<Readonly<Record<string, string>>>
  /**
   * The page keys omitted from the shared navigation, live: two-step
   * verification once the administrator turned it off, while the person has
   * no app and no removal standing (HIL-1303).
   */
  readonly hiddenSections: ReadonlySignal<readonly string[]>
  /** The account's verified address, or null — no Email row then. */
  readonly verifiedEmail: ReadonlySignal<string | null>
  /**
   * Whether device keys are the account's only way in (HIL-1166) — the warning
   * line under the name; false while the list is empty.
   */
  readonly passkeyOnly: ReadonlySignal<boolean>
  /**
   * The Theme row's line — the person's position, or the default's marked as
   * the default; null while switching is off, and there is no row.
   */
  readonly theme: ReadonlySignal<string | null>
  /**
   * The Time zone row's line — the person's own zone, or the browser's zone
   * (falling back to project or UTC) marked as automatic.
   */
  readonly timeZone: ReadonlySignal<string>
  /** Start taking the sections from the page's answer and their frames. */
  start(): void
  /** Stop taking them. */
  dispose(): void
}

/**
 * Create the profile Overview's account projection and one shared head.
 * The ways in come from the framework browser list in the page scope.
 *
 * @param context The page's connection, scopes and actions.
 * @param binding What the project handed the page.
 */
export function createHilosProfileRootStore(
  context: HilosProfilePageContext,
  binding: HilosProfileBinding,
): HilosProfileRootStore {
  const head = createHilosProfileHeadStore(context, binding.name)
  const userId = sessionUserId(context.scopes)
  const summaries = computedSignal<Readonly<Record<string, string>>>(() => ({}))

  return {
    head,
    signedIn: computedSignal(() => userId.get() !== null),
    photoEnabled: head.photoEnabled,
    photo: head.photo,
    summaries,
    hiddenSections: head.hiddenSections,
    verifiedEmail: head.verifiedEmail,
    passkeyOnly: head.passkeyOnly,
    theme: computedSignal(() => {
      const choice = hilosThemeChoice.get()
      if (choice === null) {
        return null
      }
      const label =
        THEME_POSITIONS.find((item) => item.value === choice.position)?.label ??
        choice.position

      return choice.isDefault ? `${label} · ${THEME_COPY.default}` : label
    }),
    timeZone: computedSignal(() => {
      const own = hilosOwnTimeZone.get()
      const browser = hilosBrowserTimeZone.get()
      const effective = hilosTimeZone.get()

      return own !== null
        ? own
        : `${browser ?? effective} · ${HILOS_PROFILE_ROOT_COPY.automatic}`
    }),
    start() {
      head.start()
    },
    dispose() {
      head.dispose()
    },
  }
}
