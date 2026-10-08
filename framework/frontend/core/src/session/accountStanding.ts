// The standing of the account behind the session, as the shell draws it
// (HIL-945): the deletion strip with its one control, the mark by the avatar in
// the header, and the colors both of them and the impersonation strip take.
//
// The standing is one verdict composed on the backend — blocked, frozen, a
// scheduled deletion, and the one of them shown — and stamped on every
// handshake, like the "Access closed" card. So the state is bound here, once, by
// `bootHilos`, and each SDK's shell only reads it — the same shape as the card
// and the impersonation strip. Under a takeover the session carries the
// identity of the person taken over, so the verdict is theirs, and the shell
// speaks about them: the administrator's own scheduled deletion is not shown
// for as long as the takeover lasts.
//
// Of the three facts only a scheduled deletion gets a strip. A block takes the
// product away whole and puts the "Access closed" card in its place (HIL-289); a
// freeze does the same with the screen of the new terms, which is HIL-500's —
// this module hands that screen the pages it leaves open and the refusal code
// the server answers a frozen person with.
//
// "Keep my account" is a tracked action on the application's ONE action
// lifecycle, like Stop of the impersonation strip: busy until the server
// answers, a refusal is the error toast, and success needs no toast — the strip
// leaves with the handshake that no longer carries a deletion.
import {
  type ActionHandle,
  type ActionLifecycle,
} from '../connection/actionLifecycle.js'
import { formatCalendarDate } from '../format/date.js'
import {
  accountDeletionDaysLeft,
  formatAccountDeletionDays,
  HILOS_ACCOUNT_DELETION_CANCEL_ACTION,
} from '../profile/accountDeletion.js'
import { HilosPages } from '../routing/hilosPages.js'
import { type ScopeManager } from '../state/ScopeManager.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
  subscribeSignal,
} from '../state/signal.js'
import {
  sessionAccountStanding,
  sessionImpersonating,
  type HilosAccountStanding,
  type HilosAccountStandingKind,
  type SessionScopeOptions,
} from './sessionScope.js'

/**
 * The refusal the server answers a frozen person with (PHP
 * `PageAccountFrozenException` / `ActionAccountFrozenException`, HTTP 403): the
 * `errorCode` of a page subscription error and of an action error alike. Not a
 * 401 — it opens no sign-in.
 */
export const ACCOUNT_FROZEN_ERROR_CODE = 'account_frozen'

/**
 * The pages the freeze screen leaves open (HIL-945) — byte-equal to the pages
 * the backend declares open while frozen (`OPEN_WHILE_FROZEN`: your data, your
 * agreements and their history) and the four public footer pages, which no
 * guard closes. Exported for the freeze screen (HIL-500), which draws itself in
 * place of every other page's content.
 */
export const HILOS_FROZEN_OPEN_PAGES: readonly string[] = [
  HilosPages.PROFILE_DATA,
  HilosPages.PROFILE_AGREEMENTS,
  HilosPages.PROFILE_AGREEMENTS_HISTORY,
  HilosPages.ABOUT,
  HilosPages.TERMS,
  HilosPages.PRIVACY,
  HilosPages.LICENSE,
]

/**
 * The deletion strip's words (HIL-945). `{date}` is the calendar date of the
 * erasure, `{days}` a day phrase ({@link formatAccountDeletionDays}).
 */
export const ACCOUNT_STANDING_STRIP_COPY = {
  deletion: 'Your account will be deleted on {date} — {days} left',
  keep: 'Keep my account',
} as const

/**
 * The Bootstrap color a standing is shown in, read by the strips, the avatar
 * mark and the admin card's badge alike: a color names how much was taken away,
 * not which feature took it. Gray is for a merged account (HIL-1292): nothing
 * was taken from a person, the account itself is gone into another.
 */
export type HilosStandingTone = 'warning' | 'danger' | 'info' | 'secondary'

/** The mark by the avatar in the header: a ring of the tone and an icon in its corner. */
export interface HilosAvatarMark {
  /** The Bootstrap color of the ring and the icon. */
  readonly tone: HilosStandingTone
  /** The Bootstrap Icon naming the reason: a takeover or a scheduled deletion. */
  readonly icon: 'bi-people-fill' | 'bi-trash'
}

/** What the deletion strip draws while it stands. */
export interface HilosDeletionStrip {
  /** The LOCAL epoch-ms moment the account is erased. */
  readonly effectiveAt: number
}

/**
 * The tone a standing is shown in: gray for a merged account, red for a block,
 * blue for a freeze, yellow otherwise — a scheduled deletion and a plain account
 * alike. The strips and the avatar mark never draw a merged account: its
 * sessions are closed by the merge and a takeover of it is refused.
 *
 * @param kind The standing shown.
 */
export function hilosStandingTone(
  kind: HilosAccountStandingKind,
): HilosStandingTone {
  switch (kind) {
    case 'merged':
      return 'secondary'
    case 'blocked':
      return 'danger'
    case 'frozen':
      return 'info'
    default:
      return 'warning'
  }
}

/**
 * The deletion strip's sentence at a moment: the date and the days left, by the
 * rule of the profile's danger zone (HIL-302) — whole days rounded up, never
 * below one. A view counts it again once a minute
 * (`ACCOUNT_DELETION_TICK_MS`).
 *
 * @param strip The strip standing.
 * @param now The LOCAL moment now, in epoch ms.
 */
