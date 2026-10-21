import { useEffect } from 'react'
import {
  HILOS_I18N_COUNTRY_WINDOW_COPY,
  type HilosI18nCountryWindow as CountryWindow,
} from '@hilos/core/admin/i18n'
import { ConflictActions } from '../../../ConflictActions.js'
import { ConflictHeader } from '../../../ConflictHeader.js'
import { HilosActionError } from '../../../HilosActionError.js'
import { HilosEditNotice } from '../../../HilosEditNotice.js'
import { HilosModal } from '../../../HilosModal.js'
import { LoadingButton } from '../../../LoadingButton.js'
import { useLookOnly } from '../../../hilosLookOnly.js'
import { useSignal } from '../../../useSignal.js'
import { useTrackedAction } from '../../../useTrackedAction.js'
import { HilosI18nCountryFields } from './HilosI18nCountryFields.js'

/** One view over the country card and focused-row edit factories. */
export function HilosI18nCountryWindow({ window }: { window: CountryWindow }) {
  const copy = HILOS_I18N_COUNTRY_WINDOW_COPY
  const mode = useSignal(window.mode),
    title = useSignal(window.title),
    opened = useSignal(window.opened),
    row = useSignal(window.row),
    form = useSignal(window.form),
    state = useSignal(window.state),
    noticeKind = useSignal(window.noticeKind),
    noticeText = useSignal(window.noticeText),
    plate = useSignal(window.plate),
    options = useSignal(window.options),
    localeHint = useSignal(window.localeHint),
    touched = useSignal(window.touched),
    canSave = useSignal(window.canSave),
    saveLabel = useSignal(window.saveLabel),
    saving = useSignal(window.saving)
  const action = useTrackedAction(),
    { locked } = useLookOnly()
  const busy = saving || action.busy,
    { clearError } = action
  useEffect(() => {
    window.start()
    return () => window.dispose()
  }, [window])
  useEffect(() => {
    if (opened) clearError()
  }, [opened, clearError])
  function submit(): void {
    if (!locked && !busy) void window.save(action.run)
  }
  return (
    <HilosModal
      open={opened}
      title={title}
      onClose={() => window.close()}
      confirmOnClose={mode === 'edit' && touched && !busy}
      busy={busy}
      initialFocus="inner"
      header={
        <div data-id="i18n-country-title">
          {mode === 'edit' ? (
            <ConflictHeader title={title} conflict={state.conflict} />
          ) : (
            <h5 className="modal-title mb-0">{title}</h5>
          )}
        </div>
      }
      actions={({ requestClose }) =>
        mode === 'edit' ? (
          <ConflictActions
            conflict={state.conflict}
            disableSave={!canSave || busy}
            saveLabel={saveLabel}
            onSave={submit}
            onAcceptMine={window.keepMine}
            onAcceptTheirs={window.takeTheirs}
            cancelButton={
              <button
                type="button"
                className="btn btn-secondary"
                disabled={busy}
                data-id="i18n-country-cancel"
                onClick={requestClose}
              >
                {copy.cancel}
              </button>
            }
            saveButton={({ disabled, onSave }) => (
              <LoadingButton
                className="btn-primary"
                loading={action.loading}
                disabled={disabled}
                data-id="i18n-country-save"
                onClick={onSave}
              >
                {saveLabel}
              </LoadingButton>
            )}
          />
        ) : (
          <button
            type="button"
            className="btn btn-secondary"
            data-id="i18n-country-close"
            onClick={requestClose}
          >
            {copy.close}
          </button>
        )
      }
    >
      <div data-id="i18n-country-window">
        <form
          id="i18n-country-edit-form"
          onSubmit={(event) => {
            event.preventDefault()
            submit()
          }}
        >
          <HilosI18nCountryFields
            idPrefix="i18n-country-edit"
            mode={mode}
            code={row?.code ?? ''}
            currencySymbol={form.currencySymbol}
            currencyCode={form.currencyCode}
            defaultLocaleId={form.defaultLocaleId}
            localeOptions={options}
            localeHint={localeHint}
            describedBy="i18n-country-plate"
            busy={busy}
            onCurrencySymbol={window.setSymbol}
            onCurrencyCode={window.setCurrencyCode}
            onDefaultLocale={window.setDefaultLocale}
          />
        </form>
        <div className="mt-3">
          <HilosEditNotice
            kind={noticeKind}
            text={noticeText}
            dataId="i18n-country-notice"
          />
        </div>
        {mode === 'edit' && (
          <div data-id="i18n-country-error">
            <HilosActionError
              action={action}
              detailsTitle={copy.refusalTitle}
            />
          </div>
        )}
        <div className="hilos-stack">
          {mode === 'edit' && (
            <div className="hilos-stack invisible" aria-hidden="true" inert>
              {Object.values(copy.plates).map((variant) => (
                <div
                  key={variant.rest}
                  className="alert small py-2 mb-0 d-flex gap-2 alert-info"
                >
                  <i
                    className="bi bi-pencil flex-shrink-0 mt-1"
                    aria-hidden="true"
                  />
                  <div>
                    <strong>{variant.lead}</strong> {variant.rest}
                  </div>
                </div>
              ))}
            </div>
          )}
          <div
            id="i18n-country-plate"
            className={`alert small py-2 mb-0 d-flex gap-2 ${plate.tone}`}
            data-id="i18n-country-plate"
          >
            <i
              className={`bi flex-shrink-0 mt-1 ${plate.icon}`}
              aria-hidden="true"
            />
            <div>
              <strong>{plate.lead}</strong> {plate.rest}
            </div>
          </div>
        </div>
      </div>
    </HilosModal>
  )
}
