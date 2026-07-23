<!-- The project's in-place sign-in surface (HIL-364). The framework auth-gate
slot (HIL-165) mounts it in place of ErrorPage on an anonymous 401, and in the
HilosModal for a gated action; this component is presentation-agnostic — it only
renders the modes and submits, and the gate resumes the page / closes the modal
off the session upgrade with no navigation.

One component over the core auth surface state machine: a single small machine
(no store flow logic — the hleb defect) drives the mode, the form, and the
pending/error state; the login↔register switcher and the reachable recovery entry
come from the project's ordered method registry (email+password only for now).
Login and register dispatch their MainPage actions with request/response
correlation and surface the backend reason inline; recovery detail is deferred to
HIL-365, so its entry renders a reachable placeholder. Bootstrap classes only, no
CSS of its own (styling-rules.md). -->
<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import {
  createAuthSurface,
  MAGIC_LINK_AUTH_METHOD,
  OAUTH_GITHUB_AUTH_METHOD,
  PASSKEY_AUTH_METHOD,
  PASSKEY_DISCOVERABLE_AUTH_METHOD,
  PASSWORD_AUTH_METHOD,
  PASSWORD_MIN_LENGTH,
  SMS_AUTH_METHOD,
  type AuthField,
  type AuthMethodDescriptor,
} from '@hilos/core'
import { LoadingButton, useSignal } from '@hilos/vue'

import { submitAuth } from './authActions'
import {
  describeOAuthError,
  peekOAuthLink,
  startOAuthLogin,
} from './oauthLogin'
import { runPasskeyDiscoverableLogin } from './passkeyCeremony'

defineOptions({ name: 'AuthSurface' })

// The project declares its ORDERED enabled method registry here — the thin
// extension point. Only email+password ships now; an OAuth or passkey descriptor
// is prepended/appended later without touching the surface or the machine.
const methods: AuthMethodDescriptor[] = [
  PASSWORD_AUTH_METHOD,
  SMS_AUTH_METHOD,
  MAGIC_LINK_AUTH_METHOD,
  PASSKEY_AUTH_METHOD,
  PASSKEY_DISCOVERABLE_AUTH_METHOD,
  OAUTH_GITHUB_AUTH_METHOD,
]
const surface = createAuthSurface({ methods, onSubmit: submitAuth })

// The redirect methods (OAuth): rendered on the login surface as external
// "Continue with …" buttons, not as machine modes — the surface owns no OAuth
// mode. The button dispatches the start action; the browser leaves for the
// provider off the authorize signal (oauthLogin), so a successful start keeps its
// loading through the redirect and only a rejection clears it with an inline error.
const oauthMethods = methods.filter((method) => method.key.startsWith('oauth:'))
const oauthPending = ref<string | null>(null)
const oauthError = ref<string | null>(null)

// The usernameless / discoverable passkey method (HIL-400): an action button on
// the login entry (no email, no machine mode) whose click runs the whole
// discoverable round-trip; the session upgrade closes the surface, so a running
// click stays loading until success and only a failure clears it with an inline
// error. Absent-safe: null when the project did not enable the method.
const discoverablePasskeyMethod =
  methods.find((method) => method.key === PASSKEY_DISCOVERABLE_AUTH_METHOD.key) ??
  null
const discoverablePending = ref(false)
const discoverableError = ref<string | null>(null)

// Set on mount when an OAuth email collision armed a pending link (HIL-282): the
// account already exists, so the surface pre-fills its email and shows a "finish
// linking" prompt asking the user to sign in with an existing method. The token
// replay itself is the global watcher's job (oauthLogin), not this component's.
const linkPrompt = ref(false)

const mode = useSignal(surface.mode)
const form = useSignal(surface.form)
const pending = useSignal(surface.pending)
const error = useSignal(surface.error)
const submittable = useSignal(surface.submittable)

const canRegister = surface.entries.includes('register')
const canRecover = surface.entries.includes('recovery')
const canSms = surface.entries.includes('sms')
const canMagicLink = surface.entries.includes('magic_link')
const canPasskey = surface.entries.includes('passkey')
const isFormMode = computed(
  () => mode.value === 'login' || mode.value === 'register',
)
const isSmsMode = computed(
  () => mode.value === 'sms_request' || mode.value === 'sms_confirm',
)

// The magic-link request has no in-form confirm step (the emailed link is
// confirmed on the /auth/magic route), so a successful request has no next mode
// to advance to. This local flag turns the form into a generic "check your email"
// acknowledgement; it resets whenever the mode changes.
const magicSent = ref(false)
watch(mode, () => {
  magicSent.value = false
  oauthError.value = null
  discoverableError.value = null
})

