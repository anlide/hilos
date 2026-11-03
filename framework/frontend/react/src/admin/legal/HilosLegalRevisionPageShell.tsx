import { useMemo, type ReactNode } from 'react'
import {
  createHilosLegalRevision,
  LEGAL_CATALOG_REFUSAL_SECTION,
} from '@hilos/core/admin/legal'
import {
  computedSignal,
  hilosLegalDocumentLabel,
  HilosPages,
  type HilosLegalContext,
} from '@hilos/core/base'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosSkeleton } from '../../HilosSkeleton.js'
import { usePageInstanceName } from '../../usePageInstanceName.js'
import { useSignal } from '../../useSignal.js'

/**
 * The common entity head and catalog state of Revision Overview and Text.
 *
 * @param props The current revision page, its live context and body.
 */
export function HilosLegalRevisionPageShell({
  context,
  page,
  children,
}: {
  context: HilosLegalContext
  page: string
  children?: ReactNode
}) {
  const revisionSource = useMemo(
    () => createHilosLegalRevision(context),
    [context],
  )
  const refusalSource = useMemo(
    () => context.scopes.pageDataSignal(LEGAL_CATALOG_REFUSAL_SECTION),
    [context],
  )
  const refusal = useSignal(refusalSource)
  const currentRevision = useMemo(
    () =>
      computedSignal(() =>
        context.scopes.page()?.key === page &&
        typeof refusalSource.get() !== 'string'
          ? revisionSource.get()
          : undefined,
      ),
    [context, page, refusalSource, revisionSource],
  )
  const details = useSignal(currentRevision)
  const documentName = useMemo(
    () =>
      computedSignal(() => {
        const current = currentRevision.get()
        return current ? hilosLegalDocumentLabel(current.document) : undefined
      }),
    [currentRevision],
  )
  const revisionName = useMemo(
    () =>
      computedSignal(() => {
        const current = currentRevision.get()
        if (!current) return undefined
        return current.declared && current.revision
          ? 'Revision of ' + current.revision.publishedOn
          : 'Revision ' + current.revisionId
      }),
    [currentRevision],
  )
  usePageInstanceName(context.scopes, HilosPages.LEGAL_DOCUMENT, documentName)
  usePageInstanceName(context.scopes, HilosPages.LEGAL_REVISION, revisionName)

  return (
    <HilosAdminPage
      page={page}
      headKind="entity"
      tabsLabel="Revision pages"
      headVisual={
        <span
          className="bg-body-secondary rounded-3 d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
          aria-hidden="true"
        >
          <i className="bi bi-file-earmark-text" />
        </span>
      }
      headBadges={
        details ? (
          <>
            {details.current && (
              <span
                className="badge text-bg-primary"
                data-id="legal-revision-current"
              >
                Current
              </span>
            )}
            {details.declared && details.revision && (
              <span
                className="badge text-bg-secondary"
                data-id="legal-revision-significance"
              >
                {details.revision.significance}
              </span>
            )}
            {!details.declared && (
              <span className="badge text-bg-warning">Not in code</span>
            )}
          </>
        ) : undefined
      }
      headDescription={
        details ? (
          <p
            className="text-body-secondary mb-0"
            data-id="legal-revision-metadata"
          >
            {hilosLegalDocumentLabel(details.document)}
            {details.declared && details.revision && (
              <>
                {' '}
                · Published {details.revision.publishedOn} · Effective{' '}
                {details.revision.effectiveOn} · Set{' '}
                {details.revision.setVersion}
              </>
            )}
          </p>
        ) : undefined
      }
    >
      {typeof refusal === 'string' ? (
        <div
          className="alert alert-danger text-break"
          role="alert"
          data-id="legal-catalog-refusal"
        >
          <h2 className="h6">The legal document catalog could not be loaded</h2>
          <p className="mb-0">{refusal}</p>
        </div>
      ) : details ? (
        <>
          {!details.declared && (
            <div
              className="alert alert-warning"
              data-id="legal-revision-undeclared"
            >
              This revision is no longer in code. Its text and comparison are
              unavailable.
            </div>
          )}
          {children}
        </>
      ) : (
        <HilosSkeleton />
      )}
    </HilosAdminPage>
  )
}
