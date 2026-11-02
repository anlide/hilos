import { useMemo, type ReactNode } from 'react'
import {
  createHilosLegalDocument,
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
 * The common entity head and catalog state of the four document pages.
 *
 * @param props The current document page, its live context and body.
 */
export function HilosLegalDocumentPageShell({
  context,
  page,
  children,
}: {
  context: HilosLegalContext
  page: string
  children?: ReactNode
}) {
  const documentSource = useMemo(
    () => createHilosLegalDocument(context),
    [context],
  )
  const document = useSignal(documentSource)
  const refusalSource = useMemo(
    () => context.scopes.pageDataSignal(LEGAL_CATALOG_REFUSAL_SECTION),
    [context],
  )
  const refusal = useSignal(refusalSource)
  const name = useMemo(
    () =>
      computedSignal(() => {
        if (
          context.scopes.page()?.key !== page ||
          typeof refusalSource.get() === 'string'
        )
          return undefined
        const current = documentSource.get()
        return current ? hilosLegalDocumentLabel(current.document) : undefined
      }),
    [context, page, refusalSource, documentSource],
  )
  usePageInstanceName(context.scopes, HilosPages.LEGAL_DOCUMENT, name)
  const available = document && typeof refusal !== 'string' ? document : null

  return (
    <HilosAdminPage
      page={page}
      headKind="entity"
      tabsLabel="Document pages"
      headVisual={
        <span
          className="bg-body-secondary rounded-3 d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
          aria-hidden="true"
        >
          <i className="bi bi-file-earmark-text" />
        </span>
      }
      headMeta={
        available ? (
          <code data-id="legal-document-key">{available.document}</code>
        ) : undefined
      }
      headBadges={
        available ? (
          <>
            {available.set && (
              <span
                className="badge bg-body-tertiary text-body-emphasis border"
                data-id="legal-document-set-version"
              >
                Set {available.set.version}
              </span>
            )}
            {!available.declared && (
              <span className="badge text-bg-warning">Not in code</span>
            )}
          </>
        ) : undefined
      }
      headDescription={
        available?.revision ? (
          <p
            className="text-body-secondary mb-0"
            data-id="legal-document-revision"
          >
            {available.revision.publishedOn} · {available.revision.significance}{' '}
            · Effective {available.revision.effectiveOn}
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
      ) : document ? (
        children
      ) : (
        <HilosSkeleton />
      )}
    </HilosAdminPage>
  )
}
