<!-- The current user's profile page (HilosPages.PROFILE): shows the signed-in
user and edits the display name in a modal (edit-in-modal is a hard Hilos rule).
The committed name comes from the live self-connection data; the modal diffs a
draft against it through the core threeWayMerge, so a concurrent rename from
another tab surfaces as a conflict. Submit blocks the button (LoadingButton)
until the backend lands the rename (the committed name reaches the draft) or
rejects it (a framework action_error shown in the modal). Bootstrap classes
only, no CSS of its own (styling-rules.md). -->
<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'

import { hilosToasts, isPasskeySupported, threeWayMerge } from '@hilos/core'
import {
  ConflictActions,
  ConflictHeader,
  HilosModal,
  HilosNotificationPreferences,
  LoadingButton,
  useSignal,
} from '@hilos/vue'

import { describeOAuthError, startOAuthLink } from '../../auth/oauthLogin'
import { runPasskeyRegister } from '../../auth/passkeyCeremony'
import { connection } from '../../bootstrap/connection'
import { currentUserId } from '../../bootstrap/session'
import {
  clearRenameError,
  clearSetPasswordError,
  renameError,
  sendAddPasswordConfirm,
  sendAddPasswordRequest,
  sendAddSmsConfirm,
  sendAddSmsRequest,
  sendRename,
  sendSetPassword,
  sendUnlinkIdentity,
  setPasswordError,
  subscribePasswordUpdated,
  unlinkIdentityError,
} from './profileActions'
import {
  availableProviders,
  bindNotificationPreferences,
  committedName,
  passwordSection,
  profileDetail,
  profileIdentities,
} from './profilePage'
import { type IdentityItem } from './types/lists/IdentityItem'
import { PASSWORD_MODE_ADDED } from '../../auth/passwordSignals'

defineOptions({ name: 'ProfilePage' })

const NAME_MIN = 2
const NAME_MAX = 64

// A passkey row reads by DEVICE, not by registry value (HIL-418): a person
// recognizes their laptop, where the credential id means nothing to anyone. The
// name comes from the credential sidecar; a key whose browser was unrecognized
// keeps the generic title, which is still truer than an opaque string.
const PASSKEY_IDENTITY_TYPE = 'passkey'
const PASSKEY_FALLBACK_NAME = 'Passkey'
// The `YYYY-MM-DD` head of an SQL datetime — the part that is a date.
const SQL_DATE_LENGTH = 10

// The profile is a signed-in-only surface. The backend AUTHENTICATED page guard
// 401s an anonymous subscribe, and the framework auth-gate (HilosView) mounts the
// project sign-in surface in place off that 401 — a single owner of the sign-in
// form. This view must NOT render its own sign-in surface up front: that mounts a
// second AuthSurface instance which the gate's 401 then swaps out, resetting any
// in-progress input. Until the subscription answers (a snapshot or the 401), an
// anonymous session shows only a placeholder, never page content; the gate draws
// the form. Registering/logging in flips the session user id, the guard starts
// passing, and the preserved subscription resumes into the profile card.
const selfId = useSignal(currentUserId)
const isAuthenticated = computed(() => selfId.value !== null)

const detail = useSignal(profileDetail)
const committed = useSignal(committedName)
const identities = useSignal(profileIdentities)
const error = useSignal(renameError)
const unlinkError = useSignal(unlinkIdentityError)

// Link an account (HIL-401): the configured OAuth providers not yet attached to
// this account. Linking is the redirect start pattern — the button dispatches
// `link_oauth_start` and the browser leaves for the provider off the authorize
// signal — so a started button stays loading through the redirect and only a
// rejection clears it with an inline error. The provider drops off `providers`
// (and its button vanishes) once the link lands and the identity list re-emits.
const providers = useSignal(availableProviders)

/**
 * The heading of one login-method row: the passkey's device name, else the OAuth
 * provider, else the method itself.
 *
 * @param identity The row being rendered.
 */
function identityTitle(identity: IdentityItem): string {
  if (identity.type === PASSKEY_IDENTITY_TYPE) {
    return identity.deviceName ?? PASSKEY_FALLBACK_NAME
  }

  return identity.provider ?? identity.type
}

