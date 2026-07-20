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
  PASSWORD_AUTH_METHOD,
  PASSWORD_MIN_LENGTH,
  SMS_AUTH_METHOD,
  type AuthField,
} from '@hilos/core'
import { LoadingButton, useSignal } from '@hilos/vue'

import { submitAuth } from './authActions'

defineOptions({ name: 'AuthSurface' })

// The project declares its ORDERED enabled method registry here — the thin
// extension point. Only email+password ships now; an OAuth or passkey descriptor
// is prepended/appended later without touching the surface or the machine.
const surface = createAuthSurface({
  methods: [PASSWORD_AUTH_METHOD, SMS_AUTH_METHOD, MAGIC_LINK_AUTH_METHOD],
  onSubmit: submitAuth,
})

const mode = useSignal(surface.mode)
const form = useSignal(surface.form)
const pending = useSignal(surface.pending)
const error = useSignal(surface.error)
const submittable = useSignal(surface.submittable)

const canRegister = surface.entries.includes('register')
const canRecover = surface.entries.includes('recovery')
const canSms = surface.entries.includes('sms')
const canMagicLink = surface.entries.includes('magic_link')
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

// Start each mount clean: the surface may be re-shown for a new gated action.
onMounted(() => {
  surface.reset()
})
</script>

<template>
  <section data-id="auth-surface" class="mx-auto" style="max-width: 24rem">
    <h1 class="h4 mb-3" data-id="auth-heading">{{ heading }}</h1>

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
    <form
      v-else-if="isSmsMode"
      novalidate
      @submit.prevent="submit"
    >
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

    <!-- Recovery is reachable but its backend (HIL-365) is not landed yet. -->
    <div
      v-else
      class="alert alert-info"
      role="status"
      data-id="auth-recovery-placeholder"
    >
      Password recovery is coming soon.
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
    </div>
  </section>
</template>
