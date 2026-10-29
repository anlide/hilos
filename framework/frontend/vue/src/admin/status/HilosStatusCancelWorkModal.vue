<!-- Cancellation follows the live caption of the captured public identity. -->
<script setup lang="ts">
import { formatMoment, hilosTimeZone, hilosToasts } from '@hilos/core/base'
import type { HilosStatusWorkCancel } from '@hilos/core/admin/status'
import { computed, useId } from 'vue'

import HilosEditNotice from '../../HilosEditNotice.vue'
import HilosFormError from '../../HilosFormError.vue'
import HilosModal from '../../HilosModal.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{
  /** The page-owned focus and correlated cancellation lifecycle. */
  controller: HilosStatusWorkCancel
}>()
const opened = useSignal(props.controller.opened)
const target = useSignal(props.controller.target)
const busy = useSignal(props.controller.busy)
const canSubmit = useSignal(props.controller.canSubmit)
const blockedReason = useSignal(props.controller.blockedReason)
const error = useSignal(props.controller.error)
const failure = useSignal(props.controller.failure)
const timeZone = useSignal(hilosTimeZone)
const formId = useId()
const descriptionId = useId()
const copyText = computed(() =>
  failure.value?.errorDetail
    ? [failure.value.errorType, failure.value.errorDetail]
        .filter(Boolean)
        .join('\n')
    : (error.value ?? ''),
)

function moment(at: number): string {
  void timeZone.value
  return (
    formatMoment(at, {
      format: {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        timeZoneName: 'short',
      },
    }) ?? '—'
  )
}

async function submit(): Promise<void> {
  if (await props.controller.submit()) {
    const message = props.controller.success.get()
    if (message !== null) hilosToasts.push(message, { severity: 'success' })
  }
}
</script>

<template>
  <HilosModal
    :model-value="opened"
    title="Cancel scheduled maintenance"
    :busy="busy"
    initial-focus="dialog"
    @update:model-value="controller.close()"
  >
    <form
      v-if="opened"
      :id="formId"
      aria-label="Cancel scheduled maintenance"
      :aria-describedby="descriptionId"
      data-id="hilos-status-work-cancel-form"
      @submit.prevent="submit"
    >
      <HilosFormError
        :message="error"
        :error-type="failure?.errorType"
        :error-detail="failure?.errorDetail"
        details-title="Cancel scheduled maintenance"
        :copy-text="copyText"
        data-id="hilos-status-work-cancel-error"
        announce
      />
      <p
        class="fw-semibold text-break"
        data-id="hilos-status-work-cancel-target"
      >
        {{ target?.title ?? 'Announcement unavailable' }}
      </p>
      <p v-if="target" data-id="hilos-status-work-cancel-interval">
        {{ moment(target.startsAt) }} – {{ moment(target.endsAt) }}
      </p>
      <p :id="descriptionId" class="mb-0 text-body-secondary">
        This announcement will leave the upcoming list. Subscribers will be
        notified of the cancellation.
      </p>
      <HilosEditNotice
        :kind="blockedReason ? 'deleted' : null"
        :text="blockedReason ?? ''"
        data-id="hilos-status-work-cancel-blocked"
      />
    </form>
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        :disabled="busy"
        data-id="hilos-status-work-cancel-close"
        @click="requestClose"
      >
        Back
      </button>
      <LoadingButton
        :form="formId"
        type="submit"
        class="btn-danger"
        :loading="busy"
        :disabled="!busy && !canSubmit"
        data-id="hilos-status-work-cancel-confirm"
        >{{
          !busy && target === undefined ? 'Deleted' : 'Cancel maintenance'
        }}</LoadingButton
      >
    </template>
  </HilosModal>
</template>
