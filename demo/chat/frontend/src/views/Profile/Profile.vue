<!-- The profile root: account fields, catalog sections with live summaries, and the danger zone. -->
<script setup lang="ts">
import {
  computed,
  inject,
  nextTick,
  onMounted,
  onUnmounted,
  ref,
  watch,
} from 'vue'
import {
  createHilosSecondFactorStore,
  createHilosLegalAgreementsStore,
  createHilosDataExportStore,
  profileDataExportNode,
  describeHilosDataExport,
  describeHilosLegalAgreements,
  describeHilosNotificationChannels,
  describeHilosProfileSignInMethods,
  focusInitial,
  hilosChildLinks,
  hilosNotificationPreferences,
  hilosProfilePasswordState,
  HilosPages,
  sessionAuthMethods,
  startHilosNotificationPreferences,
  threeWayMerge,
  type HilosSecondFactorContext,
} from '@hilos/core'
import {
  ConflictActions,
  ConflictHeader,
  HilosAccountDeletion,
  HilosAvatar,
  HilosFormError,
  HilosLink,
  HilosModal,
  HilosPageHeading,
  HilosStepUpStep,
  LoadingButton,
  hilosRouterKey,
  useSignal,
} from '@hilos/vue'
import { actions, connection } from '../../bootstrap/connection.js'
import { currentUserId, scopes } from '../../bootstrap/session.js'
import {
  profileDeviceCount,
  profileSessionCount,
  profileSignInMethods,
} from '../../profile/profileLists.js'
import {
  clearRenameError,
  profileStepUp,
  renameError,
  sendRename,
} from './profileActions.js'
import { committedName, profileDetail } from './profilePage.js'
import { useEmailChange } from './useEmailChange.js'

defineOptions({ name: 'ProfilePage' })
const NAME_MIN = 2
const NAME_MAX = 64
const selfId = useSignal(currentUserId)
const isAuthenticated = computed(() => selfId.value !== null)
const detail = useSignal(profileDetail)
const committed = useSignal(committedName)
const error = useSignal(renameError)
const stepUpOpening = useSignal(profileStepUp.opening)
const stepUpRefusal = useSignal(profileStepUp.refusal)
const stepUpBusy = useSignal(profileStepUp.busy)
const methods = useSignal(profileSignInMethods)
const password = computed(() => hilosProfilePasswordState(methods.value))
const offered = useSignal(sessionAuthMethods(scopes))
const channels = useSignal(hilosNotificationPreferences.channels)
const sessionsCount = useSignal(profileSessionCount)
const devicesCount = useSignal(profileDeviceCount)
const deletionContext: HilosSecondFactorContext = {
  connection,
  scopes,
  actions,
}
const secondFactor = createHilosSecondFactorStore(deletionContext)
const securityState = useSignal(secondFactor.state)
const agreements = createHilosLegalAgreementsStore(deletionContext)
const agreementState = useSignal(agreements.state)
const dataExport = createHilosDataExportStore(
  connection,
  profileDataExportNode(scopes),
)
const dataExportState = useSignal(dataExport.state)
let stopAgreements: (() => void) | null = null
const router = inject(hilosRouterKey)
if (!router) throw new Error('Profile requires a provided Hilos router.')
const identity = useSignal(router.pageIdentity)
const route = useSignal(router.currentRoute)
const sections = computed(() =>
  hilosChildLinks(
    identity.value?.children ?? [],
    route.value.params,
    router.resolvePath,
  ),
)
function sectionId(page: string): string {
  return `profile-${page.replace('hilos_profile_', '').replaceAll('_', '-')}`
}
function sectionIcon(page: string): string {
  switch (page) {
    case HilosPages.PROFILE_SIGN_IN:
      return 'bi-key'
    case HilosPages.PROFILE_NOTIFICATIONS:
      return 'bi-bell'
    case HilosPages.PROFILE_SESSIONS:
      return 'bi-laptop'
    case HilosPages.PROFILE_DEVICES:
      return 'bi-broadcast'
    case HilosPages.PROFILE_AGREEMENTS:
      return 'bi-file-earmark-check'
    case HilosPages.PROFILE_DATA:
      return 'bi-download'
    case HilosPages.PROFILE_SECURITY:
      return 'bi-shield-lock'
    default:
      return 'bi-folder'
  }
}
function sectionSummary(page: string): string {
  switch (page) {
    case HilosPages.PROFILE_SIGN_IN:
      return describeHilosProfileSignInMethods(methods.value, offered.value)
    case HilosPages.PROFILE_NOTIFICATIONS:
      return describeHilosNotificationChannels(channels.value)
    case HilosPages.PROFILE_SESSIONS:
      return `${sessionsCount.value} active sign-ins`
    case HilosPages.PROFILE_DEVICES:
      return `${devicesCount.value} subscribed to push`
    case HilosPages.PROFILE_AGREEMENTS:
      return describeHilosLegalAgreements(agreementState.value)
    case HilosPages.PROFILE_DATA:
      return describeHilosDataExport(dataExportState.value)
    case HilosPages.PROFILE_SECURITY:
      return `Two-step verification is ${securityState.value?.authenticators.length ? 'on' : 'off'}`
    default:
      return ''
  }
}
let stopPreferences: (() => void) | null = null
onMounted(() => {
  stopPreferences = startHilosNotificationPreferences({ connection, scopes })
  secondFactor.start()
  stopAgreements = agreements.start()
  dataExport.start()
})
onUnmounted(() => {
  stopPreferences?.()
  secondFactor.dispose()
  stopAgreements?.()
  dataExport.dispose()
})

