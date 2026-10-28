<script setup lang="ts">
import { hilosToasts, type ActionHandle } from '@hilos/core/base'
import {
  HILOS_MODERATION_RULE_DELETE_COPY,
  type HilosModerationRuleDelete,
} from '@hilos/core/admin/moderation'
import { computed, shallowRef, watch } from 'vue'

import HilosActionError from '../../HilosActionError.vue'
import HilosEditNotice from '../../HilosEditNotice.vue'
import HilosModal from '../../HilosModal.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useLookOnly } from '../../hilosAdminViewMode.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

const props = defineProps<{ remove: HilosModerationRuleDelete }>()
const copy = HILOS_MODERATION_RULE_DELETE_COPY
const target = useSignal(props.remove.target)
const saving = useSignal(props.remove.saving)
const submitAvailable = useSignal(props.remove.submitAvailable)
const notice = useSignal(props.remove.notice)
const openState = useSignal(props.remove.openState)
const { locked } = useLookOnly()
const open = computed({
  get: () => openState.value,
  set: (next: boolean) => {
    if (!next) props.remove.close()
  },
})

function createAction() {
  return useTrackedAction({ toast: false })
}

const action = shallowRef(createAction())
// A replacement confirmation must not inherit an old runner or its late refusal.
watch(target, () => (action.value = createAction()), { flush: 'sync' })

async function submit(): Promise<void> {
  if (locked.value || !props.remove.canSubmit.get()) return
  const attempt = action.value
  await props.remove.submit(async (handle: ActionHandle) => {
    const success = await attempt.run(handle)
    if (success) {
      const { message } = await handle.done
      if (message !== undefined) {
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
    :title="copy.title"
    :busy="saving"
    initial-focus="dialog"
  >
    <div v-if="target" data-id="hilos-moderation-rule-delete">
      <div data-id="hilos-moderation-rule-delete-error">
        <HilosActionError :action="action" :details-title="copy.refusalTitle" />
      </div>
      <p class="mb-2">{{ copy.body(target.title) }}</p>
      <p
        class="mb-0 text-truncate text-body-secondary"
        :title="target.expectedRule"
        data-id="hilos-moderation-rule-delete-rule"
      >
        {{ target.expectedRule }}
      </p>
      <HilosEditNotice
        :kind="notice?.kind ?? null"
        :text="notice?.text ?? ''"
        data-id="hilos-moderation-rule-delete-notice"
      />
    </div>
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        :disabled="saving"
        data-id="hilos-moderation-rule-delete-cancel"
        @click="requestClose"
      >
        {{ copy.cancel }}
      </button>
      <LoadingButton
        class="btn-danger"
        type="button"
        :loading="action.busy.value"
        :disabled="!submitAvailable"
        data-id="hilos-moderation-rule-delete-confirm"
        @click="submit"
      >
        {{ copy.confirm }}
      </LoadingButton>
    </template>
  </HilosModal>
</template>
