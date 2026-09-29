<!-- The profile root's email window (HIL-299, HIL-1169): one modal, five steps, the content changing in place. -->
<script setup lang="ts">
import {
  focusInitial,
  HILOS_PROFILE_EMAIL_CHANGE_COPY as COPY,
  HILOS_PROFILE_EMAIL_CHANGE_STEPS,
  HILOS_STEP_UP_COPY,
  type HilosProfileEmailChangeFlow,
} from '@hilos/core'
import { computed, nextTick, ref, watch } from 'vue'
import HilosFormError from '../HilosFormError.vue'
import HilosModal from '../HilosModal.vue'
import LoadingButton from '../LoadingButton.vue'
import HilosStepUpStep from '../auth/HilosStepUpStep.vue'
import { useSignal } from '../useSignal.js'

const props = defineProps<{ flow: HilosProfileEmailChangeFlow }>()
const step = useSignal(props.flow.step)
const was = useSignal(props.flow.was)
const currentCode = useSignal(props.flow.currentCode)
const newEmail = useSignal(props.flow.newEmail)
const newCode = useSignal(props.flow.newCode)
const now = useSignal(props.flow.now)
const busy = useSignal(props.flow.busy)
const refusal = useSignal(props.flow.refusal)
const canSubmit = useSignal(props.flow.canSubmit)
const asksBeforeClosing = useSignal(props.flow.asksBeforeClosing)
const stepUpOpening = useSignal(props.flow.stepUp.opening)
const stepUpRefusal = useSignal(props.flow.stepUp.refusal)
const body = ref<HTMLElement | null>(null)
const open = computed({
  get: () => step.value !== 'closed',
  set: (value: boolean) => {
    if (!value) props.flow.close()
  },
})
const title = computed(() =>
  step.value === 'step-up'
    ? HILOS_STEP_UP_COPY.title
    : step.value === 'done'
      ? COPY.doneTitle
      : COPY.title,
)
// The step's place in the list: 1 to 5, 0 on the confirmation.
const stepNumber = computed(
  () =>
    HILOS_PROFILE_EMAIL_CHANGE_STEPS.indexOf(
      step.value as (typeof HILOS_PROFILE_EMAIL_CHANGE_STEPS)[number],
    ) + 1,
)

/**
 * Put a typed value into the flow.
 *
 * @param event The input event of the step's field.
 * @param target The flow's value the field edits.
 */
function typed(event: Event, target: { set(value: string): void }): void {
  target.set((event.target as HTMLInputElement).value)
}

// The confirmation's body is replaced by step one: focus it.
watch(step, (next, previous) => {
  if (previous !== 'step-up' || next !== 'send-current') return
  void nextTick(() => {
    const dialog = body.value?.closest<HTMLElement>('[role="dialog"]')
    if (dialog) focusInitial(dialog)
  })
})
</script>

