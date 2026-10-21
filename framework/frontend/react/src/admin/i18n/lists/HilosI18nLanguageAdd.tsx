import { useEffect, useMemo } from 'react'
import {
  HILOS_I18N_LANGUAGE_ADD_COPY,
  createHilosI18nBuiltInLanguages,
  type HilosI18nLanguageAdd as LanguageAdd,
  type HilosI18nLanguageContext,
} from '@hilos/core/admin/i18n'
import { HilosActionError } from '../../../HilosActionError.js'
import { HilosModal } from '../../../HilosModal.js'
import { LoadingButton } from '../../../LoadingButton.js'
import { useLookOnly } from '../../../hilosLookOnly.js'
import { useSignal } from '../../../useSignal.js'
import { useTrackedAction } from '../../../useTrackedAction.js'

/** The catalog-backed creation form and the core's custom-language confirmation. */
export function HilosI18nLanguageAdd({
  add,
  context,
}: {
  add: LanguageAdd
  context: HilosI18nLanguageContext
}) {
  const copy = HILOS_I18N_LANGUAGE_ADD_COPY
  const step = useSignal(add.step),
    form = useSignal(add.form),
    known = useSignal(add.known),
    complete = useSignal(add.complete),
    canSubmit = useSignal(add.canSubmit)
  const catalogSignal = useMemo(
    () => createHilosI18nBuiltInLanguages(context),
    [context],
  )
  const catalog = useSignal(catalogSignal)
  const formAction = useTrackedAction(),
    confirmAction = useTrackedAction()
  const { clearError: clearFormError } = formAction,
    { clearError: clearConfirmError } = confirmAction
  const { locked } = useLookOnly()
  useEffect(() => {
    if (step === 'form') clearFormError()
    if (step === 'confirm') clearConfirmError()
  }, [step, clearFormError, clearConfirmError])
  function submit(): void {
    if (!locked) void add.submit(formAction.run)
  }
  function confirm(): void {
    if (!locked) void add.confirm(confirmAction.run)
  }
  return (
    <>
      <HilosModal
        open={step === 'form'}
        title={copy.title}
        onClose={add.close}
        busy={formAction.busy}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={formAction.busy}
              data-id="i18n-language-add-cancel"
              onClick={requestClose}
            >
              {copy.cancel}
            </button>
            <LoadingButton
              className="btn-primary"
              type="submit"
              form="i18n-language-add-form"
              loading={formAction.loading}
              disabled={!canSubmit || formAction.busy}
              data-id="i18n-language-add-submit"
            >
              {copy.submit}
            </LoadingButton>
          </>
        )}
      >
        <form
          id="i18n-language-add-form"
          onSubmit={(event) => {
            event.preventDefault()
            submit()
          }}
        >
          <div className="row g-2">
            <div className="col-12 col-sm-4">
              <label
                className="form-label small fw-semibold"
                htmlFor="i18n-language-add-code"
              >
                {copy.codeLabel}
              </label>
              <input
                id="i18n-language-add-code"
                className="form-control form-control-sm"
                value={form.code}
                placeholder={copy.codePlaceholder}
                disabled={formAction.busy}
                maxLength={2}
                aria-describedby="i18n-language-add-code-hint"
                data-id="i18n-language-add-code"
                data-autofocus
                onChange={(event) => add.setCode(event.target.value)}
              />
              <div id="i18n-language-add-code-hint" className="form-text">
                {copy.codeHint}
              </div>
            </div>
            <div className="col-12 col-sm-8">
              <label
                className="form-label small fw-semibold"
                htmlFor="i18n-language-add-native-name"
              >
                {copy.nativeNameLabel}
              </label>
              <input
                id="i18n-language-add-native-name"
                className="form-control form-control-sm"
                value={form.nativeName}
                placeholder={copy.nativeNamePlaceholder}
                disabled={formAction.busy}
                maxLength={64}
                aria-describedby="i18n-language-add-native-name-hint"
                data-id="i18n-language-add-native-name"
                onChange={(event) => add.setNativeName(event.target.value)}
              />
              <div
                id="i18n-language-add-native-name-hint"
                className="form-text"
              >
                {known ? copy.nativeNameKnownHint : copy.nativeNameOwnHint}
              </div>
            </div>
          </div>
          <div className="form-check mt-2">
            <input
              id="i18n-language-add-rtl"
              className="form-check-input"
              type="checkbox"
              checked={form.rtl}
              disabled={formAction.busy}
              data-id="i18n-language-add-rtl"
              onChange={(event) => add.setRtl(event.target.checked)}
            />
            <label
              className="form-check-label small"
              htmlFor="i18n-language-add-rtl"
            >
              {copy.rtlLabel}
            </label>
          </div>
        </form>
        <div className="mt-3" data-id="i18n-language-add-error">
          <HilosActionError
            action={formAction}
            detailsTitle={copy.refusalTitle}
          />
        </div>
        {complete && (
          <div
            className={`alert small py-2 mb-0 d-flex gap-2 ${known ? 'alert-success' : 'alert-warning'}`}
            data-kind={known ? 'known' : 'own'}
            data-id="i18n-language-add-plate"
          >
            <i
              className={`bi flex-shrink-0 mt-1 ${known ? 'bi-database-check' : 'bi-question-circle'}`}
              aria-hidden="true"
            />
            <div>
              <strong>{known ? copy.knownLead : copy.ownLead}</strong>{' '}
              {known ? copy.knownRest : copy.ownRest}
            </div>
          </div>
        )}
      </HilosModal>
      <HilosModal
        open={step === 'confirm'}
        title={copy.confirmTitle}
        onClose={add.close}
        busy={confirmAction.busy}
        initialFocus="dialog"
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={confirmAction.busy}
              data-id="i18n-language-confirm-cancel"
              onClick={requestClose}
            >
              {copy.cancel}
            </button>
            <LoadingButton
              className="btn-warning"
              loading={confirmAction.loading}
              disabled={!canSubmit || confirmAction.busy}
              data-id="i18n-language-confirm-submit"
              onClick={confirm}
            >
              {copy.confirmSubmit}
            </LoadingButton>
          </>
        )}
      >
        <div data-id="i18n-language-confirm">
          <div className="d-flex align-items-center gap-3 mb-3">
            <span
              className="rounded-3 bg-warning-subtle text-warning-emphasis d-inline-flex align-items-center justify-content-center fw-semibold text-uppercase p-3"
              data-id="i18n-language-confirm-code"
            >
              {form.code}
            </span>
            <div>
              <div className="fw-semibold">{form.nativeName.trim()}</div>
              <div className="small text-body-secondary">
                {copy.direction(form.rtl)}
              </div>
            </div>
          </div>
          <p>{copy.catalogLine(form.code, catalog?.length ?? 0)}</p>
          <ul className="mb-2 ps-3">
            {copy.consequences.map((consequence) => (
              <li key={consequence}>{consequence}</li>
            ))}
          </ul>
          <div data-id="i18n-language-confirm-error">
            <HilosActionError
              action={confirmAction}
              detailsTitle={copy.refusalTitle}
            />
          </div>
          <div className="alert alert-secondary small py-2 mb-0">
            {copy.confirmNote}
          </div>
        </div>
      </HilosModal>
    </>
  )
}
