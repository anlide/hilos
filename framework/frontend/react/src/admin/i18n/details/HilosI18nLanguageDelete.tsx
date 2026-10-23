import { useEffect, useRef, useState } from 'react'
import {
  HILOS_I18N_LANGUAGE_DELETE_ACTION,
  HILOS_I18N_LANGUAGE_ROW_DELETE_ACTION,
  HILOS_I18N_LANGUAGE_DELETE_COPY,
  createHilosI18nLanguageDelete,
  type HilosI18nLanguageContext,
  type HilosI18nLanguageRow,
} from '@hilos/core/admin/i18n'
import { HilosActionError } from '../../../HilosActionError.js'
import { HilosEditNotice } from '../../../HilosEditNotice.js'
import { HilosModal } from '../../../HilosModal.js'
import { LoadingButton } from '../../../LoadingButton.js'
import { useLookOnly } from '../../../hilosLookOnly.js'
import { useTrackedAction } from '../../../useTrackedAction.js'

/** The common confirmation for a language row and its card, held through its own echo. */
export function HilosI18nLanguageDelete({
  open,
  onClose,
  onDeleted,
  context,
  source,
  actionName,
}: {
  open: boolean
  onClose: () => void
  onDeleted?: () => void
  context: HilosI18nLanguageContext
  source: Pick<
    HilosI18nLanguageRow,
    'languageId' | 'code' | 'nativeName' | 'canDelete' | 'deleteReason'
  > | null
  actionName:
    | typeof HILOS_I18N_LANGUAGE_DELETE_ACTION
    | typeof HILOS_I18N_LANGUAGE_ROW_DELETE_ACTION
}) {
  const copy = HILOS_I18N_LANGUAGE_DELETE_COPY
  const action = useTrackedAction()
  const { locked } = useLookOnly()
  const [target, setTarget] = useState({
    languageId: null as number | null,
    name: '',
    action: actionName,
  })
  const wasOpen = useRef(false),
    mounted = useRef(false),
    sending = useRef(false)
  const { clearError } = action
  useEffect(() => {
    mounted.current = true
    return () => {
      mounted.current = false
    }
  }, [])
  useEffect(() => {
    if (open && !wasOpen.current && source) {
      setTarget({
        languageId: source.languageId,
        name: source.nativeName,
        action: actionName,
      })
      clearError()
    }
    wasOpen.current = open
  }, [open, source, actionName, clearError])
  const elsewhere =
    open && !action.busy && source?.languageId !== target.languageId
  function close(): void {
    if (!sending.current) onClose()
  }
  async function confirm(): Promise<void> {
    if (
      !open ||
      sending.current ||
      locked ||
      elsewhere ||
      target.languageId === null
    )
      return
    // A dependency appearing after opening is judged by the server's live write door.
    sending.current = true
    const { deleteLanguage } = createHilosI18nLanguageDelete(
      context,
      target.action,
    )
    try {
      if (
        (await action.run(deleteLanguage(target.languageId))) &&
        mounted.current
      ) {
        onClose()
        onDeleted?.()
      }
    } finally {
      sending.current = false
    }
  }
  return (
    <HilosModal
      open={open}
      title={copy.title(target.name)}
      onClose={close}
      busy={action.busy}
      initialFocus="dialog"
      actions={({ requestClose }) => (
        <>
          <button
            type="button"
            className="btn btn-secondary"
            disabled={action.busy}
            data-id="i18n-language-delete-cancel"
            onClick={requestClose}
          >
            {copy.cancel}
          </button>
          <LoadingButton
            className="btn-danger"
            loading={action.busy}
            disabled={elsewhere}
            data-id="i18n-language-delete-confirm"
            onClick={() => void confirm()}
          >
            {copy.confirm}
          </LoadingButton>
        </>
      )}
    >
      <div data-id="i18n-language-delete-window">
        <div data-id="i18n-language-delete-error">
          <HilosActionError action={action} detailsTitle={copy.refusalTitle} />
        </div>
        <p>{copy.body}</p>
        <div className="alert alert-secondary small py-2 mb-0">{copy.note}</div>
        <HilosEditNotice
          kind={elsewhere ? 'deleted' : null}
          text={copy.elsewhere}
          dataId="i18n-language-delete-notice"
        />
      </div>
    </HilosModal>
  )
}