export function formatHilosDeletionStrip(
  strip: HilosDeletionStrip,
  now: number,
): string {
  return ACCOUNT_STANDING_STRIP_COPY.deletion
    .replace('{date}', formatCalendarDate(strip.effectiveAt))
    .replace(
      '{days}',
      formatAccountDeletionDays(
        accountDeletionDaysLeft(strip.effectiveAt, now),
      ),
    )
}

const standing = createSignal<HilosAccountStanding | null>(null)
const deletionStrip = createSignal<HilosDeletionStrip | null>(null)
const avatarMark = createSignal<HilosAvatarMark | null>(null)

/** The lifecycle "Keep my account" is dispatched on; set by {@link bindAccountStanding}. */
let boundActions: ActionLifecycle | null = null

/** The live binding's own release, so a stale unbind cannot undo a newer bind. */
let boundRelease: (() => void) | null = null

/**
 * The standing of the person this session acts as, or `null` for an anonymous
 * session and before any bind.
 *
 * One per loaded SDK, bound by `bootHilos` and read by the shell and by the
 * freeze screen (HIL-500). Under a takeover it is the standing of the person
 * taken over.
 */
export const hilosAccountStanding: ReadonlySignal<HilosAccountStanding | null> =
  standing

/**
 * The deletion strip this session is owed, or `null`: it stands while the
 * standing shown is a scheduled deletion and no takeover lasts — a takeover
 * speaks about the person taken over, and the administrator's own deletion
 * would only mislead there.
 */
export const hilosDeletionStrip: ReadonlySignal<HilosDeletionStrip | null> =
  deletionStrip

/**
 * The mark by the avatar in the header, or `null`: under a takeover the people
 * icon in the tone of the person taken over; otherwise the trash icon in yellow
 * while the session's own deletion is scheduled. A block and a freeze get no
 * mark of their own — they have no strip to pair it with.
 */
export const hilosSessionAvatarMark: ReadonlySignal<HilosAvatarMark | null> =
  avatarMark

/**
 * Derive {@link hilosAccountStanding}, {@link hilosDeletionStrip} and
 * {@link hilosSessionAvatarMark} from the session scope and remember the
 * lifecycle {@link keepMyAccount} dispatches on.
 *
 * Bound by `bootHilos` before the socket opens, beside the impersonation strip,
 * so the first handshake is never missed. The SDK shell tests bind it
 * themselves over a scope fed with a handshake.
 *
 * @param scopes The application's scope-partitioned stores.
 * @param actions The application's one action reply lifecycle.
 * @param options Impersonated-by slot override.
 * @returns Unbind: stops following the session and forgets the lifecycle.
 */
export function bindAccountStanding(
  scopes: ScopeManager,
  actions: ActionLifecycle,
  options: SessionScopeOptions = {},
): () => void {
  boundRelease?.()
  const current = sessionAccountStanding(scopes)
  const impersonating = sessionImpersonating(scopes, options)
  const strip = computedSignal<HilosDeletionStrip | null>(() => {
    const value = current.get()

    return value !== null &&
      value.shown === 'deletion_scheduled' &&
      value.deletionEffectiveAt !== null &&
      !impersonating.get()
      ? { effectiveAt: value.deletionEffectiveAt }
      : null
  })
  const mark = computedSignal<HilosAvatarMark | null>(() => {
    const value = current.get()
    if (impersonating.get()) {
      return {
        tone: hilosStandingTone(value?.shown ?? 'none'),
        icon: 'bi-people-fill',
      }
    }

    return value?.shown === 'deletion_scheduled'
      ? { tone: 'warning', icon: 'bi-trash' }
      : null
  })
  boundActions = actions
  standing.set(current.get())
  deletionStrip.set(strip.get())
  avatarMark.set(mark.get())
  const unsubscribe = [
    subscribeSignal(current, (next) => standing.set(next)),
    subscribeSignal(strip, (next) => deletionStrip.set(next)),
    subscribeSignal(mark, (next) => avatarMark.set(next)),
  ]
  const release = (): void => {
    for (const off of unsubscribe) {
      off()
    }
    if (boundRelease !== release) {
      return
    }
    boundRelease = null
    boundActions = null
    standing.set(null)
    deletionStrip.set(null)
    avatarMark.set(null)
  }
  boundRelease = release

  return release
}

/**
 * Keep the account: call the scheduled deletion off on the bound lifecycle —
 * the same action the profile's danger zone sends (HIL-302), open to a frozen
 * person too.
 *
 * The handle settles on the server's own answer; the strip leaves with the
 * handshake that no longer carries a deletion.
 *
 * @returns The tracked handle of the cancel action.
 * @throws Error When nothing is bound — a programming error, since the only
 *   strip offering the control exists after the bind.
 */
export function keepMyAccount(): ActionHandle {
  if (boundActions === null) {
    throw new Error(
      'keepMyAccount() before bindAccountStanding(): bootHilos binds the strip.',
    )
  }

  return boundActions.dispatch(HILOS_ACCOUNT_DELETION_CANCEL_ACTION, {})
}
