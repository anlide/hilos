import {
  DATA_EXPORT_DOWNLOAD_PATH,
  HILOS_DATA_EXPORT_COPY as COPY,
  HILOS_STEP_UP_COPY,
  dataExportStatusText,
  type HilosDataExportStore,
  type HilosDataExportFlow,
} from '@hilos/core'
import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
import { LoadingButton } from '../LoadingButton.js'
import { HilosStepUpStep } from '../auth/HilosStepUpStep.js'
import { useSignal } from '../useSignal.js'

export interface HilosDataExportProps {
  store: HilosDataExportStore
  flow: HilosDataExportFlow
  lead?: string
}

/** The shared personal-copy surface; its owner supplies the running store and flow. */
export function HilosDataExport({ store, flow, lead }: HilosDataExportProps) {
  const node = useSignal(store.state)
  const busy = useSignal(flow.busy)
  const refusal = useSignal(flow.refusal)
  const stepRefusal = useSignal(flow.stepUp.refusal)
  const open = useSignal(flow.open)
  const status = dataExportStatusText(node)
  const preparingRoom = COPY.preparing.replace(
    '{time}',
    new Date().toLocaleString(),
  )
  return (
    <section className="border rounded p-3 mb-3" data-id="data-export">
      <h3 className="h6 mb-2">{COPY.title}</h3>
      <p className="small text-body-secondary mb-2">
        {COPY.lead} {lead}
      </p>
      <div className="visually-hidden" role="status" aria-live="polite">
        {status}
      </div>
      <div className="visually-hidden" role="alert" aria-live="assertive">
        {open ? '' : refusal}
      </div>
      <div className="hilos-stack">
        <p className="small mb-2 invisible" aria-hidden="true" inert>
          {preparingRoom}
        </p>
        {node !== null ? (
          <p className="small mb-2" data-id={`data-export-${node.state}`}>
            {status}
          </p>
        ) : null}
      </div>
      <HilosFormError
        message={open ? null : refusal}
        dataId="data-export-error"
      />
      <div className="hilos-stack">
        <div
          className="d-flex flex-column gap-2 invisible"
          aria-hidden="true"
          inert
        >
          <span className="btn btn-sm btn-primary">{COPY.download}</span>
          <span className="btn btn-sm btn-outline-secondary">
            {COPY.prepareNew}
          </span>
        </div>
        <div className="d-flex flex-column gap-2">
          {node?.state === 'ready' ? (
            <>
              <a
                className="btn btn-sm btn-primary"
                href={DATA_EXPORT_DOWNLOAD_PATH}
                download
                data-id="data-export-download"
              >
                {COPY.download}
              </a>
              <LoadingButton
                className="btn-sm btn-outline-secondary"
                loading={busy}
                data-id="data-export-prepare-new"
                onClick={() => void flow.prepare()}
              >
                {COPY.prepareNew}
              </LoadingButton>
            </>
          ) : node?.state !== 'preparing' ? (
            <LoadingButton
              className="btn-sm btn-primary"
              loading={busy}
              data-id={
                node?.state === 'failed'
                  ? 'data-export-retry'
                  : 'data-export-prepare'
              }
              onClick={() => void flow.prepare()}
            >
              {node?.state === 'failed' ? COPY.retry : COPY.prepare}
            </LoadingButton>
          ) : null}
        </div>
      </div>
      <HilosModal
        open={open}
        title={HILOS_STEP_UP_COPY.title}
        initialFocus="inner"
        onClose={() => flow.close()}
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
              form="hilos-data-export-proof"
              loading={busy}
              data-id="data-export-confirm"
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
          id="hilos-data-export-proof"
          data-id="data-export-step-up"
          onSubmit={(event) => {
            event.preventDefault()
            void flow.confirm()
          }}
        >
          <HilosStepUpStep controller={flow.stepUp} />
          <HilosFormError message={refusal} dataId="data-export-order-error" />
        </form>
      </HilosModal>
    </section>
  )
}
