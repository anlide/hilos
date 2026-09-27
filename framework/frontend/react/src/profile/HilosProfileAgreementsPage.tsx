import { useEffect, useMemo, useState } from 'react'
import {
  createHilosLegalAgreementsStore,
  describeHilosLegalAgreement,
  formatHilosLegalDate,
  hilosLegalDocumentLabel,
  HILOS_PAGE_ROUTES,
  HilosPages,
  type HilosLegalAgreement,
  type HilosLegalChange,
  type HilosLegalClause,
  type HilosLegalContext,
} from '@hilos/core'
import { HilosLink } from '../HilosLink.js'
import { HilosModal } from '../HilosModal.js'
import { HilosPageHeading } from '../HilosPageHeading.js'
import { HilosLegalChanges } from '../legal/HilosLegalChanges.js'
import { HilosLegalRevisionText } from '../legal/HilosLegalRevisionText.js'
import { useSignal } from '../useSignal.js'

export interface HilosProfileAgreementsPageProps {
  context: HilosLegalContext
}

/** Personal agreement coverage with read-only text and comparison dialogs. */
export function HilosProfileAgreementsPage({
  context,
}: HilosProfileAgreementsPageProps) {
  const store = useMemo(
    () => createHilosLegalAgreementsStore(context),
    [context],
  )
  useEffect(() => store.start(), [store])
  const state = useSignal(store.state)
  const texts = useSignal(store.texts)
  const rows =
    state?.documents.map((agreement) => ({
      agreement,
      ...describeHilosLegalAgreement(agreement),
    })) ?? []
  const [textDialog, setTextDialog] = useState<{
    title: string
    clauses: HilosLegalClause[]
  } | null>(null)
  const [changesDialog, setChangesDialog] = useState<{
    title: string
    changes: HilosLegalChange[]
    from: string
    to: string
  } | null>(null)
  function openText(agreement: HilosLegalAgreement): void {
    const text = texts?.documents.find(
      (entry) => entry.document === agreement.document,
    )
    if (!text) return
    const held = agreement.held
    setTextDialog({
      title: `${hilosLegalDocumentLabel(agreement.document)} · ${formatHilosLegalDate((held ?? agreement.current).publishedOn)}`,
      clauses:
        held && held.revisionId !== agreement.current.revisionId
          ? (text.held ?? text.current)
          : text.current,
    })
  }
  function openChanges(agreement: HilosLegalAgreement): void {
    const text = texts?.documents.find(
      (entry) => entry.document === agreement.document,
    )
    if (!text || !agreement.held) return
    setChangesDialog({
      title: `${hilosLegalDocumentLabel(agreement.document)} — what changed`,
      changes: text.changes,
      from: formatHilosLegalDate(agreement.held.publishedOn),
      to: formatHilosLegalDate(agreement.current.publishedOn),
    })
  }
  return (
    <div data-id="profile-agreements-view">
      <HilosPageHeading />
      {state && texts ? (
        <>
          <p className="small text-body-secondary">
            These are the revisions you accepted. The current text may have
            changed since then.
          </p>
          {rows.length === 0 && (
            <p className="text-body-secondary">
              This project publishes no legal documents
            </p>
          )}
          {rows.map((row) => (
            <div
              key={row.agreement.document}
              className="py-3 border-bottom"
              data-id="legal-agreement-row"
              data-document={row.agreement.document}
            >
              <div className="d-flex align-items-center gap-3">
                <i
                  className={`bi fs-5 text-body-secondary ${row.agreement.document === 'terms' ? 'bi-file-earmark-check' : 'bi-shield-check'}`}
                  aria-hidden="true"
                />
                <div className="flex-grow-1 text-break">
                  <h2 className="h6 mb-1">
                    {hilosLegalDocumentLabel(row.agreement.document)}
                  </h2>
                  <div className="small" data-id="legal-agreement-state">
                    {row.summary}
                  </div>
                  <div className="small text-body-secondary">
                    {row.standard}
                  </div>
                </div>
                <button
                  type="button"
                  className="btn btn-sm btn-outline-secondary"
                  data-id="legal-agreement-open"
                  onClick={() => openText(row.agreement)}
                >
                  Open
                </button>
              </div>
              {row.notice && (
                <div
                  className={`alert small py-2 mt-3 mb-0 ${row.agreement.standing === 'covered' ? 'alert-secondary' : 'alert-warning'}`}
                  data-id="legal-agreement-notice"
                >
                  {row.notice}
                  {row.canCompare && (
                    <button
                      type="button"
                      className="btn btn-link btn-sm p-0 ms-1 align-baseline"
                      data-id="legal-agreement-changes-open"
                      onClick={() => openChanges(row.agreement)}
                    >
                      What changed
                    </button>
                  )}
                </div>
              )}
            </div>
          ))}
          <div className="d-flex align-items-center gap-3 py-3 border-bottom mt-3">
            <i
              className="bi bi-clock-history fs-5 text-body-secondary"
              aria-hidden="true"
            />
            <div className="flex-grow-1">
              <h2 className="h6 mb-0">Revision history</h2>
            </div>
            <HilosLink
              to={HILOS_PAGE_ROUTES[HilosPages.PROFILE_AGREEMENTS_HISTORY]!}
              className="btn btn-sm btn-outline-secondary"
              data-id="profile-agreements-history-open"
            >
              Open
            </HilosLink>
          </div>
        </>
      ) : (
        <p className="text-body-secondary">Loading agreements…</p>
      )}
      <HilosModal
        open={textDialog !== null}
        title={textDialog?.title ?? ''}
        initialFocus="dialog"
        size="wide"
        onClose={() => setTextDialog(null)}
        actions={({ requestClose }) => (
          <button
            type="button"
            className="btn btn-secondary"
            data-id="legal-text-close"
            onClick={requestClose}
          >
            Close
          </button>
        )}
      >
        {textDialog && (
          <div data-id="legal-revision-text-modal">
            <HilosLegalRevisionText clauses={textDialog.clauses} />
          </div>
        )}
      </HilosModal>
      <HilosModal
        open={changesDialog !== null}
        title={changesDialog?.title ?? ''}
        initialFocus="dialog"
        size="wide"
        onClose={() => setChangesDialog(null)}
        actions={({ requestClose }) => (
          <button
            type="button"
            className="btn btn-secondary"
            data-id="legal-changes-close"
            onClick={requestClose}
          >
            Close
          </button>
        )}
      >
        {changesDialog && (
          <div data-id="legal-changes-modal">
            <HilosLegalChanges
              changes={changesDialog.changes}
              fromLabel={changesDialog.from}
              toLabel={changesDialog.to}
            />
          </div>
        )}
      </HilosModal>
    </div>
  )
}
