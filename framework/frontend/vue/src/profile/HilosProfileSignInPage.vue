<!-- The current account's ways in. Every parameter and confirmation lives in a dialog. -->
<script setup lang="ts">
import {
  createHilosProfileAddSignInFlow,
  createHilosProfilePasswordChangeFlow,
  createHilosProfileSignInMethods,
  createHilosProfileSignInActions,
  focusInitial,
  hilosProfileAddableWays,
  hilosProfileSignInSubtitle,
  hilosProfileSignInTitle,
  HILOS_PROFILE_SIGN_IN_COPY,
  HILOS_STEP_UP_COPY,
  hilosToasts,
  isHilosProfilePasskeyOnly,
  isPasskeySupported,
  PROFILE_PASSWORD_MODE_ADDED,
  SEND_AGAIN_LABEL,
  SEND_PROGRESS_DETAILS_CLASS,
  SEND_PROGRESS_ROW_CLASS,
  sessionAuthMethods,
  watchHilosProfilePasswordUpdated,
  type HilosAuthContext,
  type HilosProfileAddableWay,
  type HilosProfileSignInMethod,
} from '@hilos/core'
import { computed, nextTick, onUnmounted, ref, useId, watch } from 'vue'

import HilosProfilePasswordChange from './HilosProfilePasswordChange.vue'
import HilosStepUpStep from '../auth/HilosStepUpStep.vue'
import HilosActionError from '../HilosActionError.vue'
import HilosFormError from '../HilosFormError.vue'
import HilosModal from '../HilosModal.vue'
import HilosSendProgress from '../HilosSendProgress.vue'
import HilosPageHeading from '../HilosPageHeading.vue'
import LoadingButton from '../LoadingButton.vue'
import { useSignal } from '../useSignal.js'
import { useTrackedAction } from '../useTrackedAction.js'

const props = defineProps<{
  context: HilosAuthContext
}>()
const methodsSignal = createHilosProfileSignInMethods(props.context.scopes)
const methods = useSignal(methodsSignal)
const offered = useSignal(sessionAuthMethods(props.context.scopes))
const passkeySupported = isPasskeySupported()
/** What the account can still add: the chooser's buttons and the warning's. */
const ways = computed(() =>
  hilosProfileAddableWays(methods.value, offered.value, passkeySupported),
)
const passkeyOnly = computed(() => isHilosProfilePasskeyOnly(methods.value))
const passkeyOnlyWays = computed(() =>
  ways.value.filter((way) => way.kind !== 'passkey'),
)
const actions = createHilosProfileSignInActions(props.context)
const baseId = useId()
const PASSWORD_MIN = 8

function title(method: HilosProfileSignInMethod): string {
  return hilosProfileSignInTitle(method, offered.value)
}

function wayKey(way: HilosProfileAddableWay): string {
  return way.kind === 'provider' ? way.key : way.kind
}

function passkeyOnlyWayId(way: HilosProfileAddableWay): string {
  return way.kind === 'provider'
    ? `profile-sign-in-passkey-only-link-${way.key}`
    : `profile-sign-in-passkey-only-${way.kind}`
}

function passkeyOnlyWayLabel(way: HilosProfileAddableWay): string {
  switch (way.kind) {
    case 'password':
      return HILOS_PROFILE_SIGN_IN_COPY.addPassword
    case 'phone':
      return HILOS_PROFILE_SIGN_IN_COPY.addPhone
    case 'provider':
      return HILOS_PROFILE_SIGN_IN_COPY.linkProvider.replace('{name}', way.name)
    case 'passkey':
      return 'Passkey'
  }
}

