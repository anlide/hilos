// Revision history and lazy, request-correlated read-only dialogs (HIL-498).
import { z } from 'zod'
import { ActionError } from '../connection/actionLifecycle.js'
import { offsetMs } from '../session/serverClock.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
} from '../state/signal.js'
import {
  formatHilosLegalDate,
  legalChangeSchema,
  legalClauseSchema,
  legalDocumentSchema,
  legalRevisionSchema,
  type HilosLegalContext,
  type HilosLegalDocumentKey,
} from './legalAgreements.js'

export const PROFILE_LEGAL_REVISIONS_SECTION = 'legalRevisions'
export const LEGAL_REVISION_TEXT_ACTION = 'hilos_legal_revision_text'
export const LEGAL_REVISION_CHANGES_ACTION = 'hilos_legal_revision_changes'
export const legalRevisionTextReplySchema = z.looseObject({
  document: legalDocumentSchema,
  revisionId: z.string(),
  clauses: z.array(legalClauseSchema),
})
export const legalRevisionChangesReplySchema = z.looseObject({
  document: legalDocumentSchema,
  fromRevisionId: z.string(),
  toRevisionId: z.string(),
  changes: z.array(legalChangeSchema),
})
export const legalHistoryRevisionSchema = legalRevisionSchema.extend({
  origin: z.enum(['first', 'project', 'standard']),
  previousSetVersion: z.number().int().nullable(),
})
export const legalRevisionsSchema = z.looseObject({
  documents: z.array(
    z.looseObject({
      document: legalDocumentSchema,
      revisions: z.array(legalHistoryRevisionSchema),
    }),
  ),
})
export type HilosLegalHistoryRevision = z.infer<
  typeof legalHistoryRevisionSchema
>
export type HilosLegalRevisionText = z.infer<
  typeof legalRevisionTextReplySchema
>
export type HilosLegalRevisionChanges = z.infer<
  typeof legalRevisionChangesReplySchema
>
export interface HilosLegalRevisionDialog {
  readonly kind: 'text' | 'changes'
  readonly document: HilosLegalDocumentKey
  readonly revisionId: string
  readonly busy: boolean
  readonly refusal: string | null
  readonly text?: HilosLegalRevisionText
  readonly changes?: HilosLegalRevisionChanges
}

/** Reads the revision list already carried by the page response. */
export function hilosLegalRevisionHistory(context: HilosLegalContext) {
  const section = context.scopes.pageDataSignal(PROFILE_LEGAL_REVISIONS_SECTION)
  return computedSignal(() => {
    const parsed = legalRevisionsSchema.safeParse(section.get())
    return parsed.success ? parsed.data.documents : []
  })
}

/** Reads only a dialog the person opened; closing or replacing it invalidates late replies. */
export function createHilosLegalRevisionReader(context: HilosLegalContext) {
  const dialog = createSignal<HilosLegalRevisionDialog | null>(null)
  let round = 0
  async function read(
    document: HilosLegalDocumentKey,
    revisionId: string,
    kind: 'text' | 'changes',
  ): Promise<void> {
    const mine = ++round
    const base = { kind, document, revisionId, busy: true, refusal: null }
    dialog.set(base)
    try {
      if (kind === 'text') {
        const answer = await context.actions.dispatch(
          LEGAL_REVISION_TEXT_ACTION,
          { document, revisionId },
          { replySchema: legalRevisionTextReplySchema },
        ).done
        if (mine !== round) return
        if (answer.reply === undefined)
          throw new Error('The server returned no revision text')
        dialog.set({ ...base, busy: false, text: answer.reply })
      } else {
        const answer = await context.actions.dispatch(
          LEGAL_REVISION_CHANGES_ACTION,
          { document, revisionId },
          { replySchema: legalRevisionChangesReplySchema },
        ).done
        if (mine !== round) return
        if (answer.reply === undefined)
          throw new Error('The server returned no revision comparison')
        dialog.set({ ...base, busy: false, changes: answer.reply })
      }
    } catch (error) {
      if (mine !== round) return
      dialog.set({
        ...base,
        busy: false,
        refusal:
          error instanceof ActionError || error instanceof Error
            ? error.message
            : 'Could not read this revision',
      })
    }
  }
  return {
    dialog: dialog as ReadonlySignal<HilosLegalRevisionDialog | null>,
    open: (document: HilosLegalDocumentKey, revisionId: string) =>
      read(document, revisionId, 'text'),
    compare: (document: HilosLegalDocumentKey, revisionId: string) =>
      read(document, revisionId, 'changes'),
    close() {
      round += 1
      dialog.set(null)
    },
  }
}

/** Renders provenance and a publication's effective date; it never decides acceptance coverage. */
export function describeHilosLegalRevision(
  revision: HilosLegalHistoryRevision,
): string {
  if (revision.origin === 'first')
    return `First revision · Hilos standard ${revision.setVersion}`
  const today = new Date(Date.now() + offsetMs()).toISOString().slice(0, 10)
  const significance =
    revision.significance === 'editorial'
      ? 'Editorial change'
      : `Substantial change · ${today >= revision.effectiveOn ? 'took effect' : 'takes effect'} ${formatHilosLegalDate(revision.effectiveOn)}`
  const origin =
    revision.origin === 'standard'
      ? `Hilos standard changed, set ${revision.previousSetVersion} → ${revision.setVersion}`
      : 'changed by the project'
  return `${significance} · ${origin}`
}
