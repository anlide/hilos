<!-- HilosProfileSecurityPage — the profile's security page
(HilosPages.PROFILE_SECURITY, /profile/security, HIL-494): two-step
verification for the person signed in. The connected authenticator apps, each
with Remove; the backup codes left of the set, with Show; Add an app; and "If
you lose access" — the removal wait and the delayed removal itself. Every
mutation is a modal (the modal-only editing rule), and every one but the first
connection, the wait and the removal starts with a code from an app or a backup
code: a stolen live session must not strip or copy the factor.
The section, its live copy and the actions are the core's
(createHilosSecondFactorStore / createHilosSecondFactorActions); this view owns
only the markup. The page is drawn from text — the mockup's node is a debt
(D-113). Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosSecondFactorActions,
  createHilosSecondFactorStore,
  focusInitial,
  formatCalendarDate,
  type HilosBackupCodeEntry,
  type HilosSecondFactorAuthenticator,
  type HilosSecondFactorContext,
  type HilosSecondFactorEnrollment,
  type HilosSecondFactorProof,
} from '@hilos/core'
import {
  computed,
  nextTick,
  onMounted,
  onUnmounted,
  ref,
  watch,
  type Ref,
} from 'vue'

import HilosActionError from '../HilosActionError.vue'
import HilosBackupCodes from '../HilosBackupCodes.vue'
import HilosModal from '../HilosModal.vue'
import HilosQrCode from '../HilosQrCode.vue'
import LoadingButton from '../LoadingButton.vue'
import { useSignal } from '../useSignal.js'
import { useTrackedAction } from '../useTrackedAction.js'

defineOptions({ name: 'HilosProfileSecurityPage' })

const props = defineProps<{
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosSecondFactorContext
}>()

/** One day in ms — the unit of the removal wait. */
const DAY_MS = 86_400_000

const store = createHilosSecondFactorStore(props.context)
const actions = createHilosSecondFactorActions(props.context)

onMounted(() => store.start())
onUnmounted(() => store.dispose())

const state = useSignal(store.state)
const apps = computed(() => state.value?.authenticators ?? [])
const factorOn = computed(() => apps.value.length > 0)
/** The last app of a factor an administrator requires cannot be removed. */
const removalLocked = computed(
  () => (state.value?.required ?? false) && apps.value.length === 1,
)

/**
 * A moment as the day it falls on.
 *
 * @param moment The LOCAL epoch-ms moment.
 */
function day(moment: number): string {
  return formatCalendarDate(moment)
}

/**
 * Put focus on the field of the step a modal has just moved to, or on the
 * dialog when the step has none: the field that held it is gone, and the modal
 * places focus only when it opens.
 *
 * @param form The modal's form, whose dialog is searched once the step is drawn.
 */
function focusStep(form: Ref<HTMLFormElement | null>): void {
  void nextTick(() => {
    const dialog = form.value?.closest<HTMLElement>('[role="dialog"]')
    if (dialog) {
      focusInitial(dialog)
    }
  })
}

// ---- The proof a modal starts with: a code from an app, or a backup code. ----
const proofCode = ref('')
const proofBackup = ref(false)

function resetProof(): void {
  proofCode.value = ''
  proofBackup.value = false
}

function proof(): HilosSecondFactorProof {
  return { code: proofCode.value.trim(), backupCode: proofBackup.value }
}

// ---- Connect an app: name → (a code, when one is connected) → scan and confirm
// → the backup codes, for the first app only → "connect another?" ----
type EnrollStep = 'name' | 'proof' | 'scan' | 'codes' | 'more'
const enrollOpen = ref(false)
const enrollStep = ref<EnrollStep>('name')
const enrollLabel = ref('')
const enrollCode = ref('')
const enrollment = ref<HilosSecondFactorEnrollment | null>(null)
const issuedCodes = ref<readonly string[]>([])
const issuedSaved = ref(false)
const enrollAction = useTrackedAction()
const enrollForm = ref<HTMLFormElement | null>(null)
watch(enrollStep, () => focusStep(enrollForm))
/** Issued codes not yet marked saved: closing asks first, they are shown once. */
const enrollCodesUnsaved = computed(
  () => enrollStep.value === 'codes' && !issuedSaved.value,
)

