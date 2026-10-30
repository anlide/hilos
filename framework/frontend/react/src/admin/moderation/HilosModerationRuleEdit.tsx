import { useEffect, useRef, useState } from 'react'
import { hilosToasts, type ActionHandle } from '@hilos/core/base'
import {
  HILOS_MODERATION_RULE_EDIT_COPY as COPY,
  type HilosModerationRuleEdit as RuleEdit,
  type HilosModerationRuleEditTarget,
} from '@hilos/core/admin/moderation'

import { ConflictActions } from '../../ConflictActions.js'
import { HilosActionError } from '../../HilosActionError.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosModal } from '../../HilosModal.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useLookOnly } from '../../hilosLookOnly.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

/** Keep the runner local to one core row-edit opening. */
export function HilosModerationRuleEdit({ edit }: { edit: RuleEdit }) {
  const target = useSignal(edit.target)
  const [opening, setOpening] = useState({ target, key: 0 })
  if (opening.target !== target) {
    setOpening({ target, key: opening.key + 1 })
  }
  return target ? (
    <RuleEditWindow key={opening.key} edit={edit} target={target} />
  ) : null
}

/** Present the core merge, notice and save verdict without another edit store. */
function RuleEditWindow({
  edit,
  target,
}: {
  edit: RuleEdit
  target: HilosModerationRuleEditTarget
}) {
  const open = useSignal(edit.opened)
  const form = useSignal(edit.form)
  const state = useSignal(edit.state)
  const dirty = useSignal(edit.dirty)
  const notice = useSignal(edit.notice)
  const saving = useSignal(edit.saving)
  const saveAvailable = useSignal(edit.saveAvailable)
  const saveLabel = useSignal(edit.saveLabel)
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
    if (locked || !edit.canSave.get()) return
    await edit.save(async (handle: ActionHandle) => {
      const success = await action.run(handle)
      if (success) {
        const { message } = await handle.done
        if (
          mounted.current &&
          edit.target.get() === target &&
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
      onClose={edit.close}
      confirmOnClose={dirty}
      busy={saving}
      actions={({ requestClose }) => (
        <ConflictActions
          conflict={state.conflict}
          disableSave={!saveAvailable}
          saveLabel={saveLabel}
          onSave={() => void submit()}
          onAcceptMine={edit.keepMine}
          onAcceptTheirs={edit.takeTheirs}
          cancelButton={
            <button
              type="button"
              className="btn btn-secondary"
              disabled={saving}
              data-id="hilos-moderation-rule-edit-cancel"
              onClick={requestClose}
            >
              {COPY.cancel}
            </button>
          }
          saveButton={({ disabled, onSave }) => (
            <LoadingButton
              className="btn-primary"
              loading={action.busy}
              disabled={disabled}
              data-id="hilos-moderation-rule-edit-submit"
              onClick={onSave}
            >
              {saveLabel}
            </LoadingButton>
          )}
        />
      )}
    >
      <div data-id="hilos-moderation-rule-edit">
        <div data-id="hilos-moderation-rule-edit-error">
          <HilosActionError action={action} detailsTitle={COPY.refusalTitle} />
        </div>
        <form
          id="hilos-moderation-rule-edit-form"
          onSubmit={(event) => {
            event.preventDefault()
            void submit()
          }}
        >
          <label
            className="form-label"
            htmlFor="hilos-moderation-rule-edit-rule"
          >
            {COPY.ruleLabel}
          </label>
          <textarea
            id="hilos-moderation-rule-edit-rule"
            className="form-control"
            rows={4}
            value={form.rule}
            data-id="hilos-moderation-rule-edit-rule"
            data-autofocus
            onChange={(event) => edit.patchForm({ rule: event.target.value })}
          />
          <HilosEditNotice
            kind={notice?.kind ?? null}
            text={notice?.text ?? ''}
            dataId="hilos-moderation-rule-edit-notice"
          />
        </form>
      </div>
    </HilosModal>
  )
}
