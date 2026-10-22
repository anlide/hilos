// Shared public legal text, reader and lifecycle. Each page receives its whole
// answer through its existing subscription; group frames never relabel old text.
import { z } from 'zod'
import { ActionError } from '../connection/actionLifecycle.js'
import {
  SIGNAL_TYPE_PAGE_RESPONSE,
  SIGNAL_TYPE_PAGE_SUBSCRIBE,
} from '../protocol/constants.js'
import { type PageResponseWire } from '../protocol/scopePayload.js'
import { sessionImpersonating, sessionUserId } from '../session/sessionScope.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
} from '../state/signal.js'
import {
  formatHilosLegalDate,
  legalAgreementsStateSchema,
  legalChangeSchema,
  legalClauseSchema,
  legalRevisionSchema,
  PROFILE_LEGAL_AGREEMENTS_SECTION,
  SIGNAL_LEGAL_AGREEMENTS_STATE,
  type HilosLegalAgreement,
  type HilosLegalContext,
  type HilosLegalDocumentKey,
} from './legalAgreements.js'
import {
  describeHilosLegalReconsentPlate,
  LEGAL_ACCEPT_ACTION,
  LEGAL_RECONSENT_COPY,
  type HilosLegalReconsentPerson,
  type HilosLegalReconsentPlate,
} from './legalReconsent.js'
import { legalHistoryRevisionSchema } from './legalRevisions.js'

/** A public legal document: the revision in force, its text, the history and, for a reader behind, the comparison. */
export const legalPublicDocumentSchema = z.looseObject({
  current: legalRevisionSchema,
  clauses: z.array(legalClauseSchema),
  revisions: z.array(legalHistoryRevisionSchema),
  changes: z
    .looseObject({
      fromRevisionId: z.string(),
      toRevisionId: z.string(),
      changes: z.array(legalChangeSchema),
    })
    .nullable(),
})

/** The section as the wire carries it: null when the project publishes no document or its catalog is faulty. */
export const legalPublicDocumentSectionSchema =
  legalPublicDocumentSchema.nullable()

export type HilosLegalPublicDocument = z.infer<typeof legalPublicDocumentSchema>

/** Neutral words shared by public legal readers in all three view packages. */
export const LEGAL_PUBLIC_DOCUMENT_COPY = {
  guest:
    'Revision in force since {date}. Without an account there is nothing to accept.',
  unrecorded:
    'The revision of {date} is in force. No acceptance of yours is on record.',
  covered: 'You accepted the revision of {date} — it is the one in force.',
  reworded:
    'You accepted the revision of {held}. The one in force since {current} changes only the wording — nothing to accept.',
  accept: 'Accept the new revision',
  whatChanged: 'What changed',
  changesTitle: 'What changed · {from} → {to}',
  history: 'Revision history',
  inForce: 'in force',
  youAccepted: 'you accepted this',
  open: 'Open',
  revisionLoading: 'Loading revision…',
} as const

/** Words supplied by the document; the renderer needs no document-specific logic. */
export interface HilosLegalPublicDocumentCopy {
  readonly loading: string
  readonly nonePublished: string
  readonly guest: string
  readonly unrecorded: string
  readonly covered: string
  readonly reworded: string
  readonly changed: string
  readonly accept: string
  readonly whatChanged: string
  readonly changesTitle: string
  readonly history: string
  readonly inForce: string
  readonly youAccepted: string
  readonly open: string
  readonly revisionTitle: string
  readonly revisionLoading: string
  readonly onlyPerson: string
}

/** Where the reader stands, from the page's answer and the session. */
export type HilosLegalPublicDocumentReaderState =
  | 'loading'
  | 'unpublished'
  | 'guest'
  | 'unrecorded'
  | 'covered'
  | 'reworded'
  | 'due'

/** The reader's line over the text: its sentence, the deadline plate and what may be pressed. */
export interface HilosLegalPublicDocumentReader {
  readonly state: HilosLegalPublicDocumentReaderState
  readonly line: string
  readonly plate: HilosLegalReconsentPlate | null
  readonly canAccept: boolean
  readonly canCompare: boolean
  readonly onlyPerson: string | null
}

/** Who reads the page and what the session says about them. */
export interface HilosLegalPublicDocumentReaderContext {
  /** The person the session acts as, or null for a guest. */
  readonly person: HilosLegalReconsentPerson | null
  /** Whether the account is frozen, which names the deadline plate. */
  readonly frozen: boolean
  /** The LOCAL moment now, in epoch ms. */
  readonly now: number
}

/** A line that offers nothing to press. */
const QUIET = {
  plate: null,
  canAccept: false,
  canCompare: false,
  onlyPerson: null,
} as const

/**
 * The reader's line. Coverage itself is the server's verdict: a standing inside
 * its window or past it asks for a decision, and nothing else does.
 *
 * @param document The public document section, undefined until the page answered, null when none is published.
 * @param agreement The reader's document standing, undefined until it is known for the person in the tab.
 * @param reader Who reads and what the session says about them.
 * @param copy The words of this document.
 */
