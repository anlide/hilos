<!-- The project's identifier-first sign-in surface (HIL-423, mockup
framework/guest). The framework auth-gate slot (HIL-165) mounts it in place of
ErrorPage on an anonymous 401 and in the HilosModal for a gated action; it is
presentation-agnostic and owns no route of its own — the gate resumes the page /
closes the modal off the session upgrade with no navigation.

There is no login/register/recovery switcher any more. One "email or phone"
field is typed, the @hilos/core flow machine (authFlow) looks it up live, and
what is revealed is a function of the reply. Everything with a rule to it —
the lookup debounce, the re-entry and echo guards, pending, the error, the
resend gate, the late-outcome verdict, which screen the axes add up to — lives
in the machine; this component reads its signals, draws them, and hands three
seams (`authActions`) back to it.

Nothing here branches on a method key: the icon rows render `flow.icons`, the
channel controls render `flow.channels`, and the main control is whatever
`primaryAction` says it is. That is what lets HIL-427 ship the enabled set from
settings without touching this file.

Bootstrap classes only, no CSS of its own (styling-rules.md). -->
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
  AUTH_CONVERGE_SIGNAL,
  authAckToFlowPatch,
  authConvergeSignalSchema,
  createAuthActions,
  createAuthFlow,
  createOAuthLogin,
  MAGIC_LINK_FLOW_METHOD,
  oauthTrip,
  oauthTripMessage,
  oauthTripTitle,
  PASSKEY_FLOW_METHOD,
  PASSWORD_METHOD_KEY,
  PASSWORD_MIN_LENGTH,
  sessionPendingAck,
  sessionPendingRegistration,
  SMS_CODE_CHANNEL,
  TELEGRAM_CODE_CHANNEL,
  toFlowPatch,
  type AuthFlowScreen,
  type HilosAuthContext,
  type OAuthTripOutcome,
  type ProjectSignal,
} from '@hilos/core'

import LoadingButton from '../LoadingButton.vue'
import { useSignal } from '../useSignal.js'
import { hilosAuthGateKey } from './hilosAuthGateKey.js'

defineOptions({ name: 'HilosAuthSurface' })

const props = defineProps<{
  /** The project context: its stores, its method registry, its terms paths. */
  context: HilosAuthContext
}>()

/** How often the countdowns redraw — one second, the smallest unit they show. */
const COUNTDOWN_TICK_MS = 1000

/** Milliseconds in a second, for reading a remaining span as a clock. */
const MS_PER_SECOND = 1000

/** Seconds in a minute, for the same. */
const SECONDS_PER_MINUTE = 60

// The declarations the project makes and the stores it owns (HIL-409): the ordered
// method registry that drives the field, the icons and the reveal, the code
// channels, and where the consent texts are served.
const context = props.context

// The framework wire, bound to that context: the three seams the machine
// delegates, plus this surface's own dispatches and the OAuth link it peeks at.
const authActions = createAuthActions(context)
const oauth = createOAuthLogin(context)

// Bootstrap icons per method and per channel. The view owns both maps and the
// core contract carries neither: an icon is a property of this surface's design
// language, and a method the framework knows about must not be able to
// prescribe one. An unlisted key still renders — with the generic glyph — which
// is what keeps the surface agnostic about the set it is given.
const METHOD_ICONS: Record<string, string> = {
  [PASSKEY_FLOW_METHOD.key]: 'bi bi-fingerprint',
  [MAGIC_LINK_FLOW_METHOD.key]: 'bi bi-envelope',
  'oauth:github': 'bi bi-github',
  'oauth:google': 'bi bi-google',
}

/** The glyph an unlisted method or channel key falls back to. */
const GENERIC_ICON = 'bi bi-box-arrow-in-right'

const CHANNEL_ICONS: Record<string, string> = {
  [SMS_CODE_CHANNEL.key]: 'bi bi-chat-dots',
  [TELEGRAM_CODE_CHANNEL.key]: 'bi bi-telegram',
}

/**
 * What each screen is called. The surface owns its heading — the modal frame
 * stopped hard-coding one — because what the screen is called changes with the
 * step, and the machine is what knows which step the axes add up to.
 */
const HEADINGS: Record<AuthFlowScreen, string> = {
  sign_in: 'Sign in',
  create_account: 'Create your account',
  terms: 'Terms and privacy',
  confirm_identifier: 'Confirm your email',
  enter_code: 'Enter the code',
  reset_code: 'Reset your password',
  choose_password: 'Choose a new password',
  two_step: 'Two-step verification',
  waiting_external: 'Sign in',
  check_inbox: 'Check your inbox',
  done_registered: 'Your account is ready',
  done_password_changed: 'Password changed',
  done_signed_in: "You're signed in",
}

