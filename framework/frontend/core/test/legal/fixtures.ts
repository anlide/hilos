import { type ActionLifecycle } from '../../src/connection/actionLifecycle.js'
import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import { type ProjectSignal } from '../../src/protocol/parseSignal.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { ingest } from '../../src/state/normalizer.js'
import {
  type HilosLegalAgreement,
  type HilosLegalClause,
  type HilosLegalChange,
} from '../../src/legal/legalAgreements.js'
import { type HilosLegalTerms } from '../../src/legal/legalTerms.js'
import { type HilosLegalPrivacy } from '../../src/legal/legalPrivacy.js'

export const first = {
  revisionId: '2026-09-17',
  publishedOn: '2026-09-17',
  effectiveOn: '2026-09-17',
  significance: 'substantial' as const,
  setVersion: 1,
  deviationCount: 1,
}
export const current = {
  ...first,
  revisionId: '2026-09-27',
  publishedOn: '2026-09-27',
  effectiveOn: '2026-09-27',
  significance: 'editorial' as const,
}
export const clause: HilosLegalClause = {
  clauseKey: 'standard.retention',
  standardStatement: 'Standard retention',
  source: 'deviation',
  statement: 'Project retention',
  text: 'First paragraph.\n\nSecond paragraph.',
  direction: 'stricter',
}
export const change: HilosLegalChange = {
  clauseKey: clause.clauseKey,
  title: clause.standardStatement,
  kind: 'changed',
  before: { ...clause, text: 'Old wording' },
  after: clause,
}
export const agreement: HilosLegalAgreement = {
  document: 'terms',
  current,
  held: first,
  acceptedAt: Date.UTC(2026, 8, 18),
  accepted: [
    { revisionId: first.revisionId, acceptedAt: Date.UTC(2026, 8, 18) },
  ],
  standing: 'covered',
  deadline: null,
}
export const state = { documents: [agreement] }
export const texts = {
  documents: [
    { document: 'terms', current: [clause], held: [clause], changes: [change] },
  ],
}
export const history = {
  documents: [
    {
      document: 'terms',
      revisions: [
        { ...first, origin: 'first', previousSetVersion: null },
        { ...current, origin: 'project', previousSetVersion: 1 },
      ],
    },
  ],
}

/** A substantial Terms revision in force since 1 October, after the editorial one. */
export const substantial = {
  ...first,
  revisionId: '2026-10-01',
  publishedOn: '2026-10-01',
  effectiveOn: '2026-10-01',
}
/** The public Terms section as a guest, or a reader holding the revision in force, receives it (HIL-501). */
export const termsSection: HilosLegalTerms = {
  current: substantial,
  clauses: [clause],
  revisions: [
    { ...first, origin: 'first', previousSetVersion: null },
    { ...current, origin: 'project', previousSetVersion: 1 },
    { ...substantial, origin: 'project', previousSetVersion: 1 },
  ],
  changes: null,
}
/** The same section for a reader holding the first revision: it carries their comparison. */
export const termsSectionBehind: HilosLegalTerms = {
  ...termsSection,
  changes: {
    fromRevisionId: first.revisionId,
    toRevisionId: substantial.revisionId,
    changes: [change],
  },
}

/**
 * A reader's Terms standing beside {@link termsSection}.
 *
 * @param standing The server's verdict.
 * @param held The revision the reader holds, or null with no record.
 * @param deadline The outstanding deadline, for a standing inside its window or past it.
 */
export function termsAgreement(
  standing: HilosLegalAgreement['standing'],
  held: HilosLegalAgreement['held'],
  deadline: string | null = null,
): HilosLegalAgreement {
  return {
    document: 'terms',
    current: substantial,
    held,
    acceptedAt: held === null ? null : Date.UTC(2026, 8, 18),
    accepted:
      held === null
        ? []
        : [{ revisionId: held.revisionId, acceptedAt: Date.UTC(2026, 8, 18) }],
    standing,
    deadline,
  }
}

/** The agreements section carrying one Terms standing. */
export function termsAgreements(agreement: HilosLegalAgreement) {
  return { documents: [agreement] }
}

/**
 * A real scope store with a signal-only connection and caller-owned action replies.
 *
 * @param actions The lifecycle the actions are dispatched on.
 * @param options The page opened and the data its answer already carries; the profile's agreements page by default.
 */
export function legalContext(
  actions: ActionLifecycle,
  options: {
    readonly page?: string
    readonly data?: Readonly<Record<string, unknown>>
  } = {},
) {
  const listeners = new Set<(signal: ProjectSignal) => void>()
  const frames: unknown[] = []
  const connection = {
    send(frame: string): boolean {
      frames.push(JSON.parse(frame))
      return true
    },
    on(event: string, listener: (signal: ProjectSignal) => void) {
      if (event === 'projectSignal') listeners.add(listener)
      return () => listeners.delete(listener)
    },
    // This signal-only connection keeps no buffered page frames.
    forgetPageFrames(): void {},
  } as unknown as HilosConnection
  const scopes = new ScopeManager()
  const page = scopes.openPage(options.page ?? 'hilos_profile_agreements')
  const data = options.data ?? {
    legalAgreements: state,
    legalAgreementTexts: texts,
    legalRevisions: history,
  }
  for (const [key, value] of Object.entries(data)) page.data.set(key, value)
  return {
    context: { connection, scopes, actions },
    frames,
    page,
    emit(data: unknown) {
      for (const listener of listeners)
        listener({
          kind: 'project',
          type: 'hilos_legal_agreements_state',
          data,
        } as ProjectSignal)
    },
    /** Any frame the server sends, a handshake response included. */
    project(type: string, data: unknown) {
      for (const listener of listeners)
        listener({ kind: 'project', type, data, envelope: {} } as ProjectSignal)
    },
    /** Deliver a complete page answer through the normalizer before notifying readers. */
    answer(
      data: Readonly<Record<string, unknown>>,
      pageKey = options.page ?? 'hilos_profile_agreements',
    ) {
      const currentPage = scopes.page()
      if (currentPage?.key === pageKey)
        ingest(currentPage, { data: { ...data } })
      for (const listener of listeners)
        listener({
          kind: 'project',
          type: 'page_response',
          data: { page: pageKey, payload: { data } },
        } as ProjectSignal)
    },
    listenerCount: () => listeners.size,
  }
}

/** Privacy moves independently of the Terms revision in the fixtures. */
export const privacyCurrent = {
  ...substantial,
  revisionId: '2026-10-05',
  publishedOn: '2026-10-05',
  effectiveOn: '2026-11-14',
}
export const privacySection: HilosLegalPrivacy = {
  current: privacyCurrent,
  clauses: [{ ...clause, clauseKey: 'standard.session_data' }],
  revisions: [
    { ...first, origin: 'first', previousSetVersion: null },
    { ...privacyCurrent, origin: 'project', previousSetVersion: 1 },
  ],
  changes: null,
}
export const privacySectionBehind: HilosLegalPrivacy = {
  ...privacySection,
  changes: {
    fromRevisionId: first.revisionId,
    toRevisionId: privacyCurrent.revisionId,
    changes: [{ ...change, clauseKey: 'standard.session_data' }],
  },
}

/** A reader behind the policy, independently of their Terms acceptance. */
export const privacyAgreement: HilosLegalAgreement = {
  ...termsAgreement('window', first, '2026-11-14'),
  document: 'privacy',
  current: privacyCurrent,
}
