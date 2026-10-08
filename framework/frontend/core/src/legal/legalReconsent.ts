// The "the terms have changed" screen (HIL-500): the window that stands over the
// page when a person signs in while a document waits for their decision, the
// reminder in the header that holds it afterwards, the freeze screen that takes
// the content's place once the deadline has passed, and the administrator's
// preview of the same screen on the document's page.
//
// What there is to decide is read off the session without asking: the account
// standing (HIL-945) names the documents past their deadline and those still
// inside their window. The content of the screen - both revisions, what changed
// between them, the text in force - is read from the server when the screen
// opens, and the acceptance is one tracked action. Nothing is sent for "Later"
// and nothing for "I do not accept": silence is not acceptance, and a refusal is
// recorded nowhere.
//
// The window rises by itself at one moment only: a sign-in in this tab. The
// first frame of a tab opened on a live sign-in is where counting starts, not an
// entrance - otherwise the window would stand over every reload, which is
// exactly what the owner refused. Everything later - a revision published
// mid-work, a reconnect, a takeover starting or stopping - leaves the window
// down; the header's reminder is how the person gets back to it.
import { z } from 'zod'
import {
  ActionError,
  type ActionLifecycle,
} from '../connection/actionLifecycle.js'
import {
  accountDeletionDaysLeft,
  formatAccountDeletionDays,
} from '../profile/accountDeletion.js'
import { type ScopeManager } from '../state/ScopeManager.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
  subscribeSignal,
} from '../state/signal.js'
import {
  sessionAccountStanding,
  sessionHandshakeHeard,
  sessionImpersonating,
  sessionUserName,
  type HilosAccountStanding,
  type SessionScopeOptions,
} from '../session/sessionScope.js'
import {
  formatHilosLegalDate,
  legalChangeSchema,
  legalClauseSchema,
  legalDocumentSchema,
  legalRevisionSchema,
  legalStandingSchema,
  type HilosLegalChange,
  type HilosLegalClause,
  type HilosLegalDocumentKey,
  type HilosLegalRevision,
  type HilosLegalStanding,
} from './legalAgreements.js'
import {
  hilosLegalClauseIcon,
  LEGAL_CONSENT_LOAD_FAILED_MESSAGE,
  LEGAL_CONSENT_REVISED_MESSAGE,
} from './legalConsent.js'

/** Read what the screen shows the signed-in person; open while frozen. */
export const LEGAL_RECONSENT_ACTION = 'hilos_legal_reconsent'
/** Accept the revisions in force the screen showed; open while frozen. */
export const LEGAL_ACCEPT_ACTION = 'hilos_legal_accept'
/** Read the screen of one document as a holder of its previous revision sees it (public). */
export const LEGAL_RECONSENT_PREVIEW_ACTION = 'hilos_legal_reconsent_preview'

/** One document waiting for the person's decision, as the content reply carries it. */
export const legalReconsentDocumentSchema = z.looseObject({
  document: legalDocumentSchema,
  standing: z.enum(['window', 'lapsed']),
  deadline: z.string(),
  held: legalRevisionSchema,
  current: legalRevisionSchema,
  changes: z.array(legalChangeSchema),
  clauses: z.array(legalClauseSchema),
})

/**
 * The screen's content: the refusal setting, every document waiting for a
 * decision, and the account's confirmed address for the person plaque.
 */
export const legalReconsentContentSchema = z.looseObject({
  refusal: z.enum(['freeze', 'remind']),
  documents: z.array(legalReconsentDocumentSchema),
  identifier: z.string().nullable(),
})

/**
 * One document through the eyes of whoever held its previous revision - the
 * administrator's preview. A first revision has nothing before it: then the
 * standing, the deadline and the held revision are null.
 */
export const legalReconsentPreviewSchema = z.looseObject({
  document: legalDocumentSchema,
  standing: legalStandingSchema.nullable(),
  deadline: z.string().nullable(),
  held: legalRevisionSchema.nullable(),
  current: legalRevisionSchema,
  changes: z.array(legalChangeSchema),
  clauses: z.array(legalClauseSchema),
})

export type HilosLegalReconsentContent = z.infer<
  typeof legalReconsentContentSchema
>
export type HilosLegalReconsentDocument = z.infer<
  typeof legalReconsentDocumentSchema
>
export type HilosLegalReconsentPreview = z.infer<
  typeof legalReconsentPreviewSchema
>

/**
 * One section of the screen - a document of the content or the one document of
 * a preview. The screen draws both from this shape.
 */