function openEnroll(): void {
  enrollAction.clearError()
  resetProof()
  enrollLabel.value = ''
  enrollCode.value = ''
  enrollment.value = null
  issuedCodes.value = []
  issuedSaved.value = false
  enrollStep.value = 'name'
  enrollOpen.value = true
}

async function enrollNext(): Promise<void> {
  if (enrollStep.value === 'name' && factorOn.value) {
    enrollStep.value = 'proof'

    return
  }
  const handle = actions.enrollStart(factorOn.value ? proof() : null)
  if (await enrollAction.run(handle)) {
    enrollment.value = (await handle.done).reply ?? null
    enrollStep.value = 'scan'
  }
}

async function enrollConfirm(): Promise<void> {
  const started = enrollment.value
  if (started === null) {
    return
  }
  const handle = actions.enrollConfirm(
    started.authenticatorId,
    enrollCode.value.trim(),
    enrollLabel.value.trim(),
  )
  if (await enrollAction.run(handle)) {
    const codes = (await handle.done).reply?.backupCodes
    if (codes !== undefined) {
      issuedCodes.value = codes
      enrollStep.value = 'codes'

      return
    }
    enrollStep.value = 'more'
  }
}

const enrollSubmitLabel = computed(
  () =>
    ({
      name: 'Next',
      proof: 'Next',
      scan: 'Connect',
      codes: 'Done',
      more: 'Connect another',
    })[enrollStep.value],
)

const enrollSubmitDisabled = computed(() => {
  switch (enrollStep.value) {
    case 'proof':
      return proofCode.value.trim() === ''
    case 'scan':
      return enrollCode.value.trim() === ''
    case 'codes':
      return !issuedSaved.value
    default:
      return false
  }
})

function enrollSubmit(): void {
  // Enter submits the form past the disabled button, so the guard is here too.
  if (enrollAction.busy.value || enrollSubmitDisabled.value) {
    return
  }
  switch (enrollStep.value) {
    case 'name':
    case 'proof':
      void enrollNext()

      return
    case 'scan':
      void enrollConfirm()

      return
    case 'codes':
      enrollStep.value = 'more'

      return
    case 'more':
      openEnroll()
  }
}

// ---- Show the backup codes, and issue a new set. ----
type CodesStep = 'proof' | 'list' | 'renew' | 'new'
const codesOpen = ref(false)
const codesStep = ref<CodesStep>('proof')
const listedCodes = ref<readonly HilosBackupCodeEntry[]>([])
const renewedCodes = ref<readonly string[]>([])
const renewedSaved = ref(false)
const codesAction = useTrackedAction()
const codesForm = ref<HTMLFormElement | null>(null)
watch(codesStep, () => focusStep(codesForm))
// One tracked action runs both steps that can be refused, so the refusal
// details name the one that was: showing the codes, or issuing new ones.
const codesRefusalTitle = computed(() =>
  codesStep.value === 'renew'
    ? "Couldn't create new backup codes"
    : "Couldn't show the backup codes",
)
/** A new set not yet marked saved: closing asks first, it is shown once. */
const renewedUnsaved = computed(
  () => codesStep.value === 'new' && !renewedSaved.value,
)

function openCodes(): void {
  codesAction.clearError()
  resetProof()
  listedCodes.value = []
  renewedCodes.value = []
  renewedSaved.value = false
  codesStep.value = 'proof'
  codesOpen.value = true
}

async function codesSubmit(): Promise<void> {
  if (codesAction.busy.value || codesSubmitDisabled.value) {
    return
  }
  if (codesStep.value === 'list') {
    // A new set asks for the NEXT code: the one that opened the list is spent.
    resetProof()
    codesAction.clearError()
    codesStep.value = 'renew'

    return
  }
  if (codesStep.value === 'new') {
    codesOpen.value = false

    return
  }
  if (codesStep.value === 'proof') {
    const handle = actions.showCodes(proof())
    if (await codesAction.run(handle)) {
      listedCodes.value = (await handle.done).reply?.codes ?? []
      codesStep.value = 'list'
    }

    return
  }
  const handle = actions.renewCodes(proof())
  if (await codesAction.run(handle)) {
    renewedCodes.value = (await handle.done).reply?.backupCodes ?? []
    codesStep.value = 'new'
  }
}

const codesSubmitLabel = computed(
  () =>
    ({ proof: 'Show', list: 'Issue new codes', renew: 'Issue', new: 'Done' })[
      codesStep.value
    ],
)