const heading = computed(() => {
  switch (mode.value) {
    case 'register':
      return 'Create your account'
    case 'recovery_request':
    case 'recovery_confirm':
    case 'recovery_set':
      return 'Reset your password'
    case 'sms_request':
    case 'sms_confirm':
      return 'Sign in with your phone'
    case 'magic_link_request':
      return 'Email me a sign-in link'
    case 'passkey':
      return 'Sign in with a passkey'
    default:
      return 'Sign in'
  }
})
const submitLabel = computed(() => {
  switch (mode.value) {
    case 'register':
      return 'Create account'
    case 'sms_request':
      return 'Send code'
    case 'magic_link_request':
      return 'Send link'
    case 'passkey':
      return 'Use passkey'
    default:
      return 'Sign in'
  }
})

/**
 * Mirror one input into the machine's form field.
 *
 * @param field The form field to set.
 * @param event The input event.
 */
function update(field: AuthField, event: Event): void {
  surface.setField(field, (event.target as HTMLInputElement).value)
}

function submit(): void {
  if (!submittable.value || pending.value) {
    return
  }
  void surface.submit()
}

// Magic-link request: submit, then on a clean (ok, no error) outcome show the
// generic sent acknowledgement — the backend always answers generically
// (login-only, anti-enumeration), so this reveals nothing about the address.
async function submitMagicRequest(): Promise<void> {
  if (!submittable.value || pending.value) {
    return
  }
  await surface.submit()
  if (!error.value) {
    magicSent.value = true
  }
}

/** The data-id slug for a method's redirect button, e.g. `auth-oauth-github`. */
function oauthDataId(key: string): string {
  return `auth-oauth-${key.replace(/^oauth:/, '')}`
}

// Begin a redirect login: dispatch the start action and, on the "accepted" ack,
// keep the button loading while the authorize signal navigates the browser to the
// provider (oauthLogin). A rejection — an unknown provider, a timeout, or a
// dropped connection — clears loading and surfaces the reason inline.
async function continueWithOAuth(method: AuthMethodDescriptor): Promise<void> {
  if (oauthPending.value !== null) {
    return
  }
  oauthPending.value = method.key
  oauthError.value = null
  try {
    await startOAuthLogin(method.key)
  } catch (error) {
    oauthPending.value = null
    oauthError.value = describeOAuthError(error)
  }
}

// Begin a usernameless passkey sign-in (HIL-400): run the discoverable ceremony
// (empty allowCredentials → OS picker → confirm). On success the session upgrade
// closes the surface via the auth gate, so keep loading; a cancelled picker or a
// rejected assertion clears loading and surfaces the reason inline.
async function continueWithDiscoverablePasskey(): Promise<void> {
  if (discoverablePending.value) {
    return
  }
  discoverablePending.value = true
  discoverableError.value = null
  const outcome = await runPasskeyDiscoverableLogin()
  if (!outcome.ok) {
    discoverablePending.value = false
    discoverableError.value = outcome.message ?? null
  }
}

// Start each mount clean: the surface may be re-shown for a new gated action.
// When an OAuth collision armed a pending link, pre-fill its email and raise the
// "finish linking" prompt so the user re-authenticates the existing account.
onMounted(() => {
  surface.reset()
  oauthPending.value = null
  oauthError.value = null
  discoverablePending.value = false
  discoverableError.value = null
  const pendingLink = peekOAuthLink()
  linkPrompt.value = pendingLink !== null
  if (pendingLink !== null) {
    surface.setField('email', pendingLink.email)
  }
})
</script>