/** What the main button of each screen says; empty where a screen has none. */
const SUBMIT_LABELS: Record<AuthFlowScreen, string> = {
  sign_in: 'Sign in',
  create_account: 'Create account',
  terms: 'Create account',
  confirm_identifier: 'Confirm',
  enter_code: 'Continue',
  reset_code: 'Continue',
  choose_password: 'Save password',
  two_step: 'Verify',
  waiting_external: '',
  check_inbox: 'Continue',
  done_registered: 'Continue',
  done_password_changed: 'Continue',
  done_signed_in: 'Continue',
}

/**
 * The sentence for a refusal that arrived as a bare code. Hilos i18n is
 * backend-side, so a reply that carries its own message wins and this map is
 * only for the codes sent without one (and for the converge, which never
 * carries prose at all).
 */
const CODE_MESSAGES: Record<string, string> = {
  identifier_taken: 'That address already has an account — sign in instead.',
  reservation_expired:
    'That registration expired. Start again from the address.',
  reset_code_expired: 'That reset code has expired. Ask for a new one.',
  password_already_changed:
    'The password was already changed on another device. Sign in with the new one.',
  magic_link_invalid:
    'That sign-in link is no longer valid. Ask for a new one.',
  send_cap_reached: 'Too many codes have gone out. Please try again later.',
  rate_limited: 'Too many attempts. Please wait a moment and try again.',
  challenge_required: 'Please confirm you are not a robot and try again.',
}

/** What is shown when a refusal carried neither a sentence nor a known code. */
const GENERIC_ERROR = 'That did not work. Please try again.'

const auth = createAuthFlow({
  methods: context.methods,
  channels: context.channels,
  onDetect: (identifier) => authActions.onDetect(identifier),
  onSubmit: authActions.onSubmit,
  onMethodAction: authActions.onMethodAction,
})

const gate = inject(hilosAuthGateKey, null)

const state = useSignal(auth.flow)
const form = useSignal(auth.form)
const detection = useSignal(auth.detection)
const pending = useSignal(auth.pending)
const error = useSignal(auth.error)
const submittable = useSignal(auth.submittable)
const icons = useSignal(auth.icons)
const channels = useSignal(auth.channels)
const primaryAction = useSignal(auth.primaryAction)
const screenKey = useSignal(auth.screenKey)
const resendAvailableAt = useSignal(auth.resendAvailableAt)
const expiresAt = useSignal(auth.expiresAt)
// The two pending facts the surface resumes from are DERIVED from the session
// scope by the framework's own factories, never handed in: a project cannot pass a
// stale copy of state the framework already owns.
const resumable = useSignal(sessionPendingRegistration(context.scopes))
const ack = useSignal(sessionPendingAck(context.scopes))

// Set on mount when an OAuth email collision armed a pending link (HIL-282): the
// account already exists, so the surface pre-fills its address and shows a
// "finish linking" prompt asking the person to sign in with an existing method.
// The token replay itself is the global watcher's job (oauthLogin).
const linkPrompt = ref(false)

// Channels that answered "cannot reach this number" (HIL-492). Client state, not
// stored anywhere: it is true of a number and not of an account, so it is cleared
// the moment the number changes.
const unavailableChannels = ref(new Set<string>())

// What a converge said when it moved this surface without being asked (an expired
// reservation, a password another device already changed). It is view state
// because the machine's external door clears the error region on purpose — a step
// rebuilt under somebody's hands must not inherit the old screen's complaint —
// while this sentence is the news ABOUT that move and belongs on the screen it
// lands on. The next dispatch clears it: by then the person is acting on the new
// screen, and news about the past is over.
const notice = ref<string | null>(null)

// The clock the countdowns are read against. A ticking ref rather than Date.now()
// inside the computeds: a computed only recomputes when something it read changes,
// so a bare Date.now() would freeze the number at whatever it was when the screen
// opened.
const now = ref(Date.now())

const identifierInput = ref<HTMLInputElement | null>(null)
const codeInput = ref<HTMLInputElement | null>(null)
const newPasswordInput = ref<HTMLInputElement | null>(null)
const consentInput = ref<HTMLInputElement | null>(null)

const heading = computed(() =>
  screenKey.value === 'confirm_identifier' &&
  state.value.identifierKind === 'phone'
    ? 'Confirm your number'
    : HEADINGS[screenKey.value],
)

const submitLabel = computed(() => SUBMIT_LABELS[screenKey.value])

// The inline refusal: the backend's own sentence when it sent one, its semantic
// code turned into ours when it did not.
const errorMessage = computed(() => {
  const shown = error.value
  if (shown === null) {
    return null
  }

  return (
    shown.message ??
    (shown.code === null ? null : CODE_MESSAGES[shown.code]) ??
    GENERIC_ERROR
  )
})

// The icon row above the field, and the passwordless exits that live next to the
// password itself. Both are the machine's visible set split by placement — which
// icons are visible at all (empty field, typed kind, intent) was decided there.
const rowIcons = computed(() =>
  icons.value.filter((method) => method.placement !== 'password_adjacent'),
)
const adjacentIcons = computed(() =>
  icons.value.filter((method) => method.placement === 'password_adjacent'),
)

