import { type ActionLifecycle } from '../../src/connection/actionLifecycle.js'
import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import { type ProjectSignal } from '../../src/protocol/parseSignal.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import {
  type HilosLegalAgreement,
  type HilosLegalClause,
  type HilosLegalChange,
} from '../../src/legal/legalAgreements.js'

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

/** A real scope store with a signal-only connection and caller-owned action replies. */
export function legalContext(actions: ActionLifecycle) {
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
  } as unknown as HilosConnection
  const scopes = new ScopeManager()
  const page = scopes.openPage('hilos_profile_agreements')
  page.data.set('legalAgreements', state)
  page.data.set('legalAgreementTexts', texts)
  page.data.set('legalRevisions', history)
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
    listenerCount: () => listeners.size,
  }
}
