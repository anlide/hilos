<script setup lang="ts">
import { hilosToasts, type ActionHandle } from '@hilos/core/base'
import {
  HILOS_MODERATION_RULE_ADD_COPY,
  type HilosModerationRuleAdd,
} from '@hilos/core/admin/moderation'
import { computed, shallowRef, watch } from 'vue'

import HilosActionError from '../../HilosActionError.vue'
import HilosModal from '../../HilosModal.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useLookOnly } from '../../hilosAdminViewMode.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

const props = defineProps<{ add: HilosModerationRuleAdd }>()
const copy = HILOS_MODERATION_RULE_ADD_COPY
const target = useSignal(props.add.target)
const rule = useSignal(props.add.rule)
const dirty = useSignal(props.add.dirty)
const saving = useSignal(props.add.saving)
const submitAvailable = useSignal(props.add.submitAvailable)
const openState = useSignal(props.add.openState)
const { locked } = useLookOnly()
const open = computed({
  get: () => openState.value,
  set: (next: boolean) => {
    if (!next) props.add.close()
  },
})
const title = computed(() =>
  target.value ? copy.title(target.value.title) : copy.open,
)

function createAction() {
  // A rule refusal validates this field; keep it beside the draft, without a toast.
  return useTrackedAction({ toast: false })
}

const action = shallowRef(createAction())
// A replacement window must not inherit a pending runner or its late refusal.
watch(target, () => (action.value = createAction()), { flush: 'sync' })

function changeRule(event: Event): void {
  if (event.target instanceof HTMLTextAreaElement) {
    props.add.setRule(event.target.value)
  }
}

async function submit(): Promise<void> {
  if (locked.value) return
  const attempt = action.value
  await props.add.submit(async (handle: ActionHandle) => {
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
    :title="title"
    :confirm-on-close="dirty"
    :busy="saving"
  >
    <div data-id="hilos-moderation-rule-add">
      <div data-id="hilos-moderation-rule-add-error">
        <HilosActionError :action="action" :details-title="copy.refusalTitle" />
      </div>
      <form id="hilos-moderation-rule-add-form" @submit.prevent="submit">
        <label class="form-label" for="hilos-moderation-rule-add-rule">
          {{ copy.ruleLabel }}
        </label>
        <textarea
          id="hilos-moderation-rule-add-rule"
          class="form-control"
          rows="4"
          :value="rule"
          data-id="hilos-moderation-rule-add-rule"
          data-autofocus
          @input="changeRule"
        ></textarea>
      </form>
    </div>
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        :disabled="saving"
        data-id="hilos-moderation-rule-add-cancel"
        @click="requestClose"
      >
        {{ copy.cancel }}
      </button>
      <LoadingButton
        class="btn-primary"
        type="submit"
        form="hilos-moderation-rule-add-form"
        :loading="action.busy.value"
        :disabled="!submitAvailable"
        data-id="hilos-moderation-rule-add-submit"
      >
        {{ copy.save }}
      </LoadingButton>
    </template>
  </HilosModal>
</template>