<template>
  <section data-id="auth-surface" class="mx-auto" style="max-width: 24rem">
    <h1 class="h4 mb-3" data-id="auth-heading">{{ heading }}</h1>

    <!-- OAuth email-collision re-auth prompt (HIL-282): the provider email already
    has an account, so ask the user to sign in with an existing method to finish
    linking. The pending link token is redeemed globally once the session upgrades. -->
    <div
      v-if="linkPrompt && mode === 'login'"
      class="alert alert-info"
      role="status"
      data-id="auth-link-prompt"
    >
      That email already has an account. Sign in to finish linking it.
    </div>

    <form v-if="isFormMode" novalidate @submit.prevent="submit">
      <div class="mb-3">
        <label class="form-label" for="auth-email">Email</label>
        <input
          id="auth-email"
          type="email"
          class="form-control"
          autocomplete="username"
          data-autofocus
          data-id="auth-email"
          :value="form.email"
          @input="update('email', $event)"
        />
      </div>

      <div class="mb-3">
        <label class="form-label" for="auth-password">Password</label>
        <input
          id="auth-password"
          type="password"
          class="form-control"
          :autocomplete="
            mode === 'register' ? 'new-password' : 'current-password'
          "
          data-id="auth-password"
          :value="form.password"
          @input="update('password', $event)"
        />
        <div v-if="mode === 'register'" class="form-text">
          At least {{ PASSWORD_MIN_LENGTH }} characters.
        </div>
      </div>

      <div v-if="mode === 'register'" class="mb-3">
        <label class="form-label" for="auth-confirm">Confirm password</label>
        <input
          id="auth-confirm"
          type="password"
          class="form-control"
          autocomplete="new-password"
          data-id="auth-confirm"
          :value="form.confirmPassword"
          @input="update('confirmPassword', $event)"
        />
      </div>

      <div
        v-if="error"
        class="alert alert-danger py-2"
        role="alert"
        data-id="auth-error"
      >
        {{ error }}
      </div>

      <LoadingButton
        type="submit"
        class="btn-primary w-100"
        :loading="pending"
        :disabled="!submittable"
        data-id="auth-submit"
      >
        {{ submitLabel }}
      </LoadingButton>
    </form>

    <!-- SMS one-time-code sign-in (HIL-280): request a code for a phone, then
    submit it. A valid code signs in an existing phone or creates one. -->
    <form v-else-if="isSmsMode" novalidate @submit.prevent="submit">
      <div v-if="mode === 'sms_request'" class="mb-3">
        <label class="form-label" for="auth-phone">Phone number</label>
        <input
          id="auth-phone"
          type="tel"
          class="form-control"
          autocomplete="tel"
          data-autofocus
          data-id="auth-phone"
          :value="form.phone"
          @input="update('phone', $event)"
        />
        <div class="form-text">We'll text you a one-time sign-in code.</div>
      </div>

      <div v-else class="mb-3">
        <label class="form-label" for="auth-sms-code">Code</label>
        <input
          id="auth-sms-code"
          type="text"
          inputmode="numeric"
          class="form-control"
          autocomplete="one-time-code"
          data-autofocus
          data-id="auth-sms-code"
          :value="form.code"
          @input="update('code', $event)"
        />
        <div class="form-text">Sent to {{ form.phone }}.</div>
      </div>

      <div
        v-if="error"
        class="alert alert-danger py-2"
        role="alert"
        data-id="auth-error"
      >
        {{ error }}
      </div>

      <LoadingButton
        type="submit"
        class="btn-primary w-100"
        :loading="pending"
        :disabled="!submittable"
        data-id="auth-submit"
      >
        {{ submitLabel }}
      </LoadingButton>
    </form>

    <!-- Email magic-link sign-in (HIL-283): request a passwordless link for an
    email. Login-only — the response is always the same generic acknowledgement,
    and the emailed link is confirmed on the /auth/magic route. -->
    <template v-else-if="mode === 'magic_link_request'">
      <div
        v-if="magicSent"
        class="alert alert-success"
        role="status"
        data-id="auth-magic-sent"
      >
        If an account exists for that email, we've sent it a sign-in link. Check
        your inbox and open the link to continue.
      </div>

      <form v-else novalidate @submit.prevent="submitMagicRequest">
        <div class="mb-3">
          <label class="form-label" for="auth-magic-email">Email</label>
          <input
            id="auth-magic-email"
            type="email"
            class="form-control"
            autocomplete="username"
            data-autofocus
            data-id="auth-magic-email"
            :value="form.email"
            @input="update('email', $event)"
          />
          <div class="form-text">We'll email you a one-time sign-in link.</div>
        </div>

        <div
          v-if="error"
          class="alert alert-danger py-2"
          role="alert"
          data-id="auth-error"
        >
          {{ error }}
        </div>

        <LoadingButton
          type="submit"
          class="btn-primary w-100"
          :loading="pending"
          :disabled="!submittable"
          data-id="auth-submit"
        >
          {{ submitLabel }}
        </LoadingButton>
      </form>
    </template>

    <!-- Passkey sign-in (HIL-284): username-first — enter an email, then the
    WebAuthn assertion round-trip runs on submit (passkeyCeremony) and upgrades the
    session. Registering a passkey lives in the signed-in profile, not here. -->
    <form v-else-if="mode === 'passkey'" novalidate @submit.prevent="submit">
      <div class="mb-3">
        <label class="form-label" for="auth-passkey-email">Email</label>
        <input
          id="auth-passkey-email"
          type="email"
          class="form-control"
          autocomplete="username webauthn"
          data-autofocus
          data-id="auth-passkey-email"
          :value="form.email"
          @input="update('email', $event)"
        />
        <div class="form-text">
          We'll ask your device to confirm your passkey.
        </div>
      </div>

      <div
        v-if="error"
        class="alert alert-danger py-2"
        role="alert"
        data-id="auth-error"
      >
        {{ error }}
      </div>

      <LoadingButton
        type="submit"
        class="btn-primary w-100"
        :loading="pending"
        :disabled="!submittable"
        data-id="auth-submit"
      >
        {{ submitLabel }}
      </LoadingButton>
    </form>

    <!-- Recovery is reachable but its backend (HIL-365) is not landed yet. -->
    <div
      v-else
      class="alert alert-info"
      role="status"
      data-id="auth-recovery-placeholder"
    >
      Password recovery is coming soon.
    </div>

    <!-- Redirect sign-in (HIL-281): external "Continue with …" providers, shown
    on the login surface only. Each button starts a redirect; the browser leaves
    for the provider off the authorize signal, so a running button stays loading
    through the navigation. -->
    <div
      v-if="mode === 'login' && oauthMethods.length > 0"
      class="mt-3"
      data-id="auth-oauth"
    >
      <div class="d-flex align-items-center text-muted small mb-3">
        <hr class="flex-grow-1" />
        <span class="px-2">or</span>
        <hr class="flex-grow-1" />
      </div>

      <div
        v-if="oauthError"
        class="alert alert-danger py-2"
        role="alert"
        data-id="auth-oauth-error"
      >
        {{ oauthError }}
      </div>

      <button
        v-for="method in oauthMethods"
        :key="method.key"
        type="button"
        class="btn btn-outline-secondary w-100 d-flex align-items-center justify-content-center gap-2"
        :disabled="oauthPending !== null"
        :data-id="oauthDataId(method.key)"
        @click="continueWithOAuth(method)"
      >
        <span
          v-if="oauthPending === method.key"
          class="spinner-border spinner-border-sm"
          role="status"
          aria-hidden="true"
        ></span>
        {{ method.label }}
      </button>
    </div>

    <!-- Usernameless / discoverable passkey (HIL-400): a one-tap sign-in action on
    the login entry — no email. Like OAuth it is an action button, not a machine
    mode: the click runs the whole discoverable round-trip (empty allowCredentials
    → OS picker → confirm) and the session upgrade closes the surface. -->
    <div
      v-if="mode === 'login' && discoverablePasskeyMethod !== null"
      class="mt-3"
      data-id="auth-passkey-discoverable-block"
    >
      <div
        v-if="discoverableError"
        class="alert alert-danger py-2"
        role="alert"
        data-id="auth-passkey-discoverable-error"
      >
        {{ discoverableError }}
      </div>

      <button
        type="button"
        class="btn btn-outline-secondary w-100 d-flex align-items-center justify-content-center gap-2"
        :disabled="discoverablePending"
        data-id="auth-passkey-discoverable"
        @click="continueWithDiscoverablePasskey()"
      >
        <span
          v-if="discoverablePending"
          class="spinner-border spinner-border-sm"
          role="status"
          aria-hidden="true"
        ></span>
        {{ discoverablePasskeyMethod.label }}
      </button>
    </div>

    <div class="d-flex justify-content-between mt-3 small">
      <button
        v-if="mode !== 'login'"
        type="button"
        class="btn btn-link p-0"
        data-id="auth-to-login"
        @click="surface.switchTo('login')"
      >
        Sign in
      </button>
      <button
        v-if="mode === 'login' && canRegister"
        type="button"
        class="btn btn-link p-0"
        data-id="auth-to-register"
        @click="surface.switchTo('register')"
      >
        Create account
      </button>
      <button
        v-if="mode === 'login' && canRecover"
        type="button"
        class="btn btn-link p-0"
        data-id="auth-to-recovery"
        @click="surface.switchTo('recovery_request')"
      >
        Forgot password?
      </button>
      <button
        v-if="mode === 'login' && canSms"
        type="button"
        class="btn btn-link p-0"
        data-id="auth-to-sms"
        @click="surface.switchTo('sms_request')"
      >
        Sign in with phone
      </button>
      <button
        v-if="mode === 'login' && canMagicLink"
        type="button"
        class="btn btn-link p-0"
        data-id="auth-to-magic"
        @click="surface.switchTo('magic_link_request')"
      >
        Email me a link
      </button>
      <button
        v-if="mode === 'login' && canPasskey"
        type="button"
        class="btn btn-link p-0"
        data-id="auth-to-passkey"
        @click="surface.switchTo('passkey')"
      >
        Use a passkey
      </button>
    </div>
  </section>
</template>