/**
 * The sub-line of a passkey row: what it is and when it was added.
 *
 * The date half of the SQL stamp is shown AS WRITTEN. The stamp carries no zone,
 * so parsing it into a Date would silently read the server's clock as the
 * browser's and could move the day by one — a guess dressed up as a locale.
 *
 * @param identity The passkey row being rendered.
 */
function passkeySubtitle(identity: IdentityItem): string {
  if (identity.addedAt === null) {
    return PASSKEY_FALLBACK_NAME
  }

  return `${PASSKEY_FALLBACK_NAME} · added ${identity.addedAt.slice(0, SQL_DATE_LENGTH)}`
}
const linkPendingKey = ref<string | null>(null)
const linkError = ref<string | null>(null)

async function linkProvider(key: string): Promise<void> {
  if (linkPendingKey.value !== null) {
    return
  }
  linkPendingKey.value = key
  linkError.value = null
  try {
    await startOAuthLink(key)
    // Accepted, working: the browser is navigated to the provider off the
    // authorize signal, so the button intentionally stays loading through it.
  } catch (caught) {
    linkPendingKey.value = null
    linkError.value = describeOAuthError(caught)
  }
}

// Unlink is server-confirmed, one row at a time: `unlinkConfirmKey` is the row
// showing its inline "remove?" confirm, `unlinkLoadingKey` the row whose delete
// is in flight. The row vanishes when the identities projection re-emits without
// it (success); a rejection arrives as an action_error.
const unlinkConfirmKey = ref<string | null>(null)
const unlinkLoadingKey = ref<string | null>(null)

function askUnlink(key: string): void {
  unlinkConfirmKey.value = key
}

function cancelUnlink(): void {
  unlinkConfirmKey.value = null
}

function confirmUnlink(key: string): void {
  if (unlinkLoadingKey.value !== null) {
    return
  }
  unlinkLoadingKey.value = sendUnlinkIdentity(Number(key)) ? key : null
}

// A rejected unlink arrives as a framework action_error: release the row spinner
// and keep the confirm open so the user can retry.
watch(unlinkError, (reason) => {
  if (reason !== null) {
    unlinkLoadingKey.value = null
  }
})

// When the list changes (a successful unlink removed the row), drop confirm and
// loading state that no longer maps to a present identity.
watch(identities, (list) => {
  const keys = new Set(list.map((identity) => identity.key))
  if (unlinkConfirmKey.value !== null && !keys.has(unlinkConfirmKey.value)) {
    unlinkConfirmKey.value = null
  }
  if (unlinkLoadingKey.value !== null && !keys.has(unlinkLoadingKey.value)) {
    unlinkLoadingKey.value = null
  }
})

const editing = ref(false)
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

// Add-a-passkey (HIL-284): the register ceremony runs in the profile because a
// passkey is enrolled for the already-signed-in user. The button blocks while the
// WebAuthn round-trip runs; a clean outcome shows a success note (the credential
// list refresh is HIL-404), a failure shows the specific reason inline.
const passkeySupported = isPasskeySupported()
const passkeyPending = ref(false)
const passkeyError = ref<string | null>(null)

async function addPasskey(): Promise<void> {
  if (passkeyPending.value) {
    return
  }
  passkeyPending.value = true
  passkeyError.value = null
  const outcome = await runPasskeyRegister()
  passkeyPending.value = false
  if (outcome.ok) {
    hilosToasts.push('Passkey added. You can now sign in with it.', {
      severity: 'success',
    })
  } else {
    passkeyError.value = outcome.message ?? null
  }
}

// Add a phone (HIL-403): a two-step modal wizard (phone → OTP code) that attaches
// a verified `sms` identity to the signed-in user. Server-confirmed, not
// optimistic: step 1 advances to the code step only on the backend `::success`
// (the resend cooldown makes a repeat a silent success); step 2 closes the modal
// on success and the new identity arrives over the identities projection. A
// malformed phone, a wrong/expired code, or a phone already in use surfaces as an
// inline error and stays on the step for a retry.
const addingSms = ref(false)
const smsStep = ref<1 | 2>(1)
const smsPhone = ref('')
const smsCode = ref('')
const smsLoading = ref(false)
const smsError = ref<string | null>(null)