<template>
  <!-- Every step is one server-confirmed submit; a refusal stays on the step in
  the room HilosFormError holds for it. Closing on steps 2 to 4 asks first,
  because a code is already out; the address moves only on step 4, so a flow
  abandoned anywhere changes nothing. -->
  <HilosModal
    v-model="open"
    :title="title"
    :confirm-on-close="asksBeforeClosing"
    initial-focus="inner"
  >
    <!-- This dialog's own voice; one region for all the steps, since only one
    step is on screen at a time. -->
    <div
      class="visually-hidden"
      role="alert"
      aria-live="assertive"
      data-id="profile-email-live-assertive"
    >
      {{ step === 'step-up' ? stepUpRefusal : refusal }}
    </div>

    <div ref="body">
      <form
        v-if="step === 'step-up'"
        id="hilos-profile-email-step-up"
        data-id="profile-email-step-up"
        @submit.prevent="flow.submit()"
      >
        <HilosStepUpStep :controller="flow.stepUp" />
      </form>
      <ol
        v-if="step !== 'step-up' && step !== 'done'"
        class="list-unstyled d-flex flex-column gap-1 mb-3 small"
        data-id="profile-email-steps"
      >
        <li
          v-for="(label, index) in COPY.steps"
          :key="label"
          class="d-flex align-items-center gap-2"
          :class="
            index + 1 === stepNumber ? 'fw-semibold' : 'text-body-secondary'
          "
          :aria-current="index + 1 === stepNumber ? 'step' : undefined"
        >
          <span
            class="badge rounded-pill"
            :class="
              index + 1 === stepNumber ? 'text-bg-primary' : 'text-bg-secondary'
            "
            >{{ index + 1 }}</span
          >
          <span>{{ label }}</span>
        </li>
      </ol>

      <form v-if="step === 'send-current'" @submit.prevent="flow.submit()">
        <p class="small text-body-secondary mb-0">
          {{ COPY.sendCurrentLead }} <strong>{{ was }}</strong>
          {{ COPY.sendCurrentTail }}
        </p>
        <HilosFormError :message="refusal" data-id="profile-email-error" />
      </form>

      <form
        v-else-if="step === 'confirm-current'"
        @submit.prevent="flow.submit()"
      >
        <label class="form-label" for="profile-email-code-current">{{
          COPY.code
        }}</label>
        <input
          id="profile-email-code-current"
          type="text"
          inputmode="numeric"
          autocomplete="one-time-code"
          class="form-control"
          data-autofocus
          data-id="profile-email-code-current"
          :value="currentCode"
          @input="typed($event, flow.currentCode)"
        />
        <div class="form-text">{{ COPY.sentTo.replace('{address}', was) }}</div>
        <HilosFormError :message="refusal" data-id="profile-email-error" />
      </form>

      <form v-else-if="step === 'new-address'" @submit.prevent="flow.submit()">
        <label class="form-label" for="profile-email-new">{{
          COPY.newEmail
        }}</label>
        <input
          id="profile-email-new"
          type="email"
          autocomplete="email"
          class="form-control"
          data-autofocus
          data-id="profile-email-new"
          :value="newEmail"
          @input="typed($event, flow.newEmail)"
        />
        <div class="form-text">{{ COPY.newEmailHint }}</div>
        <HilosFormError :message="refusal" data-id="profile-email-error" />
      </form>

      <form v-else-if="step === 'confirm-new'" @submit.prevent="flow.submit()">
        <label class="form-label" for="profile-email-code-new">{{
          COPY.code
        }}</label>
        <input
          id="profile-email-code-new"
          type="text"
          inputmode="numeric"
          autocomplete="one-time-code"
          class="form-control"
          data-autofocus
          data-id="profile-email-code-new"
          :value="newCode"
          @input="typed($event, flow.newCode)"
        />
        <div class="form-text">
          {{ COPY.sentTo.replace('{address}', newEmail.trim()) }}
        </div>
        <HilosFormError :message="refusal" data-id="profile-email-error" />
      </form>

      <div
        v-else-if="step === 'done'"
        class="text-center py-2"
        data-id="profile-email-outcome"
      >
        <i
          class="bi bi-check-circle-fill text-success fs-2 d-block mb-2"
          aria-hidden="true"
        ></i>
        <div class="fw-semibold mb-1">{{ COPY.changed }}</div>
        <p class="small text-body-secondary mb-0">
          {{ COPY.outcomeWas }} <s data-id="profile-email-was">{{ was }}</s
          >, {{ COPY.outcomeNow }}
          <strong data-id="profile-email-now">{{ now }}</strong
          >. {{ COPY.outcomeTail }}
        </p>
      </div>
    </div>

    <template #actions="{ requestClose }">
      <template v-if="step === 'step-up'">
        <button
          type="button"
          class="btn btn-outline-secondary"
          data-id="profile-email-cancel"
          @click="requestClose"
        >
          {{ COPY.cancel }}
        </button>
        <LoadingButton
          v-if="stepUpOpening !== null"
          type="submit"
          form="hilos-profile-email-step-up"
          class="btn-primary"
          :loading="busy"
          data-id="profile-email-step-up-confirm"
        >
          {{ HILOS_STEP_UP_COPY.confirm }}
        </LoadingButton>
      </template>
      <template v-else-if="step !== 'done'">
        <button
          type="button"
          class="btn btn-outline-secondary"
          data-id="profile-email-cancel"
          @click="requestClose"
        >
          {{ COPY.cancel }}
        </button>
        <LoadingButton
          v-if="step === 'send-current'"
          class="btn-primary"
          :loading="busy"
          data-autofocus
          data-id="profile-email-send-current"
          @click="flow.submit()"
        >
          {{ COPY.sendCode }}
        </LoadingButton>
        <LoadingButton
          v-else-if="step === 'confirm-current'"
          class="btn-primary"
          :loading="busy"
          :disabled="!canSubmit"
          data-id="profile-email-confirm-current"
          @click="flow.submit()"
        >
          {{ COPY.continue }}
        </LoadingButton>
        <LoadingButton
          v-else-if="step === 'new-address'"
          class="btn-primary"
          :loading="busy"
          :disabled="!canSubmit"
          data-id="profile-email-send-new"
          @click="flow.submit()"
        >
          {{ COPY.sendCode }}
        </LoadingButton>
        <LoadingButton
          v-else
          class="btn-primary"
          :loading="busy"
          :disabled="!canSubmit"
          data-id="profile-email-confirm-new"
          @click="flow.submit()"
        >
          {{ COPY.changeEmail }}
        </LoadingButton>
      </template>
      <template v-else>
        <button
          type="button"
          class="btn btn-outline-secondary"
          data-id="profile-email-again"
          @click="flow.again()"
        >
          {{ COPY.again }}
        </button>
        <button
          type="button"
          class="btn btn-primary"
          data-autofocus
          data-id="profile-email-done"
          @click="requestClose"
        >
          {{ COPY.done }}
        </button>
      </template>
    </template>
  </HilosModal>
</template>
