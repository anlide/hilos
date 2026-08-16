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
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import {
  createAuthSurface,
  MAGIC_LINK_AUTH_METHOD,
  OAUTH_GITHUB_AUTH_METHOD,
  PASSKEY_DISCOVERABLE_AUTH_METHOD,
  PASSWORD_AUTH_METHOD,
  PASSWORD_MIN_LENGTH,
  SMS_AUTH_METHOD,
  SMS_CODE_CHANNEL,
  TELEGRAM_CODE_CHANNEL,
  toLocal,
  type AuthField,
  type AuthMethodDescriptor,
  type AuthMode,
  type AuthSubmitOutcome,
  type CodeChannelDescriptor,
} from '@hilos/core'
import { LoadingButton, useSignal } from '@hilos/vue'

import {
  abandonRegistration,
  requestPhoneCode,
  resendRegisterCode,
  submitAuth,
  subscribeCodeChannelUnavailable,
} from './authActions'
import {
  describeOAuthError,
  peekOAuthLink,
  startOAuthLogin,
} from './oauthLogin'
import { runPasskeyDiscoverableLogin } from './passkeyCeremony'
import { pendingRegistration } from '../bootstrap/session'

defineOptions({ name: 'AuthSurface' })

/** How often the expiry countdown redraws — one second, the smallest unit it shows. */
const COUNTDOWN_TICK_MS = 1000

/** Milliseconds in a second, for reading a remaining span as a clock. */
const MS_PER_SECOND = 1000

/** Seconds in a minute, for the same. */
const SECONDS_PER_MINUTE = 60

// The project declares its ORDERED enabled method registry here — the thin
// extension point. Only email+password ships now; an OAuth or passkey descriptor
// is prepended/appended later without touching the surface or the machine.
const methods: AuthMethodDescriptor[] = [
  PASSWORD_AUTH_METHOD,
  SMS_AUTH_METHOD,
  MAGIC_LINK_AUTH_METHOD,
  PASSKEY_DISCOVERABLE_AUTH_METHOD,
  OAUTH_GITHUB_AUTH_METHOD,
]
// The project declares its ORDERED code channels the same way it declares methods
// (HIL-492): SMS is the primary and draws the button, the rest draw the icon row
// under it. Adding a channel here and in the backend registry is the whole change —
// nothing below reads a channel by name.
const codeChannels: CodeChannelDescriptor[] = [
  SMS_CODE_CHANNEL,
  TELEGRAM_CODE_CHANNEL,
]
const primaryChannel =
  codeChannels.find((channel) => channel.primary) ?? codeChannels[0]
const secondaryChannels = codeChannels.filter(
  (channel) => channel.key !== primaryChannel.key,
)

// Which channel the running phone-code request is going over. The primary button
// and each icon are the same submit with a different value here, which is why only
// this ref — and not a second code path — separates them.
const chosenChannel = ref(primaryChannel.key)

// The channel the DELIVERED code went over, taken from the outcome rather than from
// the click: the code screen names it, and a click is only a request while the
// outcome is the fact.
const deliveredChannel = ref(primaryChannel.key)

// Channels that answered "cannot reach this number" (HIL-492). Client state, not
// stored anywhere: it is true of a number and not of an account, so it is cleared
// the moment the number changes.
const unavailableChannels = ref(new Set<string>())

// Bootstrap: the icon of a channel that already said no stays dimmed until the
// person edits the number, so a second click cannot repeat a request that is known
// to fail.
let stopWatchingChannels: (() => void) | null = null
onMounted(() => {
  stopWatchingChannels = subscribeCodeChannelUnavailable((channel) => {
    unavailableChannels.value = new Set(unavailableChannels.value).add(channel)
  })
})
onUnmounted(() => {
  stopWatchingChannels?.()
  stopWatchingChannels = null
})

