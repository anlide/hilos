import { useEffect, useMemo, useRef, useState } from 'react'
import {
  HILOS_I18N_LANGUAGE_SWITCH_ON_COPY,
  createHilosI18nLanguageSwitchOn,
  hilosI18nLanguageSwitchOn,
  type HilosI18nLanguageCard,
  type HilosI18nLanguageContext,
} from '@hilos/core'
import { HilosActionError } from '../../../HilosActionError.js'
import { HilosEditNotice } from '../../../HilosEditNotice.js'
import { HilosModal } from '../../../HilosModal.js'
import { LoadingButton } from '../../../LoadingButton.js'
import { useLookOnly } from '../../../hilosLookOnly.js'
import { useTrackedAction } from '../../../useTrackedAction.js'

/** The live language switch-on verdict and its tracked confirmation. */
export function HilosI18nLanguageSwitchOn({
  context,
  card,
}: {
  context: HilosI18nLanguageContext
  card: HilosI18nLanguageCard | null
}) {
  const copy = HILOS_I18N_LANGUAGE_SWITCH_ON_COPY
  const verdict = hilosI18nLanguageSwitchOn(card)
  const command = useMemo(
    () => createHilosI18nLanguageSwitchOn(context),
    [context],
  )
  const action = useTrackedAction()
  const { locked } = useLookOnly()
  const [target, setTarget] = useState<{ code: string; name: string } | null>(
    null,
  )
  const mounted = useRef(false),
    sending = useRef(false)
  useEffect(() => {
    mounted.current = true
    return () => {
      mounted.current = false
    }
  }, [])
  useEffect(() => {
    if (card === null || (target && card.code !== target.code)) setTarget(null)
  }, [card, target])
  const elsewhere = target !== null && !action.busy && card?.enabled === true
  function open(): void {
    if (!card) return
    action.clearError()
    setTarget({ code: card.code, name: card.nativeName })
  }
  function close(): void {
    if (!sending.current) setTarget(null)
  }
  async function confirm(): Promise<void> {
    if (sending.current || locked || elsewhere || !card || !target) return
    sending.current = true
    try {
      if ((await action.run(command.switchOn(target.code))) && mounted.current)
        setTarget(null)
    } finally {
      sending.current = false
    }
  }
  return (
    <>
      {verdict.shown && (
        <>
          <button
            type="button"
            className="btn btn-sm btn-outline-primary"
            data-id="language-card-switch-on"
            onClick={open}
          >
            {copy.open}
          </button>
        </>
      )}
      <HilosModal
        open={target !== null}
        title={copy.title(target?.name ?? '')}
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
              data-id="language-card-switch-on-cancel"
              onClick={requestClose}
            >
              {copy.cancel}
            </button>
            <LoadingButton
              className="btn-primary"
              loading={action.loading}
              disabled={action.busy || elsewhere}
              data-id="language-card-switch-on-confirm"
              onClick={() => void confirm()}
            >
              {copy.confirm}
            </LoadingButton>
          </>
        )}
      >
        <div data-id="language-card-switch-on-error">
          <HilosActionError action={action} detailsTitle={copy.refusalTitle} />
        </div>
        <HilosEditNotice
          kind={elsewhere ? 'updated' : null}
          text={copy.elsewhere}
          dataId="language-card-switch-on-notice"
        />
        <p>{copy.body}</p>
        <div className="d-flex flex-wrap gap-3 py-2 border-top border-bottom my-2 small">
          <div>
            <span className="text-body-secondary">{copy.localeLabel}</span>{' '}
            {verdict.locale !== 'on' ? (
              <span
                className="badge text-bg-warning-subtle text-warning-emphasis"
                data-id="language-card-switch-on-locale"
              >
                <i
                  className="bi bi-exclamation-triangle me-1"
                  aria-hidden="true"
                />
                {copy.locale[verdict.locale]}
              </span>
            ) : (
              <span data-id="language-card-switch-on-locale">
                {copy.locale.on}
              </span>
            )}
          </div>
          <div>
            <span className="text-body-secondary">{copy.namesLabel}</span>{' '}
            <span
              className="fw-semibold"
              data-id="language-card-switch-on-names"
            >
              {verdict.nameCount}
            </span>
          </div>
        </div>
        {verdict.warning !== null && (
          <div
            className="alert alert-warning small py-2 mb-0"
            data-id="language-card-switch-on-warning"
          >
            {verdict.warning}
          </div>
        )}
      </HilosModal>
    </>
  )
}
