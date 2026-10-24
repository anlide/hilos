<!-- Three incident operations share the core's captured target and draft. -->
<script setup lang="ts">
import {
  HILOS_STATUS_ACTION_COPY,
  type HilosStatusIncidentController,
} from '@hilos/core/admin/status'
import { computed, useId } from 'vue'

import HilosEditNotice from '../../HilosEditNotice.vue'
import HilosFormError from '../../HilosFormError.vue'
import HilosModal from '../../HilosModal.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{
  /** The page-owned incident window, including its tracked submit. */
  controller: HilosStatusIncidentController
}>()
const dialog = useSignal(props.controller.dialog)
const target = useSignal(props.controller.target)
const draft = useSignal(props.controller.draft)
const busy = useSignal(props.controller.busy)
const error = useSignal(props.controller.error)
const failure = useSignal(props.controller.failure)
const blockedReason = useSignal(props.controller.blockedReason)
const canSubmit = useSignal(props.controller.canSubmit)
const copy = computed(() =>
  dialog.value ? HILOS_STATUS_ACTION_COPY[dialog.value.operation] : null,
)
const copyText = computed(() => {
  const detail = failure.value?.errorDetail
  return detail
    ? [failure.value?.errorType, detail].filter(Boolean).join('\n')
    : (error.value ?? '')
})
const formId = useId()
const fieldId = useId()

/** Enter confirms; a multiline update keeps Enter and submits with Ctrl/Command+Enter. */
function onEnter(event: KeyboardEvent): void {
  if (event.target instanceof Element && event.target.closest('button, a'))
    return
  if (
    dialog.value?.operation === 'add-update' &&
    !event.ctrlKey &&
    !event.metaKey
  )
    return
  event.preventDefault()
  void props.controller.submit()
}
</script>

<template>
  <HilosModal
    :model-value="dialog !== null"
    :title="copy?.title ?? ''"
    :busy="busy"
    :initial-focus="dialog?.operation === 'add-update' ? 'inner' : 'dialog'"
    @update:model-value="controller.close()"
  >
    <form
      v-if="dialog && copy"
      :id="formId"
      data-id="hilos-status-incident-form"
      @submit.prevent="controller.submit()"
      @keydown.enter="onEnter"
    >
      <HilosFormError
        :message="error"
        :error-type="failure?.errorType"
        :error-detail="failure?.errorDetail"
        :details-title="copy.refusalTitle"
        :copy-text="copyText"
        data-id="hilos-status-incident-error"
        announce
      />
      <p class="fw-semibold text-break" data-id="hilos-status-incident-target">
        {{ dialog.incident.componentName }} · #{{ dialog.incident.incidentId }}
      </p>
      <p>{{ copy.lead }}</p>
      <p
        v-if="target"
        class="small text-body-secondary"
        data-id="hilos-status-incident-time"
      >
        {{ target.label }} ·
        {{ target.publishedAtLabel ?? target.publishAtLabel }}
      </p>
      <HilosEditNotice
        :kind="blockedReason ? 'deleted' : null"
        :text="blockedReason ?? ''"
        data-id="hilos-status-incident-blocked"
      />
      <template v-if="dialog.operation === 'add-update'">
        <label :for="fieldId" class="form-label">{{
          HILOS_STATUS_ACTION_COPY.updateField
        }}</label>
        <textarea
          :id="fieldId"
          :value="draft"
          class="form-control"
          rows="5"
          :placeholder="HILOS_STATUS_ACTION_COPY.updatePlaceholder"
          data-id="hilos-status-update-text"
          data-autofocus
          @input="
            controller.setDraft(($event.target as HTMLTextAreaElement).value)
          "
        ></textarea>
        <p class="small text-body-secondary mb-0 mt-2">
          Ctrl/Command+Enter to publish.
        </p>
      </template>
    </form>
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        :disabled="busy"
        data-id="hilos-status-incident-cancel"
        @click="requestClose"
      >
        {{ HILOS_STATUS_ACTION_COPY.cancel }}
      </button>
      <LoadingButton
        :form="formId"
        type="submit"
        :class="
          dialog?.operation === 'add-update' ? 'btn-primary' : 'btn-danger'
        "
        :loading="busy"
        :disabled="!busy && !canSubmit"
        data-id="hilos-status-incident-confirm"
        >{{ copy?.confirm }}</LoadingButton
      >
    </template>
  </HilosModal>
</template>
