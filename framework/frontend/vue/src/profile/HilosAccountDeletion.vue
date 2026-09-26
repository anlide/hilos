<!-- HilosAccountDeletion — the danger zone at the bottom of a profile page and
its one window (HIL-302). Without a scheduled deletion the zone offers "Delete my
account…", and the window walks the operation's confirmation (when asked), step 1
"what will happen" and step 2 "the code"; with one, the zone is a warning with
the date and the days left, and the window is "Deletion in progress" with a plain
"Keep my account". Every open tab follows the person's state, so a deletion
started or called off in one turns the others too. The state, the actions and the
window's steps are the core's (createHilosAccountDeletionStore /
createHilosAccountDeletionFlow); this view owns only the markup. The refusal sits
above the buttons in the room HilosFormError keeps for it, and the window speaks
through its own live region. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  ACCOUNT_DELETION_TICK_MS,
  accountDeletionDaysLeft,
  createHilosAccountDeletionFlow,
  createHilosAccountDeletionStore,
  focusInitial,
  formatAccountDeletionDays,
  formatCalendarDate,
  HILOS_ACCOUNT_DELETION_COPY as COPY,
  HILOS_STEP_UP_COPY,
  type HilosSecondFactorContext,
} from '@hilos/core'
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'

import HilosFormError from '../HilosFormError.vue'
import HilosModal from '../HilosModal.vue'
import LoadingButton from '../LoadingButton.vue'
import HilosStepUpStep from '../auth/HilosStepUpStep.vue'
import { useSignal } from '../useSignal.js'

defineOptions({ name: 'HilosAccountDeletion' })

const props = defineProps<{
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosSecondFactorContext
}>()

const store = createHilosAccountDeletionStore(props.context)
const flow = createHilosAccountDeletionFlow(props.context, store)
const state = useSignal(store.state)
const step = useSignal(flow.step)
const opening = useSignal(flow.opening)
const code = useSignal(flow.code)
const busy = useSignal(flow.busy)
const refusal = useSignal(flow.refusal)
const stepUpOpening = useSignal(flow.stepUp.opening)
const stepUpRefusal = useSignal(flow.stepUp.refusal)
const now = ref(Date.now())
const body = ref<HTMLElement | null>(null)
let tick: ReturnType<typeof setInterval> | null = null

// The step the window moves to takes the focus - the code field on step 2 -
// since the window stays and only its content changes.
watch(step, () => {
  void nextTick(() => {
    const dialog = body.value?.closest<HTMLElement>('[role="dialog"]')
    if (dialog) {
      focusInitial(dialog)
    }
  })
})

onMounted(() => {
  store.start()
  flow.follow()
  tick = setInterval(() => {
    now.value = Date.now()
  }, ACCOUNT_DELETION_TICK_MS)
})
onUnmounted(() => {
  if (tick !== null) {
    clearInterval(tick)
  }
  flow.dispose()
  store.dispose()
})

const deletion = computed(() => state.value?.deletion ?? null)
const open = computed({
  get: () => step.value !== 'closed' && step.value !== 'opening',
  set: (value: boolean) => {
    if (!value) {
      flow.close()
    }
  },
})
const stepNumber = computed(() => (step.value === 'code' ? 2 : 1))
const daysLeft = computed(() =>
  deletion.value === null
    ? ''
    : formatAccountDeletionDays(
        accountDeletionDaysLeft(deletion.value.effectiveAt, now.value),
      ),
)
const voice = computed(() =>
  step.value === 'step-up' ? stepUpRefusal.value : refusal.value,
)

function fill(template: string): string {
  const current = deletion.value

  return template
    .replace(
      '{graceDays}',
      formatAccountDeletionDays(opening.value?.graceDays ?? 0),
    )
    .replace('{destination}', opening.value?.destination ?? '')
    .replace('{days}', daysLeft.value)
    .replace(
      '{date}',
      current === null ? '' : formatCalendarDate(current.effectiveAt),
    )
    .replace(
      '{requestedDate}',
      current === null ? '' : formatCalendarDate(current.requestedAt),
    )
}
</script>

