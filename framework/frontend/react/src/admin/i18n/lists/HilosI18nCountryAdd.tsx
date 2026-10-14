import { useEffect } from 'react'
import {
  HILOS_I18N_COUNTRY_ADD_COPY,
  type HilosI18nCountryAdd as CountryAdd,
} from '@hilos/core'
import { HilosActionError } from '../../../HilosActionError.js'
import { HilosModal } from '../../../HilosModal.js'
import { LoadingButton } from '../../../LoadingButton.js'
import { useLookOnly } from '../../../hilosLookOnly.js'
import { useSignal } from '../../../useSignal.js'
import { useTrackedAction } from '../../../useTrackedAction.js'
import { HilosI18nCountryFields } from '../details/HilosI18nCountryFields.js'

/** The catalog-aware creation form and responsibility confirmation. */
export function HilosI18nCountryAdd({ add }: { add: CountryAdd }) {
  const copy = HILOS_I18N_COUNTRY_ADD_COPY
  const step = useSignal(add.step),
    form = useSignal(add.form),
    kind = useSignal(add.kind),
    catalogSize = useSignal(add.catalogSize),
    canSubmit = useSignal(add.canSubmit),
    saving = useSignal(add.saving)
  const formAction = useTrackedAction(),
    confirmAction = useTrackedAction(),
    { locked } = useLookOnly()
  const { clearError: clearFormError } = formAction,
    { clearError: clearConfirmError } = confirmAction
  const dirty =
    form.code !== '' || form.currencySymbol !== '' || form.currencyCode !== ''
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
        confirmOnClose={dirty && !saving}
        initialFocus="inner"
        closeOnBackdrop={!saving}
        closeOnEsc={!saving}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={saving}
              data-id="i18n-country-add-cancel"
              onClick={requestClose}
            >
              {copy.cancel}
            </button>
            <LoadingButton
              className="btn-primary"
              type="submit"
              form="i18n-country-add-form"
              loading={formAction.loading}
              disabled={!canSubmit}
              data-id="i18n-country-add-submit"
            >
              {copy.submit}
            </LoadingButton>
          </>
        )}
      >
        <form
          id="i18n-country-add-form"
          onSubmit={(event) => {
            event.preventDefault()
            submit()
          }}
        >
          <HilosI18nCountryFields
            idPrefix="i18n-country-add"
            mode="add"
            code={form.code}
            currencySymbol={form.currencySymbol}
            currencyCode={form.currencyCode}
            busy={saving}
            onCode={add.setCode}
            onCurrencySymbol={add.setCurrencySymbol}
            onCurrencyCode={add.setCurrencyCode}
          />
        </form>
        <div className="mt-3" data-id="i18n-country-add-error">
          <HilosActionError
            action={formAction}
            detailsTitle={copy.refusalTitle}
          />
        </div>
        <div className="hilos-stack">
          <div className="hilos-stack invisible" aria-hidden="true" inert>
            {[
              { lead: copy.knownLead, rest: copy.knownRest },
              { lead: copy.ownLead, rest: copy.ownRest },
            ].map((variant) => (
              <div
                key={variant.lead}
                className="alert small py-2 mb-0 d-flex gap-2"
              >
                <i
                  className="bi bi-question-circle flex-shrink-0 mt-1"
                  aria-hidden="true"
                />
                <div>
                  <strong>{variant.lead}</strong> {variant.rest}
                </div>
              </div>
            ))}
          </div>
          {kind && (
            <div
              className={`alert small py-2 mb-0 d-flex gap-2 ${kind === 'known' ? 'alert-success' : 'alert-warning'}`}
              data-kind={kind}
              data-id="i18n-country-add-plate"
            >
              <i
                className={`bi flex-shrink-0 mt-1 ${kind === 'known' ? 'bi-database-check' : 'bi-question-circle'}`}
                aria-hidden="true"
              />
              <div>
                <strong>
                  {kind === 'known' ? copy.knownLead : copy.ownLead}
                </strong>{' '}
                {kind === 'known' ? copy.knownRest : copy.ownRest}
              </div>
            </div>
          )}
        </div>
      </HilosModal>
      <HilosModal
        open={step === 'confirm'}
        title={copy.confirmTitle}
        onClose={add.close}
        closeOnBackdrop={!saving}
        closeOnEsc={!saving}
        initialFocus="dialog"
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={saving}
              data-id="i18n-country-confirm-cancel"
              onClick={requestClose}
            >
              {copy.cancel}
            </button>
            <LoadingButton
              className="btn-warning"
              loading={confirmAction.loading}
              disabled={!canSubmit}
              data-id="i18n-country-confirm-submit"
              onClick={confirm}
            >
              {copy.confirmSubmit}
            </LoadingButton>
          </>
        )}
      >
        <div
          className="d-flex align-items-center gap-3 mb-3"
          data-id="i18n-country-confirm"
        >
          <span
            className="rounded-3 bg-warning-subtle text-warning-emphasis d-inline-flex align-items-center justify-content-center fs-4 px-3 py-2 text-uppercase"
            data-id="i18n-country-confirm-code"
          >
            {form.code}
          </span>
          <div>
            <div className="fw-semibold">
              {form.currencySymbol} {form.currencyCode}
            </div>
            <div className="small text-body-secondary">{copy.entered}</div>
          </div>
        </div>
        <p>{copy.catalogLine(form.code, catalogSize ?? 0)}</p>
        <ul className="mb-2 ps-3">
          {copy.consequences.map((item) => (
            <li key={item}>{item}</li>
          ))}
        </ul>
        <div data-id="i18n-country-confirm-error">
          <HilosActionError
            action={confirmAction}
            detailsTitle={copy.refusalTitle}
          />
        </div>
        <div
          className="alert alert-secondary small py-2 mb-0"
          data-id="i18n-country-confirm-note"
        >
          {copy.confirmNote}
        </div>
      </HilosModal>
    </>
  )
}