// The password reveals INSIDE the identifier step as a function of the reply: an
// account that signs in with one asks for it, a free address that may be
// registered with one offers it, and everything else (a phone, a passwordless
// account) never shows the field at all.
const showPassword = computed(() => {
  const result = detection.value.result
  if (
    state.value.step !== 'identifier' ||
    result === null ||
    result.kind !== 'email'
  ) {
    return false
  }

  return result.status === 'active'
    ? result.methods.includes(PASSWORD_METHOD_KEY)
    : result.status === 'none' &&
        result.registerable.includes(PASSWORD_METHOD_KEY)
})

// The recovery key sits beside the password and only for an account that HAS one:
// there is nothing to reset for an address that signs in by link, and nothing at
// all for one that has no account yet.
const showRecovery = computed(() => {
  const result = detection.value.result

  return (
    showPassword.value &&
    result !== null &&
    result.status === 'active' &&
    result.methods.includes(PASSWORD_METHOD_KEY)
  )
})

/**
 * The line under the identifier field. It never says "wrong": an unrecognized
 * value is answered by describing what the field takes, and a resolved lookup
 * says what it found — which is the whole conversation the reveal is having.
 */
const identifierHint = computed(() => {
  if (state.value.step !== 'identifier') {
    return null
  }
  if (form.value.identifier.trim() === '') {
    return 'Your email address or phone number.'
  }
  if (state.value.identifierKind === 'unknown') {
    return 'That does not look like an email address or a phone number yet.'
  }
  const result = detection.value.result
  if (result === null) {
    return null
  }
  if (result.status === 'none') {
    return result.registerable.length > 0
      ? 'No account yet — this creates one.'
      : 'No account for this, and registration is closed.'
  }
  // A held address has no account to describe yet. The field is reachable with
  // one under the cursor — "Not that address?" comes back here and keeps what
  // the lookup found (HIL-486) — and saying "this account has no password"
  // about a registration nobody confirmed is worse than saying nothing.
  if (result.status !== 'active') {
    return null
  }

  return showPassword.value ? null : 'This account has no password.'
})

/** The channel the main button sends over, or null when the screen has none. */
const primaryChannel = computed(() => {
  const action = primaryAction.value
  if (action === null || action.kind !== 'channel') {
    return null
  }

  return channels.value.find((channel) => channel.key === action.key) ?? null
})

/** The other channels this number can be reached on, offered as icons. */
const otherChannels = computed(() => {
  const primary = primaryChannel.value

  return primary === null
    ? []
    : channels.value.filter((channel) => channel.key !== primary.key)
})

/** The method the machine promoted to the main button, or null. */
const primaryMethod = computed(() => {
  const action = primaryAction.value
  if (action === null || action.kind !== 'method') {
    return null
  }

  return context.methods.find((method) => method.key === action.key) ?? null
})

/** The channel a delivered code went over, named on the code screen. */
const deliveredChannel = computed(() => {
  const key = state.value.channelKey
  if (key === null) {
    return null
  }

  return context.channels.find((channel) => channel.key === key)?.label ?? key
})

const resendIn = computed(() => remaining(resendAvailableAt.value))
const expiresIn = computed(() => remaining(expiresAt.value))

/**
 * A server moment read as the `m:ss` still to run, or null once it is spent.
 *
 * @param moment The local-scale epoch-ms moment, or null when nothing is armed.
 * @returns The remaining span as a clock, or null.
 */
function remaining(moment: number | null): string | null {
  if (moment === null) {
    return null
  }
  const left = moment - now.value
  if (left <= 0) {
    return null
  }
  const seconds = Math.ceil(left / MS_PER_SECOND)

  return (
    Math.floor(seconds / SECONDS_PER_MINUTE) +
    ':' +
    String(seconds % SECONDS_PER_MINUTE).padStart(2, '0')
  )
}

/**
 * The stable `data-id` of one method's control.
 *
 * @param key The method key, e.g. `oauth:github`.
 * @returns The slug the e2e specs address it by, e.g. `auth-icon-oauth-github`.
 */
function methodDataId(key: string): string {
  return `auth-icon-${key.replace(/[:_]/g, '-')}`
}

/**
 * The stable `data-id` of one channel's control.
 *
 * @param key The channel key, e.g. `sms`.
 * @returns The slug the e2e specs address it by, e.g. `auth-channel-sms`.
 */
function channelDataId(key: string): string {
  return `auth-channel-${key}`
}

/**
 * The glyph of one method, or the generic one for a key this view has no icon
 * for — a method set that grows must still render.
 *
 * @param key The method key.
 * @returns The Bootstrap icon classes.
 */
function methodIcon(key: string): string {
  return METHOD_ICONS[key] ?? GENERIC_ICON
}

/**
 * The glyph of one channel, on the same terms as {@link methodIcon}.
 *
 * @param key The channel key.
 * @returns The Bootstrap icon classes.
 */
