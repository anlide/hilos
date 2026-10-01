// The public Terms page (HIL-501): the text of the revision in force and its
// history to every reader, and to a signed-in one their standing, the comparison
// with the revision they hold and the acceptance of the one in force.
//
// The page is public, so a change of rights does not answer it again
// (page-access-control.md, "Pages a rights change cannot move are skipped"). The
// store asks for the page's whole answer itself, in three cases: the person in
// the tab changed, the agreements group says the held or the current Terms
// revision moved, and an acceptance was sent. Until the answer lands the pair
// already shown stays: a group frame carries neither the text nor the
// comparison, and relabelling them would draw a revision nobody accepted.
//
// Nothing here touches the browser when it is created: the store starts only
// when the page mounts, which keeps the prerendered file inert.
import { z } from 'zod'
import { ActionError } from '../connection/actionLifecycle.js'
import { SIGNAL_TYPE_PAGE_SUBSCRIBE } from '../protocol/constants.js'
import { HilosPages } from '../routing/hilosPages.js'
import { sessionUserId } from '../session/sessionScope.js'
import {
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
} from './legalAgreements.js'
import {
  describeHilosLegalReconsentPlate,
  LEGAL_ACCEPT_ACTION,
  LEGAL_RECONSENT_COPY,
  type HilosLegalReconsentPerson,
  type HilosLegalReconsentPlate,
} from './legalReconsent.js'
import { legalHistoryRevisionSchema } from './legalRevisions.js'

/** The page-data section every reader of /terms receives. */
export const LEGAL_TERMS_SECTION = 'legalTerms'
/** The page's own read of one Terms revision, open to a guest. */
export const TERMS_REVISION_TEXT_ACTION = 'hilos_terms_revision_text'

