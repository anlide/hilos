import { useEffect, useRef } from 'react'
import {
  HILOS_I18N_COUNTRY_DELETE_COPY,
  hilosI18nCountryDeleteLabel,
  type ActionHandle,
  type HilosI18nCountryDeleteTarget,
} from '@hilos/core'
import { HilosActionError } from '../../../HilosActionError.js'
import { HilosEditNotice } from '../../../HilosEditNotice.js'
import { HilosModal } from '../../../HilosModal.js'
import { LoadingButton } from '../../../LoadingButton.js'
import { useLookOnly } from '../../../hilosLookOnly.js'
import { useTrackedAction } from '../../../useTrackedAction.js'

/** Both entry points keep their chosen target independently of the live source. */
export function HilosI18nCountryDelete({
  open,
  onClose,
  onDeleted,
  target,
  source,
  deleteCountry,
}: {
  open: boolean
  onClose: () => void
  onDeleted?: () => void
  target: HilosI18nCountryDeleteTarget | null
  source: HilosI18nCountryDeleteTarget | null
  deleteCountry: (countryId: number) => ActionHandle
}) {
  const copy = HILOS_I18N_COUNTRY_DELETE_COPY,
    action = useTrackedAction(),
    { locked } = useLookOnly()
  const mounted = useRef(false),
    sending = useRef(false)
  const { clearError } = action
  useEffect(() => {
    mounted.current = true
    return () => {
      mounted.current = false
    }
  }, [])
  useEffect(() => {
    if (open) clearError()
  }, [open, clearError])
  const elsewhere =
    open && !action.busy && source?.countryId !== target?.countryId
  function close(): void {
    if (!sending.current) onClose()
  }
  async function confirm(): Promise<void> {
    if (!open || !target || sending.current || locked || elsewhere) return
    sending.current = true
    try {
      if (
        (await action.run(deleteCountry(target.countryId))) &&
        mounted.current
      ) {
        onClose()
        onDeleted?.()
      }
    } finally {
      sending.current = false
    }
  }
  return (
    <HilosModal
      open={open}
      title={copy.title(target ? hilosI18nCountryDeleteLabel(target) : '')}
      onClose={close}
      closeOnBackdrop={!action.busy}
      closeOnEsc={!action.busy}
      initialFocus="dialog"
      actions={({ requestClose }) => (
        <>
          <button
            type="button"
            className="btn btn-secondary"
            disabled={action.busy}
            data-id="i18n-country-delete-cancel"
            onClick={requestClose}
          >
            {copy.cancel}
          </button>
          <LoadingButton
            className="btn-danger"
            loading={action.loading}
            disabled={action.busy || elsewhere}
            data-id="i18n-country-delete-confirm"
            onClick={() => void confirm()}
          >
            {copy.confirm}
          </LoadingButton>
        </>
      )}
    >
      <div data-id="i18n-country-delete-window">
        <div data-id="i18n-country-delete-error">
          <HilosActionError action={action} detailsTitle={copy.refusalTitle} />
        </div>
        <p>{copy.body}</p>
        <div className="alert alert-warning small py-2 mb-0">{copy.note}</div>
        <HilosEditNotice
          kind={elsewhere ? 'deleted' : null}
          text={copy.elsewhere}
          dataId="i18n-country-delete-notice"
        />
      </div>
    </HilosModal>
  )
}