export interface HilosLegalReconsentSection {
  readonly document: HilosLegalDocumentKey
  readonly standing: HilosLegalStanding | null
  readonly deadline: string | null
  readonly held: HilosLegalRevision | null
  readonly current: HilosLegalRevision
  readonly changes: readonly HilosLegalChange[]
  readonly clauses: readonly HilosLegalClause[]
}

/** Where the screen stands: over the page, in the content's place, or in the admin preview. */
export type HilosLegalReconsentVariant = 'window' | 'frozen' | 'preview'

/** What the body of the screen shows: the changes, a document's text, its comparison, or the refusal step. */
export type HilosLegalReconsentView =
  | { readonly kind: 'changes' }
  | { readonly kind: 'text'; readonly document: HilosLegalDocumentKey }
  | { readonly kind: 'compare'; readonly document: HilosLegalDocumentKey }
  | { readonly kind: 'refuse' }

/** Every word of the screen, one set for the three view packages. */
export const LEGAL_RECONSENT_COPY = {
  heading: 'The terms have changed',
  frozenHeading: 'The terms were not accepted',
  acceptedOne:
    'You accepted the revision of {date}. Since then {n} clause changed — the rest is as before.',
  acceptedMany:
    'You accepted the revision of {date}. Since then {n} clauses changed — the rest is as before.',
  before: 'Before: {statement}',
  wordingChanged: 'Wording changed',
  kinds: { changed: 'Changed', added: 'Added', removed: 'Removed' },
  fullText: 'Full text',
  compare: 'Line-by-line comparison',
  backToChanges: 'Back to the changes',
  refuse: 'I do not accept',
  later: 'Later',
  accept: 'Accept',
  refuseFreeze:
    'After {date} you will not be able to use the product until you accept. Sign-in, your data and its export stay.',
  refuseRemind: 'Nothing changes: this reminder will keep coming back.',
  dataLink: 'Download a copy of your data',
  deleteLink: 'Delete my account',
  back: 'Back',
  close: 'Close',
  daysLeft: '{days} left',
  until: 'until {date}',
  deadlinePassed: 'deadline passed {date}',
  accountFrozen: 'account frozen · deadline passed {date}',
  previewWindow: '{days} left · until {date}',
  previewNoWindow: 'No window · in force since {date}',
  previewEditorial: 'Editorial · nobody is asked to accept it',
  previewFirst:
    'This is the first revision: there is nothing to compare it with, and nobody sees this screen.',
  freezeKeeps: 'What the freeze does not take away',
  keepAccount: 'Keep my account',
  signOut: 'Sign out',
  onlyPerson: 'Only {name} can accept the terms.',
  notYou: 'Not you? Sign out',
  badgeDays: 'The terms have changed — {days} left to decide',
  badgeReview: 'The terms have changed — please review them',
  loadFailed: LEGAL_CONSENT_LOAD_FAILED_MESSAGE,
  retry: 'Try again',
  revised: LEGAL_CONSENT_REVISED_MESSAGE,
} as const

/** The plate over a section: its color, icon, sentence and the smaller line beside it. */
export interface HilosLegalReconsentPlate {
  readonly tone: 'warning' | 'secondary' | 'info'
  readonly icon: string
  readonly text: string
  readonly detail: string | null
}

/** One line of the list of changes. */
export interface HilosLegalReconsentChangeLine {
  readonly clauseKey: string
  readonly icon: string
  readonly statement: string
  readonly kind: string
  readonly before: string | null
}

/** What the header's reminder needs: the nearest deadline still ahead, or none when only lapsed documents wait. */
export interface HilosLegalReconsentDue {
  readonly nearestDeadline: string | null
}

/** The person the screen speaks to, and whether an administrator is acting as them. */
export interface HilosLegalReconsentPerson {
  readonly name: string
  readonly impersonated: boolean
}

/**
 * Whole days left until a deadline, from the midnight the deadline's calendar
 * day starts at - rounded up and never below one, like the deletion strip.
 *
 * @param deadline The deadline's calendar day, `YYYY-MM-DD`.
 * @param now The LOCAL moment now, in epoch ms.
 */
export function hilosLegalDaysLeft(deadline: string, now: number): number {
  return accountDeletionDaysLeft(Date.parse(`${deadline}T00:00:00Z`), now)
}

/**
 * The sections of the screen, from the content or from a preview.
 *
 * @param content The loaded content or preview.
 */
