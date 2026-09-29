<!-- One password-change window; the agnostic flow owns every transition (HIL-300). -->
<script setup lang="ts">
import {
  focusInitial,
  HILOS_PROFILE_PASSWORD_CHANGE_COPY as COPY,
  HILOS_STEP_UP_COPY,
  type HilosProfilePasswordChangeFlow,
} from '@hilos/core'
import { computed, nextTick, ref, useId, watch } from 'vue'
import HilosFormError from '../HilosFormError.vue'
import HilosModal from '../HilosModal.vue'
import LoadingButton from '../LoadingButton.vue'
import HilosStepUpStep from '../auth/HilosStepUpStep.vue'
import { useSignal } from '../useSignal.js'

const props = defineProps<{ flow: HilosProfilePasswordChangeFlow }>()
const step = useSignal(props.flow.step)
const opening = useSignal(props.flow.opening)
const code = useSignal(props.flow.code)
const newPassword = useSignal(props.flow.newPassword)
const signOutOthers = useSignal(props.flow.signOutOthers)
const signedOutOthers = useSignal(props.flow.signedOutOthers)
const busy = useSignal(props.flow.busy)
const refusal = useSignal(props.flow.refusal)
const stepUpRefusal = useSignal(props.flow.stepUp.refusal)
const asksBeforeClosing = useSignal(props.flow.asksBeforeClosing)
const body = ref<HTMLElement | null>(null)
const id = useId()
const open = computed({
  get: () => step.value !== 'closed' && step.value !== 'opening',
  set: (value: boolean) => {
    if (!value) props.flow.close()
  },
})
const title = computed(() =>
  step.value === 'step-up' || step.value === 'refused'
    ? HILOS_STEP_UP_COPY.title
    : step.value === 'done'
      ? COPY.doneTitle
      : COPY.title,
)
const stepNumber = computed(() =>
  step.value === 'code'
    ? 2
    : step.value === 'password'
      ? 3
      : step.value === 'done'
        ? 4
        : 1,
)
const voice = computed(() =>
  step.value === 'step-up' ? stepUpRefusal.value : refusal.value,
)
function fill(text: string): string {
  return text.replace('{destination}', opening.value?.destination ?? '')
}
watch(step, () => {
  void nextTick(() => {
    const dialog = body.value?.closest<HTMLElement>('[role="dialog"]')
    if (dialog) focusInitial(dialog)
  })
})
</script>

