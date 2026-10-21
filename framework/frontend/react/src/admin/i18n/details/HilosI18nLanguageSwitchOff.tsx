import { useEffect, useMemo, useRef, useState } from 'react'
import {
  HILOS_I18N_LANGUAGE_SWITCH_OFF_COPY,
  createHilosI18nLanguageSwitchOff,
  hilosI18nLanguageSwitchOff,
  type HilosI18nLanguageCard,
  type HilosI18nLanguageContext,
} from '@hilos/core/admin/i18n'
import { HilosActionError } from '../../../HilosActionError.js'
import { HilosEditNotice } from '../../../HilosEditNotice.js'
import { HilosModal } from '../../../HilosModal.js'
import { LoadingButton } from '../../../LoadingButton.js'
import { useLookOnly } from '../../../hilosLookOnly.js'
import { useTrackedAction } from '../../../useTrackedAction.js'

/** The live language switch-off verdict and its tracked confirmation. */
export function HilosI18nLanguageSwitchOff({
  context,
  card,
}: {
  context: HilosI18nLanguageContext
  card: HilosI18nLanguageCard | null
}) {
  const copy = HILOS_I18N_LANGUAGE_SWITCH_OFF_COPY
  const verdict = hilosI18nLanguageSwitchOff(card)
  const command = useMemo(
    () => createHilosI18nLanguageSwitchOff(context),
    [context],
  )
  const action = useTrackedAction()
  const { locked } = useLookOnly()
  const [target, setTarget] = useState<{
    languageId: number
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
    if (card === null || (target && card.languageId !== target.languageId))
      setTarget(null)
  }, [card, target])
  const elsewhere = target !== null && !action.busy && card?.enabled === false
  function open(): void {
    if (!card) return
    action.clearError()
    setTarget({ languageId: card.languageId, name: card.nativeName })
  }
  function close(): void {
    if (!sending.current) setTarget(null)
  }
  async function confirm(): Promise<void> {
    if (
      sending.current ||
      locked ||
      elsewhere ||
      !card ||
      !target ||
      verdict.disabled
    )
      return
    sending.current = true
    try {
      if (
        (await action.run(command.switchOff(target.languageId))) &&
        mounted.current
      )
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
            className="btn btn-sm btn-outline-danger"
            disabled={verdict.disabled}
            aria-describedby={
              verdict.reason ? 'language-card-switch-off-reason' : undefined
            }
            data-id="language-card-switch-off"
            onClick={open}
          >
            {copy.open}
          </button>
          {verdict.reason && (
            <p
              id="language-card-switch-off-reason"
              className="small text-body-secondary mt-1 mb-0"
              data-id="language-card-switch-off-reason"
            >
              {verdict.reason}
            </p>
          )}
        </>
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
              data-id="language-card-switch-off-cancel"
              onClick={requestClose}
            >
              {copy.cancel}
            </button>
            <LoadingButton
              className="btn-danger"
              loading={action.loading}
              disabled={action.busy || elsewhere || verdict.disabled}
              data-id="language-card-switch-off-confirm"
              onClick={() => void confirm()}
            >
              {copy.confirm}
            </LoadingButton>
          </>
        )}
      >
        <div data-id="language-card-switch-off-error">
          <HilosActionError action={action} detailsTitle={copy.refusalTitle} />
        </div>
        <HilosEditNotice
          kind={elsewhere ? 'updated' : null}
          text={copy.elsewhere}
          dataId="language-card-switch-off-notice"
        />
        <p>{copy.body}</p>
        <div className="alert alert-secondary small py-2 mb-0">{copy.note}</div>
      </HilosModal>
    </>
  )
}
