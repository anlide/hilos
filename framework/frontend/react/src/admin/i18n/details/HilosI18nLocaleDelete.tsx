import { useEffect, useRef, useState } from 'react'
import {
  HILOS_I18N_LOCALE_DELETE_COPY,
  type ActionHandle,
  type HilosI18nLocaleDeleteTarget,
} from '@hilos/core'
import { HilosActionError } from '../../../HilosActionError.js'
import { HilosEditNotice } from '../../../HilosEditNotice.js'
import { HilosModal } from '../../../HilosModal.js'
import { LoadingButton } from '../../../LoadingButton.js'
import { useLookOnly } from '../../../hilosLookOnly.js'
import { useTrackedAction } from '../../../useTrackedAction.js'

interface LocaleDeleteProps {
  open: boolean
  onClose: () => void
  target: HilosI18nLocaleDeleteTarget | null
  source: HilosI18nLocaleDeleteTarget | null
  deleteLocale: (
    languageId: number,
    countryId: number | null,
    localeId: number,
  ) => ActionHandle
}

/** The confirmation and its action outlive the locale's own deletion echo. */
export function HilosI18nLocaleDelete(props: LocaleDeleteProps) {
  return props.open && props.target ? (
    <LocaleDelete {...props} target={props.target} />
  ) : null
}

function LocaleDelete({
  target: selected,
  source,
  onClose,
  deleteLocale,
}: LocaleDeleteProps & { target: HilosI18nLocaleDeleteTarget }) {
  const [target] = useState(() => ({ ...selected }))
  const copy = HILOS_I18N_LOCALE_DELETE_COPY,
    action = useTrackedAction(),
    { locked } = useLookOnly()
  const sending = useRef(false),
    mounted = useRef(false)
  useEffect(() => {
    mounted.current = true
    return () => {
      mounted.current = false
    }
  }, [])
  const elsewhere =
    !action.busy &&
    (source === null ||
      source.languageId !== target.languageId ||
      source.countryId !== target.countryId ||
      source.localeId !== target.localeId)
  function close(): void {
    if (!sending.current) onClose()
  }
  async function confirm(): Promise<void> {
    if (sending.current || locked || elsewhere) return
    sending.current = true
    try {
      if (
        (await action.run(
          deleteLocale(target.languageId, target.countryId, target.localeId),
        )) &&
        mounted.current
      )
        onClose()
    } finally {
      sending.current = false
    }
  }
  return (
    <HilosModal
      open
      title={copy.title(target.nativeName, target.countryLabel)}
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
            data-id="i18n-locale-delete-cancel"
            onClick={requestClose}
          >
            {copy.cancel}
          </button>
          <LoadingButton
            className="btn-danger"
            loading={action.loading}
            disabled={action.busy || elsewhere}
            data-id="i18n-locale-delete-confirm"
            onClick={() => void confirm()}
          >
            {copy.confirm}
          </LoadingButton>
        </>
      )}
    >
      <div data-id="i18n-locale-delete-window">
        <div data-id="i18n-locale-delete-error">
          <HilosActionError action={action} detailsTitle={copy.refusalTitle} />
        </div>
        <p>{copy.body(target.languageCode, target.countryCode)}</p>
        <div className="alert alert-warning small py-2 mb-0">{copy.note}</div>
        <HilosEditNotice
          kind={elsewhere ? 'deleted' : null}
          text={copy.elsewhere}
          dataId="i18n-locale-delete-notice"
        />
      </div>
    </HilosModal>
  )
}
