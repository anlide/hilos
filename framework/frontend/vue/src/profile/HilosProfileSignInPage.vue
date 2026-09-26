<!-- The current account's ways in. Every parameter and confirmation lives in a dialog. -->
<script setup lang="ts">
import {
  createHilosProfileAddSignInFlow,
  createHilosProfileSignInActions,
  createSignal,
  focusInitial,
  hilosProfileLinkableProviders,
  hilosProfilePasswordState,
  hilosProfileSignInSubtitle,
  hilosProfileSignInTitle,
  HILOS_PROFILE_SIGN_IN_COPY,
  hilosToasts,
  isPasskeySupported,
  PROFILE_PASSWORD_MODE_ADDED,
  sessionAuthMethods,
  watchHilosProfilePasswordUpdated,
  type HilosAuthContext,
  type HilosProfileSignInMethod,
} from '@hilos/core'
import { computed, nextTick, onUnmounted, ref, useId, watch } from 'vue'

import HilosActionError from '../HilosActionError.vue'
import HilosFormError from '../HilosFormError.vue'
import HilosModal from '../HilosModal.vue'
import HilosPageHeading from '../HilosPageHeading.vue'
import LoadingButton from '../LoadingButton.vue'
import { useSignal } from '../useSignal.js'
import { useTrackedAction } from '../useTrackedAction.js'

const props = defineProps<{
  context: HilosAuthContext
  methods: readonly HilosProfileSignInMethod[]
}>()
const methodsSignal = createSignal(props.methods)
watch(
  () => props.methods,
  (methods) => methodsSignal.set(methods),
)
const offered = useSignal(sessionAuthMethods(props.context.scopes))
const providers = computed(() =>
  hilosProfileLinkableProviders(props.methods, offered.value),
)
const passwordState = computed(() => hilosProfilePasswordState(props.methods))
const passkeySupported = isPasskeySupported()
const actions = createHilosProfileSignInActions(props.context)
const baseId = useId()
const PASSWORD_MIN = 8

function title(method: HilosProfileSignInMethod): string {
  return hilosProfileSignInTitle(method, offered.value)
}

const unlinkKey = ref<string | null>(null)
const unlinkMethod = computed(() =>
  props.methods.find((method) => method.key === unlinkKey.value),
)
const remaining = computed(() =>
  props.methods
    .filter((method) => method.key !== unlinkKey.value)
    .map(title)
    .join(', '),
)
const unlinkAction = useTrackedAction()
const unlinkOpen = computed({
  get: () => unlinkKey.value !== null,
  set: (open) => {
    if (!open && !unlinkAction.busy.value) unlinkKey.value = null
  },
})
function askUnlink(method: HilosProfileSignInMethod): void {
  if (!method.canUnlink || unlinkAction.busy.value) return
  unlinkAction.clearError()
  unlinkKey.value = method.key
}
async function remove(): Promise<void> {
  if (unlinkKey.value === null || unlinkAction.busy.value) return
  if (await unlinkAction.run(actions.unlinkIdentity(Number(unlinkKey.value))))
    unlinkKey.value = null
}
watch(
  () => props.methods,
  (methods) => {
    if (
      unlinkKey.value !== null &&
      !methods.some((method) => method.key === unlinkKey.value)
    )
      unlinkKey.value = null
  },
)

const passwordOpen = ref(false)
const currentPassword = ref('')
const newPassword = ref('')
const confirmPassword = ref('')
const passwordAction = useTrackedAction()
const passwordAwaiting = ref(false)
const passwordValid = computed(
  () =>
    currentPassword.value !== '' &&
    newPassword.value.length >= PASSWORD_MIN &&
    newPassword.value === confirmPassword.value,
)
function changePassword(): void {
  passwordAction.clearError()
  currentPassword.value = ''
  newPassword.value = ''
  confirmPassword.value = ''
  passwordAwaiting.value = false
  passwordOpen.value = true
}
async function savePassword(): Promise<void> {
  if (
    !passwordValid.value ||
    passwordAwaiting.value ||
    passwordAction.busy.value
  )
    return
  passwordAwaiting.value = true
  if (
    !(await passwordAction.run(
      actions.setPassword(currentPassword.value, newPassword.value),
    ))
  )
    passwordAwaiting.value = false
}
const stopPassword = watchHilosProfilePasswordUpdated(
  props.context.connection,
  (data) => {
    passwordOpen.value = false
    passwordAwaiting.value = false
    currentPassword.value = ''
    newPassword.value = ''
    confirmPassword.value = ''
    hilosToasts.push(
      data.mode === PROFILE_PASSWORD_MODE_ADDED
        ? HILOS_PROFILE_SIGN_IN_COPY.passwordAdded
        : HILOS_PROFILE_SIGN_IN_COPY.passwordChanged,
      { severity: 'success' },
    )
  },
)