function openAddSms(): void {
  smsStep.value = 1
  smsPhone.value = ''
  smsCode.value = ''
  smsError.value = null
  smsLoading.value = false
  addingSms.value = true
}

async function submitSmsRequest(): Promise<void> {
  if (smsPhone.value.trim() === '' || smsLoading.value) {
    return
  }
  smsLoading.value = true
  smsError.value = null
  const outcome = await sendAddSmsRequest(smsPhone.value.trim())
  smsLoading.value = false
  if (outcome.ok) {
    smsStep.value = 2
  } else {
    smsError.value = outcome.message
  }
}

async function submitSmsConfirm(): Promise<void> {
  if (smsCode.value.trim() === '' || smsLoading.value) {
    return
  }
  smsLoading.value = true
  smsError.value = null
  const outcome = await sendAddSmsConfirm(
    smsPhone.value.trim(),
    smsCode.value.trim(),
  )
  smsLoading.value = false
  if (outcome.ok) {
    addingSms.value = false
  } else {
    smsError.value = outcome.message
  }
}

// Password (HIL-402): a signed-in user adds or changes their own password. The
// form mode comes from the loaded identities — Change when a password exists, Add
// when a proven email exists but no password, otherwise a disabled hint (confirm
// an email first → HIL-406). Server-confirmed, not optimistic: a submit stays
// loading until the password_updated signal lands (success) or an action_error
// arrives (failure).
const PASSWORD_MIN = 8

const password = useSignal(passwordSection)
const passwordError = useSignal(setPasswordError)

const currentPassword = ref('')
const newPassword = ref('')
const confirmPassword = ref('')
const passwordLoading = ref(false)

const passwordValid = computed(() => {
  if (newPassword.value.length < PASSWORD_MIN) {
    return false
  }
  if (newPassword.value !== confirmPassword.value) {
    return false
  }

  return !password.value.hasPassword || currentPassword.value !== ''
})

function submitPassword(): void {
  if (!passwordValid.value || passwordLoading.value) {
    return
  }
  passwordLoading.value = sendSetPassword(
    password.value.hasPassword ? currentPassword.value : null,
    newPassword.value,
  )
}

// A rejected set-password arrives as a framework action_error: release the button
// and keep the fields so the user can correct and retry.
watch(passwordError, (reason) => {
  if (reason !== null) {
    passwordLoading.value = false
  }
})

// Add a password via email (HIL-406): shown when the user has neither a password
// nor a verified email (SMS-only or legacy OAuth). A two-step inline wizard proves
// an email (enter email → code) then sets the password on it. Server-confirmed:
// step 1 advances only on the backend `::success` (email issued whether or not the
// resend cooldown suppresses a duplicate); step 2 succeeds through the reused
// password_updated signal, which resets the wizard and flips the section to Change
// as the new identity lands. A malformed/in-use email, a wrong/expired code, or a
// weak password stays on the step with an inline error.
const addPwStep = ref<1 | 2>(1)
const addPwEmail = ref('')
const addPwCode = ref('')
const addPwPassword = ref('')
const addPwConfirm = ref('')
const addPwLoading = ref(false)
const addPwError = ref<string | null>(null)

const addPwValid = computed(
  () =>
    addPwCode.value.trim() !== '' &&
    addPwPassword.value.length >= PASSWORD_MIN &&
    addPwPassword.value === addPwConfirm.value,
)

async function submitAddPasswordRequest(): Promise<void> {
  if (addPwEmail.value.trim() === '' || addPwLoading.value) {
    return
  }
  addPwLoading.value = true
  addPwError.value = null
  const outcome = await sendAddPasswordRequest(addPwEmail.value.trim())
  addPwLoading.value = false
  if (outcome.ok) {
    addPwStep.value = 2
  } else {
    addPwError.value = outcome.message
  }
}

async function submitAddPasswordConfirm(): Promise<void> {
  if (!addPwValid.value || addPwLoading.value) {
    return
  }
  addPwLoading.value = true
  addPwError.value = null
  const outcome = await sendAddPasswordConfirm(
    addPwEmail.value.trim(),
    addPwCode.value.trim(),
    addPwPassword.value,
  )
  // Success is signalled (password_updated): the subscription resets this wizard
  // and the section flips to Change. Only reflect a rejection here.
  if (outcome.ok) {
    return
  }
  addPwLoading.value = false
  addPwError.value = outcome.message
}