export function hilosLegalReconsentSections(
  content: HilosLegalReconsentContent | HilosLegalReconsentPreview,
): readonly HilosLegalReconsentSection[] {
  return isHilosLegalReconsentPreview(content) ? [content] : content.documents
}

/**
 * Whether what was read is a preview - one document at the top level - rather
 * than the content, which lists its documents.
 *
 * @param content The loaded content or preview.
 */
function isHilosLegalReconsentPreview(
  content: HilosLegalReconsentContent | HilosLegalReconsentPreview,
): content is HilosLegalReconsentPreview {
  return !Array.isArray(content.documents)
}

/**
 * The plate over one section.
 *
 * @param section The section.
 * @param variant Where the screen stands.
 * @param now The LOCAL moment now, in epoch ms.
 * @returns The plate, or null for a first revision in the preview, which draws a line instead.
 */
export function describeHilosLegalReconsentPlate(
  section: HilosLegalReconsentSection,
  variant: HilosLegalReconsentVariant,
  now: number,
): HilosLegalReconsentPlate | null {
  const copy = LEGAL_RECONSENT_COPY
  const deadline = section.deadline ?? section.current.effectiveOn
  const date = formatHilosLegalDate(deadline)
  const days = formatAccountDeletionDays(hilosLegalDaysLeft(deadline, now))
  if (variant === 'preview') {
    if (section.held === null) return null
    if (section.current.significance === 'editorial') {
      return {
        tone: 'secondary',
        icon: 'bi-pencil',
        text: copy.previewEditorial,
        detail: null,
      }
    }
    return section.standing === 'window'
      ? {
          tone: 'warning',
          icon: 'bi-hourglass-split',
          text: copy.previewWindow
            .replace('{days}', days)
            .replace('{date}', date),
          detail: null,
        }
      : {
          tone: 'secondary',
          icon: 'bi-calendar-check',
          text: copy.previewNoWindow.replace(
            '{date}',
            formatHilosLegalDate(section.current.effectiveOn),
          ),
          detail: null,
        }
  }
  if (section.standing === 'window') {
    return {
      tone: 'warning',
      icon: 'bi-hourglass-split',
      text: copy.daysLeft.replace('{days}', days),
      detail: copy.until.replace('{date}', date),
    }
  }
  return variant === 'frozen'
    ? {
        tone: 'info',
        icon: 'bi-snow',
        text: copy.accountFrozen.replace('{date}', date),
        detail: null,
      }
    : {
        tone: 'secondary',
        icon: 'bi-calendar-x',
        text: copy.deadlinePassed.replace('{date}', date),
        detail: null,
      }
}

/**
 * "You accepted the revision of … Since then n clauses changed", or null when
 * nothing was held - a first revision in the preview.
 *
 * @param section The section.
 */
export function describeHilosLegalReconsentAccepted(
  section: HilosLegalReconsentSection,
): string | null {
  if (section.held === null) return null
  const count = section.changes.length
  const template =
    count === 1
      ? LEGAL_RECONSENT_COPY.acceptedOne
      : LEGAL_RECONSENT_COPY.acceptedMany

  return template
    .replace('{date}', formatHilosLegalDate(section.held.publishedOn))
    .replace('{n}', String(count))
}

/**
 * One line of the list of changes: the clause's icon, its wording now (or the
 * wording it had, for a removed clause), the kind, and the wording before.
 *
 * @param change The change between the held revision and the one in force.
 */
export function describeHilosLegalReconsentChange(
  change: HilosLegalChange,
): HilosLegalReconsentChangeLine {
  const after = change.after?.statement ?? null
  const before = change.before?.statement ?? null
  let beforeLine: string | null = null
  if (change.kind === 'changed' && before !== null) {
    beforeLine =
      before === after
        ? LEGAL_RECONSENT_COPY.wordingChanged
        : LEGAL_RECONSENT_COPY.before.replace('{statement}', before)
  }

  return {
    clauseKey: change.clauseKey,
    icon: hilosLegalClauseIcon(change.clauseKey),
    statement: after ?? before ?? change.title,
    kind: LEGAL_RECONSENT_COPY.kinds[change.kind],
    before: beforeLine,
  }
}

/**
 * The refusal step's sentence: under the freeze setting the day the product
 * closes, under the remind setting that nothing changes.
 *
 * @param content The loaded content.
 */