function channelIcon(key: string): string {
  return CHANNEL_ICONS[key] ?? GENERIC_ICON
}

/**
 * Mirror the identifier field into the machine, which restarts the flow from it.
 *
 * @param event The input event.
 */
function updateIdentifier(event: Event): void {
  auth.setField('identifier', (event.target as HTMLInputElement).value)
  // A dimmed channel is dimmed about a NUMBER, not about the person: editing the
  // number makes every channel worth asking again.
  unavailableChannels.value = new Set()
  notice.value = null
}

/**
 * Mirror the password field into the machine.
 *
 * @param event The input event.
 */
function updatePassword(event: Event): void {
  auth.setField('password', (event.target as HTMLInputElement).value)
}

/**
 * Mirror the one-time code field into the machine.
 *
 * @param event The input event.
 */
function updateCode(event: Event): void {
  auth.setField('code', (event.target as HTMLInputElement).value)
}

/**
 * Mirror the new-password field into the machine.
 *
 * @param event The input event.
 */
function updateNewPassword(event: Event): void {
  auth.setField('newPassword', (event.target as HTMLInputElement).value)
}

/**
 * Mirror the consent checkbox into the machine.
 *
 * @param event The change event.
 */
function updateConsent(event: Event): void {
  auth.setField('consentAccepted', (event.target as HTMLInputElement).checked)
}

function submit(): void {
  void auth.submit()
}

function resend(): void {
  void auth.resend()
}

/**
 * Hand off to an icon method's ceremony.
 *
 * @param key The chosen method key.
 */
function chooseMethod(key: string): void {
  void auth.chooseMethod(key)
}

/**
 * Send the code over one channel — choosing it IS the send.
 *
 * @param key The chosen channel key.
 */
function chooseChannel(key: string): void {
  void auth.chooseChannel(key)
}

// The key icon: enter recovery and ask for the code in one move, so the first
// send and every re-send afterwards travel the same path.
function startRecovery(): void {
  auth.startRecovery()
  void auth.resend()
}

// "Not that address?": give up the registration this session started — every tab
// of it goes back to the field — while the hold on the address itself is left
// alone, so one session cannot free an address another is registering. What was
// typed survives; only the reservation is dropped.
function abandon(): void {
  void authActions.abandonRegistration()
  auth.backToIdentifier()
}

// The consent screen's way back. Nothing was reserved yet, so unlike "not that
// address?" there is nothing to give up — this is the plain return to the field.
function backToIdentifier(): void {
  auth.backToIdentifier()
}

// Cancel a running ceremony: the machine aborts its signal, so the device dialog
// closes rather than being left open for a late finger to satisfy.
function cancelMethod(): void {
  auth.cancelMethod()
}

// The OAuth trip running behind this screen, when the parked ceremony is one
// (HIL-633). The park is the same step for every icon method, but an OAuth wait is
// the one that has somewhere for the person to LOOK — another window — so it says
// where, names the provider, and drops its Cancel once the window has closed
// itself. Read from the trip and not from the flow, because the phase is the
// trip's own and the flow parks in `external` for both of them.
const trip = useSignal(oauthTrip)

// Continue on a finished flow: clear the announcement on the server, then let the
// gate play out the resume it was holding — closing the surface and un-gating the
// page in one move.
//
// Only when the server heard it. The ack is a ROW (HIL-422), so a surface closed
// over a refused dispatch would leave the mark standing and meet the person again
// with the same panel on the next handshake — while the error explaining why is
// gone with the screen that held it.
async function continueFromDone(): Promise<void> {
  await auth.submit()
  if (auth.error.get() === null) {
    gate?.dismiss()
  }
}

/**
 * Apply what the server says this surface should be showing, whoever asked for
 * it: a converge about the address being waited on, or an ack this connection
 * still owes its person.
 *
 * @param step The step off the wire.
 * @param intent The intent off the wire.
 * @param code The semantic reason of a rollback, or null.
 */
function applyFromServer(
  step: unknown,
  intent: unknown,
  code: string | null,
): void {
  const patch = toFlowPatch(step, intent)
  if (patch === null) {
    return
  }
  auth.applyExternal(patch)
  notice.value = code === null ? null : (CODE_MESSAGES[code] ?? null)
}

/**
 * Whether a converge is about the identifier this surface is waiting on.
 *
 * The server converges on the NORMALIZED identifier (a lowercased address), so
 * the comparison is case-insensitive and also accepts the normalized form the
 * lookup answered with — otherwise a person who typed their address in capitals
 * would never be told their own registration finished elsewhere.
 *
 * @param identifier The identifier the converge names.
 * @returns Whether it is the one on screen.
 */
function isCurrentIdentifier(identifier: string): boolean {
  const typed = form.value.identifier.trim().toLowerCase()
  const normalized = detection.value.result?.normalized.toLowerCase() ?? null
  const converged = identifier.trim().toLowerCase()

  return converged === typed || converged === normalized
}