const editing = ref(false)
const renameReady = ref(false)
const renameBody = ref<HTMLElement | null>(null)
const draft = ref('')
// The committed name captured when the modal opened — the 3-way merge baseline.
const baseline = ref('')
const loading = ref(false)

const merge = computed(() =>
  threeWayMerge(baseline.value, draft.value.trim(), committed.value),
)
const conflict = computed(() => merge.value.conflict)
const dirty = computed(() => draft.value.trim() !== baseline.value)
const valid = computed(() => {
  const trimmed = draft.value.trim()

  return trimmed.length >= NAME_MIN && trimmed.length <= NAME_MAX
})

// Change the account email (HIL-299): one modal walks five steps - a code to the
// current address, that code, the new address, the code from it, the outcome. The
// step machine lives in useEmailChange; the Email row that opens it shows only when
// the account has a verified address, because without one there is no current
// mailbox to prove (adding an address is the add-password flow above, HIL-406).
const EMAIL_STEP_LABELS = [
  'Code to your current address',
  'Enter the code',
  'New address',
  'Code from the new address',
  'Done',
]
// The step that shows the outcome instead of a form.
const EMAIL_STEP_DONE = 5

const {
  open: changingEmail,
  step: emailStep,
  was: emailWas,
  currentCode: emailCurrentCode,
  newEmail: emailNew,
  newCode: emailNewCode,
  now: emailNow,
  error: emailError,
  loading: emailLoading,
  canSubmit: emailCanSubmit,
  asksBeforeClosing: emailAsksBeforeClosing,
  start: startEmailChange,
  submit: submitEmailStep,
  again: changeEmailAgain,
} = useEmailChange()
const emailBody = ref<HTMLElement | null>(null)

watch(emailStep, (step, previous) => {
  if (previous === 0 && step === 1) {
    focusStep(emailBody.value)
  }
})

/** Focus the field of a dialog step after its previous body has been replaced. */
function focusStep(body: HTMLElement | null): void {
  void nextTick(() => {
    const dialog = body?.closest<HTMLElement>('[role="dialog"]')
    if (dialog) {
      focusInitial(dialog)
    }
  })
}

async function openEdit(): Promise<void> {
  clearRenameError()
  baseline.value = committed.value
  draft.value = committed.value
  loading.value = false
  const outcome = await profileStepUp.open('change_name')
  renameReady.value = outcome === 'skip'
  editing.value = true
}

async function confirmRenameStepUp(): Promise<void> {
  if (await profileStepUp.confirm()) {
    renameReady.value = true
    focusStep(renameBody.value)
  }
}

function submit(): void {
  if (!valid.value || !dirty.value || conflict.value || loading.value) {
    return
  }

  loading.value = sendRename(draft.value.trim())
}

// Success is state-driven: while a submit is in flight, the rename has landed
// once the committed name reaches the draft. Outside the modal, keep the
// baseline synced so the next open starts fresh.
watch(committed, (name) => {
  if (loading.value && name === draft.value.trim()) {
    loading.value = false
    editing.value = false
  } else if (!editing.value) {
    baseline.value = name
    draft.value = name
  }
})

// A rejected rename arrives as a framework action_error: release the button and
// keep the modal open so the user can retry from their draft.
watch(error, (reason) => {
  if (reason !== null) {
    loading.value = false
  }
})

// Closing the modal clears the in-flight flag (the draft resets on the next open).
watch(editing, (open) => {
  if (!open) {
    loading.value = false
  }
})

