<!-- Removal always addresses the captured subscription, never its replacement. -->
<script setup lang="ts">
import { hilosToasts } from '@hilos/core/base'
import {
  HILOS_STATUS_SUBSCRIBERS_COPY,
  type HilosStatusSubscriberController,
} from '@hilos/core/admin/status'
import { computed, useId } from 'vue'

import HilosEditNotice from '../../HilosEditNotice.vue'
import HilosFormError from '../../HilosFormError.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosModal from '../../HilosModal.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{
  /** The page-owned removal window and its correlated action lifecycle. */
  controller: HilosStatusSubscriberController
}>()
const dialog = useSignal(props.controller.dialog)
const target = useSignal(props.controller.target)
const busy = useSignal(props.controller.busy)
const error = useSignal(props.controller.error)
const failure = useSignal(props.controller.failure)
const blockedReason = useSignal(props.controller.blockedReason)
const canSubmit = useSignal(props.controller.canSubmit)
const copyText = computed(() => {
  const detail = failure.value?.errorDetail
  return detail
    ? [failure.value?.errorType, detail].filter(Boolean).join('\n')
    : (error.value ?? '')
})
const formId = useId()
const descriptionId = useId()

/** Only a correlated success from this still-mounted window speaks to its initiator. */
async function submit(): Promise<void> {
  if (await props.controller.submit()) {
    const message = props.controller.success.get()
    if (message !== null) hilosToasts.push(message, { severity: 'success' })
  }
}
</script>

<template>
  <HilosModal
    :model-value="dialog !== null"
    :title="HILOS_STATUS_SUBSCRIBERS_COPY.remove"
    :busy="busy"
    initial-focus="dialog"
    @update:model-value="controller.close()"
  >
    <form
      v-if="dialog"
      :id="formId"
      :aria-label="HILOS_STATUS_SUBSCRIBERS_COPY.remove"
      :aria-describedby="descriptionId"
      data-id="hilos-status-subscriber-remove-form"
      @submit.prevent="submit"
    >
      <HilosFormError
        :message="error"
        :error-type="failure?.errorType"
        :error-detail="failure?.errorDetail"
        :details-title="HILOS_STATUS_SUBSCRIBERS_COPY.remove"
        :copy-text="copyText"
        data-id="hilos-status-subscriber-remove-error"
        announce
      />
      <p
        class="fw-semibold text-break"
        data-id="hilos-status-subscriber-remove-target"
      >
        <HilosHideable :value="dialog.email" /> · #{{ dialog.subscriberId }}
      </p>
      <p :id="descriptionId">{{ HILOS_STATUS_SUBSCRIBERS_COPY.removeLead }}</p>
      <p class="text-body-secondary">
        {{ HILOS_STATUS_SUBSCRIBERS_COPY.removeHint }}
      </p>
      <HilosEditNotice
        :kind="blockedReason ? 'deleted' : null"
        :text="blockedReason ?? ''"
        data-id="hilos-status-subscriber-remove-blocked"
      />
    </form>
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        :disabled="busy"
        data-id="hilos-status-subscriber-remove-cancel"
        @click="requestClose"
      >
        {{ HILOS_STATUS_SUBSCRIBERS_COPY.cancel }}
      </button>
      <LoadingButton
        :form="formId"
        type="submit"
        class="btn-danger"
        :loading="busy"
        :disabled="!busy && !canSubmit"
        data-id="hilos-status-subscriber-remove-confirm"
        >{{
          !busy && target === null
            ? HILOS_STATUS_SUBSCRIBERS_COPY.deletedLabel
            : HILOS_STATUS_SUBSCRIBERS_COPY.removeConfirm
        }}</LoadingButton
      >
    </template>
  </HilosModal>
</template>