// The phone-code request is the one submit that names a channel, so it bypasses
// the generic action map — everything else is unchanged.
const surface = createAuthSurface({
  methods,
  onSubmit: async (submitMode, submitForm) => {
    if (submitMode !== 'sms_request') {
      const outcome = await submitAuth(submitMode, submitForm)
      armExpiry(outcome)

      return outcome
    }

    const outcome = await requestPhoneCode(submitForm.phone, chosenChannel.value)
    deliveredChannel.value = outcome.channel
    armExpiry(outcome)

    return outcome
  },
})

/**
 * Send the code over one channel: remember which, then run the ordinary submit.
 *
 * @param channel The channel key the person picked.
 */
function sendCodeOver(channel: string): void {
  chosenChannel.value = channel
  void surface.submit()
}

// Bootstrap icons per channel key. The view owns this and the core contract does
// not carry it: an icon is a property of the surface's design language, and a
// channel the framework knows about must not be able to prescribe one.
const channelIcons: Record<string, string> = {
  [SMS_CODE_CHANNEL.key]: 'bi bi-chat-dots',
  [TELEGRAM_CODE_CHANNEL.key]: 'bi bi-telegram',
}

/**
 * The label of the channel a delivered code went over, for the code screen.
 *
 * Off the DELIVERED channel and not the clicked one, which is the same distinction
 * `deliveredChannel` is declared for: a code can fall back to another channel, and a
 * screen resumed after a reload (HIL-486) had no click at all — the channel comes
 * back with the step, from the server.
 */
const deliveredChannelLabel = computed(
  () =>
    codeChannels.find((channel) => channel.key === deliveredChannel.value)
      ?.label ?? deliveredChannel.value,
)

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

// The registration code step's resend link (HIL-415): a dispatch of its own, not
// the step's submit — the submit sends the code being typed, this asks for a new
// letter. Inside the cooldown the backend answers ok and mails nothing; only a
// refusal (the hold on the address ran out) has anything to say, and it says it
// here rather than in the machine's error, which belongs to the submit.
const resendPending = ref(false)
const resendError = ref<string | null>(null)

// Set on mount when an OAuth email collision armed a pending link (HIL-282): the
// account already exists, so the surface pre-fills its email and shows a "finish
// linking" prompt asking the user to sign in with an existing method. The token
// replay itself is the global watcher's job (oauthLogin), not this component's.
const linkPrompt = ref(false)

const resumable = useSignal(pendingRegistration)
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
  oauthError.value = null
  discoverableError.value = null
  resendError.value = null
})

// The LOCAL moment the code or link this screen is waiting on stops being good
// (HIL-486), or null while nothing is waiting. It always arrives from the server —
// off the submit that sent something, or off the handshake for a registration this
// session started elsewhere — because a duration measured in the tab is spent by a
// reload, which is exactly the moment this screen has to be honest about.
const expiresAt = ref<number | null>(null)

// The clock the countdown is read against. A ticking ref rather than Date.now()
// inside the computed: a computed only recomputes when something it read changes,
// so a bare Date.now() would freeze the number at whatever it was when the screen
// opened and the code would "expire" without the display ever moving.
const now = ref(Date.now())
let clock: ReturnType<typeof setInterval> | null = null
onMounted(() => {
  clock = setInterval(() => {
    now.value = Date.now()
  }, COUNTDOWN_TICK_MS)
})
onUnmounted(() => {
  if (clock !== null) {
    clearInterval(clock)
    clock = null
  }
})

// The three screens that wait on something sent: the two code screens and the
// "check your inbox" acknowledgement. The countdown is gated on being one of them
// rather than on the moment alone, so a spent expiry left over from an abandoned
// screen cannot disable the identifier field somebody came back to.
const waiting = computed(
  () =>
    mode.value === 'register_confirm' ||
    mode.value === 'sms_confirm' ||
    (mode.value === 'magic_link_request' && magicSent.value),
)

/** What the screen is waiting on, for the one sentence that names it. */
const waitingSubject = computed(() =>
  mode.value === 'magic_link_request' ? 'link' : 'code',
)

const remainingMs = computed(() =>
  expiresAt.value === null || !waiting.value
    ? null
    : expiresAt.value - now.value,
)

