<!-- The page owns the controller's lifetime; this window only draws its form. -->
<script setup lang="ts">
import {
  STATUS_SUBSCRIPTION_COPY as COPY,
  type StatusSubscription,
} from '@hilos/core/status'
import { computed, useId } from 'vue'

import HilosFormError from '../HilosFormError.vue'
import HilosModal from '../HilosModal.vue'
import LoadingButton from '../LoadingButton.vue'
import { useSignal } from '../useSignal.js'

const props = defineProps<{
  /** The single mounted public page's subscription controller. */
  controller: StatusSubscription
}>()

const operation = useSignal(props.controller.operation)
const dialog = useSignal(props.controller.dialog)
const draft = useSignal(props.controller.draft)
const busy = useSignal(props.controller.busy)
const usable = useSignal(props.controller.usable)
const step = useSignal(props.controller.step)
const error = useSignal(props.controller.error)
const maskedEmail = useSignal(props.controller.maskedEmail)
const canSubmit = useSignal(props.controller.canSubmit)
const formId = `status-subscription-${useId()}`
const emailId = `${formId}-email`
const hintId = `${formId}-hint`
const errorId = `${formId}-error`
const title = computed(() =>
  operation.value === 'subscribe'
    ? COPY.subscribe
    : operation.value === 'unsubscribe'
      ? COPY.unsubscribe
      : operation.value === 'confirm'
        ? 'Confirm subscription'
        : 'Subscription link',
)
const progress = computed(() => {
  if (!usable.value || step.value === 'waiting') return COPY.waiting
  if (step.value !== 'pending') return ''
  return operation.value === 'confirm'
    ? 'Confirming your subscription…'
    : operation.value === 'unsubscribe' && maskedEmail.value === null
      ? 'Checking your unsubscribe link…'
      : 'Waiting for confirmation…'
})

function setEmail(event: Event): void {
  props.controller.setDraft({ email: (event.target as HTMLInputElement).value })
}

function close(open = false): void {
  if (!open) props.controller.close()
}
</script>

<template>
  <HilosModal
    :model-value="dialog"
    :title="title"
    :busy="busy"
    :initial-focus="operation === 'subscribe' ? '' : 'dialog'"
    @update:model-value="close"
  >
    <form
      :id="formId"
      novalidate
      data-id="status-subscription-form"
      @submit.prevent="controller.submit()"
    >
      <template v-if="operation === 'subscribe'">
        <p class="text-body-secondary mb-2">
          Letters about outages. You do not need an account.
        </p>
        <label class="form-label" :for="emailId">Email address</label>
        <input
          :id="emailId"
          :value="draft.email"
          type="email"
          name="email"
          autocomplete="email"
          class="form-control form-control-sm"
          data-autofocus
          data-id="status-subscription-email"
          :aria-describedby="`${hintId} ${errorId}`"
          :aria-invalid="error ? true : undefined"
          @input="setEmail"
        />
        <p :id="hintId" class="small text-body-secondary mt-2 mb-0">
          We will send a link to confirm the address. Nothing else is sent until
          you confirm, and every letter has a link to unsubscribe. If you did
          not ask for the email, ignore it.
        </p>
      </template>
      <p
        v-else-if="operation === 'unsubscribe'"
        class="mb-0 text-break"
        data-id="status-subscription-address"
      >
        Stop sending status updates to <strong>{{ maskedEmail ?? '…' }}</strong
        >?
      </p>

      <div class="hilos-stack small mt-2">
        <p class="invisible mb-0" aria-hidden="true" inert>
          {{ COPY.waiting }}
        </p>
        <p class="mb-0" data-id="status-subscription-progress">
          {{ progress }}
        </p>
      </div>
      <div
        class="visually-hidden"
        role="status"
        aria-live="polite"
        aria-atomic="true"
        data-id="status-subscription-live"
      >
        {{ progress }}
      </div>
      <div :id="errorId">
        <HilosFormError
          :message="error"
          data-id="status-subscription-error"
          announce
        />
      </div>
    </form>
    <template #actions>
      <button
        type="button"
        class="btn btn-secondary"
        :disabled="busy"
        data-id="status-subscription-cancel"
        @click="close()"
      >
        {{
          operation === 'subscribe'
            ? 'Cancel'
            : operation === 'unsubscribe'
              ? COPY.keep
              : 'Close'
        }}
      </button>
      <LoadingButton
        v-if="operation === 'subscribe' || maskedEmail !== null"
        type="submit"
        :form="formId"
        :class="operation === 'subscribe' ? 'btn-primary' : 'btn-danger'"
        :loading="busy"
        :disabled="!busy && !canSubmit"
        data-id="status-subscription-submit"
      >
        {{ operation === 'subscribe' ? 'Subscribe' : COPY.unsubscribe }}
      </LoadingButton>
      <span
        v-else-if="operation === 'unsubscribe'"
        class="btn btn-danger invisible"
        aria-hidden="true"
        inert
      >
        {{ COPY.unsubscribe }}
      </span>
    </template>
  </HilosModal>
</template>
