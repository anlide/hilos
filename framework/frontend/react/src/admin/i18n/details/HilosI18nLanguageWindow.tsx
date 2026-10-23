import { useEffect } from 'react'
import {
  HILOS_I18N_LANGUAGE_NATIVE_NAME_MAX,
  HILOS_I18N_LANGUAGE_WINDOW_COPY,
  type HilosI18nLanguageWindow as LanguageWindow,
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

/** One edit/view window over the core's card or focused table-row session. */
export function HilosI18nLanguageWindow({
  window,
}: {
  window: LanguageWindow
}) {
  const copy = HILOS_I18N_LANGUAGE_WINDOW_COPY
  const opened = useSignal(window.opened),
    mode = useSignal(window.mode),
    row = useSignal(window.row),
    live = useSignal(window.live),
    form = useSignal(window.form),
    state = useSignal(window.state),
    frozen = useSignal(window.frozen),
    plate = useSignal(window.plate),
    notice = useSignal(window.notice),
    saveAvailable = useSignal(window.saveAvailable),
    saveLabel = useSignal(window.saveLabel)
  const action = useTrackedAction()
  const { locked } = useLookOnly()
  const { clearError } = action
  useEffect(() => {
    window.start()
    return () => window.dispose()
  }, [window])
  useEffect(() => {
    if (mode !== null) clearError()
  }, [mode, clearError])
  const title = copy.title[mode ?? 'view'](row?.nativeName ?? '')
  const shown = mode === 'view' ? (live ?? row ?? form) : form
  const disabled = frozen || state.gone || action.busy
  function close(): void {
    if (!action.busy) window.close()
  }
  function submit(): void {
    if (mode === 'edit' && !locked) void window.save(action.run)
  }
  return (
    <HilosModal
      open={opened}
      title={title}
      onClose={close}
      confirmOnClose={mode === 'edit' && state.dirty}
      busy={action.busy}
      initialFocus="dialog"
      header={
        <div data-id="i18n-language-edit-title">
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
            disableSave={!saveAvailable}
            saveLabel={saveLabel}
            onSave={submit}
            onAcceptMine={window.keepMine}
            onAcceptTheirs={window.takeTheirs}
            cancelButton={
              <button
                type="button"
                className="btn btn-secondary"
                disabled={action.busy}
                data-id="i18n-language-edit-cancel"
                onClick={requestClose}
              >
                {copy.cancel}
              </button>
            }
            saveButton={({ disabled: saveDisabled, onSave }) => (
              <LoadingButton
                className="btn-primary"
                loading={action.busy}
                disabled={saveDisabled}
                data-id="i18n-language-edit-submit"
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
            data-id="i18n-language-edit-close"
            onClick={requestClose}
          >
            {copy.close}
          </button>
        )
      }
    >
      <div data-id="i18n-language-edit-window">
        <form
          onSubmit={(event) => {
            event.preventDefault()
            submit()
          }}
        >
          <div className="row g-2">
            <div className="col-4">
              <label
                className="form-label small fw-semibold"
                htmlFor="i18n-language-edit-code"
              >
                {copy.labels.code}
              </label>
              <input
                id="i18n-language-edit-code"
                className="form-control form-control-sm"
                value={row?.code ?? ''}
                disabled
                aria-describedby="i18n-language-code-hint"
                data-id="i18n-language-edit-code"
              />
              <div id="i18n-language-code-hint" className="form-text">
                <i className="bi bi-database-check me-1" aria-hidden="true" />
                {copy.hints.code}
              </div>
            </div>
            <div className="col-8">
              <label
                className="form-label small fw-semibold"
                htmlFor="i18n-language-edit-native-name"
              >
                {copy.labels.nativeName}
              </label>
              <input
                id="i18n-language-edit-native-name"
                className="form-control form-control-sm"
                value={shown.nativeName}
                maxLength={HILOS_I18N_LANGUAGE_NATIVE_NAME_MAX}
                disabled={disabled}
                aria-describedby="i18n-language-name-hint"
                data-id="i18n-language-edit-native-name"
                onChange={(event) =>
                  window.patchForm({ nativeName: event.target.value })
                }
              />
              <div id="i18n-language-name-hint" className="form-text">
                {copy.hints.nativeName[mode === 'view' ? 'view' : 'edit']}
              </div>
            </div>
          </div>
          <div className="form-check mt-2">
            <input
              id="i18n-language-edit-rtl"
              className="form-check-input"
              type="checkbox"
              checked={shown.rtl}
              disabled={disabled}
              aria-describedby={
                mode === 'view' ? 'i18n-language-rtl-hint' : undefined
              }
              data-id="i18n-language-edit-rtl"
              onChange={(event) =>
                window.patchForm({ rtl: event.target.checked })
              }
            />
            <label
              className="form-check-label small"
              htmlFor="i18n-language-edit-rtl"
            >
              {copy.labels.rtl}
            </label>
          </div>
          {mode === 'view' && (
            <div id="i18n-language-rtl-hint" className="form-text">
              {copy.hints.rtl}
            </div>
          )}
        </form>
        <div data-id="i18n-language-edit-error">
          <HilosActionError action={action} detailsTitle={copy.refusalTitle} />
        </div>
        <HilosEditNotice
          kind={notice?.kind ?? null}
          text={notice?.text ?? ''}
          dataId="i18n-language-edit-notice"
        />
        <div
          className={`alert small py-2 mb-0 d-flex gap-2 ${plate === 'on' ? 'alert-warning' : 'alert-info'}`}
          data-kind={plate}
          data-id="i18n-language-edit-plate"
        >
          <i
            className={`bi flex-shrink-0 mt-1 ${plate === 'on' ? 'bi-lock' : 'bi-pencil'}`}
            aria-hidden="true"
          />
          <div>
            <p className="mb-0">
              <strong>{copy.plate[plate].lead}</strong> {copy.plate[plate].text}
            </p>
            {plate !== 'on' && <p className="mt-2 mb-0">{copy.plate.code}</p>}
          </div>
        </div>
      </div>
    </HilosModal>
  )
}
