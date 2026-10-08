// The "the terms have changed" screen's content, shared by the three view packages' tests (HIL-500).
import {
  type HilosLegalReconsentContent,
  type HilosLegalReconsentPreview,
} from '../../src/legal/legalReconsent.js'
import { change, clause, current, first } from './fixtures.js'

/** The midnight 1 November 2026 starts at, UTC: 9 days before the terms' deadline. */
export const RECONSENT_NOW = Date.UTC(2026, 10, 1)

/**
 * The content of a person holding the first terms (inside their window until
 * 10 November) and a privacy policy past its deadline.
 *
 * @param refusal What a refusal after the deadline does.
 * @param identifier The person's confirmed address, or null when absent.
 */
export function reconsentContent(
  refusal: 'freeze' | 'remind' = 'freeze',
  identifier: string | null = 'maria@example.com',
): HilosLegalReconsentContent {
  return {
    refusal,
    identifier,
    documents: [
      {
        document: 'terms',
        standing: 'window',
        deadline: '2026-11-10',
        held: first,
        current,
        changes: [
          change,
          {
            ...change,
            clauseKey: 'standard.availability',
            title: 'Availability',
            kind: 'changed',
            before: { ...clause, statement: 'No promise of uptime' },
            after: { ...clause, statement: 'Data may be wiped' },
          },
        ],
        clauses: [clause],
      },
      {
        document: 'privacy',
        standing: 'lapsed',
        deadline: '2026-09-27',
        held: first,
        current,
        changes: [{ ...change, kind: 'added', before: null }],
        clauses: [clause],
      },
    ],
  }
}

/**
 * The administrator's preview of a document.
 *
 * @param kind Which revision is in force: a first one, an editorial one, one inside its window, or one past it.
 */
export function reconsentPreview(
  kind: 'first' | 'editorial' | 'window' | 'lapsed',
): HilosLegalReconsentPreview {
  const substantial = { ...current, significance: 'substantial' as const }
  switch (kind) {
    case 'first':
      return {
        document: 'privacy',
        standing: null,
        deadline: null,
        held: null,
        current: first,
        changes: [],
        clauses: [clause],
      }
    case 'editorial':
      return {
        document: 'terms',
        standing: 'covered',
        deadline: null,
        held: first,
        current,
        changes: [change],
        clauses: [clause],
      }
    case 'window':
      return {
        document: 'terms',
        standing: 'window',
        deadline: '2026-11-10',
        held: first,
        current: substantial,
        changes: [change],
        clauses: [clause],
      }
    case 'lapsed':
      return {
        document: 'terms',
        standing: 'lapsed',
        deadline: '2026-09-27',
        held: first,
        current: substantial,
        changes: [change],
        clauses: [clause],
      }
  }
}
