import { useEffect, useMemo } from 'react'
import {
  createHilosLegalAcceptancesExport,
  createHilosLegalAcceptancesTable,
  hilosLegalAcceptancesExportFilterLine,
  hilosLegalAcceptancesExportStatus,
  hilosLegalAcceptancesExportStatusRoom,
  hilosLegalDocumentLabel,
  HILOS_LEGAL_ACCEPTANCES_EXPORT_COPY as EXPORT_COPY,
  HILOS_STEP_UP_COPY,
  HilosLegalRowKey,
  HILOS_TABLE_ACTIONS_KEY,
  HilosPages,
  LEGAL_ACCEPTANCES_EXPORT_DOWNLOAD_PATH,
  resolveHilosPath,
  type HilosLegalContext,
} from '@hilos/core'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosFormError } from '../../HilosFormError.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { HilosStepUpStep } from '../../auth/HilosStepUpStep.js'
import { useSignal } from '../../useSignal.js'

/** Immutable acceptance records with the complete server-supplied filter vocabulary, and their export. */
export function HilosLegalAcceptancesPage({
  context,
}: {
  context: HilosLegalContext
}) {
  const table = useMemo(
    () => createHilosLegalAcceptancesTable(context),
    [context],
  )
  const exporter = useMemo(
    () => createHilosLegalAcceptancesExport(context, table.controller),
    [context, table],
  )
  useEffect(() => {
    table.start()
    exporter.start()
    return () => {
      exporter.dispose()
      table.dispose()
    }
  }, [table, exporter])
  const node = useSignal(exporter.state)
  const busy = useSignal(exporter.busy)
  const refusal = useSignal(exporter.refusal)
  const stepRefusal = useSignal(exporter.stepUp.refusal)
  const open = useSignal(exporter.open)
  const status = hilosLegalAcceptancesExportStatus(node)
  const statusRoom = useMemo(() => hilosLegalAcceptancesExportStatusRoom(), [])
  return (
    <HilosAdminPage page={HilosPages.LEGAL_ACCEPTANCES}>
      <div className="d-flex flex-column align-items-end mb-3">
        <div className="visually-hidden" role="status" aria-live="polite">
          {status}
        </div>
        <div className="visually-hidden" role="alert" aria-live="assertive">
          {open ? '' : refusal}
        </div>
        <div className="d-flex flex-wrap justify-content-end gap-2">
          {node?.state === 'ready' ? (
            <a
              className="btn btn-sm btn-primary"
              href={LEGAL_ACCEPTANCES_EXPORT_DOWNLOAD_PATH}
              download
              data-id="legal-acceptances-export-download"
            >
              {EXPORT_COPY.download}
            </a>
          ) : null}
          <LoadingButton
            className="btn-sm btn-outline-primary"
            loading={busy}
            disabled={node?.state === 'preparing'}
            data-id="legal-acceptances-export"
            onClick={() => void exporter.export()}
          >
            <i className="bi bi-download me-1" aria-hidden="true"></i>
            {EXPORT_COPY.button}
          </LoadingButton>
        </div>
        <div className="hilos-stack w-100 text-end mt-2">
          <div className="invisible" aria-hidden="true" inert>
            <p className="small mb-0">{statusRoom}</p>
            <p className="small text-body-secondary text-truncate mb-0">
              {EXPORT_COPY.all}
            </p>
          </div>
          {node !== null ? (
            <div>
              <p
                className="small mb-0"
                data-id={`legal-acceptances-export-${node.state}`}
              >
                {status}
              </p>
              <p
                className="small text-body-secondary text-truncate mb-0"
                data-id="legal-acceptances-export-filter"
              >
                {hilosLegalAcceptancesExportFilterLine(node)}
              </p>
            </div>
          ) : null}
        </div>
        <div className="w-100">
          <HilosFormError
            message={open ? null : refusal}
            dataId="legal-acceptances-export-error"
          />
        </div>
      </div>
      <div data-id="legal-acceptances-table">
        <HilosViewportTable
          controller={table.controller}
          cells={{
            [HilosLegalRowKey.name]: (row) => (
              <div data-id="legal-acceptance-row" data-record={row.rowKey}>
                <strong>
                  <HilosHideable value={row.name} />
                </strong>
                <div className="small text-body-secondary">
                  <HilosHideable value={row.email}>
                    {(email) => email ?? 'No verified email'}
                  </HilosHideable>
                </div>
              </div>
            ),
            [HilosLegalRowKey.document]: (row) =>
              hilosLegalDocumentLabel(row.document),
            [HilosLegalRowKey.revisionId]: (row) => (
              <>
                {row.revisionId}{' '}
                {row.declared === false && (
                  <span className="badge text-bg-warning">Not in code</span>
                )}
              </>
            ),
            [HILOS_TABLE_ACTIONS_KEY]: (row) => (
              <HilosLink
                to={resolveHilosPath(HilosPages.USER, {
                  userId: String(row.userId),
                })}
                className="btn btn-sm btn-outline-secondary"
                data-id="legal-acceptance-person"
              >
                Account
              </HilosLink>
            ),
          }}
        />
      </div>
      <p className="small text-body-secondary mt-3">
        Acceptance records cannot be edited or deleted here. A record names one
        person, one document, one exact revision and the time of acceptance.
        Refusal and silence create no acceptance record.
      </p>
      <HilosModal
        open={open}
        title={HILOS_STEP_UP_COPY.title}
        initialFocus="inner"
        onClose={() => exporter.close()}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-outline-secondary"
              onClick={requestClose}
            >
              {HILOS_STEP_UP_COPY.cancel}
            </button>
            <LoadingButton
              className="btn-primary"
              type="submit"
              form="hilos-legal-acceptances-export-proof"
              loading={busy}
              data-id="legal-acceptances-export-confirm"
            >
              {HILOS_STEP_UP_COPY.confirm}
            </LoadingButton>
          </>
        )}
      >
        <div className="visually-hidden" role="alert" aria-live="assertive">
          {stepRefusal ?? refusal}
        </div>
        <form
          id="hilos-legal-acceptances-export-proof"
          data-id="legal-acceptances-export-step-up"
          onSubmit={(event) => {
            event.preventDefault()
            void exporter.confirm()
          }}
        >
          <HilosStepUpStep controller={exporter.stepUp} />
          <HilosFormError
            message={refusal}
            dataId="legal-acceptances-export-order-error"
          />
        </form>
      </HilosModal>
    </HilosAdminPage>
  )
}
