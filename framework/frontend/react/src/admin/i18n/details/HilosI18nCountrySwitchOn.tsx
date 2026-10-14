import { useEffect, useMemo, useRef, useState } from 'react'
import {
  HILOS_I18N_COUNTRY_SWITCH_ON_COPY,
  createHilosI18nCountrySwitchOn,
  hilosI18nCountrySwitchOn,
  hilosI18nCountryLabel,
  type HilosI18nCountryCard,
  type HilosI18nCountryContext,
} from '@hilos/core'
import { HilosActionError } from '../../../HilosActionError.js'
import { HilosEditNotice } from '../../../HilosEditNotice.js'
import { HilosModal } from '../../../HilosModal.js'
import { LoadingButton } from '../../../LoadingButton.js'
import { useLookOnly } from '../../../hilosLookOnly.js'
import { useTrackedAction } from '../../../useTrackedAction.js'

/** The live country switch-on verdict and its tracked confirmation. */
export function HilosI18nCountrySwitchOn({
  context,
  card,
}: {
  context: HilosI18nCountryContext
  card: HilosI18nCountryCard | null
}) {
  const copy = HILOS_I18N_COUNTRY_SWITCH_ON_COPY
  const verdict = hilosI18nCountrySwitchOn(card)
  const command = useMemo(
    () => createHilosI18nCountrySwitchOn(context),
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
    setTarget({ code: card.code, name: hilosI18nCountryLabel(card) })
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
            data-id="country-card-switch-on"
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
              data-id="country-card-switch-on-cancel"
              onClick={requestClose}
            >
              {copy.cancel}
            </button>
            <LoadingButton
              className="btn-primary"
              loading={action.loading}
              disabled={action.busy || elsewhere}
              data-id="country-card-switch-on-confirm"
              onClick={() => void confirm()}
            >
              {copy.confirm}
            </LoadingButton>
          </>
        )}
      >
        <div data-id="country-card-switch-on-error">
          <HilosActionError action={action} detailsTitle={copy.refusalTitle} />
        </div>
        <HilosEditNotice
          kind={elsewhere ? 'updated' : null}
          text={copy.elsewhere}
          dataId="country-card-switch-on-notice"
        />
        <p>{copy.body}</p>
        <div className="hilos-stack">
          <div className="hilos-stack invisible" aria-hidden="true" inert>
            {[
              copy.note.missing,
              copy.note.off(card?.summary.defaultLocaleCode ?? ''),
            ].map((note) => (
              <div key={note} className="alert small py-2 mb-0 alert-secondary">
                {note}
              </div>
            ))}
          </div>
          {verdict.note !== null && (
            <div
              className={`alert small py-2 mb-0 ${verdict.defaultLocale === 'off' ? 'alert-warning' : 'alert-secondary'}`}
              data-id="country-card-switch-on-note"
            >
              {verdict.note}
            </div>
          )}
        </div>
      </HilosModal>
    </>
  )
}