const unlinkKey = ref<string | null>(null)
const unlinkMethod = computed(() =>
  methods.value.find((method) => method.key === unlinkKey.value),
)
const remaining = computed(() =>
  methods.value
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
watch(methods, (current) => {
  if (
    unlinkKey.value !== null &&
    !current.some((method) => method.key === unlinkKey.value)
  )
    unlinkKey.value = null
})

const passwordFlow = createHilosProfilePasswordChangeFlow(props.context)
const passwordStep = useSignal(passwordFlow.step)
const stopPassword = watchHilosProfilePasswordUpdated(
  props.context.connection,
  (data) => {
    if (passwordFlow.step.get() !== 'closed') return
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
const sendProgress = useSignal(flow.sendProgress)
const resendAt = useSignal(flow.resendAt)
const sentEmail = useSignal(flow.email)
const sentPhone = useSignal(flow.phone)
const stepUpRefusal = useSignal(flow.stepUp.refusal)
const stepUpBusy = useSignal(flow.stepUp.busy)
const pendingProvider = useSignal(flow.provider)
/** The dialog opens on the server's answer, never on the click. */
const addOpening = computed(() => step.value === 'opening')
/** The button that asked for the dialog; it alone spins while it opens. */
const addPressed = ref<string | null>(null)
const onStepUp = computed(
  () => step.value === 'step-up' || step.value === 'refused',
)
const addTitle = computed(() =>
  onStepUp.value ? HILOS_STEP_UP_COPY.title : 'Add a way to sign in',
)
const addDraft = ref({
  email: '',
  phone: '',
  code: '',
  newPassword: '',
  confirm: '',
})
const addBody = ref<HTMLElement | null>(null)
const addOpen = computed({
  get: () => step.value !== 'closed' && step.value !== 'opening',
  set: (open) => {
    if (!open) flow.close()
  },
})
const addDirty = computed(() =>
  Object.values(addDraft.value).some((value) => value !== ''),
)
function openAdd(way?: HilosProfileAddableWay): void {
  addPressed.value = way === undefined ? 'add' : wayKey(way)
  addDraft.value = {
    email: '',
    phone: '',
    code: '',
    newPassword: '',
    confirm: '',
  }
  void flow.open(way)
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

function sendAgainAdd(): void {
  addDraft.value.code = ''
  void flow.sendAgain()
}
onUnmounted(() => {
  stopPassword()
  passwordFlow.dispose()
  flow.dispose()
})
</script>

<template>
  <section data-id="profile-sign-in-view">
    <HilosPageHeading />
    <div
      v-if="passkeyOnly"
      class="alert alert-warning d-flex gap-2"
      data-id="profile-sign-in-passkey-only"
    >
      <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
      <div>
        <div class="fw-semibold">
          {{ HILOS_PROFILE_SIGN_IN_COPY.passkeyOnlyTitle }}
        </div>
        <p class="mb-2">{{ HILOS_PROFILE_SIGN_IN_COPY.passkeyOnlyReason }}</p>
        <template v-if="passkeyOnlyWays.length > 0">
          <p class="mb-2">{{ HILOS_PROFILE_SIGN_IN_COPY.passkeyOnlyAdd }}</p>
          <div class="d-flex flex-wrap gap-2">
            <LoadingButton
              v-for="way in passkeyOnlyWays"
              :key="wayKey(way)"
              class="btn-sm btn-outline-secondary"
              :loading="addOpening && addPressed === wayKey(way)"
              :disabled="addOpening"
              :data-id="passkeyOnlyWayId(way)"
              @click="openAdd(way)"
              >{{ passkeyOnlyWayLabel(way) }}</LoadingButton
            >
          </div>
        </template>
        <p v-else class="mb-0" data-id="profile-sign-in-passkey-only-none">
          {{ HILOS_PROFILE_SIGN_IN_COPY.passkeyOnlyNone }}
        </p>
      </div>
    </div>
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
          <LoadingButton
            v-if="method.type === 'password'"
            class="btn-sm btn-outline-secondary"
            :loading="passwordStep === 'opening'"
            data-id="profile-password-change"
            @click="passwordFlow.open()"
            >Change</LoadingButton
          >
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
    <LoadingButton
      class="btn-sm btn-outline-primary mt-3"
      :loading="addOpening && addPressed === 'add'"
      :disabled="addOpening"
      data-id="profile-sign-in-add"
      @click="openAdd()"
      >Add a way to sign in</LoadingButton
    >

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

    <HilosProfilePasswordChange :flow="passwordFlow" />

    <HilosModal
      v-model="addOpen"
      :title="addTitle"
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
          {{ step === 'step-up' ? stepUpRefusal : refusal }}
        </div>
        <div class="hilos-stack">
          <div class="invisible" aria-hidden="true" inert>
            <div :class="SEND_PROGRESS_ROW_CLASS">
              <i
                class="bi bi-hourglass-split flex-shrink-0"
                aria-hidden="true"
              ></i>
              <span class="flex-grow-1 text-truncate">&nbsp;</span>
              <span :class="SEND_PROGRESS_DETAILS_CLASS"
                ><i class="bi bi-info-circle" aria-hidden="true"></i
              ></span>
            </div>
            <div class="mb-3">
              <span class="btn btn-link btn-sm p-0">{{
                SEND_AGAIN_LABEL
              }}</span>
            </div>
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
            <template v-for="way in ways" :key="wayKey(way)">
              <span
                v-if="way.kind === 'password'"
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
              <span
                v-else-if="way.kind === 'phone'"
                class="btn btn-outline-secondary"
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
                v-else-if="way.kind === 'provider'"
                class="btn btn-outline-secondary"
                ><span class="d-flex align-items-center gap-3 text-start"
                  ><i
                    class="bi bi-box-arrow-in-right fs-5"
                    aria-hidden="true"
                  ></i
                  ><span
                    ><span class="d-block fw-semibold small">{{
                      way.label
                    }}</span
                    ><span class="d-block small text-body-secondary">{{
                      HILOS_PROFILE_SIGN_IN_COPY.providerDescription
                    }}</span></span
                  ></span
                ></span
              >
              <span v-else class="btn btn-outline-secondary"
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
            </template>
          </div>
          <form
            v-if="step === 'step-up'"
            :id="`${baseId}-step-up`"
            class="align-self-start"
            data-id="profile-sign-in-add-step-up"
            @submit.prevent="flow.confirmStepUp()"
          >
            <HilosStepUpStep :controller="flow.stepUp" />
          </form>
          <div v-else-if="step === 'refused'" class="align-self-start"></div>
          <div
            v-else-if="step === 'choose'"
            class="d-flex flex-column gap-2 align-self-start"
          >
            <template v-for="way in ways" :key="wayKey(way)">
              <button
                v-if="way.kind === 'password'"
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
                v-else-if="way.kind === 'phone'"
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
                v-else-if="way.kind === 'provider'"
                class="btn btn-outline-secondary"
                :loading="busy && pendingProvider === way.key"
                :disabled="busy"
                :data-id="`profile-oauth-link-${way.key}`"
                @click="flow.chooseProvider(way.key)"
                ><span class="d-flex align-items-center gap-3 text-start"
                  ><i
                    class="bi bi-box-arrow-in-right fs-5"
                    aria-hidden="true"
                  ></i
                  ><span
                    ><span class="d-block fw-semibold small">{{
                      way.label
                    }}</span
                    ><span class="d-block small text-body-secondary">{{
                      HILOS_PROFILE_SIGN_IN_COPY.providerDescription
                    }}</span></span
                  ></span
                ></LoadingButton
              >
              <LoadingButton
                v-else
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
            </template>
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
              <HilosSendProgress
                v-if="field.key === 'code'"
                :progress="sendProgress"
                :to="step === 'phone-code' ? sentPhone : sentEmail"
                :resend-at="resendAt"
                :busy="busy"
                :data-id="
                  step === 'phone-code'
                    ? 'profile-add-sms-send'
                    : 'profile-add-password-send'
                "
                @send-again="sendAgainAdd"
              />
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
        <LoadingButton
          v-if="step === 'step-up'"
          type="submit"
          :form="`${baseId}-step-up`"
          class="btn btn-primary"
          :loading="stepUpBusy"
          :disabled="stepUpBusy"
          data-id="profile-sign-in-add-step-up-confirm"
          >{{ HILOS_STEP_UP_COPY.confirm }}</LoadingButton
        >
        <template v-else-if="step !== 'choose' && step !== 'refused'">
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="busy"
            data-id="profile-sign-in-add-back"
            @click="flow.back"
          >
            Back
          </button>
          <LoadingButton
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
      </template>
    </HilosModal>
  </section>
</template>