/** The Terms section: the revision in force, its text, the history and, for a reader behind, the comparison. */
export const legalTermsSchema = z.looseObject({
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

/** The section as the wire carries it: null when the project publishes no Terms or its catalog is faulty. */
export const legalTermsSectionSchema = legalTermsSchema.nullable()

export type HilosLegalTerms = z.infer<typeof legalTermsSchema>

/** Every word of the page, one set for the three view packages. */
export const LEGAL_TERMS_COPY = {
  loading: 'Loading the terms…',
  nonePublished: 'This project has not published its terms.',
  guest:
    'Revision in force since {date}. Without an account there is nothing to accept.',
  unrecorded:
    'The revision of {date} is in force. No acceptance of yours is on record.',
  covered: 'You accepted the revision of {date} — it is the one in force.',
  reworded:
    'You accepted the revision of {held}. The one in force since {current} changes only the wording — nothing to accept.',
  changed:
    'The terms changed on {current}. You accepted the revision of {held}.',
  accept: 'Accept the new revision',
  whatChanged: 'What changed',
  changesTitle: 'What changed · {from} → {to}',
  history: 'Revision history',
  inForce: 'in force',
  youAccepted: 'you accepted this',
  open: 'Open',
  revisionTitle: 'Terms — revision of {date}',
  revisionLoading: 'Loading revision…',
} as const

/** Where the reader stands, from the page's answer and the session. */
export type HilosLegalTermsReaderState =
  | 'loading'
  | 'unpublished'
  | 'guest'
  | 'unrecorded'
  | 'covered'
  | 'reworded'
  | 'due'

/** The reader's line over the text: its sentence, the deadline plate and what may be pressed. */
export interface HilosLegalTermsReader {
  readonly state: HilosLegalTermsReaderState
  readonly line: string
  readonly plate: HilosLegalReconsentPlate | null
  readonly canAccept: boolean
  readonly canCompare: boolean
  readonly onlyPerson: string | null
}

/** Who reads the page and what the session says about them. */
export interface HilosLegalTermsReaderContext {
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
 * @param terms The Terms section, undefined until the page answered, null when none is published.
 * @param agreement The reader's Terms standing, undefined until it is known for the person in the tab.
 * @param reader Who reads and what the session says about them.
 */
export function describeHilosLegalTermsReader(
  terms: HilosLegalTerms | null | undefined,
  agreement: HilosLegalAgreement | null | undefined,
  reader: HilosLegalTermsReaderContext,
): HilosLegalTermsReader {
  const copy = LEGAL_TERMS_COPY
  const person = reader.person
  if (terms === undefined || (person !== null && agreement === undefined)) {
    return { ...QUIET, state: 'loading', line: copy.loading }
  }
  if (terms === null) {
    return { ...QUIET, state: 'unpublished', line: copy.nonePublished }
  }
  if (person === null) {
    return {
      ...QUIET,
      state: 'guest',
      line: copy.guest.replace(
        '{date}',
        formatHilosLegalDate(terms.current.effectiveOn),
      ),
    }
  }
  if (!agreement || agreement.held === null) {
    return {
      ...QUIET,
      state: 'unrecorded',
      line: copy.unrecorded.replace(
        '{date}',
        formatHilosLegalDate(terms.current.publishedOn),
      ),
    }
  }
  const held = agreement.held
  const current = agreement.current
  const heldDate = formatHilosLegalDate(held.publishedOn)
  const canCompare = terms.changes !== null
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
          changes: terms.changes?.changes ?? [],
          clauses: terms.clauses,
        },
        reader.frozen ? 'frozen' : 'window',
        reader.now,
      ),
      canAccept: !person.impersonated,
      canCompare,
      onlyPerson: person.impersonated
        ? LEGAL_RECONSENT_COPY.onlyPerson.replace('{name}', person.name)
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

/** The page's live state and its one command. */
export interface HilosLegalTermsStore {
  /** The Terms section: undefined until the page answered, null when none is published. */
  readonly terms: ReadonlySignal<HilosLegalTerms | null | undefined>
  /** The reader's Terms standing: undefined until known for the person in the tab, null without one. */
  readonly agreement: ReadonlySignal<HilosLegalAgreement | null | undefined>
  /** Whether an acceptance is on its way, until the page answers after it. */
  readonly accepting: ReadonlySignal<boolean>
  /** The last refusal of an acceptance, until the next press. */
  readonly refusal: ReadonlySignal<string | null>
  /** Follow the page's answer, the group's frames and the person in the tab; returns the complete cleanup. */
  start(): () => void
  /**
   * Accept the Terms revision in force the page shows - that one document only.
   *
   * @returns Whether the server accepted.
   */
  accept(): Promise<boolean>
}

/**
 * The live state of the Terms page.
 *
 * @param context Where the page's answer, the group's frames and the acceptance travel.
 */
export function createHilosLegalTermsStore(
  context: HilosLegalContext,
): HilosLegalTermsStore {
  const terms = createSignal<HilosLegalTerms | null | undefined>(undefined)
  const agreement = createSignal<HilosLegalAgreement | null | undefined>(
    undefined,
  )
  const accepting = createSignal(false)
  const refusal = createSignal<string | null>(null)
  let stop: (() => void) | null = null

  const refresh = (): void => {
    if (context.scopes.page()?.key !== HilosPages.TERMS) return
    context.connection.send(
      JSON.stringify({
        type: SIGNAL_TYPE_PAGE_SUBSCRIBE,
        page: HilosPages.TERMS,
        params: {},
      }),
    )
  }
  const readTerms = (raw: unknown): void => {
    if (raw === undefined) {
      terms.set(undefined)
      return
    }
    const parsed = legalTermsSectionSchema.safeParse(raw)
    terms.set(parsed.success ? parsed.data : null)
  }
  const readAgreement = (raw: unknown): void => {
    if (raw === undefined) {
      agreement.set(undefined)
      return
    }
    const parsed = legalAgreementsStateSchema.safeParse(raw)
    agreement.set(
      parsed.success
        ? (parsed.data.documents.find((item) => item.document === 'terms') ??
            null)
        : null,
    )
  }

  return {
    terms: terms as ReadonlySignal<HilosLegalTerms | null | undefined>,
    agreement: agreement as ReadonlySignal<
      HilosLegalAgreement | null | undefined
    >,
    accepting: accepting as ReadonlySignal<boolean>,
    refusal: refusal as ReadonlySignal<string | null>,
    start(): () => void {
      stop?.()
      const termsSection = context.scopes.pageDataSignal(LEGAL_TERMS_SECTION)
      const agreementsSection = context.scopes.pageDataSignal(
        PROFILE_LEGAL_AGREEMENTS_SECTION,
      )
      const userId = sessionUserId(context.scopes)
      readTerms(termsSection.get())
      readAgreement(agreementsSection.get())
      const offTerms = subscribeSignal(termsSection, readTerms)
      const offAgreements = subscribeSignal(agreementsSection, (raw) => {
        // The page answered: an acceptance in flight is now on record or refused.
        accepting.set(false)
        readAgreement(raw)
      })
      const offUser = subscribeSignal(userId, () => {
        // Somebody else reads now: the previous person's line must not stand meanwhile.
        agreement.set(undefined)
        refresh()
      })
      const offFrames = context.connection.on('projectSignal', (signal) => {
        if (signal.type !== SIGNAL_LEGAL_AGREEMENTS_STATE) return
        const parsed = legalAgreementsStateSchema.safeParse(signal.data)
        const previous = agreement.get()
        if (!parsed.success || !previous) return
        const next = parsed.data.documents.find(
          (item) => item.document === 'terms',
        )
        if (
          next === undefined ||
          next.held?.revisionId !== previous.held?.revisionId ||
          next.current.revisionId !== previous.current.revisionId
        ) {
          refresh()
          return
        }
        agreement.set(next)
      })
      stop = () => {
        offTerms()
        offAgreements()
        offUser()
        offFrames()
        terms.set(undefined)
        agreement.set(undefined)
        accepting.set(false)
        refusal.set(null)
      }
      return stop
    },
    async accept(): Promise<boolean> {
      const shown = terms.get()
      if (accepting.get() || !shown || !agreement.get()) return false
      refusal.set(null)
      accepting.set(true)
      try {
        await context.actions.dispatch(LEGAL_ACCEPT_ACTION, {
          acceptedRevisions: { terms: shown.current.revisionId },
        }).done
      } catch (failure) {
        refusal.set(
          failure instanceof ActionError && failure.message !== ''
            ? failure.message
            : LEGAL_RECONSENT_COPY.loadFailed,
        )
        accepting.set(false)
        // The most frequent refusal is a revision that moved meanwhile: the new answer shows it.
        refresh()

        return false
      }
      // The buttons stay busy until the page answers with the acceptance on record.
      refresh()

      return true
    },
  }
}
