import { useContext, useEffect, useMemo } from 'react'
import {
  computedSignal,
  createHilosLegalRevisionsTable,
  createHilosLegalRevisionAcceptanceSummary,
  HilosPages,
  LEGAL_CATALOG_REFUSAL_SECTION,
  LEGAL_REVISION_SECTION,
  legalAdminRevisionSchema,
  resolveHilosPath,
  type HilosLegalContext,
} from '@hilos/core'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosLegalChanges } from '../../legal/HilosLegalChanges.js'
import { HilosLegalRevisionText } from '../../legal/HilosLegalRevisionText.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'

/** Exact revision text, changes and its live acceptance count. */
export function HilosLegalRevisionPage({
  context,
}: {
  context: HilosLegalContext
}) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error('HilosLegalRevisionPage requires a provided Hilos router.')
  const document = useMemo(
    () =>
      computedSignal(() =>
        String(router.currentRoute.get().params.documentKey ?? ''),
      ),
    [router],
  )
  const revisionId = useMemo(
    () =>
      computedSignal(() =>
        String(router.currentRoute.get().params.revisionId ?? ''),
      ),
    [router],
  )
  const revisions = useMemo(
    () =>
      createHilosLegalRevisionsTable(
        context,
        document,
        HilosPages.LEGAL_REVISION,
        revisionId,
      ),
    [context, document, revisionId],
  )
  const raw = useSignal(context.scopes.pageDataSignal(LEGAL_REVISION_SECTION))
  const refusal = useSignal(
    context.scopes.pageDataSignal(LEGAL_CATALOG_REFUSAL_SECTION),
  )
  const parsed = legalAdminRevisionSchema.safeParse(raw)
  const details = parsed.success ? parsed.data : null
  const summary = useMemo(
    () =>
      createHilosLegalRevisionAcceptanceSummary(
        revisions.controller,
        revisionId,
      ),
    [revisions, revisionId],
  )
  const accepted = useSignal(summary)
  useEffect(() => {
    revisions.start()
    return () => revisions.dispose()
  }, [revisions])
  return (
    <HilosAdminPage page={HilosPages.LEGAL_REVISION}>
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
            {!details.declared ? (
              <div
                className="alert alert-warning"
                data-id="legal-revision-undeclared"
              >
                This revision is no longer in code. Its text and comparison are
                unavailable.
              </div>
            ) : (
              details.revision && (
                <>
                  <p>
                    <strong>{details.revisionId}</strong>
                    {details.current && (
                      <span className="badge text-bg-primary ms-2">
                        Current
                      </span>
                    )}{' '}
                    <span className="badge text-bg-secondary ms-2">
                      {details.revision.significance}
                    </span>
                  </p>
                  <p className="text-body-secondary small">
                    Published {details.revision.publishedOn} · Effective{' '}
                    {details.revision.effectiveOn}
                  </p>
                  <section className="mb-4">
                    <h2 className="h5">Changes from the previous revision</h2>
                    {details.changes !== null &&
                    details.predecessorId !== null ? (
                      <HilosLegalChanges
                        changes={details.changes}
                        fromLabel={details.predecessorId}
                        toLabel={details.revisionId}
                      />
                    ) : (
                      <p className="text-body-secondary">
                        This is the first revision; there is nothing to compare
                        it with.
                      </p>
                    )}
                  </section>
                  {details.clauses && (
                    <section className="mb-4">
                      <h2 className="h5">Complete text</h2>
                      <HilosLegalRevisionText clauses={details.clauses} />
                    </section>
                  )}
                </>
              )
            )}
            <section>
              <h2 className="h5">Who accepted this revision</h2>
              <p data-id="legal-revision-accepted">{accepted}</p>
              <HilosLink
                to={resolveHilosPath(HilosPages.LEGAL_ACCEPTANCES)}
                className="btn btn-outline-secondary"
                data-id="legal-open-acceptances"
              >
                Open acceptances
              </HilosLink>
            </section>
          </>
        )
      )}
    </HilosAdminPage>
  )
}