// Zero does NOT roll the step back: the truth about a freed identifier is the
// server's (the sweep broadcasts it), so reaching zero blocks the input and offers
// a way out, and nothing more.
const expired = computed(
  () => remainingMs.value !== null && remainingMs.value <= 0,
)

/** The remaining life as `m:ss`, or null when nothing is counting down. */
const expiresIn = computed(() => {
  const remaining = remainingMs.value
  if (remaining === null || remaining <= 0) {
    return null
  }
  const seconds = Math.ceil(remaining / MS_PER_SECOND)

  return (
    Math.floor(seconds / SECONDS_PER_MINUTE) +
    ':' +
    String(seconds % SECONDS_PER_MINUTE).padStart(2, '0')
  )
})

/**
 * The identifier step each waiting screen goes back to. The magic-link screen is
 * absent on purpose: its acknowledgement and its form are one mode, so clearing
 * the acknowledgement IS the way back.
 */
const IDENTIFIER_MODE_OF: Partial<Record<AuthMode, AuthMode>> = {
  register_confirm: 'register',
  sms_confirm: 'sms_request',
}

/**
 * Start the countdown of what a submit just left waiting.
 *
 * Silence leaves the current countdown alone rather than clearing it: a mistyped
 * code is answered by an outcome with no moment in it, and the screen it lands on
 * is still counting down the very code being retyped.
 *
 * @param outcome The outcome the submit resolved to.
 */
function armExpiry(outcome: AuthSubmitOutcome): void {
  if (outcome.expiresAt !== undefined) {
    expiresAt.value = toLocal(outcome.expiresAt)
  }
}

/**
 * Give up what this screen is waiting on and go back to the identifier field.
 *
 * The "not that address?" way out (HIL-486). The session's memory of the step is
 * dropped on the server — so no tab of it, and no later reload, comes back to this
 * screen — while the hold on the identifier itself is left alone, which is what
 * stops one session from freeing an address another one is registering.
 */
function restart(): void {
  void abandonRegistration()
  expiresAt.value = null
  magicSent.value = false
  const back = IDENTIFIER_MODE_OF[mode.value]
  if (back !== undefined) {
    surface.switchTo(back)
  }
}

