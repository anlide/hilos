// Registration and the admin preview read the same current documents (HIL-499).
import { z } from 'zod'
import { createSignal, type ReadonlySignal } from '../state/signal.js'
import {
  ActionError,
  type ActionLifecycle,
} from '../connection/actionLifecycle.js'
import { AUTH_ACTION_LEGAL_CONSENT } from '../auth/authProtocol.js'
import {
  legalClauseSchema,
  legalDocumentSchema,
  legalRevisionSchema,
  type HilosLegalClause,
} from './legalAgreements.js'

export const LEGAL_CONSENT_ACTION = AUTH_ACTION_LEGAL_CONSENT
export const LEGAL_CONSENT_REVISED_MESSAGE =
  'The terms were updated while you were reading. Review the differences and accept again.'
export const LEGAL_TERMS_UNPUBLISHED_MESSAGE =
  'This project has not published its terms yet, so an account cannot be created here.'
export const LEGAL_CONSENT_LOAD_FAILED_MESSAGE =
  'The terms could not be loaded.'

export const legalConsentTermsSchema = z.looseObject({
  form: z.enum(['checkbox', 'line']),
  documents: z.array(
    z.looseObject({
      document: legalDocumentSchema,
      revision: legalRevisionSchema,
      clauses: z.array(legalClauseSchema),
    }),
  ),
})

export type HilosLegalConsentTerms = z.infer<typeof legalConsentTermsSchema>
export type HilosLegalConsentDocument =
  HilosLegalConsentTerms['documents'][number]

/** Load the complete content through its own validated action reply. */
export async function loadHilosLegalConsentTerms(context: {
  readonly actions: ActionLifecycle
}): Promise<HilosLegalConsentTerms> {
  const { reply } = await context.actions.dispatch(
    LEGAL_CONSENT_ACTION,
    {},
    { replySchema: legalConsentTermsSchema },
  ).done
  if (reply === undefined) {
    throw new ActionError(
      LEGAL_CONSENT_ACTION,
      'invalid-reply',
      LEGAL_CONSENT_LOAD_FAILED_MESSAGE,
    )
  }

  return reply
}

/** Preserve the exact revisions shown; the server checks whether they are still current. */
export function hilosLegalConsentAcceptance(
  terms: HilosLegalConsentTerms,
): Readonly<Record<string, string>> {
  return Object.fromEntries(
    terms.documents.map(({ document, revision }) => [
      document,
      revision.revisionId,
    ]),
  )
}

/** Both documents' deviations, in document and clause order. */
export function hilosLegalConsentDeviations(
  terms: HilosLegalConsentTerms,
): readonly HilosLegalClause[] {
  return terms.documents.flatMap(({ clauses }) =>
    clauses.filter(({ source }) => source === 'deviation'),
  )
}

export const HILOS_LEGAL_CLAUSE_ICONS: Readonly<Record<string, string>> = {
  'standard.file_access': 'bi-link-45deg',
  'standard.retention': 'bi-clock-history',
  'standard.moderation': 'bi-eye',
  'standard.availability': 'bi-activity',
  'standard.account_rules': 'bi-person-x',
  'standard.ownership': 'bi-person-check',
  'standard.no_sale': 'bi-cash-coin',
  'standard.deletion': 'bi-trash',
  'standard.export': 'bi-download',
  'standard.passwords': 'bi-key',
  'standard.access_log': 'bi-database',
  'standard.session_data': 'bi-pc-display',
  'standard.breach_notice': 'bi-shield-exclamation',
}

/** An unknown future clause still has a visible document icon. */
export function hilosLegalClauseIcon(clauseKey: string): string {
  return Object.hasOwn(HILOS_LEGAL_CLAUSE_ICONS, clauseKey)
    ? HILOS_LEGAL_CLAUSE_ICONS[clauseKey]!
    : 'bi-file-earmark-text'
}

/** Keeps a lazily opened preview from accepting a reply after it was closed or reopened. */
export function createHilosLegalConsentPreview(context: {
  readonly actions: ActionLifecycle
}) {
  const opened = createSignal(false)
  const terms = createSignal<HilosLegalConsentTerms | null>(null)
  const loading = createSignal(false)
  const error = createSignal<string | null>(null)
  let generation = 0

  return {
    opened: opened as ReadonlySignal<boolean>,
    terms: terms as ReadonlySignal<HilosLegalConsentTerms | null>,
    loading: loading as ReadonlySignal<boolean>,
    error: error as ReadonlySignal<string | null>,
    async open(): Promise<void> {
      const current = ++generation
      opened.set(true)
      terms.set(null)
      error.set(null)
      loading.set(true)
      try {
        const reply = await loadHilosLegalConsentTerms(context)
        if (current === generation) terms.set(reply)
      } catch {
        if (current === generation) error.set(LEGAL_CONSENT_LOAD_FAILED_MESSAGE)
      } finally {
        if (current === generation) loading.set(false)
      }
    },
    close(): void {
      generation += 1
      opened.set(false)
      terms.set(null)
      loading.set(false)
      error.set(null)
    },
  }
}
