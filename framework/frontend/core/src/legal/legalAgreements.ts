// Legal agreement state and its page-data text section (HIL-498).
import { z } from 'zod'
import { type ActionLifecycle } from '../connection/actionLifecycle.js'
import { type HilosConnection } from '../connection/HilosConnection.js'
import { type ScopeManager } from '../state/ScopeManager.js'
import { SIGNAL_TYPE_PAGE_SUBSCRIBE } from '../protocol/constants.js'
import { HilosPages } from '../routing/hilosPages.js'
import {
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
} from '../state/signal.js'

export const PROFILE_LEGAL_AGREEMENTS_SECTION = 'legalAgreements'
export const PROFILE_LEGAL_AGREEMENT_TEXTS_SECTION = 'legalAgreementTexts'
export const SIGNAL_LEGAL_AGREEMENTS_STATE = 'hilos_legal_agreements_state'

export const legalDocumentSchema = z.enum(['terms', 'privacy'])
export const legalRevisionSchema = z.looseObject({
  revisionId: z.string(),
  publishedOn: z.string(),
  effectiveOn: z.string(),
  significance: z.enum(['substantial', 'editorial']),
  setVersion: z.number().int(),
  deviationCount: z.number().int(),
})
export const legalStandingSchema = z.enum([
  'none',
  'covered',
  'window',
  'lapsed',
])
export const legalSideSchema = z.looseObject({
  source: z.enum(['standard', 'deviation']),
  statement: z.string(),
  text: z.string(),
  direction: z.enum(['stricter', 'looser']).nullable(),
})
export const legalClauseSchema = legalSideSchema.extend({
  clauseKey: z.string(),
  standardStatement: z.string(),
})
export const legalChangeSchema = z.looseObject({
  clauseKey: z.string(),
  title: z.string(),
  kind: z.enum(['changed', 'added', 'removed']),
  before: legalSideSchema.nullable(),
  after: legalSideSchema.nullable(),
})
export const legalAgreementSchema = z.looseObject({
  document: legalDocumentSchema,
  current: legalRevisionSchema,
  held: legalRevisionSchema.nullable(),
  acceptedAt: z.number().int().nullable(),
  accepted: z.array(
    z.looseObject({ revisionId: z.string(), acceptedAt: z.number().int() }),
  ),
  standing: legalStandingSchema,
  deadline: z.string().nullable(),
})
export const legalAgreementsStateSchema = z.looseObject({
  documents: z.array(legalAgreementSchema),
})
export const legalAgreementTextsSchema = z.looseObject({
  documents: z.array(
    z.looseObject({
      document: legalDocumentSchema,
      current: z.array(legalClauseSchema),
      held: z.array(legalClauseSchema).nullable(),
      changes: z.array(legalChangeSchema),
    }),
  ),
})
export const LEGAL_AGREEMENTS_SIGNAL_SCHEMAS = {
  [SIGNAL_LEGAL_AGREEMENTS_STATE]: legalAgreementsStateSchema,
}

export type HilosLegalDocumentKey = z.infer<typeof legalDocumentSchema>
export type HilosLegalRevision = z.infer<typeof legalRevisionSchema>
export type HilosLegalStanding = z.infer<typeof legalStandingSchema>
export type HilosLegalAgreement = z.infer<typeof legalAgreementSchema>
export type HilosLegalClause = z.infer<typeof legalClauseSchema>
export type HilosLegalSide = z.infer<typeof legalSideSchema>
export type HilosLegalChange = z.infer<typeof legalChangeSchema>
export type HilosLegalAgreementsState = z.infer<
  typeof legalAgreementsStateSchema
>
export type HilosLegalAgreementTexts = z.infer<typeof legalAgreementTextsSchema>

export interface HilosLegalContext {
  readonly connection: HilosConnection
  readonly scopes: ScopeManager
  readonly actions: ActionLifecycle
}