export function describeHilosLegalReconsentRefusal(
  content: HilosLegalReconsentContent,
): string {
  if (content.refusal === 'remind') return LEGAL_RECONSENT_COPY.refuseRemind
  const deadlines = content.documents
    .map(({ deadline }) => deadline)
    .sort((left, right) => left.localeCompare(right))
  const nearest = deadlines[0]

  return nearest === undefined
    ? LEGAL_RECONSENT_COPY.refuseRemind
    : LEGAL_RECONSENT_COPY.refuseFreeze.replace(
        '{date}',
        formatHilosLegalDate(nearest),
      )
}

/**
 * The account's confirmed address for the person plaque over the re-consent
 * window or the frozen screen.
 *
 * The address arrives with the screen's content: while the content is being read
 * or when reading failed, it is null and the plaque draws only the person's name.
 * Under impersonation the server sends null so the administrator never sees the
 * account's address, and an administrator's preview carries no person at all.
 *
 * @param content The loaded content, preview, or null while loading.
 */
export function describeHilosLegalReconsentAddress(
  content: HilosLegalReconsentContent | HilosLegalReconsentPreview | null,
): string | null {
  if (content === null || isHilosLegalReconsentPreview(content)) {
    return null
  }
  return content.identifier
}

/**
 * The header reminder's label at a moment.
 *
 * @param due What is due.
 * @param now The LOCAL moment now, in epoch ms.
 */
export function formatHilosLegalReconsentBadge(
  due: HilosLegalReconsentDue,
  now: number,
): string {
  return due.nearestDeadline === null
    ? LEGAL_RECONSENT_COPY.badgeReview
    : LEGAL_RECONSENT_COPY.badgeDays.replace(
        '{days}',
        formatAccountDeletionDays(hilosLegalDaysLeft(due.nearestDeadline, now)),
      )
}

/**
 * What is due for a standing: something to decide, and the person may decide
 * it here - not frozen (the freeze screen speaks then), not blocked, not under a
 * takeover. A lapsed document of a person who is not frozen is the remind
 * setting at work.
 *
 * @param standing The session's standing, or null for an anonymous session.
 * @param impersonating Whether an administrator acts as the person.
 */
export function hilosLegalReconsentDueOf(
  standing: HilosAccountStanding | null,
  impersonating: boolean,
): HilosLegalReconsentDue | null {
  if (
    standing === null ||
    standing.frozen ||
    standing.blocked ||
    impersonating ||
    (standing.window.length === 0 && standing.lapsed.length === 0)
  ) {
    return null
  }
  const deadlines = standing.window
    .map(({ deadline }) => deadline)
    .sort((left, right) => left.localeCompare(right))

  return { nearestDeadline: deadlines[0] ?? null }
}

/** The screen's live state and its two commands. */
export interface HilosLegalReconsentStore {
  readonly content: ReadonlySignal<HilosLegalReconsentContent | null>
  readonly loading: ReadonlySignal<boolean>
  readonly error: ReadonlySignal<string | null>
  readonly busy: ReadonlySignal<boolean>
  readonly view: ReadonlySignal<HilosLegalReconsentView>
  /** Read the content again, from nothing. */
  load(): Promise<void>
  /**
   * Accept the revisions in force of every document shown. A refusal - the
   * terms moved while they were read, or the request failed - becomes the
   * store's error line and the content is read again.
   *
   * @returns Whether the server accepted.
   */
  accept(): Promise<boolean>
  /** Move the body to another view. */
  show(view: HilosLegalReconsentView): void
  /** Forget everything read: the screen closed. */
  reset(): void
}

/**
 * The live state of the screen over one action lifecycle. A counter of reads
 * keeps an answer that arrives after the screen was reset or read again from
 * landing.
 *
 * @param context Where the actions are dispatched.
 * @param options What to do once the acceptance landed.
 */