const heading = computed(() => {
  switch (mode.value) {
    case 'register':
      return 'Create your account'
    case 'register_confirm':
      return 'Confirm your email address'
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
    case 'register_confirm':
      return 'Confirm'
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
  if (field === 'phone') {
    // A dimmed channel is dimmed about a NUMBER, not about the person: editing the
    // number makes every channel worth asking again.
    unavailableChannels.value = new Set()
  }
}

function submit(): void {
  if (!submittable.value || pending.value) {
    return
  }
  if (mode.value === 'sms_request') {
    // Submitting the FORM is the primary channel — the button under the field —
    // while an icon is its own entry point and sets itself. Without this, a primary
    // send after a failed icon would silently repeat the icon's channel (HIL-492).
    chosenChannel.value = primaryChannel.key
  }
  void surface.submit()
}

// Magic-link request: submit, then on a clean (ok, no error) outcome show the
// same sent acknowledgement either way — the request is login-only today and
// answers an address it cannot mail with the same silent success (HIL-417 is what
// makes a link register too), so there is no second answer to render.
async function submitMagicRequest(): Promise<void> {
  if (!submittable.value || pending.value) {
    return
  }
  await surface.submit()
  if (!error.value) {
    magicSent.value = true
  }
}

// Ask for another confirmation letter for the address being registered. The
// cooldown lives on the backend (the address, not this session, owns it), so this
// only dispatches and shows a refusal; the countdown before the link is offered
// at all is HIL-423.
async function resendCode(): Promise<void> {
  if (resendPending.value) {
    return
  }
  resendPending.value = true
  resendError.value = null
  const outcome = await resendRegisterCode(form.value.email)
  resendPending.value = false
  if (!outcome.ok) {
    resendError.value = outcome.message ?? null

    return
  }
  // A fresh letter is a fresh life for the code, and a re-send the cooldown
  // swallowed answers the life the code already on its way has left.
  armExpiry(outcome)
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
  expiresAt.value = null
  resendPending.value = false
  resendError.value = null
  oauthPending.value = null
  oauthError.value = null
  discoverablePending.value = false
  discoverableError.value = null
  const pendingLink = peekOAuthLink()
  linkPrompt.value = pendingLink !== null
  if (pendingLink !== null) {
    surface.setField('email', pendingLink.email)
  }
  resumePendingRegistration()
})

/**
 * Come back to the code screen of a registration this SESSION started and never
 * finished (HIL-486). The step is read from the session scope, where the
 * handshake put it — not from anything this tab remembers — so a reload, a
 * second tab and another device all resume the same screen, and closing the tab
 * loses nothing.
 */
function resumePendingRegistration(): void {
  const resumed = resumable.value
  if (resumed === null) {
    return
  }
  // Already on this browser's scale (the session scope converts every moment it
  // hands out), so it is compared with Date.now() and never with the server's.
  expiresAt.value = resumed.expiresAt
  if (resumed.kind === 'phone') {
    surface.switchTo('sms_confirm')
    surface.setField('phone', resumed.identifier)
    deliveredChannel.value = resumed.channel ?? primaryChannel.key

    return
  }
  surface.switchTo('register_confirm')
  surface.setField('email', resumed.identifier)
}
</script>

<template>
  <section data-id="auth-surface" class="mx-auto" style="max-width: 24rem">
    <h1 class="h4 mb-3" data-id="auth-heading">{{ heading }}</h1>

    <!-- What the waiting screens are waiting on (HIL-486). One region serves all
    three of them because the system has one code screen and one thing being waited
    on at a time. Reaching zero blocks the input and offers the way back to the
    identifier field — it does NOT roll the step back on its own: whether the
    identifier is free again is the server's to say, and it says so by broadcasting
    it when the sweep gets there. -->
    <div
      v-if="expiresIn !== null"
      class="form-text mb-3"
      data-id="auth-expires-in"
    >
      <i class="bi bi-clock me-1" aria-hidden="true" />
      Your {{ waitingSubject }} expires in {{ expiresIn }}.
    </div>

    <div
      v-else-if="expired"
      class="alert alert-warning py-2"
      role="status"
      data-id="auth-expired"
    >
      <div>That {{ waitingSubject }} has expired.</div>
      <button
        type="button"
        class="btn btn-link p-0"
        data-id="auth-restart"
        @click="restart()"
      >
        Start again
      </button>
    </div>

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

    <!-- Registration confirmation code (HIL-415): the register submit only held
    the address and mailed one code — this step is what creates the account, so a
    valid code signs the session in exactly as login does. Modelled on the SMS
    code step above it; the expiry countdown and the way back to the address field
    are the shared region under the heading (HIL-486), and the done screen and the
    cooldown before a re-send is offered at all are HIL-423. -->
    <form
      v-else-if="mode === 'register_confirm'"
      novalidate
      @submit.prevent="submit"
    >
      <div class="mb-3">
        <label class="form-label" for="auth-register-code">Code</label>
        <input
          id="auth-register-code"
          type="text"
          inputmode="numeric"
          class="form-control"
          autocomplete="one-time-code"
          data-autofocus
          data-id="auth-register-code"
          :disabled="expired"
          :value="form.code"
          @input="update('code', $event)"
        />
        <div class="form-text">Sent to {{ form.email }}.</div>
      </div>

      <div
        v-if="error"
        class="alert alert-danger py-2"
        role="alert"
        data-id="auth-error"
      >
        {{ error }}
      </div>

      <div
        v-if="resendError"
        class="alert alert-danger py-2"
        role="alert"
        data-id="auth-resend-error"
      >
        {{ resendError }}
      </div>

      <LoadingButton
        type="submit"
        class="btn-primary w-100"
        :loading="pending"
        :disabled="!submittable || expired"
        data-id="auth-submit"
      >
        {{ submitLabel }}
      </LoadingButton>

      <!-- Offered only while the code is alive: past zero the hold on the address
      is gone too, so another letter would be sent for a registration that no longer
      exists. Starting again is what is on offer there. -->
      <button
        type="button"
        class="btn btn-link w-100"
        :disabled="resendPending || expired"
        data-id="auth-resend"
        @click="resendCode()"
      >
        Send the code again
      </button>
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
          :disabled="expired"
          :value="form.code"
          @input="update('code', $event)"
        />
        <div class="form-text" data-id="auth-sms-sent-via">
          Sent to {{ form.phone }} via {{ deliveredChannelLabel }}.
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
        :loading="pending && chosenChannel === primaryChannel.key"
        :disabled="!submittable || expired"
        data-id="auth-submit"
      >
        {{ submitLabel }}
      </LoadingButton>

      <!-- The other configured channels (HIL-492), drawn from the registry rather
      than named here: choosing one IS sending the code, so each icon is the same
      submit with a different channel. A channel that answered "cannot reach this
      number" is disabled until the number changes. -->
      <template v-if="mode === 'sms_request' && secondaryChannels.length > 0">
        <div class="d-flex align-items-center gap-2 my-3">
          <hr class="flex-grow-1 my-0" />
          <span class="small text-body-secondary">or send it to</span>
          <hr class="flex-grow-1 my-0" />
        </div>
        <div class="d-flex justify-content-center gap-2">
          <LoadingButton
            v-for="channel in secondaryChannels"
            :key="channel.key"
            type="button"
            class="btn-outline-secondary"
            :loading="pending && chosenChannel === channel.key"
            :disabled="
              !submittable || pending || unavailableChannels.has(channel.key)
            "
            :aria-label="`Send the code via ${channel.label}`"
            :title="
              unavailableChannels.has(channel.key)
                ? `${channel.label} cannot reach this number`
                : channel.label
            "
            :data-id="`auth-code-channel-${channel.key}`"
            @click="sendCodeOver(channel.key)"
          >
            <i :class="channelIcons[channel.key]" aria-hidden="true" />
          </LoadingButton>
        </div>
      </template>
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

    <div class="d-flex mt-3">
      <button
        v-if="mode !== 'login'"
        type="button"
        class="btn btn-link flex-fill text-center fs-4"
        aria-label="Sign in"
        title="Sign in"
        data-id="auth-to-login"
        @click="surface.switchTo('login')"
      >
        <i class="bi bi-box-arrow-in-right" aria-hidden="true" />
      </button>
      <button
        v-if="mode === 'login' && canRegister"
        type="button"
        class="btn btn-link flex-fill text-center fs-4"
        aria-label="Create account"
        title="Create account"
        data-id="auth-to-register"
        @click="surface.switchTo('register')"
      >
        <i class="bi bi-person-plus" aria-hidden="true" />
      </button>
      <button
        v-if="mode === 'login' && canRecover"
        type="button"
        class="btn btn-link flex-fill text-center fs-4"
        aria-label="Forgot password?"
        title="Forgot password?"
        data-id="auth-to-recovery"
        @click="surface.switchTo('recovery_request')"
      >
        <i class="bi bi-key" aria-hidden="true" />
      </button>
      <button
        v-if="mode === 'login' && canSms"
        type="button"
        class="btn btn-link flex-fill text-center fs-4"
        aria-label="Sign in with phone"
        title="Sign in with phone"
        data-id="auth-to-sms"
        @click="surface.switchTo('sms_request')"
      >
        <i class="bi bi-phone" aria-hidden="true" />
      </button>
      <button
        v-if="mode === 'login' && canMagicLink"
        type="button"
        class="btn btn-link flex-fill text-center fs-4"
        aria-label="Email me a link"
        title="Email me a link"
        data-id="auth-to-magic"
        @click="surface.switchTo('magic_link_request')"
      >
        <i class="bi bi-envelope" aria-hidden="true" />
      </button>
    </div>
  </section>
</template>