const flow = createHilosProfileAddSignInFlow(props.context, methodsSignal)
const step = useSignal(flow.step)
const busy = useSignal(flow.busy)
const refusal = useSignal(flow.refusal)
const pendingProvider = useSignal(flow.provider)
const addDraft = ref({
  email: '',
  phone: '',
  code: '',
  newPassword: '',
  confirm: '',
})
const addBody = ref<HTMLElement | null>(null)
const addOpen = computed({
  get: () => step.value !== 'closed',
  set: (open) => {
    if (!open) flow.close()
  },
})
const addDirty = computed(() =>
  Object.values(addDraft.value).some((value) => value !== ''),
)
function openAdd(): void {
  addDraft.value = {
    email: '',
    phone: '',
    code: '',
    newPassword: '',
    confirm: '',
  }
  flow.open()
}
watch(step, () => {
  void nextTick(() => {
    const dialog = addBody.value?.closest<HTMLElement>('[role="dialog"]')
    if (dialog) focusInitial(dialog)
  })
})
const addFields = computed(() => {
  switch (step.value) {
    case 'password-email':
      return [
        {
          key: 'email' as const,
          label: 'Email',
          type: 'email',
          autocomplete: 'email',
          dataId: 'profile-add-password-email',
        },
      ]
    case 'phone-number':
      return [
        {
          key: 'phone' as const,
          label: 'Phone number',
          type: 'tel',
          autocomplete: 'tel',
          dataId: 'profile-add-sms-phone',
        },
      ]
    case 'phone-code':
      return [
        {
          key: 'code' as const,
          label: 'Code',
          type: 'text',
          autocomplete: 'one-time-code',
          dataId: 'profile-add-sms-code',
        },
      ]
    case 'password-new':
    case 'password-code':
      return [
        ...(step.value === 'password-code'
          ? [
              {
                key: 'code' as const,
                label: 'Code',
                type: 'text',
                autocomplete: 'one-time-code',
                dataId: 'profile-add-password-code',
              },
            ]
          : []),
        {
          key: 'newPassword' as const,
          label: 'New password',
          type: 'password',
          autocomplete: 'new-password',
          dataId: 'profile-add-password-new',
        },
        {
          key: 'confirm' as const,
          label: 'Confirm new password',
          type: 'password',
          autocomplete: 'new-password',
          dataId: 'profile-add-password-confirm',
        },
      ]
    default:
      return []
  }
})
const addValid = computed(() => {
  if (
    addFields.value.some((field) =>
      field.type === 'password'
        ? addDraft.value[field.key] === ''
        : addDraft.value[field.key].trim() === '',
    )
  )
    return false
  return (
    !step.value.startsWith('password-') ||
    step.value === 'password-email' ||
    (addDraft.value.newPassword.length >= PASSWORD_MIN &&
      addDraft.value.newPassword === addDraft.value.confirm)
  )
})
const submitId = computed(() =>
  step.value === 'phone-number'
    ? 'profile-add-sms-request'
    : step.value === 'phone-code'
      ? 'profile-add-sms-confirm'
      : step.value === 'password-email'
        ? 'profile-add-password-request'
        : 'profile-add-password-save',
)
const errorId = computed(() =>
  step.value.startsWith('phone-')
    ? 'profile-add-sms-error'
    : step.value.startsWith('password-')
      ? 'profile-add-password-error'
      : 'profile-sign-in-add-error',
)
async function submitAdd(): Promise<void> {
  if (!addValid.value || busy.value) return
  switch (step.value) {
    case 'password-new':
      await flow.submitPasswordNew(addDraft.value.newPassword)
      break
    case 'password-email':
      await flow.submitPasswordEmail(addDraft.value.email.trim())
      break
    case 'password-code':
      await flow.submitPasswordCode(
        addDraft.value.code.trim(),
        addDraft.value.newPassword,
      )
      break
    case 'phone-number':
      await flow.submitPhone(addDraft.value.phone.trim())
      break
    case 'phone-code':
      await flow.submitPhoneCode(addDraft.value.code.trim())
      break
  }
}
onUnmounted(() => {
  stopPassword()
  flow.dispose()
})
</script>

