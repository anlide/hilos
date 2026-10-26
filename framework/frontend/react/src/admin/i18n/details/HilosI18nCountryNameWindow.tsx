import { useEffect, useState } from 'react'
import {
  HILOS_I18N_COUNTRY_NAME_COPY,
  HILOS_I18N_COUNTRY_NAME_MAX,
  type HilosI18nCountryNameWindow as NameWindow,
  type HilosI18nCountryNameTarget,
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

/** The core owns both creation and the ordinary name-edit session. */
export function HilosI18nCountryNameWindow({ window }: { window: NameWindow }) {
  const opened = useSignal(window.opened),
    target = useSignal(window.target)
  const [binding, setBinding] = useState({ target, revision: 0 })
  if (binding.target !== target)
    setBinding({ target, revision: binding.revision + 1 })
  useEffect(() => {
    window.start()
    return () => window.dispose()
  }, [window])
  return opened && target ? (
    <NameForm key={binding.revision} window={window} target={target} />
  ) : null
}

/** Each mounted opening has its own runner, so a former opening's reply stays there. */
function NameForm({
  window,
  target,
}: {
  window: NameWindow
  target: HilosI18nCountryNameTarget
}) {
  const copy = HILOS_I18N_COUNTRY_NAME_COPY
  const form = useSignal(window.form),
    state = useSignal(window.state),
    dirty = useSignal(window.dirty),
    noticeKind = useSignal(window.noticeKind),
    noticeText = useSignal(window.noticeText),
    saving = useSignal(window.saving),
    canSave = useSignal(window.canSave),
    saveAvailable = useSignal(window.saveAvailable),
    saveLabel = useSignal(window.saveLabel)
  const action = useTrackedAction(),
    { locked } = useLookOnly()
  const titleKey =
    target.localeCode === null
      ? target.mode
      : target.mode === 'add'
        ? 'addCorrection'
        : 'editCorrection'
  const title = `${copy.title[titleKey]} · ${target.countryName}`
  const hint =
    target.localeCode === null ? copy.hints.base : copy.hints.correction
  function submit(): void {
    if (!locked && canSave) void window.save(action.run)
  }
  return (
    <HilosModal
      open
      title={title}
      onClose={() => window.close()}
      confirmOnClose={dirty && !saving}
      busy={saving}
      header={
        <div data-id="i18n-country-name-title">
          <ConflictHeader title={title} conflict={state.conflict} />
        </div>
      }
      actions={({ requestClose }) => (
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
              disabled={saving}
              data-id="i18n-country-name-cancel"
              onClick={requestClose}
            >
              {copy.cancel}
            </button>
          }
          saveButton={({ disabled, onSave }) => (
            <LoadingButton
              className="btn-primary"
              loading={action.busy}
              disabled={disabled}
              data-id="i18n-country-name-submit"
              onClick={onSave}
            >
              {saveLabel}
            </LoadingButton>
          )}
        />
      )}
    >
      <div data-id="i18n-country-name-window">
        <dl className="row small mb-3">
          <dt className="col-sm-4">{copy.labels.subject}</dt>
          <dd className="col-sm-8">
            {target.countryName} ({target.countryCode.toUpperCase()})
          </dd>
          <dt className="col-sm-4">{copy.labels.inLanguage}</dt>
          <dd className="col-sm-8">
            {target.inLanguageNativeName} ({target.inLanguageCode})
          </dd>
          {target.localeCode !== null && (
            <>
              <dt className="col-sm-4">{copy.labels.locale}</dt>
              <dd className="col-sm-8">{target.localeCode}</dd>
            </>
          )}
        </dl>
        <form
          onSubmit={(event) => {
            event.preventDefault()
            submit()
          }}
        >
          <label className="form-label" htmlFor="i18n-country-name-input">
            {copy.labels.name}
          </label>
          <input
            id="i18n-country-name-input"
            className="form-control"
            value={form.name}
            maxLength={HILOS_I18N_COUNTRY_NAME_MAX}
            disabled={saving}
            aria-describedby="i18n-country-name-hint"
            data-id="i18n-country-name-input"
            data-autofocus
            onChange={(event) => window.patchForm({ name: event.target.value })}
          />
          <div id="i18n-country-name-hint" className="form-text">
            {hint}
          </div>
        </form>
        {target.localeCode === null && (
          <p className="small mt-3 mb-0">{copy.hints.catalog}</p>
        )}
        <div data-id="i18n-country-name-error">
          <HilosActionError action={action} detailsTitle={copy.refusalTitle} />
        </div>
        <HilosEditNotice
          kind={noticeKind}
          text={noticeText}
          dataId="i18n-country-name-notice"
        />
      </div>
    </HilosModal>
  )
}