/** Move focus to the first field of the step just opened. */
function focusStep(): void {
  const field = {
    identifier: identifierInput,
    consent: consentInput,
    code: codeInput,
    set_password: newPasswordInput,
    second_factor: codeInput,
    external: null,
    done: null,
  }[state.value.step]
  field?.value?.focus()
}

// A step CHANGE moves focus; a reveal inside the identifier step deliberately
// does not. The password appears 300ms after a keystroke, and taking the cursor
// out of the field somebody is still typing in would be the surface fighting
// them.
watch(
  () => state.value.step,
  () => {
    void nextTick(focusStep)
  },
)

// Any dispatch settles the news about a move nobody asked for: by then the person
// is acting on the new screen and whatever answers them belongs in the error
// region instead.
watch(pending, (busy) => {
  if (busy) {
    notice.value = null
  }
})

// The ack is what a finished flow left to say — including in a tab that finished
// nothing (another window of the same session). The gate opens the surface for
// it; this is what draws the right panel.
watch(ack, (value) => {
  const patch = authAckToFlowPatch(value)
  if (patch !== null) {
    auth.applyExternal(patch)
  }
})

/**
 * Show the pending link the collision arm armed: pre-fill the colliding address
 * and ask the person to sign in with a method they already have (HIL-282).
 */
function promptToFinishLink(): void {
  const pendingLink = oauth.peekOAuthLink()
  linkPrompt.value = pendingLink !== null
  if (pendingLink !== null) {
    auth.setField('identifier', pendingLink.email)
  }
}

/**
 * Answer an OAuth trip that ended while this screen was parked on it (HIL-633).
 *
 * Only a park is answered: a trip can also be a profile link running in another
 * page of the same tab, and that one is somebody else's wait. What comes back from
 * a trip is news about a move nobody on this screen asked for — which is what the
 * notice region is — while the error region stays for what the person types next.
 *
 * @param outcome How the trip ended.
 */
function applyTripOutcome(outcome: OAuthTripOutcome): void {
  if (auth.flow.get().step !== 'external') {
    return
  }
  if (outcome.kind === 'signed_in') {
    // The gate closes this surface on the upgrade; saying anything here would be
    // saying it to a screen already on its way out (HIL-422).
    return
  }
  auth.cancelMethod()
  if (outcome.kind === 'reauth_pending') {
    promptToFinishLink()

    return
  }
  notice.value = outcome.kind === 'error' ? outcome.message : null
}

let stopWatchingChannels: (() => void) | null = null
let stopWatchingConverge: (() => void) | null = null
let stopWatchingTrip: (() => void) | null = null
let clock: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  // Start every mount clean: the surface may be re-shown for a new gated action.
  auth.reset()
  notice.value = null
  unavailableChannels.value = new Set()

  stopWatchingChannels = authActions.subscribeCodeChannelUnavailable(
    (channel) => {
      unavailableChannels.value = new Set(unavailableChannels.value).add(
        channel,
      )
    },
  )

  // Liveness (HIL-415/416/486): the step can be taken away by somebody else —
  // another tab confirming the code, a reservation expiring, a recovery finished
  // on another device. A converge about a different address is ignored rather
  // than applied to whatever is being typed here.
  stopWatchingConverge = context.connection.on(
    'projectSignal',
    (signal: ProjectSignal) => {
      if (signal.type !== AUTH_CONVERGE_SIGNAL) {
        return
      }
      const data = signal.data as ReturnType<
        typeof authConvergeSignalSchema.parse
      >
      if (!isCurrentIdentifier(data.identifier)) {
        return
      }
      applyFromServer(data.step, data.intent, data.code)
    },
  )

  clock = setInterval(() => {
    now.value = Date.now()
  }, COUNTDOWN_TICK_MS)

  stopWatchingTrip = oauth.subscribeOAuthOutcome(applyTripOutcome)

  promptToFinishLink()
  // The unfinished registration comes back from the SESSION, not from anything
  // this tab remembers, so a reload, a second tab and another device all resume
  // the same screen.
  auth.resume(resumable.value)
  const patch = authAckToFlowPatch(ack.value)
  if (patch !== null) {
    auth.applyExternal(patch)
  }
})

onUnmounted(() => {
  stopWatchingChannels?.()
  stopWatchingChannels = null
  stopWatchingConverge?.()
  stopWatchingConverge = null
  stopWatchingTrip?.()
  stopWatchingTrip = null
  if (clock !== null) {
    clearInterval(clock)
    clock = null
  }
})
</script>