// Conflict resolutions: each sets the baseline so the merge no longer conflicts.
function acceptMine(): void {
  baseline.value = committed.value
}

function acceptTheirs(): void {
  draft.value = committed.value
  baseline.value = committed.value
}

function mergeBoth(): void {
  const mine = draft.value.trim()
  const theirs = committed.value
  draft.value =
    mine !== '' && theirs !== '' && mine !== theirs
      ? `${mine} / ${theirs}`
      : mine || theirs
  baseline.value = committed.value
}
</script>

<template>
  <section v-if="isAuthenticated" data-id="profile-view">
    <HilosPageHeading />
    <div
      v-if="detail"
      class="d-flex align-items-center gap-3 mt-3"
      data-id="profile-identity"
    >
      <HilosAvatar :name="detail.name" size="lg" />
      <div class="flex-grow-1 text-break">
        <div class="h5 mb-0" data-id="profile-identity-name">
          {{ detail.name }}
        </div>
        <div
          v-if="password.verifiedEmail"
          class="small text-body-secondary"
          data-id="profile-identity-email"
        >
          {{ password.verifiedEmail }}
        </div>
      </div>
    </div>
    <h2 class="h6 text-uppercase text-body-secondary mt-4 mb-2">Account</h2>
    <div v-if="detail" data-id="profile-detail">
      <div class="d-flex align-items-center gap-3 py-3 border-bottom">
        <i class="bi bi-person fs-5 text-body-secondary" aria-hidden="true"></i>
        <div class="flex-grow-1 text-break">
          <div class="fw-semibold small">Name</div>
          <div class="small text-body-secondary" data-id="profile-name">
            {{ detail.name }}
          </div>
        </div>
        <LoadingButton
          class="btn-outline-secondary btn-sm"
          :loading="stepUpBusy"
          data-id="profile-edit"
          @click="openEdit"
          >Change</LoadingButton
        >
      </div>
      <div
        v-if="password.verifiedEmail"
        class="d-flex align-items-center gap-3 py-3 border-bottom"
      >
        <i
          class="bi bi-envelope fs-5 text-body-secondary"
          aria-hidden="true"
        ></i>
        <div class="flex-grow-1 text-break">
          <div class="fw-semibold small">Email</div>
          <div class="small text-body-secondary" data-id="profile-email">
            {{ password.verifiedEmail }} · verified
          </div>
        </div>
        <LoadingButton
          class="btn-outline-secondary btn-sm"
          :loading="emailLoading"
          data-id="profile-email-change"
          @click="startEmailChange(password.verifiedEmail)"
          >Change</LoadingButton
        >
      </div>
    </div>
    <p v-else class="text-body-secondary" data-id="profile-loading">
      Loading profile…
    </p>
    <section class="mt-4" aria-labelledby="profile-sections-heading">
      <h2
        id="profile-sections-heading"
        class="h6 text-uppercase text-body-secondary mb-2"
      >
        Sections
      </h2>
      <div
        v-for="section in sections"
        :key="section.page"
        class="d-flex align-items-center gap-3 py-3 border-bottom"
        data-id="profile-section"
      >
        <i
          class="bi fs-5 text-body-secondary"
          :class="sectionIcon(section.page)"
          aria-hidden="true"
        ></i>
        <div class="flex-grow-1 text-break">
          <div class="fw-semibold small">{{ section.label }}</div>
          <div
            class="small text-body-secondary"
            :data-id="`${sectionId(section.page)}-summary`"
          >
            {{ sectionSummary(section.page) }}
          </div>
        </div>
        <HilosLink
          :to="section.to"
          class="btn btn-sm btn-outline-secondary"
          :data-id="`${sectionId(section.page)}-open`"
          >Open</HilosLink
        >
      </div>
    </section>
    <HilosAccountDeletion :context="deletionContext" />

    <HilosModal
      v-model="editing"
      :confirm-on-close="renameReady && dirty"
      initial-focus="inner"
    >
      <template #header>
        <h2 v-if="!renameReady" class="modal-title h5 mb-0">
          Confirm it's you
        </h2>
        <ConflictHeader v-else title="Change name" :conflict="conflict" />
      </template>

      <!-- This dialog's own voice: the page region above is under the backdrop,
      and the dialog is aria-modal, so from inside here that region is not there
      to be read. -->
      <div
        class="visually-hidden"
        role="alert"
        aria-live="assertive"
        data-id="profile-rename-live-assertive"
      >
        {{ renameReady ? error : stepUpRefusal }}
      </div>

      <div ref="renameBody">
        <HilosStepUpStep v-if="!renameReady" :controller="profileStepUp" />
        <form v-else @submit.prevent="submit">
          <label class="form-label" for="profile-name-field"
            >Display name</label
          >
          <input
            id="profile-name-field"
            v-model="draft"
            type="text"
            class="form-control"
            data-autofocus
            data-id="profile-name-input"
            :minlength="NAME_MIN"
            :maxlength="NAME_MAX"
          />
          <div class="form-text">
            Between {{ NAME_MIN }} and {{ NAME_MAX }} characters.
          </div>
          <div
            v-if="conflict"
            class="alert alert-warning mt-2 mb-0"
            data-id="profile-conflict-note"
          >
            The name changed elsewhere to “{{ committed }}”. Choose how to
            resolve.
          </div>
          <HilosFormError :message="error" data-id="profile-rename-error" />
        </form>
      </div>

      <template #actions="{ requestClose }">
        <template v-if="!renameReady">
          <button
            type="button"
            class="btn btn-outline-secondary"
            data-id="profile-name-step-up-cancel"
            @click="requestClose"
          >
            Cancel
          </button>
          <LoadingButton
            v-if="stepUpOpening !== null"
            class="btn-primary"
            :loading="stepUpBusy"
            data-id="profile-name-step-up-confirm"
            @click="confirmRenameStepUp"
          >
            Confirm
          </LoadingButton>
        </template>
        <ConflictActions
          v-else
          :conflict="conflict"
          :disable-save="!valid || !dirty"
          @save="submit"
          @accept-mine="acceptMine"
          @accept-theirs="acceptTheirs"
          @merge="mergeBoth"
        >
          <template #save-button="{ disabled, onSave }">
            <LoadingButton
              class="btn-primary"
              :loading="loading"
              :disabled="disabled"
              data-id="profile-rename-save"
              @click="onSave"
            >
              Save
            </LoadingButton>
          </template>
        </ConflictActions>
      </template>
    </HilosModal>

    <!-- Change-email wizard (HIL-299): one modal, five steps, the content changing
    in place - never a second modal on top. Every step is one server-confirmed
    submit; a refusal stays on the step in the room HilosFormError holds for it.
    Closing on steps 2 to 4 asks first, because a code is already out; the address
    moves only on step 4, so a flow abandoned anywhere changes nothing. -->
    <HilosModal
      v-model="changingEmail"
      :title="
        emailStep === 0
          ? 'Confirm it\'s you'
          : emailStep === EMAIL_STEP_DONE
            ? 'Email changed'
            : 'Change your email'
      "
      :confirm-on-close="emailAsksBeforeClosing"
      initial-focus="inner"
    >
      <!-- This dialog's own voice, as for the two dialogs above; one region for
      all the steps, since only one step is on screen at a time. -->
      <div
        class="visually-hidden"
        role="alert"
        aria-live="assertive"
        data-id="profile-email-live-assertive"
      >
        {{ emailStep === 0 ? stepUpRefusal : emailError }}
      </div>

      <div ref="emailBody">
        <HilosStepUpStep v-if="emailStep === 0" :controller="profileStepUp" />
        <ol
          v-if="emailStep !== 0 && emailStep !== EMAIL_STEP_DONE"
          class="list-unstyled d-flex flex-column gap-1 mb-3 small"
          data-id="profile-email-steps"
        >
          <li
            v-for="(label, index) in EMAIL_STEP_LABELS"
            :key="label"
            class="d-flex align-items-center gap-2"
            :class="
              index + 1 === emailStep ? 'fw-semibold' : 'text-body-secondary'
            "
            :aria-current="index + 1 === emailStep ? 'step' : undefined"
          >
            <span
              class="badge rounded-pill"
              :class="
                index + 1 === emailStep
                  ? 'text-bg-primary'
                  : 'text-bg-secondary'
              "
              >{{ index + 1 }}</span
            >
            <span>{{ label }}</span>
          </li>
        </ol>

        <form v-if="emailStep === 1" @submit.prevent="submitEmailStep">
          <p class="small text-body-secondary mb-0">
            We will send a code to <strong>{{ emailWas }}</strong> to make sure
            it is you.
          </p>
          <HilosFormError :message="emailError" data-id="profile-email-error" />
        </form>

        <form v-else-if="emailStep === 2" @submit.prevent="submitEmailStep">
          <label class="form-label" for="profile-email-code-current"
            >Code</label
          >
          <input
            id="profile-email-code-current"
            v-model="emailCurrentCode"
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            class="form-control"
            data-autofocus
            data-id="profile-email-code-current"
          />
          <div class="form-text">Sent to {{ emailWas }}.</div>
          <HilosFormError :message="emailError" data-id="profile-email-error" />
        </form>

        <form v-else-if="emailStep === 3" @submit.prevent="submitEmailStep">
          <label class="form-label" for="profile-email-new">New email</label>
          <input
            id="profile-email-new"
            v-model="emailNew"
            type="email"
            autocomplete="email"
            class="form-control"
            data-autofocus
            data-id="profile-email-new"
          />
          <div class="form-text">
            A notice of the change will go to your old address.
          </div>
          <HilosFormError :message="emailError" data-id="profile-email-error" />
        </form>

        <form v-else-if="emailStep === 4" @submit.prevent="submitEmailStep">
          <label class="form-label" for="profile-email-code-new">Code</label>
          <input
            id="profile-email-code-new"
            v-model="emailNewCode"
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            class="form-control"
            data-autofocus
            data-id="profile-email-code-new"
          />
          <div class="form-text">Sent to {{ emailNew.trim() }}.</div>
          <HilosFormError :message="emailError" data-id="profile-email-error" />
        </form>

        <div
          v-else-if="emailStep === EMAIL_STEP_DONE"
          class="text-center py-2"
          data-id="profile-email-outcome"
        >
          <i
            class="bi bi-check-circle-fill text-success fs-2 d-block mb-2"
            aria-hidden="true"
          ></i>
          <div class="fw-semibold mb-1">Address changed</div>
          <p class="small text-body-secondary mb-0">
            Was <s data-id="profile-email-was">{{ emailWas }}</s
            >, now <strong data-id="profile-email-now">{{ emailNow }}</strong
            >. A notice went to both.
          </p>
        </div>
      </div>

      <template #actions="{ requestClose }">
        <template v-if="emailStep === 0">
          <button
            type="button"
            class="btn btn-outline-secondary"
            data-id="profile-email-cancel"
            @click="requestClose"
          >
            Cancel
          </button>
          <LoadingButton
            v-if="stepUpOpening !== null"
            class="btn-primary"
            :loading="emailLoading"
            data-id="profile-email-step-up-confirm"
            @click="submitEmailStep"
          >
            Confirm
          </LoadingButton>
        </template>
        <template v-else-if="emailStep !== EMAIL_STEP_DONE">
          <button
            type="button"
            class="btn btn-outline-secondary"
            data-id="profile-email-cancel"
            @click="requestClose"
          >
            Cancel
          </button>
          <LoadingButton
            v-if="emailStep === 1"
            class="btn-primary"
            :loading="emailLoading"
            data-autofocus
            data-id="profile-email-send-current"
            @click="submitEmailStep"
          >
            Send code
          </LoadingButton>
          <LoadingButton
            v-else-if="emailStep === 2"
            class="btn-primary"
            :loading="emailLoading"
            :disabled="!emailCanSubmit"
            data-id="profile-email-confirm-current"
            @click="submitEmailStep"
          >
            Continue
          </LoadingButton>
          <LoadingButton
            v-else-if="emailStep === 3"
            class="btn-primary"
            :loading="emailLoading"
            :disabled="!emailCanSubmit"
            data-id="profile-email-send-new"
            @click="submitEmailStep"
          >
            Send code
          </LoadingButton>
          <LoadingButton
            v-else
            class="btn-primary"
            :loading="emailLoading"
            :disabled="!emailCanSubmit"
            data-id="profile-email-confirm-new"
            @click="submitEmailStep"
          >
            Change email
          </LoadingButton>
        </template>
        <template v-else>
          <button
            type="button"
            class="btn btn-outline-secondary"
            data-id="profile-email-again"
            @click="changeEmailAgain"
          >
            Change again
          </button>
          <button
            type="button"
            class="btn btn-primary"
            data-autofocus
            data-id="profile-email-done"
            @click="requestClose"
          >
            Done
          </button>
        </template>
      </template>
    </HilosModal>
  </section>
  <!-- Anonymous, or the subscription reply hasn't landed yet: a placeholder, never
  page content. The framework auth-gate (HilosView) mounts the sign-in surface in
  place once the AUTHENTICATED guard answers 401 — the single owner of the form. -->
  <p v-else class="text-body-secondary" data-id="profile-loading">
    Loading profile…
  </p>
</template>
