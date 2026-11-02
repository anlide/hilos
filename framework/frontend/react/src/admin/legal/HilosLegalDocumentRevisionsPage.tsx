import { useContext, useLayoutEffect, useMemo, useState } from 'react'
import {
  createHilosLegalDocument,
  createHilosLegalRevisionsTable,
  HilosLegalRowKey,
  type HilosLegalRevisionRow,
  type HilosLegalTable,
} from '@hilos/core/admin/legal'
import {
  computedSignal,
  HILOS_TABLE_ACTIONS_KEY,
  HilosPages,
  resolveHilosPath,
  type HilosLegalContext,
} from '@hilos/core/base'
import { HilosLegalDocumentPageShell } from './HilosLegalDocumentPageShell.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'

/** The table owned by one committed Revisions page setup. */
interface HilosLegalDocumentRevisionsBinding {
  readonly context: HilosLegalContext
  readonly revisions: HilosLegalTable<HilosLegalRevisionRow>
}

/**
 * The declared and recorded revision history of one legal document.
 *
 * @param props The project's legal page context.
 */
export function HilosLegalDocumentRevisionsPage({
  context,
}: {
  context: HilosLegalContext
}) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error(
      'HilosLegalDocumentRevisionsPage requires a provided Hilos router.',
    )

  const documentSource = useMemo(
    () => createHilosLegalDocument(context),
    [context],
  )
  const details = useSignal(documentSource)
  const documentKey = useMemo(
    () =>
      computedSignal(() =>
        String(router.currentRoute.get().params.documentKey ?? ''),
      ),
    [router],
  )
  const [binding, setBinding] =
    useState<HilosLegalDocumentRevisionsBinding | null>(null)

  useLayoutEffect(() => {
    const revisions = createHilosLegalRevisionsTable(
      context,
      documentKey,
      HilosPages.LEGAL_DOCUMENT_REVISIONS,
    )
    revisions.start()
    setBinding({ context, revisions })
    return () => {
      revisions.dispose()
      revisions.controller.dispose()
    }
  }, [context, documentKey])

  return (
    <HilosLegalDocumentPageShell
      context={context}
      page={HilosPages.LEGAL_DOCUMENT_REVISIONS}
    >
      {details && binding?.context === context && (
        <HilosViewportTable
          controller={binding.revisions.controller}
          cells={{
            [HilosLegalRowKey.publishedOn]: (row) => (
              <>
                <div className="fw-semibold">{row.publishedOn ?? '—'}</div>
                <div className="small text-body-secondary">{row.rowKey}</div>
                {row.current && (
                  <span className="badge text-bg-primary">Current</span>
                )}
                {!row.declared && (
                  <span className="badge text-bg-warning">Not in code</span>
                )}
              </>
            ),
            [HilosLegalRowKey.significance]: (row) => row.significance ?? '—',
            [HilosLegalRowKey.effectiveOn]: (row) => row.effectiveOn ?? '—',
            [HilosLegalRowKey.setVersion]: (row) => row.setVersion ?? '—',
            [HilosLegalRowKey.heldCount]: (row) => row.heldCount,
            [HILOS_TABLE_ACTIONS_KEY]: (row) => (
              <HilosLink
                to={resolveHilosPath(HilosPages.LEGAL_REVISION, {
                  documentKey: details.document,
                  revisionId: row.rowKey,
                })}
                className="btn btn-sm btn-outline-secondary"
                data-id="legal-revision-open"
                data-revision={row.rowKey}
              >
                Open
              </HilosLink>
            ),
          }}
        />
      )}
    </HilosLegalDocumentPageShell>
  )
}