export function createHilosLegalReconsentStore(
  context: { readonly actions: ActionLifecycle },
  options: { readonly onAccepted?: () => void } = {},
): HilosLegalReconsentStore {
  const content = createSignal<HilosLegalReconsentContent | null>(null)
  const loading = createSignal(false)
  const error = createSignal<string | null>(null)
  const busy = createSignal(false)
  const view = createSignal<HilosLegalReconsentView>({ kind: 'changes' })
  let generation = 0

  const read = async (): Promise<void> => {
    const current = ++generation
    content.set(null)
    loading.set(true)
    try {
      const { reply } = await context.actions.dispatch(
        LEGAL_RECONSENT_ACTION,
        {},
        { replySchema: legalReconsentContentSchema },
      ).done
      if (current !== generation) return
      if (reply === undefined) {
        error.set(LEGAL_RECONSENT_COPY.loadFailed)
      } else {
        content.set(reply)
      }
    } catch {
      if (current === generation) error.set(LEGAL_RECONSENT_COPY.loadFailed)
    } finally {
      if (current === generation) loading.set(false)
    }
  }

  return {
    content: content as ReadonlySignal<HilosLegalReconsentContent | null>,
    loading: loading as ReadonlySignal<boolean>,
    error: error as ReadonlySignal<string | null>,
    busy: busy as ReadonlySignal<boolean>,
    view: view as ReadonlySignal<HilosLegalReconsentView>,
    async load(): Promise<void> {
      error.set(null)
      view.set({ kind: 'changes' })
      await read()
    },
    async accept(): Promise<boolean> {
      const shown = content.get()
      // Nothing to accept: the frame that takes the screen down is still on its way.
      if (shown === null || shown.documents.length === 0 || busy.get()) {
        return false
      }
      const opened = generation
      busy.set(true)
      error.set(null)
      try {
        await context.actions.dispatch(LEGAL_ACCEPT_ACTION, {
          acceptedRevisions: Object.fromEntries(
            shown.documents.map(({ document, current }) => [
              document,
              current.revisionId,
            ]),
          ),
        }).done
        options.onAccepted?.()

        return true
      } catch (failure) {
        // The screen was closed while the answer travelled: nothing to tell anybody.
        if (opened !== generation) return false
        error.set(
          failure instanceof ActionError && failure.message !== ''
            ? failure.message
            : LEGAL_RECONSENT_COPY.loadFailed,
        )
        view.set({ kind: 'changes' })
        await read()

        return false
      } finally {
        busy.set(false)
      }
    },
    show(next: HilosLegalReconsentView): void {
      view.set(next)
    },
    reset(): void {
      generation += 1
      content.set(null)
      loading.set(false)
      error.set(null)
      busy.set(false)
      view.set({ kind: 'changes' })
    },
  }
}

/**
 * The administrator's preview of the screen on a document's page, read when it
 * opens. A counter of openings keeps a late answer from landing after it closed.
 *
 * @param context Where the action is dispatched.
 */
export function createHilosLegalReconsentPreview(context: {
  readonly actions: ActionLifecycle
}) {
  const opened = createSignal(false)
  const preview = createSignal<HilosLegalReconsentPreview | null>(null)
  const loading = createSignal(false)
  const error = createSignal<string | null>(null)
  const view = createSignal<HilosLegalReconsentView>({ kind: 'changes' })
  let generation = 0

  return {
    opened: opened as ReadonlySignal<boolean>,
    preview: preview as ReadonlySignal<HilosLegalReconsentPreview | null>,
    loading: loading as ReadonlySignal<boolean>,
    error: error as ReadonlySignal<string | null>,
    view: view as ReadonlySignal<HilosLegalReconsentView>,
    async open(document: string): Promise<void> {
      const current = ++generation
      opened.set(true)
      preview.set(null)
      error.set(null)
      view.set({ kind: 'changes' })
      loading.set(true)
      try {
        const { reply } = await context.actions.dispatch(
          LEGAL_RECONSENT_PREVIEW_ACTION,
          { document },
          { replySchema: legalReconsentPreviewSchema },
        ).done
        if (current !== generation) return
        if (reply === undefined) {
          error.set(LEGAL_RECONSENT_COPY.loadFailed)
        } else {
          preview.set(reply)
        }
      } catch {
        if (current === generation) error.set(LEGAL_RECONSENT_COPY.loadFailed)
      } finally {
        if (current === generation) loading.set(false)
      }
    },
    show(next: HilosLegalReconsentView): void {
      view.set(next)
    },
    close(): void {
      generation += 1
      opened.set(false)
      preview.set(null)
      loading.set(false)
      error.set(null)
      view.set({ kind: 'changes' })
    },
  }
}

const due = createSignal<HilosLegalReconsentDue | null>(null)
const windowOpen = createSignal(false)
const frozenScreen = createSignal(false)
const person = createSignal<HilosLegalReconsentPerson | null>(null)

/** The lifecycle the session's screen dispatches on; set by {@link bindLegalReconsent}. */
let boundActions: ActionLifecycle | null = null

/** The live binding's own release, so a stale unbind cannot undo a newer bind. */
let boundRelease: (() => void) | null = null

/**
 * What the header's reminder needs, or null when it stands down: nothing to
 * decide, frozen, blocked, under a takeover, or nobody signed in.
 */
export const hilosLegalReconsentDue: ReadonlySignal<HilosLegalReconsentDue | null> =
  due

