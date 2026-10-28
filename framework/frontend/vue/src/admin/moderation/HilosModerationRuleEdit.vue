<script setup lang="ts">
import { hilosToasts, type ActionHandle } from '@hilos/core/base'
import {
  HILOS_MODERATION_RULE_EDIT_COPY,
  type HilosModerationRuleEdit,
} from '@hilos/core/admin/moderation'
import { computed, shallowRef, watch } from 'vue'

import ConflictActions from '../../ConflictActions.vue'
import HilosActionError from '../../HilosActionError.vue'
import HilosEditNotice from '../../HilosEditNotice.vue'
import HilosModal from '../../HilosModal.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useLookOnly } from '../../hilosAdminViewMode.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

const props = defineProps<{ edit: HilosModerationRuleEdit }>()
const copy = HILOS_MODERATION_RULE_EDIT_COPY
const target = useSignal(props.edit.target)
const opened = useSignal(props.edit.opened)
const form = useSignal(props.edit.form)
const state = useSignal(props.edit.state)
const dirty = useSignal(props.edit.dirty)
const notice = useSignal(props.edit.notice)
const saving = useSignal(props.edit.saving)
const canSave = useSignal(props.edit.canSave)
const saveAvailable = useSignal(props.edit.saveAvailable)
const saveLabel = useSignal(props.edit.saveLabel)
const { locked } = useLookOnly()
const open = computed({
  get: () => opened.value,
  set: (next: boolean) => {
    if (!next) props.edit.close()
  },
})
const title = computed(() =>
  target.value ? copy.title(target.value.title) : copy.open,
)

function createAction() {
  // Refusals belong beside the draft; success uses the server's own sentence.
  return useTrackedAction({ toast: false })
}

const action = shallowRef(createAction())
// Each opening owns its runner, including a late refusal from an old address.
watch(target, () => (action.value = createAction()), { flush: 'sync' })

function changeRule(event: Event): void {
  if (event.target instanceof HTMLTextAreaElement) {
    props.edit.patchForm({ rule: event.target.value })
  }
}

async function submit(): Promise<void> {
  if (locked.value || !canSave.value) return
  const attempt = action.value
  const held = target.value
  await props.edit.save(async (handle: ActionHandle) => {
    const success = await attempt.run(handle)
    if (success) {
      const { message } = await handle.done
      if (
        action.value === attempt &&
        props.edit.target.get() === held &&
        message !== undefined
      ) {
        hilosToasts.push(message, { severity: 'success' })
      }
    }
    return success
  })
}
</script>

<template>
  <HilosModal
    v-model="open"
    :title="title"
    :confirm-on-close="dirty"
    :busy="saving"
  >
    <div data-id="hilos-moderation-rule-edit">
      <div data-id="hilos-moderation-rule-edit-error">
        <HilosActionError :action="action" :details-title="copy.refusalTitle" />
      </div>
      <form id="hilos-moderation-rule-edit-form" @submit.prevent="submit">
        <label class="form-label" for="hilos-moderation-rule-edit-rule">
          {{ copy.ruleLabel }}
        </label>
        <textarea
          id="hilos-moderation-rule-edit-rule"
          class="form-control"
          rows="4"
          :value="form.rule"
          :disabled="saving"
          data-id="hilos-moderation-rule-edit-rule"
          data-autofocus
          @input="changeRule"
        ></textarea>
        <HilosEditNotice
          :kind="notice?.kind ?? null"
          :text="notice?.text ?? ''"
          data-id="hilos-moderation-rule-edit-notice"
        />
      </form>
    </div>
    <template #actions="{ requestClose }">
      <ConflictActions
        :conflict="state.conflict"
        :disable-save="!saveAvailable"
        :save-label="saveLabel"
        @save="submit"
        @accept-mine="edit.keepMine"
        @accept-theirs="edit.takeTheirs"
      >
        <template #cancel-button>
          <button
            type="button"
            class="btn btn-secondary"
            :disabled="saving"
            data-id="hilos-moderation-rule-edit-cancel"
            @click="requestClose"
          >
            {{ copy.cancel }}
          </button>
        </template>
        <template #save-button="{ disabled, onSave }">
          <LoadingButton
            class="btn-primary"
            :loading="action.busy.value"
            :disabled="disabled"
            data-id="hilos-moderation-rule-edit-submit"
            @click="onSave"
          >
            {{ saveLabel }}
          </LoadingButton>
        </template>
      </ConflictActions>
    </template>
  </HilosModal>
</template>
