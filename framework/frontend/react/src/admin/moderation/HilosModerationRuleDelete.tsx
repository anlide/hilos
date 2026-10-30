import { useEffect, useRef, useState } from 'react'
import { hilosToasts, type ActionHandle } from '@hilos/core/base'
import {
  HILOS_MODERATION_RULE_DELETE_COPY as COPY,
  type HilosModerationRuleDelete as RuleDelete,
  type HilosModerationRuleDeleteTarget,
} from '@hilos/core/admin/moderation'

import { HilosActionError } from '../../HilosActionError.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosModal } from '../../HilosModal.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useLookOnly } from '../../hilosLookOnly.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

/** Keep each confirmation and its runner separate from a replacement opening. */
export function HilosModerationRuleDelete({ remove }: { remove: RuleDelete }) {
  const target = useSignal(remove.target)
  const [opening, setOpening] = useState({ target, key: 0 })
  if (opening.target !== target) {
    setOpening({ target, key: opening.key + 1 })
  }
  return target ? (
    <RuleDeleteWindow key={opening.key} remove={remove} target={target} />
  ) : null
}

/** Draw the frozen text while the core follows the row's current existence. */
function RuleDeleteWindow({
  remove,
  target,
}: {
  remove: RuleDelete
  target: HilosModerationRuleDeleteTarget
}) {
  const open = useSignal(remove.openState)
  const saving = useSignal(remove.saving)
  const submitAvailable = useSignal(remove.submitAvailable)
  const notice = useSignal(remove.notice)
  const action = useTrackedAction({ toast: false })
  const { locked } = useLookOnly()
  const mounted = useRef(false)
  useEffect(() => {
    mounted.current = true
    return () => {
      mounted.current = false
    }
  }, [])

  async function submit(): Promise<void> {
    if (locked || !remove.canSubmit.get()) return
    await remove.submit(async (handle: ActionHandle) => {
      const success = await action.run(handle)
      if (success) {
        const { message } = await handle.done
        if (
          mounted.current &&
          remove.target.get() === target &&
          message !== undefined
        ) {
          hilosToasts.push(message, { severity: 'success' })
        }
      }
      return success
    })
  }

  return (
    <HilosModal
      open={open}
      title={COPY.title}
      onClose={remove.close}
      busy={saving}
      initialFocus="dialog"
      actions={({ requestClose }) => (
        <>
          <button
            type="button"
            className="btn btn-secondary"
            disabled={saving}
            data-id="hilos-moderation-rule-delete-cancel"
            onClick={requestClose}
          >
            {COPY.cancel}
          </button>
          <LoadingButton
            className="btn-danger"
            type="button"
            loading={action.busy}
            disabled={!submitAvailable}
            data-id="hilos-moderation-rule-delete-confirm"
            onClick={() => void submit()}
          >
            {COPY.confirm}
          </LoadingButton>
        </>
      )}
    >
      <div data-id="hilos-moderation-rule-delete">
        <div data-id="hilos-moderation-rule-delete-error">
          <HilosActionError action={action} detailsTitle={COPY.refusalTitle} />
        </div>
        <p className="mb-2">{COPY.body(target.title)}</p>
        <p
          className="mb-0 text-truncate text-body-secondary"
          title={target.expectedRule}
          data-id="hilos-moderation-rule-delete-rule"
        >
          {target.expectedRule}
        </p>
        <HilosEditNotice
          kind={notice?.kind ?? null}
          text={notice?.text ?? ''}
          dataId="hilos-moderation-rule-delete-notice"
        />
      </div>
    </HilosModal>
  )
}