export function describeHilosLegalPublicDocumentReader(
  document: HilosLegalPublicDocument | null | undefined,
  agreement: HilosLegalAgreement | null | undefined,
  reader: HilosLegalPublicDocumentReaderContext,
  copy: HilosLegalPublicDocumentCopy,
): HilosLegalPublicDocumentReader {
  const person = reader.person
  if (document === undefined) {
    return { ...QUIET, state: 'loading', line: copy.loading }
  }
  if (document === null) {
    return { ...QUIET, state: 'unpublished', line: copy.nonePublished }
  }
  if (person !== null && agreement === undefined) {
    return { ...QUIET, state: 'loading', line: copy.loading }
  }
  if (person === null) {
    return {
      ...QUIET,
      state: 'guest',
      line: copy.guest.replace(
        '{date}',
        formatHilosLegalDate(document.current.effectiveOn),
      ),
    }
  }
  if (!agreement || agreement.held === null) {
    return {
      ...QUIET,
      state: 'unrecorded',
      line: copy.unrecorded.replace(
        '{date}',
        formatHilosLegalDate(document.current.publishedOn),
      ),
    }
  }
  const held = agreement.held
  const current = agreement.current
  const heldDate = formatHilosLegalDate(held.publishedOn)
  const canCompare = document.changes !== null
  if (agreement.standing === 'window' || agreement.standing === 'lapsed') {
    return {
      state: 'due',
      line: copy.changed
        .replace('{current}', formatHilosLegalDate(current.publishedOn))
        .replace('{held}', heldDate),
      plate: describeHilosLegalReconsentPlate(
        {
          document: agreement.document,
          standing: agreement.standing,
          deadline: agreement.deadline,
          held,
          current,
          changes: document.changes?.changes ?? [],
          clauses: document.clauses,
        },
        reader.frozen ? 'frozen' : 'window',
        reader.now,
      ),
      canAccept: !person.impersonated,
      canCompare,
      onlyPerson: person.impersonated
        ? copy.onlyPerson.replace('{name}', person.name)
        : null,
    }
  }
  if (held.revisionId === current.revisionId) {
    return {
      ...QUIET,
      state: 'covered',
      line: copy.covered.replace('{date}', heldDate),
    }
  }

  return {
    ...QUIET,
    state: 'reworded',
    line: copy.reworded
      .replace('{held}', heldDate)
      .replace('{current}', formatHilosLegalDate(current.effectiveOn)),
    canCompare,
  }
}

/** The public page and document whose answer this store follows. */
export interface HilosLegalPublicDocumentOptions {
  readonly document: HilosLegalDocumentKey
  readonly page: string
  readonly section: string
  readonly copy: HilosLegalPublicDocumentCopy
}

/** One coherent page answer; derived signals always read the same snapshot. */
interface PublicDocumentSnapshot {
  readonly document: HilosLegalPublicDocument | null | undefined
  readonly agreement: HilosLegalAgreement | null | undefined
}

/** The lightweight facts that decide whether a complete text answer is owed. */
function sameRevisions(
  left: HilosLegalAgreement | null | undefined,
  right: HilosLegalAgreement | null | undefined,
): boolean {
  return (
    left?.held?.revisionId === right?.held?.revisionId &&
    left?.current.revisionId === right?.current.revisionId
  )
}

/** The common live state and the one-document acceptance command. */
export interface HilosLegalPublicDocumentStore {
  /** Undefined before the answer, null when the document is unpublished. */
  readonly document: ReadonlySignal<HilosLegalPublicDocument | null | undefined>
  /** Undefined while waiting for this person's answer, null without a personal row. */
  readonly agreement: ReadonlySignal<HilosLegalAgreement | null | undefined>
  /** Busy through the tracked reply and the following complete page answer. */
  readonly accepting: ReadonlySignal<boolean>
  /** The backend's refusal words, retained until the next press or identity. */
  readonly refusal: ReadonlySignal<string | null>
  /** The document's words for a shared renderer. */
  readonly copy: HilosLegalPublicDocumentCopy
  /** Start following this page; the returned cleanup belongs only to this run. */
  start(): () => void
  /** Accept exactly the revision of this document currently shown. */
  accept(): Promise<boolean>
}

/**
 * Create an inert store. The page scope owns the answer; this store keeps its
 * last whole projection while a group change asks for another answer.
 *
 * @param context The existing connection, scopes and tracked actions.
 * @param options The document, public page, section and words to follow.
 */
