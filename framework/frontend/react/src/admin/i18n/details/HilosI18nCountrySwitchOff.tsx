import { useEffect, useMemo, useRef, useState } from 'react'
import {
  HILOS_I18N_COUNTRY_SWITCH_OFF_COPY,
  createHilosI18nCountrySwitchOff,
  hilosI18nCountryLabel,
  type HilosI18nCountryCard,
  type HilosI18nCountryContext,
} from '@hilos/core/admin/i18n'
import { HilosActionError } from '../../../HilosActionError.js'
import { HilosEditNotice } from '../../../HilosEditNotice.js'
import { HilosModal } from '../../../HilosModal.js'
import { LoadingButton } from '../../../LoadingButton.js'
import { useLookOnly } from '../../../hilosLookOnly.js'
import { useTrackedAction } from '../../../useTrackedAction.js'

/** The live country switch-off verdict and its tracked confirmation. */
export function HilosI18nCountrySwitchOff({
  context,
  card,
}: {
  context: HilosI18nCountryContext
  card: HilosI18nCountryCard | null
}) {
  const copy = HILOS_I18N_COUNTRY_SWITCH_OFF_COPY
  const command = useMemo(
    () => createHilosI18nCountrySwitchOff(context),
    [context],
  )
  const action = useTrackedAction()
  const { locked } = useLookOnly()
  const [target, setTarget] = useState<{
    countryId: number
    name: string
  } | null>(null)
  const mounted = useRef(false),
    sending = useRef(false)
  useEffect(() => {
    mounted.current = true
    return () => {
      mounted.current = false
    }
  }, [])
  useEffect(() => {
    if (card === null || (target && card.countryId !== target.countryId))
      setTarget(null)
  }, [card, target])
  const elsewhere = target !== null && !action.busy && card?.enabled === false
  function open(): void {
    if (!card) return
    action.clearError()
    setTarget({ countryId: card.countryId, name: hilosI18nCountryLabel(card) })
  }
  function close(): void {
    if (!sending.current) setTarget(null)
  }
  async function confirm(): Promise<void> {
    if (sending.current || locked || elsewhere || !card || !target) return
    sending.current = true
    try {
      if (
        (await action.run(command.switchOff(target.countryId))) &&
        mounted.current
      )
        setTarget(null)
    } finally {
      sending.current = false
    }
  }
  return (
    <>
      {card?.enabled === true && (
        <button
          type="button"
          className="btn btn-sm btn-outline-danger"
          data-id="country-card-switch-off"
          onClick={open}
        >
          {copy.open}
        </button>
      )}
      <HilosModal
        open={target !== null}
        title={copy.title(target?.name ?? '')}
        onClose={close}
        busy={action.busy}
        initialFocus="dialog"
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={action.busy}
              data-id="country-card-switch-off-cancel"
              onClick={requestClose}
            >
              {copy.cancel}
            </button>
            <LoadingButton
              className="btn-danger"
              loading={action.busy}
              disabled={elsewhere}
              data-id="country-card-switch-off-confirm"
              onClick={() => void confirm()}
            >
              {copy.confirm}
            </LoadingButton>
          </>
        )}
      >
        <div data-id="country-card-switch-off-error">
          <HilosActionError action={action} detailsTitle={copy.refusalTitle} />
        </div>
        <HilosEditNotice
          kind={elsewhere ? 'updated' : null}
          text={copy.elsewhere}
          dataId="country-card-switch-off-notice"
        />
        <p>{copy.body}</p>
        {card && (
          <div
            className="py-2 border-top border-bottom my-2 small"
            data-id="country-card-switch-off-counts"
          >
            <span className="text-body-secondary">{copy.localeCount}</span>{' '}
            <span
              className="fw-semibold"
              data-id="country-card-switch-off-locale-count"
            >
              {card.summary.localeCount}
            </span>
            <span className="text-body-secondary ms-2">{copy.nameCount}</span>{' '}
            <span
              className="fw-semibold"
              data-id="country-card-switch-off-name-count"
            >
              {card.summary.nameCount}
            </span>
          </div>
        )}
        <div className="alert alert-secondary small py-2 mb-0">{copy.note}</div>
      </HilosModal>
    </>
  )
}
