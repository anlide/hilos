import { useContext, useEffect, useMemo } from 'react'
import {
  computedSignal,
  createHilosLegalRevisionsTable,
  HilosPages,
  HilosLegalRowKey,
  HILOS_TABLE_ACTIONS_KEY,
  LEGAL_CATALOG_REFUSAL_SECTION,
  LEGAL_DOCUMENT_SECTION,
  legalAdminDocumentSchema,
  resolveHilosPath,
  type HilosLegalContext,
} from '@hilos/core'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'

/** A document's adopted standard, deviations and revision history. */
export function HilosLegalDocumentPage({
  context,
}: {
  context: HilosLegalContext
}) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error('HilosLegalDocumentPage requires a provided Hilos router.')
  const document = useMemo(
    () =>
      computedSignal(() =>
        String(router.currentRoute.get().params.documentKey ?? ''),
      ),
    [router],
  )
  const revisions = useMemo(
    () => createHilosLegalRevisionsTable(context, document),
    [context, document],
  )
  const raw = useSignal(context.scopes.pageDataSignal(LEGAL_DOCUMENT_SECTION))
  const refusal = useSignal(
    context.scopes.pageDataSignal(LEGAL_CATALOG_REFUSAL_SECTION),
  )
  const parsed = legalAdminDocumentSchema.safeParse(raw)
  const details = parsed.success ? parsed.data : null
  useEffect(() => {
    revisions.start()
    return () => revisions.dispose()
  }, [revisions])
  return (
    <HilosAdminPage page={HilosPages.LEGAL_DOCUMENT}>
      {typeof refusal === 'string' ? (
        <div
          className="alert alert-danger text-break"
          role="alert"
          data-id="legal-catalog-refusal"
        >
          <h2 className="h6">The legal document catalog could not be loaded</h2>
          <p className="mb-0">{refusal}</p>
        </div>
      ) : (
        details && (
          <>
            <div className="alert alert-secondary small">
              Document texts live in code. A new revision is published by a
              deployment.
            </div>
            {details.set && (
              <section data-id="legal-set" className="mb-4">
                <h2 className="h5">Hilos standard set {details.set.version}</h2>
                <p className="small text-body-secondary">
                  {details.set.publishedOn} · {details.set.significance} ·{' '}
                  {details.set.clauses.length} clauses
                </p>
                <ol className="list-group list-group-numbered">
                  {details.set.clauses.map((clause) => (
                    <li
                      key={clause.clauseKey}
                      className="list-group-item text-break"
                    >
                      <code className="small me-2">{clause.clauseKey}</code>
                      {clause.statement}
                    </li>
                  ))}
                </ol>
              </section>
            )}
            {details.newerSet && (
              <section
                className="alert alert-warning"
                data-id="legal-set-newer"
              >
                <h2 className="h6">
                  Hilos has released set {details.newerSet.version} for this
                  document
                </h2>
                <p className="small">
                  {details.newerSet.publishedOn} ·{' '}
                  {details.newerSet.significance}. The project keeps its
                  declared set until it publishes a new revision.
                </p>
                <ul className="mb-0 small">
                  {details.newerSet.changes.map((change) => (
                    <li key={change.clauseKey}>
                      {change.kind}: {change.title}{' '}
                      <code>{change.clauseKey}</code>
                    </li>
                  ))}
                </ul>
              </section>
            )}
            <section className="mb-4">
              <h2 className="h5">Different in this project</h2>
              {details.deviations.length === 0 && (
                <p className="text-body-secondary">
                  No project deviations are declared.
                </p>
              )}
              {details.deviations.map((deviation) => (
                <div
                  key={deviation.clauseKey}
                  className="border rounded p-3 mb-2 text-break"
                  data-id="legal-deviation-row"
                >
                  <h3 className="h6">
                    {deviation.statement}{' '}
                    <span className="badge text-bg-secondary">
                      {deviation.direction}
                    </span>
                  </h3>
                  <p className="small text-body-secondary">
                    Standard: {deviation.standardStatement} ·{' '}
                    <code>{deviation.clauseKey}</code>
                  </p>
                  {deviation.text.split('\n\n').map((paragraph, index) => (
                    <p key={index} className="small mb-2">
                      {paragraph}
                    </p>
                  ))}
                </div>
              ))}
            </section>
            <HilosViewportTable
              controller={revisions.controller}
              cells={{
                [HilosLegalRowKey.rowKey]: (row) => (
                  <div data-id="legal-revision-row" data-revision={row.rowKey}>
                    <strong>{row.rowKey}</strong>
                    {row.current && (
                      <span className="badge text-bg-primary ms-2">
                        Current
                      </span>
                    )}
                    {!row.declared && (
                      <span className="badge text-bg-warning ms-2">
                        Not in code
                      </span>
                    )}
                    {row.revision && (
                      <div className="small text-body-secondary">
                        {row.revision.publishedOn} · {row.revision.significance}{' '}
                        · Effective {row.revision.effectiveOn} · Set{' '}
                        {row.revision.setVersion}
                      </div>
                    )}
                  </div>
                ),
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
          </>
        )
      )}
    </HilosAdminPage>
  )
}