<template>
  <section data-id="profile-sign-in-view">
    <HilosPageHeading />
    <p v-if="methods.length === 0" class="text-body-secondary">
      No ways to sign in.
    </p>
    <div data-id="profile-identities-list">
      <div
        v-for="method in methods"
        :key="method.key"
        class="d-flex flex-wrap align-items-center gap-3 py-3 border-bottom"
        data-id="profile-identity-item"
        :data-identity-key="method.key"
      >
        <div class="flex-grow-1 text-break">
          <div class="fw-semibold" data-id="identity-type">
            {{ title(method) }}
          </div>
          <div
            class="small text-body-secondary"
            :data-id="
              method.type === 'passkey'
                ? 'identity-passkey-added'
                : 'identity-identifier'
            "
          >
            {{ hilosProfileSignInSubtitle(method) }}
          </div>
          <span
            v-if="method.verified"
            class="badge text-bg-success"
            data-id="identity-verified"
            >Verified</span
          >
          <span
            v-else
            class="badge text-bg-secondary"
            data-id="identity-unverified"
            >Unverified</span
          >
          <div
            v-if="!method.canUnlink"
            class="small text-warning-emphasis"
            data-id="identity-unlink-blocked"
          >
            {{ HILOS_PROFILE_SIGN_IN_COPY.onlyMethod }}
          </div>
        </div>
        <div class="d-flex gap-2">
          <button
            v-if="method.type === 'password'"
            class="btn btn-sm btn-outline-secondary"
            type="button"
            data-id="profile-password-change"
            @click="changePassword"
          >
            Change
          </button>
          <button
            class="btn btn-sm btn-outline-secondary"
            type="button"
            :disabled="!method.canUnlink"
            data-id="identity-unlink"
            @click="askUnlink(method)"
          >
            {{ method.type === 'oauth' ? 'Unlink' : 'Remove' }}
          </button>
        </div>
      </div>
    </div>
    <button
      class="btn btn-sm btn-outline-primary mt-3"
      type="button"
      data-id="profile-sign-in-add"
      @click="openAdd"
    >
      Add a way to sign in
    </button>

    <HilosModal
      v-model="unlinkOpen"
      :title="`Remove ${unlinkMethod ? title(unlinkMethod) : 'sign-in method'}?`"
      initial-focus="dialog"
      :close-on-esc="!unlinkAction.busy.value"
      :close-on-backdrop="!unlinkAction.busy.value"
    >
      <div data-id="profile-unlink-modal">
        <p>
          You will no longer be able to sign in with
          {{ unlinkMethod ? title(unlinkMethod) : 'this method' }}. You can
          still use: {{ remaining }}.
        </p>
        <HilosActionError
          :action="unlinkAction"
          details-title="Couldn't remove the sign-in method"
          data-id="profile-unlink-error"
        />
      </div>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="unlinkAction.busy.value"
          data-id="identity-unlink-cancel"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          class="btn btn-danger"
          :loading="unlinkAction.loading.value"
          :disabled="unlinkAction.busy.value"
          data-id="identity-unlink-yes"
          @click="remove"
          >Remove</LoadingButton
        >
      </template>
    </HilosModal>

    <HilosModal
      v-model="passwordOpen"
      title="Change your password"
      :confirm-on-close="
        currentPassword !== '' || newPassword !== '' || confirmPassword !== ''
      "
    >
      <form
        :id="`${baseId}-password`"
        data-id="profile-password-modal"
        @submit.prevent="savePassword"
      >
        <label :for="`${baseId}-current`" class="form-label"
          >Current password</label
        >
        <input
          :id="`${baseId}-current`"
          v-model="currentPassword"
          type="password"
          autocomplete="current-password"
          class="form-control mb-3"
          data-id="profile-password-current"
          data-autofocus
        />
        <label :for="`${baseId}-new`" class="form-label">New password</label>
        <input
          :id="`${baseId}-new`"
          v-model="newPassword"
          type="password"
          autocomplete="new-password"
          class="form-control mb-3"
          :aria-describedby="`${baseId}-password-hint`"
          data-id="profile-password-new"
        />
        <label :for="`${baseId}-confirm`" class="form-label"
          >Confirm new password</label
        >
        <input
          :id="`${baseId}-confirm`"
          v-model="confirmPassword"
          type="password"
          autocomplete="new-password"
          class="form-control mb-3"
          :aria-describedby="`${baseId}-password-hint`"
          data-id="profile-password-confirm"
        />
        <div
          :id="`${baseId}-password-hint`"
          class="form-text mb-3"
          data-id="profile-password-hint"
        >
          {{ HILOS_PROFILE_SIGN_IN_COPY.passwordHint }}
        </div>
        <HilosActionError
          :action="passwordAction"
          details-title="Couldn't change the password"
          data-id="profile-set-password-error"
        />
      </form>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          data-id="profile-password-cancel"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          type="submit"
          :form="`${baseId}-password`"
          class="btn btn-primary"
          :loading="passwordAction.loading.value"
          :disabled="
            !passwordValid || passwordAwaiting || passwordAction.busy.value
          "
          data-id="profile-password-save"
          >Save</LoadingButton
        >
      </template>
    </HilosModal>

    <HilosModal
      v-model="addOpen"
      title="Add a way to sign in"
      initial-focus="dialog"
      :confirm-on-close="addDirty"
    >
      <div ref="addBody" data-id="profile-sign-in-add-modal">
        <div
          class="visually-hidden"
          role="alert"
          aria-live="assertive"
          aria-atomic="true"
          data-id="profile-sign-in-add-live"
        >
          {{ refusal }}
        </div>
        <div class="hilos-stack">
          <div class="invisible" aria-hidden="true" inert>
            <div
              v-for="label in ['Code', 'New password', 'Confirm new password']"
              :key="label"
              class="mb-3"
            >
              <div class="form-label">{{ label }}</div>
              <div class="form-control">&nbsp;</div>
            </div>
            <div class="form-text">
              {{ HILOS_PROFILE_SIGN_IN_COPY.passwordHint }}
            </div>
          </div>
          <div
            class="invisible d-flex flex-column gap-2"
            aria-hidden="true"
            inert
          >
            <span
              v-if="!passwordState.hasPassword"
              class="btn btn-outline-secondary"
              ><span class="d-flex align-items-center gap-3 text-start"
                ><i class="bi bi-lock fs-5" aria-hidden="true"></i
                ><span
                  ><span class="d-block fw-semibold small">Password</span
                  ><span class="d-block small text-body-secondary">{{
                    HILOS_PROFILE_SIGN_IN_COPY.passwordDescription
                  }}</span></span
                ></span
              ></span
            >
            <span class="btn btn-outline-secondary"
              ><span class="d-flex align-items-center gap-3 text-start"
                ><i class="bi bi-phone fs-5" aria-hidden="true"></i
                ><span
                  ><span class="d-block fw-semibold small">Phone</span
                  ><span class="d-block small text-body-secondary">{{
                    HILOS_PROFILE_SIGN_IN_COPY.phoneDescription
                  }}</span></span
                ></span
              ></span
            >
            <span
              v-for="entry in providers"
              :key="entry.key"
              class="btn btn-outline-secondary"
              ><span class="d-flex align-items-center gap-3 text-start"
                ><i class="bi bi-box-arrow-in-right fs-5" aria-hidden="true"></i
                ><span
                  ><span class="d-block fw-semibold small">{{
                    entry.label
                  }}</span
                  ><span class="d-block small text-body-secondary">{{
                    HILOS_PROFILE_SIGN_IN_COPY.providerDescription
                  }}</span></span
                ></span
              ></span
            >
            <span v-if="passkeySupported" class="btn btn-outline-secondary"
              ><span class="d-flex align-items-center gap-3 text-start"
                ><i class="bi bi-fingerprint fs-5" aria-hidden="true"></i
                ><span
                  ><span class="d-block fw-semibold small">Passkey</span
                  ><span class="d-block small text-body-secondary">{{
                    HILOS_PROFILE_SIGN_IN_COPY.passkeyDescription
                  }}</span></span
                ></span
              ></span
            >
          </div>
          <div
            v-if="step === 'choose'"
            class="d-flex flex-column gap-2 align-self-start"
          >
            <button
              v-if="!passwordState.hasPassword"
              type="button"
              class="btn btn-outline-secondary"
              :disabled="busy"
              data-id="profile-sign-in-choose-password"
              @click="flow.choosePassword"
            >
              <span class="d-flex align-items-center gap-3 text-start"
                ><i class="bi bi-lock fs-5" aria-hidden="true"></i
                ><span
                  ><span class="d-block fw-semibold small">Password</span
                  ><span class="d-block small text-body-secondary">{{
                    HILOS_PROFILE_SIGN_IN_COPY.passwordDescription
                  }}</span></span
                ></span
              >
            </button>
            <button
              type="button"
              class="btn btn-outline-secondary"
              :disabled="busy"
              data-id="profile-sign-in-choose-phone"
              @click="flow.choosePhone"
            >
              <span class="d-flex align-items-center gap-3 text-start"
                ><i class="bi bi-phone fs-5" aria-hidden="true"></i
                ><span
                  ><span class="d-block fw-semibold small">Phone</span
                  ><span class="d-block small text-body-secondary">{{
                    HILOS_PROFILE_SIGN_IN_COPY.phoneDescription
                  }}</span></span
                ></span
              >
            </button>
            <LoadingButton
              v-for="entry in providers"
              :key="entry.key"
              class="btn btn-outline-secondary"
              :loading="busy && pendingProvider === entry.key"
              :disabled="busy"
              :data-id="`profile-oauth-link-${entry.key}`"
              @click="flow.chooseProvider(entry.key)"
              ><span class="d-flex align-items-center gap-3 text-start"
                ><i class="bi bi-box-arrow-in-right fs-5" aria-hidden="true"></i
                ><span
                  ><span class="d-block fw-semibold small">{{
                    entry.label
                  }}</span
                  ><span class="d-block small text-body-secondary">{{
                    HILOS_PROFILE_SIGN_IN_COPY.providerDescription
                  }}</span></span
                ></span
              ></LoadingButton
            >
            <LoadingButton
              v-if="passkeySupported"
              class="btn btn-outline-secondary"
              :loading="busy && pendingProvider === null"
              :disabled="busy"
              data-id="profile-passkey-add"
              @click="flow.choosePasskey"
              ><span class="d-flex align-items-center gap-3 text-start"
                ><i class="bi bi-fingerprint fs-5" aria-hidden="true"></i
                ><span
                  ><span class="d-block fw-semibold small">Passkey</span
                  ><span class="d-block small text-body-secondary">{{
                    HILOS_PROFILE_SIGN_IN_COPY.passkeyDescription
                  }}</span></span
                ></span
              ></LoadingButton
            >
          </div>
          <form
            v-else
            :id="`${baseId}-add`"
            class="align-self-start"
            @submit.prevent="submitAdd"
          >
            <div
              v-for="(field, index) in addFields"
              :key="field.key"
              class="mb-3"
            >
              <label :for="`${baseId}-add-${field.key}`" class="form-label">{{
                field.label
              }}</label>
              <input
                :id="`${baseId}-add-${field.key}`"
                v-model="addDraft[field.key]"
                :type="field.type"
                :autocomplete="field.autocomplete"
                class="form-control"
                :data-id="field.dataId"
                :aria-describedby="
                  field.type === 'password'
                    ? `${baseId}-add-password-hint`
                    : undefined
                "
                :data-autofocus="index === 0 ? '' : undefined"
              />
            </div>
            <div
              v-if="step === 'password-new' || step === 'password-code'"
              :id="`${baseId}-add-password-hint`"
              class="form-text"
              data-id="profile-add-password-hint"
            >
              {{ HILOS_PROFILE_SIGN_IN_COPY.passwordHint }}
            </div>
          </form>
        </div>
        <HilosFormError :message="refusal" :data-id="errorId" />
      </div>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          data-id="profile-sign-in-add-cancel"
          @click="requestClose"
        >
          Cancel
        </button>
        <button
          v-if="step !== 'choose'"
          type="button"
          class="btn btn-outline-secondary"
          :disabled="busy"
          data-id="profile-sign-in-add-back"
          @click="flow.back"
        >
          Back
        </button>
        <LoadingButton
          v-if="step !== 'choose'"
          type="submit"
          :form="`${baseId}-add`"
          class="btn btn-primary"
          :loading="busy"
          :disabled="!addValid || busy"
          :data-id="submitId"
          >{{
            step === 'password-email' || step === 'phone-number'
              ? 'Send code'
              : 'Save'
          }}</LoadingButton
        >
      </template>
    </HilosModal>
  </section>
</template>