<template>
  <HilosModal
    v-model="open"
    :title="title"
    initial-focus="inner"
    :confirm-on-close="asksBeforeClosing"
  >
    <div
      class="visually-hidden"
      role="alert"
      aria-live="assertive"
      aria-atomic="true"
      data-id="profile-password-live"
    >
      {{ voice }}
    </div>
    <div ref="body" data-id="profile-password-modal">
      <div class="hilos-stack">
        <div class="invisible" aria-hidden="true" inert>
          <ol class="list-unstyled d-flex flex-column gap-1 mb-3 small">
            <li
              v-for="(label, index) in COPY.steps"
              :key="label"
              class="d-flex align-items-center gap-2"
            >
              <span class="badge rounded-pill text-bg-secondary">{{
                index + 1
              }}</span
              ><span>{{ label }}</span>
            </li>
          </ol>
          <div class="form-label">{{ COPY.newPassword }}</div>
          <div class="form-control">&nbsp;</div>
          <div class="form-text">{{ COPY.newPasswordHint }}</div>
          <div class="form-check mt-3">
            <span class="form-check-label">{{ COPY.signOutOthers }}</span>
          </div>
        </div>
        <div class="align-self-start">
          <form
            v-if="step === 'step-up'"
            :id="`${id}-step-up-form`"
            data-id="profile-password-step-up"
            @submit.prevent="flow.confirmStepUp()"
          >
            <HilosStepUpStep :controller="flow.stepUp" />
          </form>
          <template v-else-if="step !== 'refused'">
            <ol
              v-if="step !== 'done'"
              class="list-unstyled d-flex flex-column gap-1 mb-3 small"
              data-id="profile-password-steps"
            >
              <li
                v-for="(label, index) in COPY.steps"
                :key="label"
                class="d-flex align-items-center gap-2"
                :class="
                  index + 1 === stepNumber
                    ? 'fw-semibold'
                    : 'text-body-secondary'
                "
                :aria-current="index + 1 === stepNumber ? 'step' : undefined"
              >
                <span
                  class="badge rounded-pill"
                  :class="
                    index + 1 === stepNumber
                      ? 'text-bg-primary'
                      : 'text-bg-secondary'
                  "
                  >{{ index + 1 }}</span
                ><span>{{ label }}</span>
              </li>
            </ol>
            <p
              v-if="step === 'start'"
              class="small text-body-secondary mb-0 text-break"
              data-id="profile-password-destination"
            >
              {{ fill(COPY.start) }}
            </p>
            <form
              v-else-if="step === 'code'"
              :id="`${id}-code-form`"
              @submit.prevent="flow.confirmCode()"
            >
              <label :for="`${id}-code`" class="form-label">{{
                COPY.code
              }}</label>
              <input
                :id="`${id}-code`"
                class="form-control"
                autocomplete="one-time-code"
                inputmode="numeric"
                data-autofocus
                data-id="profile-password-code"
                :value="code"
                :aria-describedby="`${id}-code-hint`"
                @input="
                  flow.code.set(($event.target as HTMLInputElement).value)
                "
              />
              <div :id="`${id}-code-hint`" class="form-text text-break">
                {{ fill(COPY.codeHint) }}
              </div>
            </form>
            <form
              v-else-if="step === 'password'"
              :id="`${id}-password-form`"
              @submit.prevent="flow.save()"
            >
              <label :for="`${id}-password`" class="form-label">{{
                COPY.newPassword
              }}</label>
              <input
                :id="`${id}-password`"
                type="password"
                class="form-control"
                autocomplete="new-password"
                data-autofocus
                data-id="profile-password-new"
                :value="newPassword"
                :aria-describedby="`${id}-password-hint`"
                @input="
                  flow.newPassword.set(
                    ($event.target as HTMLInputElement).value,
                  )
                "
              />
              <div :id="`${id}-password-hint`" class="form-text">
                {{ COPY.newPasswordHint }}
              </div>
              <div class="form-check mt-3">
                <input
                  :id="`${id}-others`"
                  type="checkbox"
                  class="form-check-input"
                  data-id="profile-password-sign-out-others"
                  :checked="signOutOthers"
                  @change="
                    flow.signOutOthers.set(
                      ($event.target as HTMLInputElement).checked,
                    )
                  "
                />
                <label :for="`${id}-others`" class="form-check-label">{{
                  COPY.signOutOthers
                }}</label>
              </div>
            </form>
            <div
              v-else-if="step === 'done'"
              class="text-center py-2"
              data-id="profile-password-outcome"
            >
              <i
                class="bi bi-check-circle-fill text-success d-block mb-2 fs-2"
                aria-hidden="true"
              ></i>
              <div class="fw-semibold mb-1">{{ COPY.doneTitle }}</div>
              <p
                class="small text-body-secondary mb-0"
                data-id="profile-password-outcome-sessions"
              >
                {{ signedOutOthers ? COPY.doneSignedOut : COPY.doneKept }}
              </p>
              <p class="small text-body-secondary mb-0">
                {{ COPY.doneResetCodes }}
              </p>
            </div>
          </template>
        </div>
      </div>
      <HilosFormError :message="refusal" data-id="profile-password-error" />
    </div>
    <template #actions="{ requestClose }">
      <template v-if="step === 'done'">
        <LoadingButton
          class="btn-outline-secondary"
          :loading="busy"
          data-id="profile-password-again"
          @click="flow.again()"
          >{{ COPY.again }}</LoadingButton
        >
        <button
          type="button"
          class="btn btn-primary"
          data-id="profile-password-done"
          @click="requestClose"
        >
          {{ COPY.done }}
        </button>
      </template>
      <template v-else>
        <button
          type="button"
          class="btn btn-outline-secondary"
          data-id="profile-password-cancel"
          @click="requestClose"
        >
          {{ COPY.cancel }}
        </button>
        <LoadingButton
          v-if="step === 'step-up'"
          type="submit"
          :form="`${id}-step-up-form`"
          class="btn-primary"
          :loading="busy"
          data-id="profile-password-step-up-confirm"
          >{{ HILOS_STEP_UP_COPY.confirm }}</LoadingButton
        >
        <LoadingButton
          v-else-if="step === 'start'"
          class="btn-primary"
          :loading="busy"
          data-id="profile-password-send-code"
          @click="flow.sendCode()"
          >{{ COPY.sendCode }}</LoadingButton
        >
        <LoadingButton
          v-else-if="step === 'code'"
          type="submit"
          :form="`${id}-code-form`"
          class="btn-primary"
          :loading="busy"
          :disabled="code.trim() === '' || busy"
          data-id="profile-password-confirm-code"
          >{{ COPY.continue }}</LoadingButton
        >
        <LoadingButton
          v-else-if="step === 'password'"
          type="submit"
          :form="`${id}-password-form`"
          class="btn-primary"
          :loading="busy"
          :disabled="newPassword === '' || busy"
          data-id="profile-password-save"
          >{{ COPY.save }}</LoadingButton
        >
      </template>
    </template>
  </HilosModal>
</template>