export function createHilosLegalPublicDocumentStore(
  context: HilosLegalContext,
  options: HilosLegalPublicDocumentOptions,
): HilosLegalPublicDocumentStore {
  const snapshot = createSignal<PublicDocumentSnapshot>({
    document: undefined,
    agreement: undefined,
  })
  const accepting = createSignal(false)
  const refusal = createSignal<string | null>(null)
  let stop: (() => void) | null = null
  let accept: (() => Promise<boolean>) | null = null

  return {
    document: computedSignal(() => snapshot.get().document),
    agreement: computedSignal(() => snapshot.get().agreement),
    accepting,
    refusal,
    copy: options.copy,
    start(): () => void {
      stop?.()
      const page = context.scopes.page()
      const userId = sessionUserId(context.scopes)
      const impersonating = sessionImpersonating(context.scopes)
      let active = true
      let epoch = 0
      let refreshPending = false
      let actionPending = false
      let expected: HilosLegalAgreement | null | undefined
      let requested: HilosLegalAgreement | null | undefined
      const current = (): boolean =>
        active && page?.key === options.page && context.scopes.page() === page
      const refresh = (): void => {
        if (!current() || refreshPending) return
        requested = expected
        refreshPending = context.connection.send(
          JSON.stringify({
            type: SIGNAL_TYPE_PAGE_SUBSCRIBE,
            page: options.page,
            params: {},
          }),
        )
      }
      const readPage = (): void => {
        if (!current()) return
        const raw = page?.data.signal(options.section).get()
        const parsed = legalPublicDocumentSectionSchema.safeParse(raw)
        const document =
          raw === undefined ? undefined : parsed.success ? parsed.data : null
        const rawAgreements = page?.data
          .signal(PROFILE_LEGAL_AGREEMENTS_SECTION)
          .get()
        const agreements = legalAgreementsStateSchema.safeParse(rawAgreements)
        snapshot.set({
          document:
            userId.get() === null && document
              ? { ...document, changes: null }
              : document,
          agreement:
            userId.get() === null || document === null
              ? null
              : agreements.success
                ? (agreements.data.documents.find(
                    (item) => item.document === options.document,
                  ) ?? null)
                : rawAgreements === undefined
                  ? undefined
                  : null,
        })
        refreshPending = false
        if (!actionPending) accepting.set(false)
        // Only a different hint received AFTER the request can owe another
        // answer. The complete answer itself may already be newer than a hint.
        if (expected !== undefined && !actionPending) {
          if (
            !sameRevisions(expected, requested) &&
            !sameRevisions(expected, snapshot.get().agreement)
          ) {
            refresh()
          } else {
            expected = undefined
          }
        }
      }
      readPage()
      const offUser = subscribeSignal(userId, () => {
        if (!current()) return
        ++epoch
        actionPending = false
        refreshPending = false
        expected = undefined
        requested = undefined
        accepting.set(false)
        refusal.set(null)
        const shown = snapshot.get().document
        snapshot.set({
          // The text is public; the comparison belongs to the previous person.
          document: shown ? { ...shown, changes: null } : shown,
          agreement: userId.get() === null ? null : undefined,
        })
        refresh()
      })
      const offFrames = context.connection.on('projectSignal', (signal) => {
        if (!current()) return
        if (signal.type === SIGNAL_TYPE_PAGE_RESPONSE) {
          // bindPageScope has already ingested the complete answer. Reading here,
          // rather than after each data-slot write, publishes the pair together.
          const response = signal.data as PageResponseWire
          if (response.page === options.page) readPage()
          return
        }
        if (signal.type !== SIGNAL_LEGAL_AGREEMENTS_STATE) return
        const parsed = legalAgreementsStateSchema.safeParse(signal.data)
        const previous = snapshot.get().agreement
        if (!parsed.success || previous === undefined || userId.get() === null)
          return
        const next =
          parsed.data.documents.find(
            (item) => item.document === options.document,
          ) ?? null
        expected = next
        if (!sameRevisions(next, previous)) {
          // An acceptance already in flight will ask on its completion. The
          // accompanying group frame must not send a second identical request.
          if (!actionPending) refresh()
          return
        }
        snapshot.set({ ...snapshot.get(), agreement: next })
      })
      const offPage = subscribeSignal(
        computedSignal(() => context.scopes.page()),
        () => cleanup(),
      )
      const cleanup = (): void => {
        if (!active) return
        active = false
        ++epoch
        expected = undefined
        requested = undefined
        offUser()
        offFrames()
        offPage()
        snapshot.set({ document: undefined, agreement: undefined })
        accepting.set(false)
        refusal.set(null)
        accept = null
      }
      accept = async (): Promise<boolean> => {
        const shown = snapshot.get()
        if (
          !current() ||
          accepting.get() ||
          !shown.document ||
          !shown.agreement ||
          impersonating.get()
        )
          return false
        const mine = epoch
        refusal.set(null)
        accepting.set(true)
        actionPending = true
        try {
          await context.actions.dispatch(LEGAL_ACCEPT_ACTION, {
            acceptedRevisions: {
              [options.document]: shown.document.current.revisionId,
            },
          }).done
        } catch (failure) {
          if (!current() || mine !== epoch) return false
          actionPending = false
          refusal.set(
            failure instanceof ActionError && failure.message !== ''
              ? failure.message
              : LEGAL_RECONSENT_COPY.loadFailed,
          )
          accepting.set(false)
          refresh()
          return false
        }
        if (!current() || mine !== epoch) return false
        actionPending = false
        // No optimistic coverage: busy ends only when the whole page answers.
        refresh()
        return true
      }
      stop = cleanup
      return cleanup
    },
    accept: () => accept?.() ?? Promise.resolve(false),
  }
}