<template>
  <section data-id="auth-surface" class="mx-auto" style="max-width: 24rem">
    <h2 class="h5 mb-3" data-id="auth-heading">{{ heading }}</h2>

    <!-- OAuth email-collision re-auth prompt (HIL-282): the provider address
    already has an account, so ask the person to sign in with an existing method
    to finish linking. The pending link token is redeemed globally once the
    session upgrades. -->
    <div
      v-if="linkPrompt && state.step === 'identifier'"
      class="alert alert-info py-2"
      role="status"
      data-id="auth-link-prompt"
    >
      That email already has an account. Sign in to finish linking it.
    </div>

    <!-- News about a move nobody on this screen asked for. Its own region, above
    the form: it is not this step's refusal, and the step it lands on is usually
    the identifier field, where the error region belongs to what is typed next. -->
    <div
      v-if="notice"
      class="alert alert-warning py-2"
      role="status"
      data-id="auth-notice"
    >
      {{ notice }}
    </div>

    <!-- The single identifier field: one screen, whatever it turns out to be.
    The icon row stands FIRST because a device key and a provider are the short
    road and the field is the long one; both live only on an empty field. -->
    <form
      v-if="state.step === 'identifier'"
      novalidate
      @submit.prevent="submit()"
    >
      <template v-if="rowIcons.length > 0">
        <div class="d-flex justify-content-center gap-2">
          <LoadingButton
            v-for="method in rowIcons"
            :key="method.key"
            type="button"
            class="btn-outline-secondary"
            :loading="pending && state.methodKey === method.key"
            :disabled="pending"
            :aria-label="method.label"
            :title="method.label"
            :data-id="methodDataId(method.key)"
            @click="chooseMethod(method.key)"
          >
            <i :class="methodIcon(method.key)" aria-hidden="true" />
          </LoadingButton>
        </div>
        <div class="d-flex align-items-center gap-2 my-3">
          <hr class="flex-grow-1 my-0" />
          <span class="small text-body-secondary">or</span>
          <hr class="flex-grow-1 my-0" />
        </div>
      </template>

      <div class="mb-3">
        <label class="form-label small fw-semibold" for="auth-identifier">
          Email or phone
        </label>
        <input
          id="auth-identifier"
          ref="identifierInput"
          type="text"
          class="form-control"
          autocomplete="username"
          placeholder="you@example.com"
          data-autofocus
          data-id="auth-identifier"
          :value="form.identifier"
          @input="updateIdentifier($event)"
        />
        <div
          v-if="identifierHint"
          class="form-text"
          data-id="auth-identifier-hint"
        >
          {{ identifierHint }}
        </div>
      </div>

      <!-- The password lives inside this step, revealed by the reply. Beside it
      stand the ways past it: the envelope for an account that would rather have
      a link, the key for one that forgot the password. -->
      <div v-if="showPassword" class="mb-3">
        <label class="form-label small fw-semibold" for="auth-password">
          Password
        </label>
        <div class="d-flex align-items-center gap-2">
          <input
            id="auth-password"
            type="password"
            class="form-control"
            :autocomplete="
              state.intent === 'register' ? 'new-password' : 'current-password'
            "
            data-id="auth-password"
            :value="form.password"
            @input="updatePassword($event)"
          />
          <LoadingButton
            v-for="method in adjacentIcons"
            :key="method.key"
            type="button"
            class="btn-outline-secondary"
            :loading="pending && state.methodKey === method.key"
            :disabled="pending"
            :aria-label="method.label"
            :title="method.label"
            :data-id="methodDataId(method.key)"
            @click="chooseMethod(method.key)"
          >
            <i :class="methodIcon(method.key)" aria-hidden="true" />
          </LoadingButton>
          <button
            v-if="showRecovery"
            type="button"
            class="btn btn-outline-secondary"
            aria-label="Forgot your password?"
            title="Forgot your password?"
            data-id="auth-recovery"
            @click="startRecovery()"
          >
            <i class="bi bi-key" aria-hidden="true" />
          </button>
        </div>
        <div v-if="state.intent === 'register'" class="form-text">
          At least {{ PASSWORD_MIN_LENGTH }} characters.
        </div>
      </div>

      <div
        v-if="errorMessage"
        class="alert alert-danger py-2"
        role="alert"
        aria-live="polite"
        data-id="auth-error"
      >
        {{ errorMessage }}
      </div>

      <!-- The main control is whatever the machine says it is: the submit, a
      passwordless method promoted to the button, or a code channel — for a phone
      the channel choice IS the send, so there is no separate button. -->
      <LoadingButton
        v-if="primaryAction?.kind === 'submit'"
        type="submit"
        class="btn-primary w-100"
        :loading="pending"
        :disabled="!submittable"
        data-id="auth-submit"
      >
        {{ submitLabel }}
      </LoadingButton>

      <LoadingButton
        v-else-if="primaryMethod"
        type="button"
        class="btn-primary w-100"
        :loading="pending"
        :disabled="pending"
        :data-id="methodDataId(primaryMethod.key)"
        @click="chooseMethod(primaryMethod.key)"
      >
        <i
          :class="methodIcon(primaryMethod.key)"
          class="me-2"
          aria-hidden="true"
        />
        {{ primaryMethod.label }}
      </LoadingButton>

      <template v-else-if="primaryChannel">
        <LoadingButton
          type="button"
          class="btn-primary w-100"
          :loading="pending"
          :disabled="pending || unavailableChannels.has(primaryChannel.key)"
          :data-id="channelDataId(primaryChannel.key)"
          @click="chooseChannel(primaryChannel.key)"
        >
          Send a code by {{ primaryChannel.label }}
        </LoadingButton>

        <template v-if="otherChannels.length > 0">
          <div class="d-flex align-items-center gap-2 my-3">
            <hr class="flex-grow-1 my-0" />
            <span class="small text-body-secondary">or send it to</span>
            <hr class="flex-grow-1 my-0" />
          </div>
          <div class="d-flex justify-content-center gap-2">
            <LoadingButton
              v-for="channel in otherChannels"
              :key="channel.key"
              type="button"
              class="btn-outline-secondary"
              :loading="pending && state.channelKey === channel.key"
              :disabled="pending || unavailableChannels.has(channel.key)"
              :aria-label="`Send the code via ${channel.label}`"
              :title="
                unavailableChannels.has(channel.key)
                  ? `${channel.label} cannot reach this number`
                  : channel.label
              "
              :data-id="channelDataId(channel.key)"
              @click="chooseChannel(channel.key)"
            >
              <i :class="channelIcon(channel.key)" aria-hidden="true" />
            </LoadingButton>
          </div>
        </template>
      </template>
    </form>

    <!-- The terms screen. Registration is unreachable without it: the machine's
    submit on the identifier step moves here, and the dispatch that creates
    anything happens from this button.

    STOPGAP (HIL-499 in epic HIL-496 replaces it): one never-pre-ticked checkbox
    covering both documents, links to their full texts, and NO acceptance record
    of any kind — a record names a revision, and revisions do not exist yet. -->
    <form
      v-else-if="state.step === 'consent'"
      novalidate
      @submit.prevent="submit()"
    >
      <p class="text-body-secondary small mb-3">
        This project runs on the standard Hilos terms.
      </p>

      <div class="form-check mb-3">
        <input
          id="auth-consent-accept"
          ref="consentInput"
          class="form-check-input"
          type="checkbox"
          data-id="auth-consent-accept"
          :checked="form.consentAccepted"
          @change="updateConsent($event)"
        />
        <label class="form-check-label small" for="auth-consent-accept">
          I agree to the
          <a :href="context.termsPath" target="_blank" rel="noopener">Terms</a>
          and the
          <a :href="context.privacyPath" target="_blank" rel="noopener">
            Privacy Policy </a
          >.
        </label>
      </div>

      <div
        v-if="errorMessage"
        class="alert alert-danger py-2"
        role="alert"
        aria-live="polite"
        data-id="auth-error"
      >
        {{ errorMessage }}
      </div>

      <LoadingButton
        type="submit"
        class="btn-primary w-100 mb-2"
        :loading="pending"
        :disabled="!submittable"
        data-id="auth-submit"
      >
        {{ submitLabel }}
      </LoadingButton>

      <button
        type="button"
        class="btn btn-link btn-sm w-100"
        data-id="auth-restart"
        @click="backToIdentifier()"
      >
        Back
      </button>
    </form>

    <!-- The one code screen, whichever code it is: confirming an address,
    signing a number in, proving a mailbox for a reset, or typing the digits that
    came in a sign-in letter (HIL-606). What differs is the heading, the line
    naming where the code went, and the way out. -->
    <form
      v-else-if="state.step === 'code'"
      novalidate
      @submit.prevent="submit()"
    >
      <div
        class="d-flex align-items-center gap-2 mb-3 px-3 py-2 rounded bg-body-tertiary"
      >
        <i class="bi bi-envelope text-body-secondary" aria-hidden="true" />
        <span class="small fw-semibold flex-grow-1">{{ form.identifier }}</span>
        <button
          v-if="state.intent === 'register'"
          type="button"
          class="btn btn-sm btn-link p-0 small"
          data-id="auth-restart"
          @click="abandon()"
        >
          Not that address?
        </button>
      </div>

      <p
        v-if="deliveredChannel"
        class="text-body-secondary small mb-3"
        data-id="auth-delivered-channel"
      >
        Sent via {{ deliveredChannel }}.
      </p>

      <!-- The letter went out with two ways back in it, so the screen says so
      before it asks for one: the link is still the shorter road for whoever can
      click it, and the field below is for whoever cannot. -->
      <div
        v-if="screenKey === 'check_inbox'"
        class="alert alert-success small py-2"
        role="status"
        data-id="auth-link-sent"
      >
        <i class="bi bi-envelope-check me-1" aria-hidden="true" />
        We've sent a sign-in link to <strong>{{ form.identifier }}</strong
        >. Open it to continue.
      </div>

      <div class="mb-3">
        <label class="form-label small fw-semibold" for="auth-code">Code</label>
        <input
          id="auth-code"
          ref="codeInput"
          type="text"
          inputmode="numeric"
          class="form-control"
          autocomplete="one-time-code"
          data-id="auth-code"
          :value="form.code"
          @input="updateCode($event)"
        />
        <div v-if="expiresIn" class="form-text" data-id="auth-expires-in">
          <i class="bi bi-clock me-1" aria-hidden="true" />
          Expires in {{ expiresIn }}.
        </div>
      </div>

      <div
        v-if="errorMessage"
        class="alert alert-danger py-2"
        role="alert"
        aria-live="polite"
        data-id="auth-error"
      >
        {{ errorMessage }}
      </div>

      <LoadingButton
        type="submit"
        class="btn-primary w-100 mb-2"
        :loading="pending"
        :disabled="!submittable"
        data-id="auth-submit"
      >
        {{ submitLabel }}
      </LoadingButton>

      <!-- The gate is the backend's (the address owns the cooldown, not this
      tab): while it holds, the button is a countdown instead. -->
      <div
        v-if="resendIn"
        class="small text-body-secondary text-center"
        data-id="auth-resend-in"
      >
        <i class="bi bi-clock me-1" aria-hidden="true" />
        Send a new code in {{ resendIn }}
      </div>
      <button
        v-else
        type="button"
        class="btn btn-link btn-sm w-100"
        :disabled="pending"
        data-id="auth-resend"
        @click="resend()"
      >
        <i class="bi bi-arrow-clockwise me-1" aria-hidden="true" />
        Send a new code
      </button>
    </form>

    <!-- The new password of a recovery. The address is not asked for again: the
    accepted code left a grant on this session, and that is what names the
    account. -->
    <form
      v-else-if="state.step === 'set_password'"
      novalidate
      @submit.prevent="submit()"
    >
      <p class="text-body-secondary small mb-3">
        The code was accepted. Choose a new password.
      </p>

      <div class="mb-3">
        <label class="form-label small fw-semibold" for="auth-new-password">
          New password
        </label>
        <input
          id="auth-new-password"
          ref="newPasswordInput"
          type="password"
          class="form-control"
          autocomplete="new-password"
          data-id="auth-new-password"
          :value="form.newPassword"
          @input="updateNewPassword($event)"
        />
        <div class="form-text">
          At least {{ PASSWORD_MIN_LENGTH }} characters.
        </div>
      </div>

      <div
        v-if="errorMessage"
        class="alert alert-danger py-2"
        role="alert"
        aria-live="polite"
        data-id="auth-error"
      >
        {{ errorMessage }}
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

    <!-- Parked on a ceremony. A link waits on the inbox, everything else waits on
    the device; both are the same step and both can be taken back — cancelling
    ends the ceremony itself rather than merely forgetting its outcome. -->
    <template v-else-if="state.step === 'external'">
      <div
        v-if="screenKey === 'check_inbox'"
        class="alert alert-success small py-2"
        role="status"
      >
        <i class="bi bi-envelope-check me-1" aria-hidden="true" />
        We've sent a sign-in link to <strong>{{ form.identifier }}</strong
        >. Open it to continue.
      </div>

      <div v-else class="text-center py-4">
        <div class="spinner-border text-primary mb-3" role="status">
          <span class="visually-hidden">Waiting</span>
        </div>
        <div v-if="trip" class="fw-semibold mb-1">
          {{ oauthTripTitle(trip) }}
        </div>
        <div class="small text-body-secondary">
          {{ trip ? oauthTripMessage(trip) : 'Waiting for your device…' }}
        </div>
      </div>

      <div
        v-if="errorMessage"
        class="alert alert-danger py-2"
        role="alert"
        aria-live="polite"
        data-id="auth-error"
      >
        {{ errorMessage }}
      </div>

      <button
        v-if="trip === null || trip.phase === 'authorizing'"
        type="button"
        class="btn btn-outline-secondary w-100"
        data-id="auth-cancel"
        @click="cancelMethod()"
      >
        Cancel
      </button>
    </template>

    <!-- The end of a flow is a screen with a button, not a fading toast: what was
    achieved is said once, and Continue is what closes it and lets the page
    through. -->
    <div v-else-if="state.step === 'done'" class="text-center py-3">
      <i
        class="bi bi-check-circle-fill text-success mb-3 fs-1"
        aria-hidden="true"
      />
      <p class="text-body-secondary small mb-4">
        <template v-if="screenKey === 'done_registered'">
          Your address is confirmed and you are signed in.
        </template>
        <template v-else-if="screenKey === 'done_password_changed'">
          Your new password is saved. Codes left on other devices no longer
          work.
        </template>
        <template v-else> You are signed in. </template>
      </p>

      <LoadingButton
        type="button"
        class="btn-primary w-100"
        :loading="pending"
        data-id="auth-continue"
        @click="continueFromDone()"
      >
        {{ submitLabel }}
      </LoadingButton>
    </div>
  </section>
</template>