<template>
  <section class="mt-5" data-id="account-deletion">
    <div
      v-if="deletion !== null"
      class="alert alert-warning d-flex flex-wrap align-items-center gap-3"
      data-id="account-deletion-scheduled"
    >
      <div class="flex-grow-1">
        <div class="fw-semibold small mb-1">
          <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i
          >{{ COPY.scheduledTitle }}
        </div>
        <p class="small mb-0">{{ fill(COPY.scheduledText) }}</p>
      </div>
      <button
        type="button"
        class="btn btn-sm btn-outline-secondary"
        data-id="account-deletion-manage"
        @click="flow.open()"
      >
        {{ COPY.scheduledButton }}
      </button>
    </div>
    <div
      v-else
      class="border border-danger-subtle rounded bg-danger-subtle p-3"
    >
      <div class="fw-semibold small mb-1">
        <i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i
        >{{ COPY.zoneTitle }}
      </div>
      <p class="small text-body-secondary mb-3">{{ COPY.zoneText }}</p>
      <LoadingButton
        class="btn-sm btn-outline-danger"
        :loading="step === 'opening'"
        data-id="account-deletion-open"
        @click="flow.open()"
      >
        {{ COPY.zoneButton }}
      </LoadingButton>
    </div>

    <HilosModal
      v-model="open"
      :title="step === 'in-progress' ? COPY.progressTitle : COPY.title"
      initial-focus="inner"
    >
      <div
        class="visually-hidden"
        role="alert"
        aria-live="assertive"
        data-id="account-deletion-live"
      >
        {{ voice }}
      </div>
      <div ref="body" data-id="account-deletion-modal">
        <HilosStepUpStep v-if="step === 'step-up'" :controller="flow.stepUp" />

        <div
          v-else-if="step === 'in-progress' && deletion !== null"
          class="text-center py-2"
        >
          <i
            class="bi bi-hourglass-split text-danger d-block mb-2 fs-2"
            aria-hidden="true"
          ></i>
          <div class="fw-semibold mb-1">{{ fill(COPY.progressLead) }}</div>
          <p class="small text-body-secondary mb-0">
            {{ fill(COPY.progressText) }}
          </p>
        </div>

        <template v-else-if="step === 'explain' || step === 'code'">
          <ol
            class="list-unstyled d-flex flex-column gap-1 mb-3 small"
            data-id="account-deletion-steps"
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
                  index + 1 === stepNumber
                    ? 'text-bg-primary'
                    : 'text-bg-secondary'
                "
                >{{ index + 1 }}</span
              >
              <span>{{ label }}</span>
            </li>
          </ol>
          <template v-if="step === 'explain'">
            <div class="alert alert-danger small py-2 mb-3">
              <ul class="mb-0 ps-3">
                <li>{{ fill(COPY.explainErase) }}</li>
                <li>{{ COPY.explainProviders }}</li>
                <li>{{ COPY.explainSubscriptions }}</li>
              </ul>
            </div>
            <p class="small text-body-secondary mb-0">
              {{ COPY.explainChangeMind }}
            </p>
          </template>
          <form v-else @submit.prevent="flow.start()">
            <p class="small text-body-secondary mb-3">
              {{ fill(COPY.codeSent) }}
            </p>
            <label class="form-label" for="hilos-account-deletion-code">{{
              COPY.codeLabel
            }}</label>
            <input
              id="hilos-account-deletion-code"
              class="form-control"
              autocomplete="one-time-code"
              inputmode="numeric"
              data-id="account-deletion-code"
              data-autofocus
              :value="code"
              @input="flow.code.set(($event.target as HTMLInputElement).value)"
            />
          </form>
        </template>

        <HilosFormError
          v-if="step !== 'step-up'"
          :message="refusal"
          data-id="account-deletion-error"
        />
      </div>

      <template #actions="{ requestClose }">
        <template v-if="step === 'in-progress'">
          <button
            type="button"
            class="btn btn-outline-secondary"
            data-id="account-deletion-close"
            @click="requestClose"
          >
            {{ COPY.close }}
          </button>
          <LoadingButton
            class="btn-primary"
            :loading="busy"
            data-id="account-deletion-keep"
            @click="flow.cancel()"
          >
            {{ COPY.keep }}
          </LoadingButton>
        </template>
        <template v-else>
          <button
            type="button"
            class="btn btn-outline-secondary"
            data-id="account-deletion-cancel"
            @click="requestClose"
          >
            {{ COPY.cancel }}
          </button>
          <LoadingButton
            v-if="step === 'step-up' && stepUpOpening !== null"
            class="btn-primary"
            :loading="busy"
            data-id="account-deletion-confirm"
            @click="flow.confirmStepUp()"
          >
            {{ HILOS_STEP_UP_COPY.confirm }}
          </LoadingButton>
          <LoadingButton
            v-else-if="step === 'explain'"
            class="btn-danger"
            :loading="busy"
            data-autofocus
            data-id="account-deletion-continue"
            @click="flow.next()"
          >
            {{ opening?.channel == null ? COPY.start : COPY.continue }}
          </LoadingButton>
          <LoadingButton
            v-else-if="step === 'code'"
            class="btn-danger"
            :loading="busy"
            data-id="account-deletion-start"
            @click="flow.start()"
          >
            {{ COPY.start }}
          </LoadingButton>
        </template>
      </template>
    </HilosModal>
  </section>
</template>