// Success is signalled (a change moves nothing in the identity projection to
// confirm it): clear the fields, release the button, and toast the outcome.
// Registered once on mount so a reply to a submit lands; torn down on unmount so a
// late signal cannot fire into a destroyed component.
// Feed the framework notification-preferences store (HIL-485) from this profile's
// subscription while the page is mounted: the section snapshot rides the page data,
// live toggles from another device arrive as the changed signal. Torn down (and the
// store cleared) on unmount so a late signal never lands in a destroyed section.
let teardownNotificationPreferences: (() => void) | null = null

let unsubscribePasswordUpdated: (() => void) | null = null
onMounted(() => {
  teardownNotificationPreferences = bindNotificationPreferences()
  clearSetPasswordError()
  unsubscribePasswordUpdated = subscribePasswordUpdated((data) => {
    currentPassword.value = ''
    newPassword.value = ''
    confirmPassword.value = ''
    passwordLoading.value = false
    // Reset the add-via-email wizard too: an add lands here, and the section flips
    // to Change, so leave it on a clean step for any later re-entry.
    addPwStep.value = 1
    addPwEmail.value = ''
    addPwCode.value = ''
    addPwPassword.value = ''
    addPwConfirm.value = ''
    addPwLoading.value = false
    addPwError.value = null
    hilosToasts.push(
      data.mode === PASSWORD_MODE_ADDED
        ? 'Password added.'
        : 'Password changed.',
      { severity: 'success' },
    )
  })
})
onUnmounted(() => {
  unsubscribePasswordUpdated?.()
  teardownNotificationPreferences?.()
})

