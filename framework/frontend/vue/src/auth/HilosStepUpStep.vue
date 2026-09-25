<script setup lang="ts">
import { HILOS_STEP_UP_COPY, type HilosStepUpStep } from '@hilos/core'

import HilosFormError from '../HilosFormError.vue'
import { useSignal } from '../useSignal.js'

const props = defineProps<{ controller: HilosStepUpStep }>()
const opening = useSignal(props.controller.opening)
const code = useSignal(props.controller.code)
const password = useSignal(props.controller.password)
const backupCode = useSignal(props.controller.backupCode)
const refusal = useSignal(props.controller.refusal)

function copy(template: string): string {
  return template
    .replace('{purpose}', opening.value?.purpose ?? '')
    .replace('{destination}', opening.value?.destination ?? '')
}
</script>

<template>
  <div data-id="step-up">
    <p class="small text-body-secondary" data-id="step-up-text">
      <template v-if="opening?.method === 'second_factor'">{{
        copy(HILOS_STEP_UP_COPY.secondFactor)
      }}</template>
      <template v-else-if="opening?.method === 'password'">{{
        copy(HILOS_STEP_UP_COPY.password)
      }}</template>
      <template v-else-if="opening?.method === 'passkey'">{{
        copy(HILOS_STEP_UP_COPY.passkey)
      }}</template>
      <template v-else-if="opening">{{
        copy(HILOS_STEP_UP_COPY.code)
      }}</template>
    </p>
    <template
      v-if="
        opening?.method === 'second_factor' ||
        opening?.method === 'email_code' ||
        opening?.method === 'sms_code'
      "
    >
      <label class="form-label" for="hilos-step-up-code">Code</label>
      <input
        id="hilos-step-up-code"
        class="form-control"
        autocomplete="one-time-code"
        inputmode="numeric"
        data-id="step-up-code"
        data-autofocus
        :value="code"
        @input="controller.code.set(($event.target as HTMLInputElement).value)"
      />
      <div v-if="opening.method === 'second_factor'" class="form-text">
        {{ HILOS_STEP_UP_COPY.codeHint }}
        <button
          type="button"
          class="btn btn-sm btn-link p-0 ms-1"
          data-id="step-up-backup-toggle"
          @click="controller.backupCode.set(!backupCode)"
        >
          {{
            backupCode
              ? HILOS_STEP_UP_COPY.useApp
              : HILOS_STEP_UP_COPY.useBackup
          }}
        </button>
      </div>
    </template>
    <template v-else-if="opening?.method === 'password'">
      <label class="form-label" for="hilos-step-up-password">Password</label>
      <input
        id="hilos-step-up-password"
        class="form-control"
        type="password"
        autocomplete="current-password"
        data-id="step-up-password"
        data-autofocus
        :value="password"
        @input="
          controller.password.set(($event.target as HTMLInputElement).value)
        "
      />
    </template>
    <HilosFormError :message="refusal" data-id="step-up-error" />
  </div>
</template>