const codesSubmitDisabled = computed(() =>
  codesStep.value === 'new'
    ? !renewedSaved.value
    : codesStep.value !== 'list' && proofCode.value.trim() === '',
)

// ---- Remove an app. ----
const removeOpen = ref(false)
const removeTarget = ref<HilosSecondFactorAuthenticator | null>(null)
const removeAction = useTrackedAction()
const removingLast = computed(() => apps.value.length === 1)
const removeSubmitDisabled = computed(() => proofCode.value.trim() === '')

function openRemove(app: HilosSecondFactorAuthenticator): void {
  removeAction.clearError()
  resetProof()
  removeTarget.value = app
  removeOpen.value = true
}

async function removeSubmit(): Promise<void> {
  const target = removeTarget.value
  if (
    target === null ||
    removeAction.busy.value ||
    removeSubmitDisabled.value
  ) {
    return
  }
  if (await removeAction.run(actions.remove(target.id, proof()))) {
    removeOpen.value = false
  }
}

// ---- The removal wait. ----
const waitOpen = ref(false)
const waitDays = ref('')
const waitAction = useTrackedAction()
/** The wait that will hold: a shorter one chosen and not yet in force wins. */
const currentWait = computed(
  () => state.value?.resetWait.pendingDays ?? state.value?.resetWait.days ?? 0,
)
const waitChosen = computed(() => Number.parseInt(waitDays.value, 10))
const waitSubmitDisabled = computed(
  () =>
    Number.isNaN(waitChosen.value) || waitChosen.value === currentWait.value,
)
/** A shorter wait waits out the wait in force first; the modal names that day. */
const waitTakesEffect = computed(() => {
  const wait = state.value?.resetWait
  if (wait === undefined || Number.isNaN(waitChosen.value)) {
    return null
  }

  return waitChosen.value < wait.days ? Date.now() + wait.days * DAY_MS : null
})

function openWait(): void {
  waitAction.clearError()
  waitDays.value = String(currentWait.value)
  waitOpen.value = true
}

async function waitSubmit(): Promise<void> {
  if (waitAction.busy.value || waitSubmitDisabled.value) {
    return
  }
  if (await waitAction.run(actions.setResetWait(waitChosen.value))) {
    waitOpen.value = false
  }
}

// ---- The delayed removal. ----
const resetOpen = ref(false)
const resetAction = useTrackedAction()
const resetCancelAction = useTrackedAction()
const resetDate = computed(() =>
  day(Date.now() + (state.value?.resetWait.days ?? 0) * DAY_MS),
)

function openReset(): void {
  resetAction.clearError()
  resetOpen.value = true
}

async function resetSubmit(): Promise<void> {
  if (resetAction.busy.value) {
    return
  }
  if (await resetAction.run(actions.requestReset())) {
    resetOpen.value = false
  }
}

function cancelReset(): void {
  void resetCancelAction.run(actions.cancelReset())
}
</script>