/** Creates the page's state reader; start returns its complete cleanup. */
export function createHilosLegalAgreementsStore(context: HilosLegalContext) {
  const state = createSignal<HilosLegalAgreementsState | null>(null)
  const texts = createSignal<HilosLegalAgreementTexts | null>(null)
  let stop: (() => void) | null = null
  const readState = (raw: unknown) => {
    const parsed = legalAgreementsStateSchema.safeParse(raw)
    state.set(parsed.success ? parsed.data : null)
  }
  const readTexts = (raw: unknown) => {
    const parsed = legalAgreementTextsSchema.safeParse(raw)
    texts.set(parsed.success ? parsed.data : null)
  }

  return {
    state: state as ReadonlySignal<HilosLegalAgreementsState | null>,
    texts: texts as ReadonlySignal<HilosLegalAgreementTexts | null>,
    start(): () => void {
      stop?.()
      const section = context.scopes.pageDataSignal(
        PROFILE_LEGAL_AGREEMENTS_SECTION,
      )
      const textSection = context.scopes.pageDataSignal(
        PROFILE_LEGAL_AGREEMENT_TEXTS_SECTION,
      )
      readState(section.get())
      readTexts(textSection.get())
      const offState = subscribeSignal(section, readState)
      const offTexts = subscribeSignal(textSection, readTexts)
      const offFrames = context.connection.on('projectSignal', (signal) => {
        if (signal.type !== SIGNAL_LEGAL_AGREEMENTS_STATE) return
        const parsed = legalAgreementsStateSchema.safeParse(signal.data)
        if (!parsed.success) return
        const previous = state.get()
        const changedTextRevisions =
          previous !== null &&
          (previous.documents.length !== parsed.data.documents.length ||
            previous.documents.some((held) => {
              const next = parsed.data.documents.find(
                (item) => item.document === held.document,
              )
              return (
                next === undefined ||
                next.current.revisionId !== held.current.revisionId ||
                next.held?.revisionId !== held.held?.revisionId
              )
            }))
        if (
          context.scopes.page()?.key === HilosPages.PROFILE_AGREEMENTS &&
          changedTextRevisions
        ) {
          // A group frame carries no text. Keep the previous matched state/text pair until
          // the page answers with both; never relabel cached clauses as a new acceptance.
          context.connection.send(
            JSON.stringify({
              type: SIGNAL_TYPE_PAGE_SUBSCRIBE,
              page: HilosPages.PROFILE_AGREEMENTS,
              params: {},
            }),
          )
          return
        }
        state.set(parsed.data)
      })
      stop = () => {
        offState()
        offTexts()
        offFrames()
        state.set(null)
        texts.set(null)
      }
      return stop
    },
  }
}

/** The names of the two legal documents, shared by every view layer. */
export function hilosLegalDocumentLabel(
  document: HilosLegalDocumentKey,
): string {
  return document === 'terms' ? 'Terms of use' : 'Privacy policy'
}

/** Formats a calendar date without letting the reader's time zone move its day. */
export function formatHilosLegalDate(date: string): string {
  const parsed = new Date(`${date}T00:00:00Z`)
  if (!Number.isFinite(parsed.getTime())) return date
  return new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
  }).format(parsed)
}

/** Formats a recorded acceptance moment in the reader's time zone: a moment, unlike a revision's calendar date. */
export function formatHilosLegalAcceptanceDate(moment: number): string {
  return new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(new Date(moment))
}

/** The profile summary follows the worst document, as judged by the backend. */
export function describeHilosLegalAgreements(
  state: HilosLegalAgreementsState | null,
): string {
  if (state === null) return 'Loading agreements…'
  if (state.documents.length === 0)
    return 'This project publishes no legal documents'
  const lapsed = state.documents.filter(
    (document) => document.standing === 'lapsed',
  )
  if (lapsed.length > 0)
    return `Not accepted: ${lapsed.map((item) => hilosLegalDocumentLabel(item.document)).join(', ')}`
  const window = state.documents.filter(
    (document) => document.standing === 'window',
  )
  if (window.length > 0) {
    const deadline = window
      .flatMap((item) => (item.deadline === null ? [] : [item.deadline]))
      .sort()[0]
    return `${window.map((item) => hilosLegalDocumentLabel(item.document)).join(', ')} updated — until ${deadline ? formatHilosLegalDate(deadline) : ''}`
  }
  if (state.documents.some((item) => item.standing === 'none'))
    return 'Nothing accepted on record'
  return 'Terms and privacy accepted'
}

/** Shared row wording; legal coverage itself remains the backend's decision. */
export function describeHilosLegalAgreement(agreement: HilosLegalAgreement) {
  const label = hilosLegalDocumentLabel(agreement.document)
  const held = agreement.held
  const revision = held ?? agreement.current
  const summary =
    held === null
      ? `No acceptance on record · revision of ${formatHilosLegalDate(agreement.current.publishedOn)} in force`
      : `Revision of ${formatHilosLegalDate(held.publishedOn)} · accepted ${agreement.acceptedAt === null ? '' : formatHilosLegalAcceptanceDate(agreement.acceptedAt)}`
  const standard = `Hilos standard ${revision.setVersion}${
    revision.deviationCount === 0
      ? ', no project differences'
      : revision.deviationCount === 1
        ? ' and 1 project difference'
        : ` and ${revision.deviationCount} project differences`
  }`
  let notice: string | null = null
  if (agreement.standing === 'window' && agreement.deadline !== null) {
    notice = `${label} updated on ${formatHilosLegalDate(agreement.current.publishedOn)}. You have until ${formatHilosLegalDate(agreement.deadline)} to read the differences and decide.`
  } else if (agreement.standing === 'lapsed' && agreement.deadline !== null) {
    notice = `${label} updated on ${formatHilosLegalDate(agreement.current.publishedOn)} and took effect on ${formatHilosLegalDate(agreement.deadline)}. You have not accepted this revision.`
  } else if (
    held !== null &&
    held.revisionId !== agreement.current.revisionId
  ) {
    notice = `The revision of ${formatHilosLegalDate(agreement.current.publishedOn)} is in force: wording only, your acceptance still covers it`
  }
  return {
    summary,
    standard,
    notice,
    canCompare:
      held !== null && held.revisionId !== agreement.current.revisionId,
  }
}
