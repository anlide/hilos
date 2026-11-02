import { useContext, useLayoutEffect, useMemo, useState } from 'react'
import {
  createHilosLegalDocumentDeviationsTable,
  HilosLegalRowKey,
  type HilosLegalDocumentDeviationRow,
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

/** The table owned by one committed Deviations page setup. */
interface HilosLegalDocumentDeviationsBinding {
  readonly context: HilosLegalContext
  readonly deviations: HilosLegalTable<HilosLegalDocumentDeviationRow>
}

/**
 * The current project's deviations from one document's standard set.
 *
 * @param props The project's legal page context.
 */
export function HilosLegalDocumentDeviationsPage({
  context,
}: {
  context: HilosLegalContext
}) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error(
      'HilosLegalDocumentDeviationsPage requires a provided Hilos router.',
    )

  const documentKey = useMemo(
    () =>
      computedSignal(() =>
        String(router.currentRoute.get().params.documentKey ?? ''),
      ),
    [router],
  )
  const [binding, setBinding] =
    useState<HilosLegalDocumentDeviationsBinding | null>(null)

  useLayoutEffect(() => {
    const deviations = createHilosLegalDocumentDeviationsTable(
      context,
      documentKey,
    )
    deviations.start()
    setBinding({ context, deviations })
    return () => {
      deviations.dispose()
      deviations.controller.dispose()
    }
  }, [context, documentKey])

  return (
    <HilosLegalDocumentPageShell
      context={context}
      page={HilosPages.LEGAL_DOCUMENT_DEVIATIONS}
    >
      {binding?.context === context && (
        <HilosViewportTable
          controller={binding.deviations.controller}
          cells={{
            [HilosLegalRowKey.statement]: (row) => (
              <>
                <div className="fw-semibold">{row.statement}</div>
                <p className="small text-body-secondary">
                  Standard: {row.standardStatement}
                </p>
                {row.text.split('\n\n').map((paragraph, index) => (
                  <p className="small mb-2" key={index}>
                    {paragraph}
                  </p>
                ))}
              </>
            ),
            [HilosLegalRowKey.direction]: (row) =>
              row.direction ? (
                <span className="badge text-bg-secondary">{row.direction}</span>
              ) : (
                <span className="text-body-secondary">—</span>
              ),
            [HilosLegalRowKey.clauseKey]: (row) => <code>{row.clauseKey}</code>,
          }}
        />
      )}
    </HilosLegalDocumentPageShell>
  )
}
