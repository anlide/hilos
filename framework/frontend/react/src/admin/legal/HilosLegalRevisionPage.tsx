import { useContext, useEffect, useMemo } from 'react'
import {
  computedSignal,
  HilosPages,
  resolveHilosPath,
  type HilosLegalContext,
} from '@hilos/core/base'
import {
  createHilosLegalRevision,
  createHilosLegalRevisionsTable,
  createHilosLegalRevisionAcceptanceSummary,
} from '@hilos/core/admin/legal'
import { HilosLink } from '../../HilosLink.js'
import { HilosLegalChanges } from '../../legal/HilosLegalChanges.js'
import { HilosLegalRevisionPageShell } from './HilosLegalRevisionPageShell.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'

/** The selected revision's comparison and live exact acceptance count. */
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
  const revisionSource = useMemo(
    () => createHilosLegalRevision(context),
    [context],
  )
  const details = useSignal(revisionSource)
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
    <HilosLegalRevisionPageShell
      context={context}
      page={HilosPages.LEGAL_REVISION}
    >
      {details && (
        <>
          {details.declared && details.revision && (
            <section className="mb-4">
              <h2 className="h5">Changes from the previous revision</h2>
              {details.changes !== null && details.predecessorId !== null ? (
                <HilosLegalChanges
                  changes={details.changes}
                  fromLabel={details.predecessorId}
                  toLabel={details.revisionId}
                />
              ) : (
                <p className="text-body-secondary">
                  This is the first revision; there is nothing to compare it
                  with.
                </p>
              )}
            </section>
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
      )}
    </HilosLegalRevisionPageShell>
  )
}
