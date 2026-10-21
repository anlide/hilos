import { useEffect, useRef, useState, type ReactNode } from 'react'
import {
  HILOS_I18N_COUNTRY_NAME_DELETE_COPY,
  createHilosI18nCountryNameDelete,
  type HilosI18nCountryContext,
  type HilosI18nCountryNameDeleteTarget,
  type HilosI18nNameRow,
} from '@hilos/core/admin/i18n'
import { HilosActionError } from '../../../HilosActionError.js'
import { HilosEditNotice } from '../../../HilosEditNotice.js'
import { HilosModal } from '../../../HilosModal.js'
import { LoadingButton } from '../../../LoadingButton.js'
import { useLookOnly } from '../../../hilosLookOnly.js'
import { useTrackedAction } from '../../../useTrackedAction.js'

interface NameDeleteProps {
  open: boolean
  onClose: () => void
  context: HilosI18nCountryContext
  target: HilosI18nCountryNameDeleteTarget | null
  source: HilosI18nNameRow | null
}

/** The frozen expected text and action survive their own deletion echo. */
export function HilosI18nCountryNameDelete(props: NameDeleteProps) {
  return props.open && props.target ? (
    <NameDelete {...props} target={props.target} />
  ) : null
}

function NameDelete({
  context,
  target: selected,
  source,
  onClose,
}: NameDeleteProps & { target: HilosI18nCountryNameDeleteTarget }) {
  const [target] = useState(() => ({ ...selected }))
  const copy = HILOS_I18N_COUNTRY_NAME_DELETE_COPY,
    action = useTrackedAction(),
    { locked } = useLookOnly()
  const mounted = useRef(false),
    sending = useRef(false)
  useEffect(() => {
    mounted.current = true
    return () => {
      mounted.current = false
    }
  }, [])
  const missing =
    source?.inLanguageId !== target.inLanguageId ||
    source.name === null ||
    (target.kind === 'base' && source.nameId !== target.nameId) ||
    (target.kind === 'override' &&
      source.corrections.find((item) => item.localeId === target.localeId)
        ?.nameId !== target.nameId)
  function close(): void {
    if (!sending.current) onClose()
  }
  async function confirm(): Promise<void> {
    if (sending.current || locked || missing) return
    sending.current = true
    try {
      const { deleteName } = createHilosI18nCountryNameDelete(context)
      if ((await action.run(deleteName(target))) && mounted.current) onClose()
    } finally {
      sending.current = false
    }
  }
  function value(name: string): ReactNode {
    return name === '' ? (
      <>
        {copy.empty}
        <span className="visually-hidden">. {copy.emptyDescription}</span>
      </>
    ) : (
      name
    )
  }
  return (
    <HilosModal
      open
      title={copy.title(target)}
      onClose={close}
      busy={action.busy}
      initialFocus="dialog"
      actions={({ requestClose }) => (
        <>
          <button
            type="button"
            className="btn btn-secondary"
            disabled={action.busy}
            data-id="i18n-country-name-delete-cancel"
            onClick={requestClose}
          >
            {copy.cancel}
          </button>
          <LoadingButton
            className="btn-danger"
            loading={action.loading}
            disabled={action.busy || missing}
            data-id="i18n-country-name-delete-confirm"
            onClick={() => void confirm()}
          >
            {target.kind === 'base' ? copy.delete : copy.remove}
          </LoadingButton>
        </>
      )}
    >
      <div data-id="i18n-country-name-delete-window">
        <div data-id="i18n-country-name-delete-error">
          <HilosActionError action={action} detailsTitle={copy.refusalTitle} />
        </div>
        <p>
          {target.countryName} ({target.countryCode.toUpperCase()}) ·{' '}
          {target.inLanguageNativeName} ({target.inLanguageCode})
          {target.kind === 'override' && ` · ${target.localeCode}`}
        </p>
        <p>{target.kind === 'base' ? copy.baseBody : copy.overrideBody}</p>
        <div className="py-2 border-top border-bottom my-2 small text-break">
          <div>
            <span className="text-body-secondary me-1">{copy.current}</span>
            <span className="fw-semibold">{value(target.expectedName)}</span>
          </div>
          {target.kind === 'override' && (
            <div>
              <span className="text-body-secondary me-1">{copy.inherited}</span>
              <span className="fw-semibold">
                {value(source?.name ?? copy.missing)}
              </span>
            </div>
          )}
        </div>
        <div
          className={`alert small py-2 mb-0 ${target.kind === 'base' ? 'alert-warning' : 'alert-secondary'}`}
        >
          {target.kind === 'base' ? (
            <>
              <p>{copy.cascade}</p>
              <p className="mb-0">{copy.catalog}</p>
            </>
          ) : (
            copy.overrideNote
          )}
        </div>
        <HilosEditNotice
          kind={missing && !action.busy ? 'deleted' : null}
          text={copy.missing}
          dataId="i18n-country-name-delete-notice"
        />
      </div>
    </HilosModal>
  )
}