function openEdit(): void {
  clearRenameError()
  baseline.value = committed.value
  draft.value = committed.value
  loading.value = false
  editing.value = true
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
    <div class="d-flex flex-column gap-1 mb-4">
      <h1 class="h4 mb-0">Profile</h1>
      <p class="mb-0 text-body-secondary">Your account.</p>
    </div>

    <div v-if="detail" class="card" data-id="profile-detail">
      <div
        class="card-body d-flex align-items-center justify-content-between gap-3"
      >
        <dl class="row flex-grow-1 mb-0">
          <dt class="col-sm-3">Name</dt>
          <dd class="col-sm-9 mb-0" data-id="profile-name">
            {{ detail.name }}
          </dd>
        </dl>
        <button
          type="button"
          class="btn btn-outline-primary btn-sm flex-shrink-0"
          data-id="profile-edit"
          @click="openEdit"
        >
          Edit
        </button>
      </div>
    </div>
    <p v-else class="text-body-secondary" data-id="profile-loading">
      Loading profile…
    </p>

    <!-- The user's linked login identities (HIL-297), each unlinkable (HIL-377).
    Scoped to the signed-in user by the backend; secrets never reach here. Unlink
    is server-confirmed: the row disappears when the projection re-emits without
    it, and the sole remaining method's control is disabled (the server also
    refuses removing a last identity). -->
    <div class="mt-4" data-id="profile-identities">
      <h2 class="h6 mb-2">Login methods</h2>
      <ul
        v-if="identities.length"
        class="list-group"
        data-id="profile-identities-list"
      >
        <li
          v-for="identity in identities"
          :key="identity.key"
          class="list-group-item d-flex align-items-center justify-content-between gap-2"
          data-id="profile-identity-item"
        >
          <span class="d-flex flex-column">
            <span
              class="fw-semibold"
              :class="{
                'text-capitalize': identity.type !== PASSKEY_IDENTITY_TYPE,
              }"
              data-id="identity-type"
            >
              {{ identityTitle(identity) }}
            </span>
            <span
              v-if="identity.type === PASSKEY_IDENTITY_TYPE"
              class="text-body-secondary small"
              data-id="identity-passkey-added"
            >
              {{ passkeySubtitle(identity) }}
            </span>
            <span
              v-else
              class="text-body-secondary small"
              data-id="identity-identifier"
            >
              {{ identity.identifier }}
            </span>
          </span>
          <span class="d-flex align-items-center gap-2 flex-shrink-0">
            <span
              v-if="identity.verified"
              class="badge text-bg-success"
              data-id="identity-verified"
            >
              Verified
            </span>
            <span
              v-else
              class="badge text-bg-secondary"
              data-id="identity-unverified"
            >
              Unverified
            </span>
            <template v-if="unlinkConfirmKey === identity.key">
              <span
                class="text-body-secondary small"
                data-id="identity-unlink-confirm"
              >
                Remove?
              </span>
              <LoadingButton
                class="btn-danger btn-sm"
                :loading="unlinkLoadingKey === identity.key"
                data-id="identity-unlink-yes"
                @click="confirmUnlink(identity.key)"
              >
                Remove
              </LoadingButton>
              <button
                type="button"
                class="btn btn-outline-secondary btn-sm"
                :disabled="unlinkLoadingKey === identity.key"
                data-id="identity-unlink-cancel"
                @click="cancelUnlink"
              >
                Cancel
              </button>
            </template>
            <button
              v-else
              type="button"
              class="btn btn-outline-danger btn-sm"
              :disabled="!identity.canUnlink"
              :title="
                identity.canUnlink
                  ? 'Remove this login method'
                  : 'You cannot remove your only login method'
              "
              data-id="identity-unlink"
              @click="askUnlink(identity.key)"
            >
              Unlink
            </button>
          </span>
        </li>
      </ul>
      <p
        v-else
        class="text-body-secondary mb-0"
        data-id="profile-identities-empty"
      >
        No linked login methods.
      </p>
      <div
        v-if="unlinkError"
        class="alert alert-danger mt-2 mb-0"
        role="alert"
        data-id="profile-unlink-error"
      >
        {{ unlinkError }}
      </div>

      <!-- Enroll a device passkey (HIL-284): runs the WebAuthn register ceremony
      for the signed-in user. Hidden where the browser lacks WebAuthn. The new
      credential appears in the list once list refresh lands (HIL-404). -->
      <div v-if="passkeySupported" class="mt-3" data-id="profile-passkey">
        <div
          v-if="passkeyError"
          class="alert alert-danger py-2"
          role="alert"
          data-id="profile-passkey-error"
        >
          {{ passkeyError }}
        </div>
        <LoadingButton
          class="btn-outline-primary btn-sm"
          :loading="passkeyPending"
          data-id="profile-passkey-add"
          @click="addPasskey"
        >
          Add a passkey
        </LoadingButton>
      </div>

      <!-- Add a phone (HIL-403): opens the two-step OTP wizard that attaches a
      verified `sms` identity to this account. The new identity appears in the list
      above once the confirm lands and the identities projection re-emits. -->
      <div class="mt-3" data-id="profile-add-sms">
        <button
          type="button"
          class="btn btn-outline-primary btn-sm"
          data-id="profile-add-sms-open"
          @click="openAddSms"
        >
          Add a phone
        </button>
      </div>

      <!-- Link an account (HIL-401): attach a new OAuth provider to this account.
      Only providers not already linked are offered; each button starts the redirect
      (staying loading through it) and the row vanishes once the link lands and the
      identity list re-emits. A rejected start shows an inline error. -->
      <div v-if="providers.length" class="mt-3" data-id="profile-oauth-link">
        <h3 class="h6 mb-2">Link an account</h3>
        <div class="d-flex flex-wrap gap-2">
          <LoadingButton
            v-for="provider in providers"
            :key="provider.key"
            class="btn-outline-primary btn-sm"
            :loading="linkPendingKey === provider.key"
            :disabled="
              linkPendingKey !== null && linkPendingKey !== provider.key
            "
            :data-id="`profile-oauth-link-${provider.key}`"
            @click="linkProvider(provider.key)"
          >
            {{ provider.label }}
          </LoadingButton>
        </div>
        <div
          v-if="linkError"
          class="alert alert-danger mt-2 mb-0"
          role="alert"
          data-id="profile-oauth-link-error"
        >
          {{ linkError }}
        </div>
      </div>
    </div>

    <!-- Password (HIL-402): add or change the account password. The mode comes
    from the loaded identities: Change when a password exists, Add when a proven
    email exists but no password, otherwise a disabled hint (confirm an email
    first → HIL-406). Server-confirmed — the form stays loading until the
    password_updated signal lands, then clears and toasts. -->
    <div class="mt-4" data-id="profile-password">
      <h2 class="h6 mb-2">Password</h2>

      <!-- No password and no verified email (HIL-406): a two-step inline wizard —
      prove an email (enter email → code), then set the password on it. Replaces the
      old disabled hint. Server-confirmed; step 2 succeeds via password_updated,
      which flips this section to Change. -->
      <template v-if="!password.hasPassword && !password.verifiedEmail">
        <form v-if="addPwStep === 1" @submit.prevent="submitAddPasswordRequest">
          <p class="text-body-secondary" data-id="profile-add-password-hint">
            Confirm an email address to set a password.
          </p>
          <div class="mb-2">
            <label class="form-label" for="profile-add-password-email"
              >Email</label
            >
            <input
              id="profile-add-password-email"
              v-model="addPwEmail"
              type="email"
              class="form-control"
              autocomplete="email"
              data-id="profile-add-password-email"
            />
            <div class="form-text">We'll email you a one-time code.</div>
          </div>
          <LoadingButton
            class="btn-primary btn-sm"
            :loading="addPwLoading"
            :disabled="addPwEmail.trim() === ''"
            data-id="profile-add-password-request"
            @click="submitAddPasswordRequest"
          >
            Send code
          </LoadingButton>
          <div
            v-if="addPwError"
            class="alert alert-danger mt-2 mb-0"
            role="alert"
            data-id="profile-add-password-error"
          >
            {{ addPwError }}
          </div>
        </form>

        <form v-else @submit.prevent="submitAddPasswordConfirm">
          <div class="mb-2">
            <label class="form-label" for="profile-add-password-code">
              Verification code
            </label>
            <input
              id="profile-add-password-code"
              v-model="addPwCode"
              type="text"
              inputmode="numeric"
              autocomplete="one-time-code"
              class="form-control"
              data-id="profile-add-password-code"
            />
            <div class="form-text">
              Enter the code sent to {{ addPwEmail }}.
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label" for="profile-add-password-new">
              New password
            </label>
            <input
              id="profile-add-password-new"
              v-model="addPwPassword"
              type="password"
              class="form-control"
              autocomplete="new-password"
              :minlength="PASSWORD_MIN"
              data-id="profile-add-password-new"
            />
            <div class="form-text">At least {{ PASSWORD_MIN }} characters.</div>
          </div>
          <div class="mb-2">
            <label class="form-label" for="profile-add-password-confirm">
              Confirm new password
            </label>
            <input
              id="profile-add-password-confirm"
              v-model="addPwConfirm"
              type="password"
              class="form-control"
              autocomplete="new-password"
              data-id="profile-add-password-confirm"
            />
          </div>
          <LoadingButton
            class="btn-primary btn-sm"
            :loading="addPwLoading"
            :disabled="!addPwValid"
            data-id="profile-add-password-save"
            @click="submitAddPasswordConfirm"
          >
            Set password
          </LoadingButton>
          <div
            v-if="addPwError"
            class="alert alert-danger mt-2 mb-0"
            role="alert"
            data-id="profile-add-password-error"
          >
            {{ addPwError }}
          </div>
        </form>
      </template>

      <form v-else @submit.prevent="submitPassword">
        <div v-if="password.hasPassword" class="mb-2">
          <label class="form-label" for="profile-password-current">
            Current password
          </label>
          <input
            id="profile-password-current"
            v-model="currentPassword"
            type="password"
            class="form-control"
            autocomplete="current-password"
            data-id="profile-password-current"
          />
        </div>
        <div class="mb-2">
          <label class="form-label" for="profile-password-new">
            New password
          </label>
          <input
            id="profile-password-new"
            v-model="newPassword"
            type="password"
            class="form-control"
            autocomplete="new-password"
            :minlength="PASSWORD_MIN"
            data-id="profile-password-new"
          />
          <div class="form-text">At least {{ PASSWORD_MIN }} characters.</div>
        </div>
        <div class="mb-2">
          <label class="form-label" for="profile-password-confirm">
            Confirm new password
          </label>
          <input
            id="profile-password-confirm"
            v-model="confirmPassword"
            type="password"
            class="form-control"
            autocomplete="new-password"
            data-id="profile-password-confirm"
          />
        </div>
        <LoadingButton
          class="btn-primary btn-sm"
          :loading="passwordLoading"
          :disabled="!passwordValid"
          data-id="profile-password-save"
          @click="submitPassword"
        >
          {{ password.hasPassword ? 'Change password' : 'Set password' }}
        </LoadingButton>
        <div
          v-if="passwordError"
          class="alert alert-danger mt-2 mb-0"
          role="alert"
          data-id="profile-set-password-error"
        >
          {{ passwordError }}
        </div>
      </form>
    </div>

    <!-- Notification channel preferences (HIL-485): one switch per globally-enabled
    channel plus the always-on note for mandatory types. The framework SDK section
    owns its own <h2> heading and renders the framework preference store, which this
    view feeds from the profile subscription (bindNotificationPreferences on mount).
    A toggle is tracked, never optimistic — the switch turns only when the server
    fans the changed signal back to every one of the user's tabs. -->
    <HilosNotificationPreferences class="mt-4" :connection="connection" />

    <HilosModal v-model="editing" :confirm-on-close="dirty">
      <template #header>
        <ConflictHeader title="Change name" :conflict="conflict" />
      </template>

      <form @submit.prevent="submit">
        <label class="form-label" for="profile-name-field">Display name</label>
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
        <div
          v-if="error"
          class="alert alert-danger mt-2 mb-0"
          data-id="profile-rename-error"
        >
          {{ error }}
        </div>
      </form>

      <template #actions>
        <ConflictActions
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

    <!-- Add-a-phone wizard (HIL-403): step 1 collects the number, step 2 the code.
    A CREATE wizard, not a merge edit — no version/conflict. Submit blocks its
    button until the backend acks; a rejection stays on the step with an inline
    reason. -->
    <HilosModal v-model="addingSms">
      <template #header>
        <h2 class="modal-title h5 mb-0">Add a phone</h2>
      </template>

      <form v-if="smsStep === 1" @submit.prevent="submitSmsRequest">
        <label class="form-label" for="profile-add-sms-phone"
          >Phone number</label
        >
        <input
          id="profile-add-sms-phone"
          v-model="smsPhone"
          type="tel"
          class="form-control"
          autocomplete="tel"
          data-autofocus
          data-id="profile-add-sms-phone"
        />
        <div class="form-text">We'll text you a one-time code.</div>
        <div
          v-if="smsError"
          class="alert alert-danger mt-2 mb-0"
          role="alert"
          data-id="profile-add-sms-error"
        >
          {{ smsError }}
        </div>
      </form>

      <form v-else @submit.prevent="submitSmsConfirm">
        <label class="form-label" for="profile-add-sms-code"
          >Verification code</label
        >
        <input
          id="profile-add-sms-code"
          v-model="smsCode"
          type="text"
          inputmode="numeric"
          autocomplete="one-time-code"
          class="form-control"
          data-autofocus
          data-id="profile-add-sms-code"
        />
        <div class="form-text">Enter the code sent to {{ smsPhone }}.</div>
        <div
          v-if="smsError"
          class="alert alert-danger mt-2 mb-0"
          role="alert"
          data-id="profile-add-sms-error"
        >
          {{ smsError }}
        </div>
      </form>

      <template #actions>
        <LoadingButton
          v-if="smsStep === 1"
          class="btn-primary"
          :loading="smsLoading"
          :disabled="smsPhone.trim() === ''"
          data-id="profile-add-sms-request"
          @click="submitSmsRequest"
        >
          Send code
        </LoadingButton>
        <LoadingButton
          v-else
          class="btn-primary"
          :loading="smsLoading"
          :disabled="smsCode.trim() === ''"
          data-id="profile-add-sms-confirm"
          @click="submitSmsConfirm"
        >
          Add phone
        </LoadingButton>
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
