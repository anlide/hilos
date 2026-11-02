import { useContext, useLayoutEffect, useMemo, useState } from 'react'
import {
  createHilosLegalDocument,
  createHilosLegalDocumentSetTable,
  HilosLegalRowKey,
  type HilosLegalDocumentSetRow,
  type HilosLegalTable,
} from '@hilos/core/admin/legal'
import {
  computedSignal,
  HilosPages,
  type HilosLegalContext,
} from '@hilos/core/base'
import { HilosLegalDocumentPageShell } from './HilosLegalDocumentPageShell.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'

/** The table owned by one committed Standard set page setup. */
interface HilosLegalDocumentSetBinding {
  readonly context: HilosLegalContext
  readonly clauses: HilosLegalTable<HilosLegalDocumentSetRow>
}

/**
 * The adopted standard clauses for one legal document.
 *
 * @param props The project's legal page context.
 */
export function HilosLegalDocumentSetPage({
  context,
}: {
  context: HilosLegalContext
}) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error(
      'HilosLegalDocumentSetPage requires a provided Hilos router.',
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
  const [binding, setBinding] = useState<HilosLegalDocumentSetBinding | null>(
    null,
  )

  useLayoutEffect(() => {
    const clauses = createHilosLegalDocumentSetTable(context, documentKey)
    clauses.start()
    setBinding({ context, clauses })
    return () => {
      clauses.dispose()
      clauses.controller.dispose()
    }
  }, [context, documentKey])

  return (
    <HilosLegalDocumentPageShell
      context={context}
      page={HilosPages.LEGAL_DOCUMENT_SET}
    >
      {details && binding?.context === context && (
        <>
          {details.set && (
            <p
              className="small text-body-secondary"
              data-id="legal-set-metadata"
            >
              Set {details.set.version} from {details.set.publishedOn} ·{' '}
              {details.set.significance}
            </p>
          )}
          <HilosViewportTable
            controller={binding.clauses.controller}
            cells={{
              [HilosLegalRowKey.clauseKey]: (row) => (
                <code>{row.clauseKey}</code>
              ),
              [HilosLegalRowKey.statement]: (row) => row.statement,
            }}
          />
        </>
      )}
    </HilosLegalDocumentPageShell>
  )
}