/** Whether the window stands over the page. */
export const hilosLegalReconsentOpen: ReadonlySignal<boolean> = windowOpen

/**
 * Whether the freeze screen takes the content's place: the person is frozen
 * and not blocked - the "Access closed" card comes first. Under a takeover too:
 * the product is closed to the person taken over, only the acceptance is not
 * offered.
 */
export const hilosFrozenScreen: ReadonlySignal<boolean> = frozenScreen

/** The person the screen speaks to, or null for an anonymous session. */
export const hilosLegalReconsentPerson: ReadonlySignal<HilosLegalReconsentPerson | null> =
  person

/**
 * The session's own screen - the window and the freeze screen share it, since
 * they never stand together. Its actions go through the lifecycle bound by
 * {@link bindLegalReconsent}; an acceptance closes the window.
 */
export const hilosLegalReconsent: HilosLegalReconsentStore =
  createHilosLegalReconsentStore(
    {
      get actions(): ActionLifecycle {
        if (boundActions === null) {
          throw new Error(
            'hilosLegalReconsent before bindLegalReconsent(): bootHilos binds the screen.',
          )
        }

        return boundActions
      },
    },
    { onAccepted: () => closeLegalReconsent() },
  )

/** Open the window and read its content: the header's reminder was pressed, or a sign-in found something due. */
export function openLegalReconsent(): void {
  windowOpen.set(true)
  hilosLegalReconsent.reset()
  void hilosLegalReconsent.load()
}

/** Close the window - "Later", Esc, the cross, an acceptance, or nothing left to decide. */
export function closeLegalReconsent(): void {
  if (!windowOpen.get()) return
  windowOpen.set(false)
  hilosLegalReconsent.reset()
}

/**
 * Follow the session: derive the reminder, the freeze screen and the person,
 * and raise the window on a sign-in in this tab.
 *
 * An entrance is the session moving from nobody to a person AFTER the tab's
 * first frame: the first frame of a tab opened on a live sign-in is where
 * counting starts. A takeover starting or stopping moves the session from one
 * person to another and is no entrance either. Bound by `bootHilos` before the
 * socket opens, beside the account standing.
 *
 * @param scopes The application's scope-partitioned stores.
 * @param actions The application's one action reply lifecycle.
 * @param options Current-user and impersonated-by slot overrides.
 * @returns Unbind: stops following the session and forgets the lifecycle.
 */
export function bindLegalReconsent(
  scopes: ScopeManager,
  actions: ActionLifecycle,
  options: SessionScopeOptions = {},
): () => void {
  boundRelease?.()
  const standing = sessionAccountStanding(scopes)
  const heard = sessionHandshakeHeard(scopes)
  const impersonating = sessionImpersonating(scopes, options)
  const userName = sessionUserName(scopes, options)
  const dueNow = computedSignal(() =>
    hilosLegalReconsentDueOf(standing.get(), impersonating.get()),
  )
  const frozenNow = computedSignal(() => {
    const value = standing.get()

    return value !== null && value.frozen && !value.blocked
  })
  const personNow = computedSignal<HilosLegalReconsentPerson | null>(() =>
    standing.get() === null
      ? null
      : { name: userName.get(), impersonated: impersonating.get() },
  )
  let counting = heard.get()
  let signedIn = standing.get() !== null
  const follow = (): void => {
    const value = standing.get()
    const nextDue = dueNow.get()
    due.set(nextDue)
    frozenScreen.set(frozenNow.get())
    person.set(personNow.get())
    if (!counting) {
      counting = heard.get()
      signedIn = value !== null
      return
    }
    const entrance = !signedIn && value !== null
    signedIn = value !== null
    if (entrance && nextDue !== null) {
      openLegalReconsent()
    } else if (nextDue === null) {
      closeLegalReconsent()
    }
  }
  boundActions = actions
  due.set(dueNow.get())
  frozenScreen.set(frozenNow.get())
  person.set(personNow.get())
  const unsubscribe = [
    subscribeSignal(standing, follow),
    subscribeSignal(heard, follow),
    subscribeSignal(impersonating, follow),
    subscribeSignal(userName, follow),
  ]
  const release = (): void => {
    for (const off of unsubscribe) {
      off()
    }
    if (boundRelease !== release) {
      return
    }
    boundRelease = null
    closeLegalReconsent()
    boundActions = null
    due.set(null)
    frozenScreen.set(false)
    person.set(null)
  }
  boundRelease = release

  return release
}