<template>
  <section data-id="profile-security" class="mx-auto py-3">
    <h1 class="h4 mb-4">Security</h1>

    <div v-if="state === null" class="text-body-secondary" role="status">
      Loading…
    </div>
    <template v-else>
      <h2 class="h6 text-uppercase text-body-secondary mb-2">
        Two-step verification
      </h2>
      <p
        v-if="state.required"
        class="small mb-2"
        data-id="profile-2fa-required"
      >
        Your administrator requires two-step verification.
      </p>
      <ul class="list-group mb-3" data-id="profile-2fa-apps">
        <li
          v-for="app in apps"
          :key="app.id"
          class="list-group-item d-flex align-items-center gap-3"
          :data-id="`profile-2fa-app-${app.id}`"
        >
          <i class="bi bi-phone-vibrate fs-5" aria-hidden="true" />
          <div class="flex-grow-1">
            <div class="fw-semibold">{{ app.label }}</div>
            <div class="small text-body-secondary">
              Connected {{ day(app.createdAt) }}
            </div>
            <div
              v-if="removalLocked"
              class="small text-body-secondary"
              data-id="profile-2fa-remove-locked"
            >
              Your administrator requires two-step verification, so the last app
              stays.
            </div>
          </div>
          <button
            type="button"
            class="btn btn-sm btn-outline-danger"
            :disabled="removalLocked"
            :data-id="`profile-2fa-remove-${app.id}`"
            @click="openRemove(app)"
          >
            Remove
          </button>
        </li>
        <li
          v-if="factorOn"
          class="list-group-item d-flex align-items-center gap-3"
        >
          <i class="bi bi-key fs-5" aria-hidden="true" />
          <div class="flex-grow-1">
            <div class="fw-semibold">Backup codes</div>
            <div
              class="small text-body-secondary"
              data-id="profile-2fa-codes-left"
            >
              {{ state.backupCodesLeft }} of {{ state.backupCodesTotal }} left
            </div>
          </div>
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            data-id="profile-2fa-codes-show"
            @click="openCodes()"
          >
            Show
          </button>
        </li>
        <li
          v-if="!factorOn"
          class="list-group-item text-body-secondary small"
          data-id="profile-2fa-off"
        >
          Two-step verification is off. Connect an authenticator app to turn it
          on.
        </li>
      </ul>
      <button
        type="button"
        class="btn btn-sm btn-outline-primary mb-4"
        data-id="profile-2fa-add"
        @click="openEnroll()"
      >
        <i class="bi bi-plus-lg me-1" aria-hidden="true" />
        Add an authenticator app
      </button>

      <h2 class="h6 text-uppercase text-body-secondary mb-2">
        If you lose access
      </h2>
      <ul class="list-group mb-3">
        <li class="list-group-item d-flex align-items-center gap-3">
          <i class="bi bi-hourglass-split fs-5" aria-hidden="true" />
          <div class="flex-grow-1">
            <div class="fw-semibold">Wait before removal</div>
            <div class="small text-body-secondary" data-id="profile-2fa-wait">
              {{ state.resetWait.days }} days
              <template v-if="state.resetWait.pendingDays !== null">
                · {{ state.resetWait.pendingDays }} days from
                {{ day(state.resetWait.pendingFrom ?? Date.now()) }}
              </template>
            </div>
          </div>
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            data-id="profile-2fa-wait-edit"
            @click="openWait()"
          >
            Change
          </button>
        </li>
      </ul>
      <div
        v-if="state.reset !== null"
        class="alert alert-warning d-flex align-items-center gap-3"
        data-id="profile-2fa-reset-pending"
      >
        <span class="flex-grow-1">
          Two-step verification will be removed on
          <strong>{{ day(state.reset.effectiveAt) }}</strong
          >.
        </span>
        <LoadingButton
          class="btn-sm btn-outline-secondary"
          :loading="resetCancelAction.loading.value"
          :disabled="resetCancelAction.busy.value"
          data-id="profile-2fa-reset-cancel"
          @click="cancelReset()"
        >
          Cancel
        </LoadingButton>
      </div>
      <button
        v-else-if="factorOn"
        type="button"
        class="btn btn-sm btn-outline-danger mb-3"
        data-id="profile-2fa-reset-request"
        @click="openReset()"
      >
        Request removal
      </button>
      <HilosActionError
        :action="resetCancelAction"
        details-title="Couldn't cancel the removal"
      />
      <p class="small text-body-secondary mb-0">
        While a removal waits, every channel you have is told about it, and any
        of those messages stops it. A longer wait makes the account harder to
        take — and makes you wait longer if your phone is really gone. A shorter
        wait takes effect only after the wait in force.
      </p>
    </template>

    <!-- Connect an app. -->
    <HilosModal
      v-model="enrollOpen"
      title="Add an authenticator app"
      :confirm-on-close="enrollCodesUnsaved"
      confirm-title="Close before saving the codes?"
      confirm-message="You have not marked these codes as saved. You can still show them later with a code from your app."
      confirm-ok-text="Close"
      confirm-cancel-text="Back to the codes"
    >
      <HilosActionError
        :action="enrollAction"
        details-title="Couldn't add the authenticator app"
      />
      <form
        ref="enrollForm"
        data-id="profile-2fa-enroll"
        @submit.prevent="enrollSubmit()"
      >
        <template v-if="enrollStep === 'name'">
          <label class="form-label" for="profile-2fa-enroll-label"
            >Name of this app</label
          >
          <input
            id="profile-2fa-enroll-label"
            v-model="enrollLabel"
            type="text"
            class="form-control"
            maxlength="64"
            placeholder="Authenticator app"
            data-id="profile-2fa-enroll-label"
            data-autofocus
          />
        </template>
        <template v-else-if="enrollStep === 'proof'">
          <p class="small">
            Enter a code from an app you already connected, or a backup code.
          </p>
          <input
            v-model="proofCode"
            type="text"
            class="form-control mb-2"
            autocomplete="one-time-code"
            aria-label="Code"
            data-id="profile-2fa-proof"
            data-autofocus
          />
          <div class="form-check">
            <input
              id="profile-2fa-enroll-backup"
              v-model="proofBackup"
              class="form-check-input"
              type="checkbox"
            />
            <label
              class="form-check-label small"
              for="profile-2fa-enroll-backup"
              >This is a backup code</label
            >
          </div>
        </template>
        <template v-else-if="enrollStep === 'scan' && enrollment !== null">
          <p class="small">
            Scan this code with your authenticator app, then enter the code the
            app shows.
          </p>
          <HilosQrCode
            :text="enrollment.otpauthUri"
            label="QR code for your authenticator app"
            class="mb-2"
          />
          <p
            class="font-monospace small text-center text-break"
            data-id="profile-2fa-enroll-secret"
          >
            {{ enrollment.secret }}
          </p>
          <input
            v-model="enrollCode"
            type="text"
            inputmode="numeric"
            class="form-control"
            autocomplete="one-time-code"
            aria-label="Code"
            data-id="profile-2fa-enroll-code"
            data-autofocus
          />
        </template>
        <template v-else-if="enrollStep === 'codes'">
          <p class="small">
            Keep these codes somewhere safe. Each one signs you in once if you
            lose your authenticator app.
          </p>
          <HilosBackupCodes
            :codes="issuedCodes"
            :saved="issuedSaved"
            @update:saved="issuedSaved = $event"
          />
        </template>
        <p v-else class="small mb-0" data-id="profile-2fa-enroll-more">
          The app is connected. A second app is the quickest way back in if you
          lose this one — connect another now?
        </p>
      </form>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="enrollAction.busy.value"
          @click="requestClose"
        >
          {{ enrollStep === 'more' ? 'Not now' : 'Cancel' }}
        </button>
        <LoadingButton
          class="btn-primary"
          :loading="enrollAction.loading.value"
          :disabled="enrollAction.busy.value || enrollSubmitDisabled"
          data-id="profile-2fa-enroll-submit"
          @click="enrollSubmit()"
        >
          {{ enrollSubmitLabel }}
        </LoadingButton>
      </template>
    </HilosModal>

    <!-- Show the backup codes. -->
    <HilosModal
      v-model="codesOpen"
      title="Backup codes"
      :confirm-on-close="renewedUnsaved"
      confirm-title="Close before saving the codes?"
      confirm-message="You have not marked the new codes as saved, and the old ones no longer work. You can still show them later with a code from your app."
      confirm-ok-text="Close"
      confirm-cancel-text="Back to the codes"
    >
      <HilosActionError
        :action="codesAction"
        :details-title="codesRefusalTitle"
      />
      <form
        ref="codesForm"
        data-id="profile-2fa-codes"
        @submit.prevent="codesSubmit()"
      >
        <template v-if="codesStep === 'proof' || codesStep === 'renew'">
          <p class="small">
            {{
              codesStep === 'renew'
                ? 'Enter the next code from your app, or a backup code. The old codes stop working.'
                : 'Enter a code from your app, or a backup code.'
            }}
          </p>
          <input
            v-model="proofCode"
            type="text"
            class="form-control mb-2"
            autocomplete="one-time-code"
            aria-label="Code"
            data-id="profile-2fa-proof"
            data-autofocus
          />
          <div class="form-check">
            <input
              id="profile-2fa-codes-backup"
              v-model="proofBackup"
              class="form-check-input"
              type="checkbox"
            />
            <label class="form-check-label small" for="profile-2fa-codes-backup"
              >This is a backup code</label
            >
          </div>
        </template>
        <ul
          v-else-if="codesStep === 'list'"
          class="list-unstyled row row-cols-2 g-2 font-monospace mb-0"
          data-id="profile-2fa-codes-list"
        >
          <li
            v-for="entry in listedCodes"
            :key="entry.code"
            class="col text-center"
          >
            <span
              class="d-block border rounded py-1"
              :class="{
                'text-decoration-line-through text-body-secondary': entry.used,
              }"
              >{{ entry.code
              }}<span v-if="entry.used" class="visually-hidden">
                (used)</span
              ></span
            >
          </li>
        </ul>
        <HilosBackupCodes
          v-else
          :codes="renewedCodes"
          :saved="renewedSaved"
          @update:saved="renewedSaved = $event"
        />
      </form>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="codesAction.busy.value"
          @click="requestClose"
        >
          Close
        </button>
        <LoadingButton
          class="btn-primary"
          :loading="codesAction.loading.value"
          :disabled="codesAction.busy.value || codesSubmitDisabled"
          data-id="profile-2fa-codes-submit"
          @click="codesSubmit()"
        >
          {{ codesSubmitLabel }}
        </LoadingButton>
      </template>
    </HilosModal>

    <!-- Remove an app. -->
    <HilosModal
      v-model="removeOpen"
      :title="removeTarget ? `Remove ${removeTarget.label}` : 'Remove app'"
    >
      <HilosActionError
        :action="removeAction"
        details-title="Couldn't remove the app"
      />
      <form data-id="profile-2fa-remove" @submit.prevent="removeSubmit()">
        <p v-if="removingLast" class="small" data-id="profile-2fa-remove-last">
          This is your last app: two-step verification turns off, your backup
          codes and trusted devices stop working, and a removal that waits is
          dropped.
        </p>
        <p class="small">Enter a code from your app, or a backup code.</p>
        <input
          v-model="proofCode"
          type="text"
          class="form-control mb-2"
          autocomplete="one-time-code"
          aria-label="Code"
          data-id="profile-2fa-proof"
          data-autofocus
        />
        <div class="form-check">
          <input
            id="profile-2fa-remove-backup"
            v-model="proofBackup"
            class="form-check-input"
            type="checkbox"
          />
          <label class="form-check-label small" for="profile-2fa-remove-backup"
            >This is a backup code</label
          >
        </div>
      </form>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="removeAction.busy.value"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          class="btn-danger"
          :loading="removeAction.loading.value"
          :disabled="removeAction.busy.value || removeSubmitDisabled"
          data-id="profile-2fa-remove-submit"
          @click="removeSubmit()"
        >
          Remove
        </LoadingButton>
      </template>
    </HilosModal>

    <!-- The removal wait. -->
    <HilosModal v-model="waitOpen" title="Wait before removal">
      <HilosActionError
        :action="waitAction"
        details-title="Couldn't change the wait"
      />
      <form data-id="profile-2fa-wait-form" @submit.prevent="waitSubmit()">
        <label class="form-label" for="profile-2fa-wait-days">Days</label>
        <input
          id="profile-2fa-wait-days"
          v-model="waitDays"
          type="number"
          inputmode="numeric"
          class="form-control"
          :min="state?.resetWait.minDays"
          :max="state?.resetWait.maxDays"
          data-id="profile-2fa-wait-days"
          data-autofocus
        />
        <p class="form-text mb-0">
          From {{ state?.resetWait.minDays }} to
          {{ state?.resetWait.maxDays }} days.
          <template v-if="waitTakesEffect !== null">
            A shorter wait takes effect on {{ day(waitTakesEffect) }}.
          </template>
        </p>
      </form>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="waitAction.busy.value"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          class="btn-primary"
          :loading="waitAction.loading.value"
          :disabled="waitAction.busy.value || waitSubmitDisabled"
          data-id="profile-2fa-wait-save"
          @click="waitSubmit()"
        >
          Save
        </LoadingButton>
      </template>
    </HilosModal>

    <!-- Ask the delayed removal. -->
    <HilosModal
      v-model="resetOpen"
      title="Request removal"
      initial-focus="dialog"
    >
      <HilosActionError
        :action="resetAction"
        details-title="Couldn't request the removal"
      />
      <p class="small" data-id="profile-2fa-reset-date">
        Two-step verification will be removed on <strong>{{ resetDate }}</strong
        >.
      </p>
      <p class="small mb-0">
        We tell you at once and then every day, on every channel you have —
        email, text message, push and the bell in the app — and each message
        lets you cancel.
      </p>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="resetAction.busy.value"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          class="btn-danger"
          :loading="resetAction.loading.value"
          :disabled="resetAction.busy.value"
          data-id="profile-2fa-reset-submit"
          @click="resetSubmit()"
        >
          Request removal
        </LoadingButton>
      </template>
    </HilosModal>
  </section>
</template>
