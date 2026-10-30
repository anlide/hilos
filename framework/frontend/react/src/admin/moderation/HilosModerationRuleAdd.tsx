import { useEffect, useRef, useState } from 'react'
import { hilosToasts, type ActionHandle } from '@hilos/core/base'
import {
  HILOS_MODERATION_RULE_ADD_COPY as COPY,
  type HilosModerationRuleAdd as RuleAdd,
} from '@hilos/core/admin/moderation'

import { HilosActionError } from '../../HilosActionError.js'
import { HilosModal } from '../../HilosModal.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useLookOnly } from '../../hilosLookOnly.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

/** Give every opening its own tracked runner, even when React batches a reopen. */
export function HilosModerationRuleAdd({ add }: { add: RuleAdd }) {
  const target = useSignal(add.target)
  const [opening, setOpening] = useState({ target, key: 0 })
  if (opening.target !== target) {
    setOpening({ target, key: opening.key + 1 })
  }
  return target ? (
    <RuleAddWindow key={opening.key} add={add} target={target} />
  ) : null
}

/** Mirror the core draft and let the standard modal own close and focus. */
function RuleAddWindow({
  add,
  target,
}: {
  add: RuleAdd
  target: NonNullable<ReturnType<RuleAdd['target']['get']>>
}) {
  const open = useSignal(add.openState)
  const rule = useSignal(add.rule)
  const dirty = useSignal(add.dirty)
  const saving = useSignal(add.saving)
  const submitAvailable = useSignal(add.submitAvailable)
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
    if (locked || !add.canSubmit.get()) return
    await add.submit(async (handle: ActionHandle) => {
      const success = await action.run(handle)
      if (success) {
        const { message } = await handle.done
        if (
          mounted.current &&
          add.target.get() === target &&
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
      title={COPY.title(target.title)}
      onClose={add.close}
      confirmOnClose={dirty}
      busy={saving}
      actions={({ requestClose }) => (
        <>
          <button
            type="button"
            className="btn btn-secondary"
            disabled={saving}
            data-id="hilos-moderation-rule-add-cancel"
            onClick={requestClose}
          >
            {COPY.cancel}
          </button>
          <LoadingButton
            className="btn-primary"
            type="submit"
            form="hilos-moderation-rule-add-form"
            loading={action.busy}
            disabled={!submitAvailable}
            data-id="hilos-moderation-rule-add-submit"
          >
            {COPY.save}
          </LoadingButton>
        </>
      )}
    >
      <div data-id="hilos-moderation-rule-add">
        <div data-id="hilos-moderation-rule-add-error">
          <HilosActionError action={action} detailsTitle={COPY.refusalTitle} />
        </div>
        <form
          id="hilos-moderation-rule-add-form"
          onSubmit={(event) => {
            event.preventDefault()
            void submit()
          }}
        >
          <label
            className="form-label"
            htmlFor="hilos-moderation-rule-add-rule"
          >
            {COPY.ruleLabel}
          </label>
          <textarea
            id="hilos-moderation-rule-add-rule"
            className="form-control"
            rows={4}
            value={rule}
            data-id="hilos-moderation-rule-add-rule"
            data-autofocus
            onChange={(event) => add.setRule(event.target.value)}
          />
        </form>
      </div>
    </HilosModal>
  )
}
