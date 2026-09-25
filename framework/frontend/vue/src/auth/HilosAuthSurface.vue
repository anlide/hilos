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
  AUTH_SURFACE_HEADING_ID,
  SIGNAL_HANDSHAKE_RESPONSE,
  authAckToFlowPatch,
  authConvergeSignalSchema,
  browserRefusesCookies,
  handshakeResponseAck,
  CODE_SEND_STATE_FAILED,
  CODE_SEND_STATE_NOT_SENT,
  CODE_SEND_STATE_QUEUED,
  CODE_SEND_STATE_SENDING,
  CODE_SEND_STATE_SENT,
  createAuthActions,
  createAuthFlow,
  createOAuthLogin,
  formatCalendarDate,
  formatCountdown,
  hilosCodeSendProgress,
  MAGIC_LINK_FLOW_METHOD,
  oauthTrip,
  oauthTripMessage,
  oauthTripTitle,
  PASSKEY_FLOW_METHOD,
  PASSWORD_METHOD_KEY,
  PASSWORD_MIN_LENGTH,
  sessionAuthMethods,
  sessionCodeDelivery,
  sessionPendingAck,
  sessionPendingAuthStep,
  sessionSecondFactorPolicy,
  shouldLowerAckPanel,
  SMS_CODE_CHANNEL,
  TELEGRAM_CODE_CHANNEL,
  toFlowPatch,
  type AuthFlowScreen,
  type HilosAuthContext,
  type OAuthTripOutcome,
  type PendingAuthStep,
  type ProjectSignal,
} from '@hilos/core'

import HilosBackupCodes from '../HilosBackupCodes.vue'
import HilosFormError from '../HilosFormError.vue'
import HilosLongText from '../HilosLongText.vue'
import HilosModal from '../HilosModal.vue'
import HilosQrCode from '../HilosQrCode.vue'
import LoadingButton from '../LoadingButton.vue'
import { useSignal } from '../useSignal.js'
import HilosAuthStepTail from './HilosAuthStepTail.vue'
import HilosCookiesRefused from './HilosCookiesRefused.vue'
import { hilosAuthGateKey } from './hilosAuthGateKey.js'

defineOptions({ name: 'HilosAuthSurface' })

const props = defineProps<{
  /** The project context: its stores, its method registry, its terms paths. */
  context: HilosAuthContext
}>()

/** How often the countdowns redraw — one second, the smallest unit they show. */
const COUNTDOWN_TICK_MS = 1000

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
  held_identifier: 'You already have a code',
  proven_identifier: 'Your address is confirmed',
  terms: 'Terms and privacy',
  confirm_identifier: 'Confirm your email',
  enter_code: 'Enter the code',
  reset_code: 'Reset your password',
  choose_password: 'Choose a new password',
  set_first_password: 'Choose a password',
  two_step: 'Two-step verification',
  two_step_setup: 'Set up two-step verification',
  two_step_codes: 'Save your backup codes',
  two_step_reset: 'Remove two-step verification',
  two_step_reset_requested: 'Removal requested',
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
  held_identifier: 'Enter the code',
  proven_identifier: 'Choose a password',
  terms: 'Create account',
  confirm_identifier: 'Confirm',
  enter_code: 'Continue',
  reset_code: 'Continue',
  choose_password: 'Save password',
  set_first_password: 'Save password',
  two_step: 'Verify',
  two_step_setup: 'Verify',
  two_step_codes: 'Continue',
  two_step_reset: 'Request removal',
  two_step_reset_requested: 'Continue',
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
  reservation_expired: 'That registration expired. Ask for a new code.',
  reset_code_expired: 'That reset code has expired. Ask for a new one.',
  password_already_changed:
    'The password was already changed on another device. Sign in with the new one.',
  magic_link_invalid:
    'That sign-in link is no longer valid. Ask for a new one.',
  send_cap_reached: 'Too many codes have gone out. Please try again later.',
  rate_limited: 'Too many attempts. Please wait a moment and try again.',
  challenge_required: 'Please confirm you are not a robot and try again.',
  second_factor_expired: 'Your sign-in step expired. Sign in again.',
  second_factor_attempts: 'Too many wrong codes. Sign in again.',
}

/** What is shown when a refusal carried neither a sentence nor a known code. */
const GENERIC_ERROR = 'That did not work. Please try again.'

/**
 * The sentences the screen hard-codes in its markup. They live here because the
 * live region says them a second time, and two copies of a sentence drift. The
 * letter's line is split around the address the visible block prints in bold;
 * the expired one is the SCREEN's news rather than a refusal of anything the
 * person did, which is why the calm region is where it is said (HIL-828).
 */
const LINK_PROMPT_MESSAGE =
  'That email already has an account. Sign in to finish linking it.'
const LINK_SENT_LEAD = "We've sent a sign-in link to"
const LINK_SENT_TAIL = 'Open it to continue.'
const CODE_EXPIRED_MESSAGE = 'That code has expired.'

/**
 * The leads of the password and second-factor screens that wrap over lines. They
 * live here because the twins of those steps say the same words: the wrapping is
 * the height, and a twin that wrapped other words would hold the wrong room
 * (styling-rules.md, "The room a step takes").
 */
const SET_PASSWORD_LEAD_WITH_EXIT =
  'Your address is confirmed. Choose a password, or create the account without one and sign in by a mailed link instead.'
const SET_PASSWORD_LEAD_PLAIN =
  'Your address is confirmed. Choose a password — your account is created when you save it.'
const SET_PASSWORD_LEAD_RECOVERY =
  'The code was accepted. Choose a new password.'
const TWO_STEP_LEAD_APP =
  'Open your authenticator app and enter the code it shows.'
const TWO_STEP_LEAD_BACKUP =
  'Enter one of the backup codes you saved when you set up two-step verification.'

/**
 * How the code screen says where the code has got to (HIL-826). The states
 * travel as stable keys and the copy lives here, the way the outcome reasons
 * already work; only the provider's refusal sentence comes off the wire as
 * words, because they are not ours to phrase.
 */
const SEND_PROGRESS_COPY: Record<
  string,
  { icon: string; tone: string; text: (target: string) => string }
> = {
  [CODE_SEND_STATE_QUEUED]: {
    icon: 'bi-hourglass-split',
    tone: 'text-body-secondary',
    text: () => 'Queued for sending…',
  },
  [CODE_SEND_STATE_SENDING]: {
    icon: 'bi-arrow-repeat',
    tone: 'text-primary',
    text: (target) => `Sending to ${target}…`,
  },
  [CODE_SEND_STATE_SENT]: {
    icon: 'bi-check-circle-fill',
    tone: 'text-success',
    text: (target) => `Sent to ${target}`,
  },
  [CODE_SEND_STATE_FAILED]: {
    icon: 'bi-exclamation-triangle-fill',
    tone: 'text-danger',
    text: () => 'Could not send',
  },
  [CODE_SEND_STATE_NOT_SENT]: {
    icon: 'bi-flask',
    tone: 'text-body-secondary',
    text: (target) =>
      `Not really sent to ${target} — letters are written here, not mailed`,
  },
}

/**
 * The send line and its idle twin, to the character — only `invisible` and the
 * state's tone differ, and neither changes the height (HIL-977).
 */
const SEND_PROGRESS_ROW_CLASS = 'd-flex align-items-center gap-2 small mb-3'

/** The details button and the inert copy of it the twin holds the room for. */
const SEND_PROGRESS_DETAILS_CLASS = 'btn btn-link btn-sm p-0 lh-1 flex-shrink-0'

/**
 * The refusal row of HilosFormError, as the twins of the steps hold its room: the
 * same classes to the character, and no component — the component's own twin
 * carries a data-id, and a twin of a step carries none (styling-rules.md, "The
 * room a step takes").
 */
const ERROR_ROW_TWIN_CLASS =
  'alert alert-danger small py-1 px-2 my-2 d-flex align-items-center gap-2'
const ERROR_DETAILS_TWIN_CLASS =
  'btn btn-link btn-sm p-0 lh-1 flex-shrink-0 text-decoration-none text-nowrap'

// The set is the installation's, live from the session scope (HIL-427): an
// administrator switching a method off reshapes this surface on the frame.
const auth = createAuthFlow({
  authMethods: sessionAuthMethods(context.scopes),
  channels: context.channels,
  onDetect: (identifier) => authActions.onDetect(identifier),
  onSubmit: authActions.onSubmit,
  onMethodAction: authActions.onMethodAction,
  secondFactorPolicy: sessionSecondFactorPolicy(context.scopes),
})

const gate = inject(hilosAuthGateKey, null)

// The line is bound at boot (HIL-826), so what a mounting surface reads is the
// value already held rather than the next frame to arrive - which is the whole
// of the reload case, where the handshake was answered before this component
// existed.
// The machine is told at once and on every change, and it owns the lifetime:
// what the line stops being about is a decision about the code, not about a tab.
const reportedProgress = useSignal(hilosCodeSendProgress)
watch(
  reportedProgress,
  (progress) => {
    auth.reportSendProgress(progress)
  },
  { immediate: true },
)

const state = useSignal(auth.flow)
const form = useSignal(auth.form)
const detection = useSignal(auth.detection)
const pending = useSignal(auth.pending)
const error = useSignal(auth.error)
const submittable = useSignal(auth.submittable)
const canFinishWithoutPassword = useSignal(auth.canFinishWithoutPassword)
const icons = useSignal(auth.icons)
const methods = useSignal(auth.methods)
const channels = useSignal(auth.channels)
const primaryAction = useSignal(auth.primaryAction)
const screenKey = useSignal(auth.screenKey)
const resendAvailableAt = useSignal(auth.resendAvailableAt)
const expiresAt = useSignal(auth.expiresAt)
const secondFactor = useSignal(auth.secondFactor)
// The two pending facts the surface resumes from are DERIVED from the session
// scope by the framework's own factories, never handed in: a project cannot pass a
// stale copy of state the framework already owns.
const resumable = useSignal(sessionPendingAuthStep(context.scopes))
const ack = useSignal(sessionPendingAck(context.scopes))
// Whether THIS panel was raised by the mark. A handshake that says the session
// owes nothing lowers a panel only when the mark raised it: the initiator stands
// on done from the action reply before the frame carrying the mark arrives, and
// until that frame lands its panel is not the mark's to take (HIL-955). Not a
// signal — the screen does not draw it.
let panelRaisedByAck = false
// What this installation can send a one-time code to, read the same way and for
// the same reason (HIL-830): the browser half cannot know the backend's mail and
// channel configuration, so it is told rather than asked to have an opinion.
const codeDelivery = useSignal(sessionCodeDelivery(context.scopes))

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

// Whether the send line's details panel is open (HIL-977). The panel shows the
// line as it is now, not a snapshot of it: a state change rewrites the text in
// place, and only a line that went away altogether closes it.
const sendDetailOpen = ref(false)

// The clock the countdowns are read against. A ticking ref rather than Date.now()
// inside the computeds: a computed only recomputes when something it read changes,
// so a bare Date.now() would freeze the number at whatever it was when the screen
// opened.
const now = ref(Date.now())

const identifierInput = ref<HTMLInputElement | null>(null)
const codeInput = ref<HTMLInputElement | null>(null)
const newPasswordInput = ref<HTMLInputElement | null>(null)
const consentInput = ref<HTMLInputElement | null>(null)

// A browser that refuses cookies cannot stay signed in by any method (HIL-1074),
// so the surface draws the card that says so instead of a form. Read once: the
// browser fires no event when its settings change, and the card sends the person
// to reload. The machine and the subscriptions below are created as always —
// with no form on screen there is nothing to press and nothing to send.
const cookiesRefused = browserRefusesCookies()

const heading = computed(() =>
  screenKey.value === 'confirm_identifier' &&
  state.value.identifierKind === 'phone'
    ? 'Confirm your number'
    : HEADINGS[screenKey.value],
)

const submitLabel = computed(() => SUBMIT_LABELS[screenKey.value])

// The way past the password, gated by both halves of the same question: whether
// the project mounted a passwordless way in that serves an address (the machine
// knows the registry) and whether this installation can mail at all (the
// handshake answers that, the same key the recovery key reads). Either half
// missing and the exit would create an account nobody could get back into.
const showFinishWithoutPassword = computed(
  () => canFinishWithoutPassword.value && codeDelivery.value.email,
)

// The password screen says which of its two endings this is. A recovery is
// replacing a password that exists; a registration is about to create the
// account, and the sentence has to say so before the button does (HIL-825).
// A registration that is ALSO offered the way past the password says so here
// too (HIL-1008): the sentence is the only place the second road is explained,
// and promising it where the exit is not offered would be a lie.
const setPasswordLead = computed(() => {
  if (state.value.intent !== 'register') {
    return SET_PASSWORD_LEAD_RECOVERY
  }

  return showFinishWithoutPassword.value
    ? SET_PASSWORD_LEAD_WITH_EXIT
    : SET_PASSWORD_LEAD_PLAIN
})

// The twin that holds the lead's height (HIL-1101, styling-rules.md "The room
// a live message takes"). For a registration the twin says the longer of the
// two sentences so neither arrival nor loss of the exit moves the fields under
// it; for a recovery the exit is never offered, so the twin says the recovery
// sentence itself.
const setPasswordLeadIdle = computed(() =>
  state.value.intent === 'register'
    ? SET_PASSWORD_LEAD_WITH_EXIT
    : SET_PASSWORD_LEAD_RECOVERY,
)

const newPasswordLabel = computed(() =>
  state.value.intent === 'register' ? 'Password' : 'New password',
)

// An account this installation offers no way into, said as the refusal it is
// (HIL-973): the person cannot get into their OWN account, so the sentence goes to
// the refusal row and not to the calm hint under the field. Why it is empty was
// resolved on the backend, never by comparing flags here.
const signInRefusal = computed(() => {
  const result = detection.value.result
  if (
    state.value.step !== 'identifier' ||
    result === null ||
    result.status !== 'active' ||
    result.signInBlock !== 'no_channel'
  ) {
    return null
  }

  return result.kind === 'phone'
    ? 'This account signs in by a code, and this installation has nothing to send it with. Whoever runs it can set that up.'
    : 'This account signs in by a mailed link, and this installation has nothing to send it with. Whoever runs it can set that up.'
})

// The inline refusal: the backend's own sentence when it sent one, its semantic
// code turned into ours when it did not, and an account left with no way in when
// no action was refused.
const errorMessage = computed(() => {
  const shown = error.value
  if (shown === null) {
    return signInRefusal.value
  }

  return (
    shown.message ??
    (shown.code === null ? null : CODE_MESSAGES[shown.code]) ??
    GENERIC_ERROR
  )
})

// What the calm region says: the screen's news, in the order they stand on it
// from the top down. More than one can be true at once, so it is a list and not
// a sentence — each line keyed by the source it came from, the way the toast
// stack keys its cards.
const announcedNews = computed(() => {
  const news: { key: string; text: string }[] = []

  if (linkPrompt.value && state.value.step === 'identifier') {
    news.push({ key: 'link_prompt', text: LINK_PROMPT_MESSAGE })
  }
  if (notice.value !== null) {
    news.push({ key: 'notice', text: notice.value })
  }
  if (screenKey.value === 'check_inbox') {
    news.push({
      key: 'link_sent',
      text: `${LINK_SENT_LEAD} ${form.value.identifier}. ${LINK_SENT_TAIL}`,
    })
  }
  // The send line is news by nature - it changes under a person who is not
  // touching anything - and it is the one thing on this screen that says why
  // nothing has arrived yet.
  const progress = sendProgress.value
  if (progress !== null && state.value.step === 'code') {
    news.push({ key: 'send_progress', text: progress.text })
  }
  if (state.value.step === 'code_expired') {
    news.push({ key: 'code_expired', text: CODE_EXPIRED_MESSAGE })
  }
  // The line under the channel row only shows; this region is what says it aloud,
  // and a block carrying aria-live of its own would be read twice (accessibility.md,
  // "Live regions").
  if (state.value.step === 'identifier') {
    for (const line of channelUnavailableLines.value) {
      news.push({ key: `channel_unavailable_${line.key}`, text: line.text })
    }
  }

  return news
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

// The password reveals INSIDE the identifier step for one reply only: an account
// that signs in with one. A free address does not offer it any more (HIL-825) —
// a registration asks for a password after the code, on the screen that creates
// the account, so nobody invents a credential for an inbox they have not proved.
const showPassword = computed(() => {
  const result = detection.value.result
  if (
    state.value.step !== 'identifier' ||
    result === null ||
    result.kind !== 'email'
  ) {
    return false
  }

  return (
    result.status === 'active' && result.methods.includes(PASSWORD_METHOD_KEY)
  )
})

// The room under the identifier field is taken from the first character typed
// and never given back while something is in the field: the reveal and the hint
// are mutually exclusive, so without it the step would change height on every
// reply the lookup brings (styling-rules.md, "The room a live message takes").
// An empty field is deliberately outside it - the icon row above leaves with the
// first character, there is nothing to answer yet, and the longest sentence of
// all lives there (an installation with no channel at all).
const roomHeld = computed(() => form.value.identifier.trim() !== '')

// The recovery key sits beside the password and only for an account that HAS one:
// there is nothing to reset for an address that signs in by link, and nothing at
// all for one that has no account yet. Its whole road is mail, so an installation
// that cannot mail does not offer it (HIL-973) — and says nothing about it, since
// the password beside it still works.
const showRecovery = computed(() => {
  const result = detection.value.result

  return (
    showPassword.value &&
    result !== null &&
    result.status === 'active' &&
    result.methods.includes(PASSWORD_METHOD_KEY) &&
    codeDelivery.value.email
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
    // Silence unless NEITHER kind can be reached: a deployment with mail and no
    // phone channel would otherwise lie to whoever was about to type the kind
    // that works, and the partial case is named after the kind is known.
    return codeDelivery.value.email || codeDelivery.value.phone
      ? 'Your email address or phone number.'
      : 'Your email address or phone number. New accounts cannot be created here — there is nothing to send a code with.'
  }
  if (state.value.identifierKind === 'unknown') {
    return 'That does not look like an email address or a phone number yet.'
  }
  const result = detection.value.result
  if (result === null) {
    return null
  }
  if (result.status === 'none') {
    if (result.registerable.length > 0) {
      return 'No account yet — this creates one.'
    }

    // Two reasons, two sentences: a locked door somebody locked, and a
    // deployment that simply has nothing to send with. Which one it is was
    // resolved on the backend, never by comparing flags here (HIL-830).
    return result.registrationBlock === 'no_channel'
      ? 'No account for this, and there is nothing to send a code with.'
      : 'No account for this, and registration is closed.'
  }
  // A held address has no account to describe — it has a code in flight, and
  // saying so is the whole point of the screen the return lands on (HIL-651).
  if (result.status === 'pending') {
    return result.kind === 'phone'
      ? 'A code is already on its way to this number.'
      : 'A code is already on its way to this address.'
  }
  // A proved one has no code left either: what is owed is the password that
  // creates the account (HIL-825).
  if (result.status === 'proven') {
    return 'You confirmed this address. Choose a password to finish.'
  }

  // An account refused a way in is told so in the refusal row; the same fact in
  // grey here would read as a second problem (HIL-973).
  if (signInRefusal.value !== null) {
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

/**
 * Why each dark channel icon is dark, one line per channel. The icon's title cannot
 * carry it — a disabled button gets no mouse events, so the title never shows — and
 * the line stands visible under the row instead (accessibility.md).
 */
const channelUnavailableLines = computed(() =>
  otherChannels.value
    .filter((channel) => unavailableChannels.value.has(channel.key))
    .map((channel) => ({
      key: channel.key,
      text: `${channel.label} cannot reach this number`,
    })),
)

/** The method the machine promoted to the main button, or null. */
const primaryMethod = computed(() => {
  const action = primaryAction.value
  if (action === null || action.kind !== 'method') {
    return null
  }

  return methods.value.find((method) => method.key === action.key) ?? null
})

/**
 * The line under the identifier row: where the code being waited for has got to,
 * or null when there is nothing to say (HIL-826).
 *
 * A missing line is a legal state and reads as silence, never as an error - the
 * server takes it away with an empty frame, and a state this build has no words
 * for is treated the same way rather than drawn as a raw key.
 */
const sendProgress = computed(() => {
  const progress = state.value.sendProgress
  if (progress === null) {
    return null
  }
  const copy = SEND_PROGRESS_COPY[progress.state]
  if (copy === undefined) {
    return null
  }
  const text = copy.text(form.value.identifier)

  return {
    icon: copy.icon,
    tone: copy.tone,
    text: progress.detail === null ? text : `${text}: ${progress.detail}`,
  }
})

// A line the server took away takes its panel with it: showing the text of
// something no longer on the screen would be news about nothing.
watch(sendProgress, (value) => {
  if (value === null) {
    sendDetailOpen.value = false
  }
})

/** The channel a delivered code went over, named on the code screen. */
const deliveredChannel = computed(() => {
  const key = state.value.channelKey
  if (key === null) {
    return null
  }

  return context.channels.find((channel) => channel.key === key)?.label ?? key
})

/**
 * The glyph on the address plaque of the code screens: the channel a delivered
 * code went over, or the envelope of a mailbox. The plaque is where the channel
 * is named — the line under it that used to say so is gone, and a number under
 * an envelope said the wrong thing.
 */
const plaqueIcon = computed(() => {
  const key = state.value.channelKey

  return key === null ? 'bi bi-envelope' : channelIcon(key)
})

const resendIn = computed(() =>
  formatCountdown(resendAvailableAt.value, now.value),
)
const expiresIn = computed(() => formatCountdown(expiresAt.value, now.value))

// What the second-factor screens draw from the step's data (HIL-494): the days a
// trusted browser skips the step (no checkbox without them), the date a removal
// already asked for takes effect, and the secret of the enrolment on the way in.
const trustDeviceDays = computed(
  () => secondFactor.value?.trustDeviceDays ?? null,
)
const resetDate = computed(() => {
  const moment = secondFactor.value?.resetEffectiveAt ?? null

  return moment === null ? null : formatCalendarDate(moment)
})
const setup = computed(() => secondFactor.value?.setup ?? null)
const backupCodes = computed(() => secondFactor.value?.backupCodes ?? [])

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

async function submit(): Promise<void> {
  await auth.submit()
  loadSetupIfMissing()
}

/**
 * Ask for the secret of the enrolment on the way in when this tab stands on it
 * without one (HIL-494). Called where THIS tab arrived on the step — its own
 * submit, its own ceremony, its mount — and not where it followed another tab
 * there: a second secret would kill the one the person is scanning in the
 * first, so a follower asks only when the person presses for it.
 */
function loadSetupIfMissing(): void {
  if (
    state.value.step === 'second_factor_setup' &&
    secondFactor.value?.setup === undefined
  ) {
    void auth.loadSecondFactorSetup()
  }
}

function loadSetup(): void {
  void auth.loadSecondFactorSetup()
}

/** Switch the code step between a code from the app and a backup code. */
function toggleBackupCode(): void {
  auth.setField('usingBackupCode', !form.value.usingBackupCode)
}

/**
 * Mirror "don't ask again on this device" into the machine.
 *
 * @param event The change event.
 */
function updateTrustDevice(event: Event): void {
  auth.setField('trustDevice', (event.target as HTMLInputElement).checked)
}

/**
 * Mirror the name of the app being connected into the machine.
 *
 * @param event The input event.
 */
function updateSecondFactorLabel(event: Event): void {
  auth.setField('secondFactorLabel', (event.target as HTMLInputElement).value)
}

/**
 * Mirror "I have saved these codes" into the machine.
 *
 * @param saved Whether the box is ticked.
 */
function updateBackupCodesSaved(saved: boolean): void {
  auth.setField('backupCodesSaved', saved)
}

function startSecondFactorReset(): void {
  auth.startSecondFactorReset()
}

function backToSecondFactor(): void {
  auth.backToSecondFactor()
}

function resend(): void {
  void auth.resend()
}

// The one control of the expired screen (HIL-828). Not the re-send above: the
// hold on the address died with the code, so this takes the address again.
function renewCode(): void {
  void auth.renewCode()
}

/**
 * Hand off to an icon method's ceremony.
 *
 * @param key The chosen method key.
 */
function chooseMethod(key: string): void {
  void auth.chooseMethod(key).then(loadSetupIfMissing)
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

// The way out of a code screen, whichever intent opened it: every tab of this
// session goes back to the field and the server frees what this browser was
// holding. One call on every path — the surface names no address, because which
// holds exist is something only the server knows. What was typed survives.
function cancelRegistration(): void {
  void authActions.cancelRegistration()
  auth.backToIdentifier()
}

// The way past the password on the screen that asks for one: the account is
// created here and now, with the mailed link as its way in. The machine
// dispatches it, so a refusal lands in the error row and rolls the surface back
// exactly as the password save's does.
function completeWithoutPassword(): void {
  void auth.finishWithoutPassword()
}

// The way back into a code this browser is already holding, offered by the
// screen a return to a held address draws. Purely local: the code is already in
// flight, so nothing is sent and no second letter is ordered.
function resumeHeldRegistration(): void {
  auth.resumeHeldRegistration()
}

// And the way on from a return to an address this browser already PROVED. Local
// for the same reason: the proof is on the hold, so nothing is sent and no code
// is spent (HIL-825).
function resumeProvenRegistration(): void {
  auth.resumeProvenRegistration()
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

// Continue on a finished flow: clear the announcement on the server, then close
// the surface — dismiss reads the session at that moment, so the panel goes and a
// 401'd page is let through in one move. The OTHER tabs of the session learn of
// it the only way they can, by the cleared mark arriving through the projection.
//
// Only once the server has said it heard. The ack is a ROW (HIL-422), so a
// surface closed over a refused dispatch would leave the mark standing and meet
// the person again with the same panel on the next handshake — while the error
// explaining why is gone with the screen that held it. Closing on the click
// instead saves a round trip and costs the ordering the seam leans on: the
// clearing is no longer provably behind whatever the person does next, and a
// logout that overtakes it hands the mark to a socket of a session that is
// already gone (HIL-865, and the seam itself is HIL-875).
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
 * Apply a step the session was MOVED to rather than one it left off on
 * (HIL-833): the address this browser was registering went to somebody else
 * while it was away, and the handshake says so by naming a REASON on the step.
 *
 * Narrow on purpose. A step with no reason is an ordinary resume — the code
 * screen a session is still standing on — and applying one anywhere but on
 * mount would rebuild the screen under the hands of somebody typing on it.
 *
 * @param step The step the session stands on, or `null` when it stands on none.
 */
function applyReportedStep(step: PendingAuthStep | null): void {
  if (step === null || step.code === null) {
    return
  }
  applyFromServer(step.step, step.intent, step.code)
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
    // The expired screen has no field left to focus (HIL-828); its one control
    // is the button that orders a new code.
    code_expired: null,
    set_password: newPasswordInput,
    second_factor: codeInput,
    second_factor_setup: codeInput,
    // The three screens of a code that is not typed here have one control each.
    second_factor_codes: null,
    second_factor_reset: null,
    second_factor_reset_requested: null,
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
  (step, previous) => {
    if (previous === 'done' && step !== 'done') {
      panelRaisedByAck = false
    }
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
//
// The mark going empty is answered here too — as the fallback: the dismissal
// arrives as a handshake response, and the listener on that frame usually lowers
// the panel before this runs (HIL-955). It answers on the TRANSITION rather than on
// the state: the initiator's machine stands on `done` from the server's reply
// before the frame carrying the mark arrives, so a tab reacting to "the mark is
// empty" would take its own panel away too early. And only from `done`: the tab
// may have LEFT the panel and be typing an address again, and somebody else's
// dismissal must not pull that screen out from under them (HIL-865).
watch(ack, (value, previous) => {
  const patch = authAckToFlowPatch(value)
  if (patch !== null) {
    panelRaisedByAck = true
    auth.applyExternal(patch)

    return
  }
  if (previous == null || state.value.step !== 'done') {
    return
  }
  // reset() orphans the answers of requests already sent, so the reply to the
  // dispatch that cleared the mark cannot land on the emptied machine. It comes
  // first: dismiss() usually unmounts the surface, and a reset after it would
  // run for nothing wherever the surface does stay (the anonymous-session modal).
  panelRaisedByAck = false
  auth.reset()
  gate?.dismiss()
})

// News of a lost race reaches a tab whose socket merely BLINKED, not only one
// that reloaded: resume() runs on mount and nowhere else (deliberately), so a
// step arriving on a reconnect would otherwise sit in the session unread.
// The second-factor wait is followed as it happens (HIL-494): a sign-in held in
// another tab brings this one to the code step, and a wait let go takes it back.
// After the reasons, so a coded return is announced by the step it already made.
watch(resumable, (step) => {
  applyReportedStep(step)
  auth.followReportedStep(step)
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
 * page of the same tab, and that one is somebody else's wait. A trip on the park
 * answers the person's own click on an icon of THIS screen, so a trip that failed
 * is a refusal of this form and lands on its refusal line
 * (`mockups/components/form-error`, the sign-in card) — the same place a refusal
 * of that click before the trip started lands (HIL-926). The notice region stays
 * for news nobody on the screen asked for (a converge).
 *
 * @param outcome How the trip ended.
 */
function applyTripOutcome(outcome: OAuthTripOutcome): void {
  if (auth.flow.get().step !== 'external') {
    return
  }
  if (outcome.kind === 'signed_in' || outcome.kind === 'second_factor') {
    // The gate closes this surface on the upgrade; saying anything here would be
    // saying it to a screen already on its way out (HIL-422). A sign-in the
    // second factor holds moves every tab to its code step through the session
    // itself (HIL-494), and cancelling here would undo that move.
    return
  }
  if (outcome.kind === 'error') {
    auth.failMethod(outcome.message)

    return
  }
  auth.cancelMethod()
  if (outcome.kind === 'reauth_pending') {
    promptToFinishLink()
  }
}

let stopWatchingChannels: (() => void) | null = null
let stopWatchingConverge: (() => void) | null = null
let stopWatchingHandshake: (() => void) | null = null
let stopWatchingTrip: (() => void) | null = null
let clock: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  // Start every mount clean: the surface may be re-shown for a new gated action.
  auth.reset()
  // Except for what the server is still saying (HIL-826): the reset empties the
  // flow the line lives on, and the line is not this surface's to forget - it
  // belongs to the session, and the frame that carried it may be minutes old.
  auth.reportSendProgress(hilosCodeSendProgress.get())
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

  stopWatchingHandshake = context.connection.on(
    'projectSignal',
    (signal: ProjectSignal) => {
      if (signal.type !== SIGNAL_HANDSHAKE_RESPONSE) {
        return
      }
      if (
        !shouldLowerAckPanel({
          ackOnHandshake: handshakeResponseAck(signal.data),
          panelRaisedByAck,
          step: auth.flow.get().step,
        })
      ) {
        return
      }
      panelRaisedByAck = false
      auth.reset()
      gate?.dismiss()
    },
  )

  clock = setInterval(() => {
    now.value = Date.now()
  }, COUNTDOWN_TICK_MS)

  stopWatchingTrip = oauth.subscribeOAuthOutcome(applyTripOutcome)

  promptToFinishLink()
  // The unfinished auth step comes back from the SESSION, not from anything
  // this tab remembers, so a reload, a second tab and another device all resume
  // the same screen.
  auth.resume(resumable.value)
  loadSetupIfMissing()
  // resume() moves the screen but says nothing about WHY it moved, and the why
  // is what the notice draws: without this a tab coming back by reload lands on
  // the identifier field, address filled in, with no word about what happened.
  applyReportedStep(resumable.value)
  const patch = authAckToFlowPatch(ack.value)
  if (patch !== null) {
    panelRaisedByAck = true
    auth.applyExternal(patch)
  }
})

onUnmounted(() => {
  stopWatchingChannels?.()
  stopWatchingChannels = null
  stopWatchingConverge?.()
  stopWatchingConverge = null
  stopWatchingHandshake?.()
  stopWatchingHandshake = null
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
    <HilosCookiesRefused
      v-if="cookiesRefused"
      :heading-id="AUTH_SURFACE_HEADING_ID"
    />
    <template v-else>
      <h2 :id="AUTH_SURFACE_HEADING_ID" class="h5 mb-3" data-id="auth-heading">
        {{ heading }}
      </h2>

      <!-- The two live regions of the screen, declared in advance and on the
    section rather than inside a form: the five forms replace one another as the
    machine steps, so a region living in one of them would die with its step —
    the very illness this cures. Two of them, because only a refusal is allowed
    to interrupt what the listener is hearing. The visible blocks below carry the
    same words for the eye and no role of their own. -->
      <div
        class="visually-hidden"
        role="alert"
        aria-live="assertive"
        data-id="auth-live-assertive"
      >
        {{ errorMessage }}
      </div>
      <div
        class="visually-hidden"
        role="status"
        aria-live="polite"
        data-id="auth-live-polite"
      >
        <div v-for="item in announcedNews" :key="item.key">{{ item.text }}</div>
      </div>

      <!-- OAuth email-collision re-auth prompt (HIL-282): the provider address
    already has an account, so ask the person to sign in with an existing method
    to finish linking. The pending link token is redeemed globally once the
    session upgrades. -->
      <div
        v-if="linkPrompt && state.step === 'identifier'"
        class="alert alert-info py-2"
        data-id="auth-link-prompt"
      >
        {{ LINK_PROMPT_MESSAGE }}
      </div>

      <!-- News about a move nobody on this screen asked for. Its own region, above
    the form: it is not this step's refusal, and the step it lands on is usually
    the identifier field, where the error region belongs to what is typed next. -->
      <div v-if="notice" class="alert alert-warning py-2" data-id="auth-notice">
        {{ notice }}
      </div>

      <div class="hilos-stack" data-id="auth-step-room">
        <!-- The room the steps take (styling-rules.md, "The room a step takes").
      The live step and the invisible twins of the steps that can turn out
      tallest share one grid cell, so the card is as tall as the tallest of them
      on every step of the ordinary path, and the actions of the live step —
      the main button and its tail — stand at the bottom of that room. A twin
      carries no data-id, no form control and no autofocus: a strict locator,
      the focus trap and the reader all pass it by; what a live step draws as a
      control the twin draws as a div or a span of the same classes, and a
      sentence that wraps is carried word for word, because the wrapping is the
      height. A step whose content is data of a length nobody controls — the QR
      code, the backup codes — has no twin and grows the card: the named
      exception. -->
        <div
          class="hilos-stack invisible"
          aria-hidden="true"
          inert
          data-id="auth-step-room-idle"
        >
          <!-- The identifier step at its tallest: the icon row of an empty field
        (one icon is enough — the row is a flex of equally tall things), the
        divider, the field with the reveal under it, the refusal row, the main
        button and the tail. -->
          <div class="d-flex flex-column">
            <div class="d-flex justify-content-center gap-2">
              <span class="btn position-relative btn-outline-secondary">
                <span><i class="bi bi-envelope" aria-hidden="true" /></span>
              </span>
            </div>
            <div class="d-flex align-items-center gap-2 my-3">
              <hr class="flex-grow-1 my-0" />
              <span class="small text-body-secondary">or</span>
              <hr class="flex-grow-1 my-0" />
            </div>
            <div class="mb-3">
              <label class="form-label small fw-semibold">Email or phone</label>
              <div class="form-control">&nbsp;</div>
              <div>
                <label class="form-label small fw-semibold">Password</label>
                <div class="d-flex align-items-center gap-2">
                  <div class="form-control">&nbsp;</div>
                  <span class="btn position-relative btn-outline-secondary">
                    <span><i class="bi bi-envelope" aria-hidden="true" /></span>
                  </span>
                </div>
              </div>
            </div>
            <div :class="ERROR_ROW_TWIN_CLASS">
              <i
                class="bi bi-exclamation-circle flex-shrink-0"
                aria-hidden="true"
              />
              <span class="flex-grow-1 text-truncate">&nbsp;</span>
              <span :class="ERROR_DETAILS_TWIN_CLASS">
                <i class="bi bi-info-circle" aria-hidden="true" />
              </span>
            </div>
            <div class="d-flex flex-column mt-auto">
              <span class="btn position-relative btn-primary w-100">
                <span>&nbsp;</span>
              </span>
              <HilosAuthStepTail />
            </div>
          </div>

          <!-- The code step at its tallest: the address plaque, the send line,
        the plaque about a mailed link, the field with the line about its
        lifetime, the refusal row, the main button and the tail. -->
          <div class="d-flex flex-column">
            <div
              class="d-flex align-items-center gap-2 mb-3 px-3 py-2 rounded bg-body-tertiary"
            >
              <i
                class="bi bi-envelope text-body-secondary"
                aria-hidden="true"
              />
              <span class="small fw-semibold flex-grow-1">&nbsp;</span>
            </div>
            <div :class="SEND_PROGRESS_ROW_CLASS">
              <i
                class="bi bi-hourglass-split flex-shrink-0"
                aria-hidden="true"
              />
              <span class="flex-grow-1 text-truncate">&nbsp;</span>
              <span :class="SEND_PROGRESS_DETAILS_CLASS">
                <i class="bi bi-info-circle" aria-hidden="true" />
              </span>
            </div>
            <div class="alert alert-success small py-2">
              <i class="bi bi-envelope-check me-1" aria-hidden="true" />
              {{ LINK_SENT_LEAD }} <strong>&nbsp;</strong>. {{ LINK_SENT_TAIL }}
            </div>
            <div class="mb-3">
              <label class="form-label small fw-semibold">Code</label>
              <div class="form-control">&nbsp;</div>
              <div class="form-text">
                <i class="bi bi-clock me-1" aria-hidden="true" />
                Expires in &nbsp;.
              </div>
            </div>
            <div :class="ERROR_ROW_TWIN_CLASS">
              <i
                class="bi bi-exclamation-circle flex-shrink-0"
                aria-hidden="true"
              />
              <span class="flex-grow-1 text-truncate">&nbsp;</span>
              <span :class="ERROR_DETAILS_TWIN_CLASS">
                <i class="bi bi-info-circle" aria-hidden="true" />
              </span>
            </div>
            <div class="d-flex flex-column mt-auto">
              <span class="btn position-relative btn-primary w-100">
                <span>&nbsp;</span>
              </span>
              <HilosAuthStepTail />
            </div>
          </div>

          <!-- The password step at its tallest: the longer of its two leads, the
        field with its hint, the refusal row, the main button and the tail. -->
          <div class="d-flex flex-column">
            <p class="text-body-secondary small mb-3">
              {{ SET_PASSWORD_LEAD_WITH_EXIT }}
            </p>
            <div class="mb-3">
              <label class="form-label small fw-semibold">Password</label>
              <div class="form-control">&nbsp;</div>
              <div class="form-text">&nbsp;</div>
            </div>
            <div :class="ERROR_ROW_TWIN_CLASS">
              <i
                class="bi bi-exclamation-circle flex-shrink-0"
                aria-hidden="true"
              />
              <span class="flex-grow-1 text-truncate">&nbsp;</span>
              <span :class="ERROR_DETAILS_TWIN_CLASS">
                <i class="bi bi-info-circle" aria-hidden="true" />
              </span>
            </div>
            <div class="d-flex flex-column mt-auto">
              <span class="btn position-relative btn-primary w-100">
                <span>&nbsp;</span>
              </span>
              <HilosAuthStepTail />
            </div>
          </div>

          <!-- The second-factor step at its tallest: the longer of its two leads,
        the field, the trusted-browser checkbox, the refusal row, the main
        button and the tail. -->
          <div class="d-flex flex-column">
            <p class="text-body-secondary small mb-3">
              {{ TWO_STEP_LEAD_BACKUP }}
            </p>
            <div class="mb-3">
              <label class="form-label small fw-semibold">Backup code</label>
              <div class="form-control">&nbsp;</div>
            </div>
            <div class="form-check mb-3">
              <span class="form-check-input"></span>
              <label class="form-check-label small"
                >Don't ask again on this device for &nbsp; days</label
              >
            </div>
            <div :class="ERROR_ROW_TWIN_CLASS">
              <i
                class="bi bi-exclamation-circle flex-shrink-0"
                aria-hidden="true"
              />
              <span class="flex-grow-1 text-truncate">&nbsp;</span>
              <span :class="ERROR_DETAILS_TWIN_CLASS">
                <i class="bi bi-info-circle" aria-hidden="true" />
              </span>
            </div>
            <div class="d-flex flex-column mt-auto">
              <span class="btn position-relative btn-primary w-100">
                <span>&nbsp;</span>
              </span>
              <HilosAuthStepTail />
            </div>
          </div>
        </div>

        <!-- The single identifier field: one screen, whatever it turns out to be.
    The icon row stands FIRST because a device key and a provider are the short
    road and the field is the long one; both live only on an empty field. -->
        <form
          v-if="state.step === 'identifier'"
          class="d-flex flex-column"
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
            <!-- One room under the field for the whole conversation with it: the
        reveal when the reply is an account that signs in with a password, the
        grey line otherwise, and nothing while the first lookup runs. The reveal
        is the tallest of the three, so an invisible twin of it holds the room
        and the line lies over that twin — the content it covers is the twin and
        nothing else (styling-rules.md, "The room a live message takes").
        A free address has no row here at all: its one way on is the main button
        (mockup node `new_email`). A found account has one because the envelope
        and the key walk past the FIELD standing beside them — with no field
        there is nothing for them to walk past. -->
            <div class="position-relative" data-id="auth-reveal-slot">
              <template v-if="showPassword">
                <label class="form-label small fw-semibold" for="auth-password">
                  Password
                </label>
                <div class="d-flex align-items-center gap-2">
                  <input
                    id="auth-password"
                    type="password"
                    class="form-control"
                    autocomplete="current-password"
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
              </template>
              <template v-else>
                <div
                  v-if="roomHeld"
                  class="invisible"
                  aria-hidden="true"
                  data-id="auth-reveal-idle"
                >
                  <!-- Spans and divs where the real row has a control: the twin holds
              room, it does not take focus or name anything. The label stays a
              `label` because Bootstrap's reboot makes that one inline-block, and
              a div in its place is two pixels shorter — which is a jump, since
              this is what the room is measured by. One icon is enough: the row is
              a flex of equally tall things, so their number is not its height. -->
                  <label class="form-label small fw-semibold">Password</label>
                  <div class="d-flex align-items-center gap-2">
                    <div class="form-control">&nbsp;</div>
                    <span class="btn position-relative btn-outline-secondary">
                      <span
                        ><i class="bi bi-envelope" aria-hidden="true"
                      /></span>
                    </span>
                  </div>
                </div>
                <div
                  v-if="identifierHint"
                  class="form-text"
                  :class="
                    roomHeld ? 'position-absolute top-0 start-0 w-100' : ''
                  "
                  data-id="auth-identifier-hint"
                >
                  {{ identifierHint }}
                </div>
              </template>
            </div>
          </div>

          <HilosFormError :message="errorMessage" data-id="auth-error" />

          <!-- The main control is whatever the machine says it is: the submit, a
      passwordless method promoted to the button, or a code channel — for a phone
      the channel choice IS the send, so there is no separate button. Both resume
      controls act on the reply the reveal is drawn from, and that reply is HELD
      while a new lookup runs (HIL-646), so they go out for as long as the machine
      is re-asking about it, exactly as the submit does. Their gate is `disabled`
      and not `loading`: `pending` is set on the keystroke, before the debounce,
      and the spinner delay equals that debounce, so a spinner would blink on
      every pause in typing. -->
          <div
            v-if="primaryAction !== null"
            class="d-flex flex-column mt-auto"
            data-id="auth-step-actions"
          >
            <LoadingButton
              v-if="primaryAction.kind === 'submit'"
              type="submit"
              class="btn-primary w-100"
              :loading="pending"
              :disabled="!submittable"
              data-id="auth-submit"
            >
              {{ submitLabel }}
            </LoadingButton>

            <LoadingButton
              v-else-if="primaryAction.kind === 'resume_code'"
              type="button"
              class="btn-primary w-100"
              :disabled="detection.status !== 'resolved'"
              data-id="auth-resume-code"
              @click="resumeHeldRegistration()"
            >
              {{ submitLabel }}
            </LoadingButton>

            <LoadingButton
              v-else-if="primaryAction.kind === 'resume_password'"
              type="button"
              class="btn-primary w-100"
              :disabled="detection.status !== 'resolved'"
              data-id="auth-resume-password"
              @click="resumeProvenRegistration()"
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

            <LoadingButton
              v-else-if="primaryChannel"
              type="button"
              class="btn-primary w-100"
              :loading="pending"
              :disabled="pending || unavailableChannels.has(primaryChannel.key)"
              :data-id="channelDataId(primaryChannel.key)"
              @click="chooseChannel(primaryChannel.key)"
            >
              Send a code by {{ primaryChannel.label }}
            </LoadingButton>

            <!-- The other channels of a number stand under the send button. A
        channel that refused the number says so under the row, and that line is
        not part of the room the tail holds: it is a rare refusal, and the
        button above it may rise by its height. -->
            <HilosAuthStepTail data-id="auth-step-tail">
              <template v-if="primaryChannel && otherChannels.length > 0">
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
                    :title="`Send the code via ${channel.label}`"
                    :data-id="channelDataId(channel.key)"
                    @click="chooseChannel(channel.key)"
                  >
                    <i :class="channelIcon(channel.key)" aria-hidden="true" />
                  </LoadingButton>
                </div>
                <div
                  v-if="channelUnavailableLines.length > 0"
                  class="small text-body-secondary mt-2"
                  data-id="auth-channel-unavailable"
                >
                  <div
                    v-for="line in channelUnavailableLines"
                    :key="line.key"
                    :data-id="`auth-channel-unavailable-${line.key}`"
                  >
                    {{ line.text }}
                  </div>
                </div>
              </template>
            </HilosAuthStepTail>
          </div>
        </form>

        <!-- The terms screen. Registration is unreachable without it: the machine's
    submit on the identifier step moves here, and the dispatch that creates
    anything happens from this button.

    STOPGAP (HIL-499 in epic HIL-496 replaces it): one never-pre-ticked checkbox
    covering both documents, links to their full texts, and NO acceptance record
    of any kind — a record names a revision, and revisions do not exist yet. -->
        <form
          v-else-if="state.step === 'consent'"
          class="d-flex flex-column"
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
              <a :href="context.termsPath" target="_blank" rel="noopener"
                >Terms</a
              >
              and the
              <a :href="context.privacyPath" target="_blank" rel="noopener">
                Privacy Policy </a
              >.
            </label>
          </div>

          <HilosFormError :message="errorMessage" data-id="auth-error" />

          <div class="d-flex flex-column mt-auto" data-id="auth-step-actions">
            <LoadingButton
              type="submit"
              class="btn-primary w-100"
              :loading="pending"
              :disabled="!submittable"
              data-id="auth-submit"
            >
              {{ submitLabel }}
            </LoadingButton>
            <HilosAuthStepTail data-id="auth-step-tail">
              <button
                type="button"
                class="btn btn-link btn-sm w-100"
                data-id="auth-restart"
                @click="backToIdentifier()"
              >
                Back
              </button>
            </HilosAuthStepTail>
          </div>
        </form>

        <!-- The one code screen, whichever code it is: confirming an address,
    signing a number in, proving a mailbox for a reset, or typing the digits that
    came in a sign-in letter (HIL-606). What differs is the heading, the line
    naming where the code went, and the way out. -->
        <form
          v-else-if="state.step === 'code'"
          class="d-flex flex-column"
          novalidate
          @submit.prevent="submit()"
        >
          <!-- The plaque names the channel by its glyph: an envelope for a
      mailbox, the channel's own icon for a number. The reader hears the channel
      as a hidden line inside the plaque — the same words the screen used to
      print under it, now for the ear only. -->
          <div
            class="d-flex align-items-center gap-2 mb-3 px-3 py-2 rounded bg-body-tertiary"
          >
            <i
              :class="plaqueIcon"
              class="text-body-secondary"
              aria-hidden="true"
            />
            <span class="small fw-semibold flex-grow-1">{{
              form.identifier
            }}</span>
            <span
              v-if="deliveredChannel"
              class="visually-hidden"
              data-id="auth-delivered-channel"
              >Sent via {{ deliveredChannel }}.</span
            >
          </div>

          <!-- The send line holds its room from the moment the code screen opens
      (HIL-977, styling-rules.md "The room a live message takes"): the slot
      always holds exactly one row, the line itself or its invisible twin of the
      very same markup, so neither the line's arrival nor a provider's long
      sentence moves the code field. The text is truncated to one line and the
      whole of it sits behind the details button, in every state. -->
          <div data-id="auth-send-progress-slot">
            <div
              v-if="sendProgress"
              :class="[SEND_PROGRESS_ROW_CLASS, sendProgress.tone]"
              data-id="auth-send-progress"
            >
              <i
                class="bi flex-shrink-0"
                :class="sendProgress.icon"
                aria-hidden="true"
              />
              <span class="flex-grow-1 text-truncate">{{
                sendProgress.text
              }}</span>
              <button
                type="button"
                :class="SEND_PROGRESS_DETAILS_CLASS"
                aria-label="Show the full message"
                title="Show the full message"
                data-id="auth-send-progress-details"
                @click="sendDetailOpen = true"
              >
                <i class="bi bi-info-circle" aria-hidden="true" />
              </button>
            </div>
            <div
              v-else
              :class="[SEND_PROGRESS_ROW_CLASS, 'invisible']"
              aria-hidden="true"
              data-id="auth-send-progress-idle"
            >
              <i
                class="bi bi-hourglass-split flex-shrink-0"
                aria-hidden="true"
              />
              <span class="flex-grow-1 text-truncate">&nbsp;</span>
              <!-- A span, not a button: the twin holds room, it does not take focus. -->
              <span :class="SEND_PROGRESS_DETAILS_CLASS">
                <i class="bi bi-info-circle" aria-hidden="true" />
              </span>
            </div>
          </div>
          <HilosModal
            v-model="sendDetailOpen"
            title="Send details"
            initial-focus="dialog"
          >
            <HilosLongText
              kind="prose"
              :text="sendProgress?.text ?? ''"
              data-id="auth-send-progress-full"
            />
            <template #actions="{ requestClose }">
              <button
                type="button"
                class="btn btn-secondary"
                data-id="auth-send-progress-close"
                @click="requestClose"
              >
                Close
              </button>
            </template>
          </HilosModal>

          <!-- The letter went out with two ways back in it, so the screen says so
      before it asks for one: the link is still the shorter road for whoever can
      click it, and the field below is for whoever cannot. -->
          <div
            v-if="screenKey === 'check_inbox'"
            class="alert alert-success small py-2"
            data-id="auth-link-sent"
          >
            <i class="bi bi-envelope-check me-1" aria-hidden="true" />
            {{ LINK_SENT_LEAD }} <strong>{{ form.identifier }}</strong
            >. {{ LINK_SENT_TAIL }}
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold" for="auth-code"
              >Code</label
            >
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

          <HilosFormError :message="errorMessage" data-id="auth-error" />

          <div class="d-flex flex-column mt-auto" data-id="auth-step-actions">
            <LoadingButton
              type="submit"
              class="btn-primary w-100"
              :loading="pending"
              :disabled="!submittable"
              data-id="auth-submit"
            >
              {{ submitLabel }}
            </LoadingButton>

            <HilosAuthStepTail data-id="auth-step-tail">
              <!-- The gate is the backend's (the address owns the cooldown, not
          this tab): while it holds, the button is a countdown instead. -->
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

              <!-- The way out, and the last thing on the card because it is the
          answer to "not this, then": a registration says so out loud and in
          red, and what it cancels is freed - the same address typed again
          starts over. A sign-in or a recovery has nothing to give back, so it
          says Back and wears the word the consent step already uses for the
          same move (HIL-829). -->
              <button
                v-if="state.intent === 'register'"
                type="button"
                class="btn btn-link btn-sm w-100 text-danger"
                data-id="auth-cancel-registration"
                @click="cancelRegistration()"
              >
                Cancel registration
              </button>
              <button
                v-else
                type="button"
                class="btn btn-link btn-sm w-100"
                data-id="auth-restart"
                @click="cancelRegistration()"
              >
                Back
              </button>
            </HilosAuthStepTail>
          </div>
        </form>

        <!-- The same screen after its countdown ran out (HIL-828). The heading and
    the address block above do not move - the person is still doing the thing
    they came to do - and what changes is everything under them: no field, no
    Confirm, one line saying the code is dead and one button offering a new one.
    Not a form: there is nothing here to submit. -->
        <div
          v-else-if="state.step === 'code_expired'"
          class="d-flex flex-column"
        >
          <div
            class="d-flex align-items-center gap-2 mb-3 px-3 py-2 rounded bg-body-tertiary"
          >
            <i
              :class="plaqueIcon"
              class="text-body-secondary"
              aria-hidden="true"
            />
            <span class="small fw-semibold flex-grow-1">{{
              form.identifier
            }}</span>
          </div>

          <div
            class="alert alert-warning small py-2 mb-3"
            data-id="auth-code-expired"
          >
            <i class="bi bi-clock-history me-1" aria-hidden="true" />
            {{ CODE_EXPIRED_MESSAGE }}
          </div>

          <div class="d-flex flex-column mt-auto" data-id="auth-step-actions">
            <!-- The gate outlives the code it was armed for: it belongs to the
        address, so a person cannot spend a code, watch it expire and re-take
        the address inside the cooldown the gate exists to hold. -->
            <div
              v-if="resendIn"
              class="small text-body-secondary text-center"
              data-id="auth-resend-in"
            >
              <i class="bi bi-clock me-1" aria-hidden="true" />
              Send a new code in {{ resendIn }}
            </div>
            <LoadingButton
              v-else
              type="button"
              class="btn-primary w-100"
              :loading="pending"
              data-id="auth-code-renew"
              @click="renewCode()"
            >
              <i class="bi bi-arrow-clockwise me-1" aria-hidden="true" />
              Send a new code
            </LoadingButton>

            <HilosAuthStepTail data-id="auth-step-tail">
              <!-- The way out, and the last thing on the card because it is the
          answer to "not this, then": a registration says so out loud and in
          red, and what it cancels is freed - the same address typed again
          starts over. A sign-in or a recovery has nothing to give back, so it
          says Back and wears the word the consent step already uses for the
          same move (HIL-829). -->
              <button
                v-if="state.intent === 'register'"
                type="button"
                class="btn btn-link btn-sm w-100 text-danger"
                data-id="auth-cancel-registration"
                @click="cancelRegistration()"
              >
                Cancel registration
              </button>
              <button
                v-else
                type="button"
                class="btn btn-link btn-sm w-100"
                data-id="auth-restart"
                @click="cancelRegistration()"
              >
                Back
              </button>
            </HilosAuthStepTail>
          </div>
        </div>

        <!-- One screen for two endings (HIL-825): a recovery writes the new password
    of an account that exists, a registration CREATES the account on the address
    it just proved. The address is not asked for again either way — what the
    accepted code left on this session is what names it. -->
        <form
          v-else-if="state.step === 'set_password'"
          class="d-flex flex-column"
          novalidate
          @submit.prevent="submit()"
        >
          <div
            class="position-relative mb-3"
            data-id="auth-set-password-lead-slot"
          >
            <p
              class="text-body-secondary small mb-0 invisible"
              aria-hidden="true"
              data-id="auth-set-password-lead-idle"
            >
              {{ setPasswordLeadIdle }}
            </p>
            <p
              class="text-body-secondary small mb-0 position-absolute top-0 start-0 w-100"
              data-id="auth-set-password-lead"
            >
              {{ setPasswordLead }}
            </p>
          </div>

          <!-- The address, for the password manager and for nobody else: a saved
      entry with no login against it is an entry its owner cannot use. Hidden
      rather than absent, because what the manager files the password under is
      the field beside it. -->
          <input
            type="text"
            hidden
            autocomplete="username"
            data-id="auth-username"
            :value="form.identifier"
          />

          <div class="mb-3">
            <label class="form-label small fw-semibold" for="auth-new-password">
              {{ newPasswordLabel }}
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

          <HilosFormError :message="errorMessage" data-id="auth-error" />

          <div class="d-flex flex-column mt-auto" data-id="auth-step-actions">
            <LoadingButton
              type="submit"
              class="btn-primary w-100"
              :loading="pending"
              :disabled="!submittable"
              data-id="auth-submit"
            >
              {{ submitLabel }}
            </LoadingButton>

            <HilosAuthStepTail data-id="auth-step-tail">
              <!-- Two ways to FINISH the registration, then the way to drop it, and
          the order carries that meaning (HIL-1008). Unemphasized rather than a
          second primary: choosing a password is still the road this screen is
          named after. The slot holds its room in both states so neither arrival
          nor loss of the exit moves the cancel button (HIL-1101). -->
              <button
                v-if="showFinishWithoutPassword"
                type="button"
                class="btn btn-link btn-sm w-100"
                :disabled="pending"
                data-id="auth-complete-passwordless"
                @click="completeWithoutPassword()"
              >
                Create it without a password
              </button>
              <span
                v-else-if="state.intent === 'register'"
                class="btn btn-link btn-sm w-100 invisible"
                aria-hidden="true"
                data-id="auth-complete-passwordless-idle"
                >Create it without a password</span
              >

              <!-- The same way out the code screen carries, for the same reason:
          this screen has no address field and no step behind it, so whoever
          changed their mind here would otherwise be shut in (HIL-825). The hold
          is alive and proved at this point, so on a registration the cancel is
          the one that really gives the address back. -->
              <button
                v-if="state.intent === 'register'"
                type="button"
                class="btn btn-link btn-sm w-100 text-danger"
                data-id="auth-cancel-registration"
                @click="cancelRegistration()"
              >
                Cancel registration
              </button>
              <button
                v-else
                type="button"
                class="btn btn-link btn-sm w-100"
                data-id="auth-restart"
                @click="cancelRegistration()"
              >
                Back
              </button>
            </HilosAuthStepTail>
          </div>
        </form>

        <!-- The code of a sign-in held on its second factor (HIL-494): from the
    app, or one of the backup codes — the person says which, and the field says
    it back. The way off it for somebody with neither is the delayed removal,
    which the step names instead once it is asked. -->
        <form
          v-else-if="state.step === 'second_factor'"
          class="d-flex flex-column"
          novalidate
          @submit.prevent="submit()"
        >
          <p
            class="text-body-secondary small mb-3"
            data-id="auth-two-step-lead"
          >
            {{
              form.usingBackupCode ? TWO_STEP_LEAD_BACKUP : TWO_STEP_LEAD_APP
            }}
          </p>
          <div class="mb-3">
            <label class="form-label small fw-semibold" for="auth-code">{{
              form.usingBackupCode ? 'Backup code' : 'Code'
            }}</label>
            <input
              id="auth-code"
              ref="codeInput"
              type="text"
              :inputmode="form.usingBackupCode ? 'text' : 'numeric'"
              class="form-control"
              autocomplete="one-time-code"
              data-id="auth-code"
              :value="form.code"
              @input="updateCode($event)"
            />
          </div>
          <div v-if="trustDeviceDays !== null" class="form-check mb-3">
            <input
              id="auth-trust-device"
              class="form-check-input"
              type="checkbox"
              data-id="auth-trust-device"
              :checked="form.trustDevice"
              @change="updateTrustDevice($event)"
            />
            <label class="form-check-label small" for="auth-trust-device"
              >Don't ask again on this device for
              {{ trustDeviceDays }} days</label
            >
          </div>

          <HilosFormError :message="errorMessage" data-id="auth-error" />

          <div class="d-flex flex-column mt-auto" data-id="auth-step-actions">
            <LoadingButton
              type="submit"
              class="btn-primary w-100"
              :loading="pending"
              :disabled="!submittable"
              data-id="auth-submit"
            >
              {{ submitLabel }}
            </LoadingButton>
            <HilosAuthStepTail data-id="auth-step-tail">
              <button
                type="button"
                class="btn btn-link btn-sm w-100"
                data-id="auth-backup-toggle"
                @click="toggleBackupCode()"
              >
                {{
                  form.usingBackupCode
                    ? 'Use the app code'
                    : 'Use a backup code'
                }}
              </button>
              <p
                v-if="resetDate !== null"
                class="small text-body-secondary text-center my-2"
                data-id="auth-reset-pending"
              >
                Removal requested, takes effect on {{ resetDate }}.
              </p>
              <button
                v-else
                type="button"
                class="btn btn-link btn-sm w-100"
                data-id="auth-reset-start"
                @click="startSecondFactorReset()"
              >
                I can't use the app or any backup code
              </button>
              <button
                type="button"
                class="btn btn-link btn-sm w-100"
                data-id="auth-restart"
                @click="backToIdentifier()"
              >
                Back
              </button>
            </HilosAuthStepTail>
          </div>
        </form>

        <!-- Asking the delayed removal from the sign-in (HIL-494): what happens,
    and that every message about it lets the owner cancel. -->
        <form
          v-else-if="state.step === 'second_factor_reset'"
          class="d-flex flex-column"
          novalidate
          @submit.prevent="submit()"
        >
          <p class="small mb-2">
            If you can use neither your authenticator app nor any backup code,
            two-step verification can be removed from your account after a
            waiting period.
          </p>
          <p class="text-body-secondary small mb-3">
            We tell you at once and then every day, on every channel you have —
            email, text message, push and the bell in the app — and each message
            lets you cancel. Until then a code from your app or a backup code
            still signs you in.
          </p>

          <HilosFormError :message="errorMessage" data-id="auth-error" />

          <div class="d-flex flex-column mt-auto" data-id="auth-step-actions">
            <LoadingButton
              type="submit"
              class="btn-danger w-100"
              :loading="pending"
              :disabled="!submittable"
              data-id="auth-submit"
            >
              {{ submitLabel }}
            </LoadingButton>
            <HilosAuthStepTail data-id="auth-step-tail">
              <button
                type="button"
                class="btn btn-link btn-sm w-100"
                data-id="auth-reset-back"
                @click="backToSecondFactor()"
              >
                Back
              </button>
            </HilosAuthStepTail>
          </div>
        </form>

        <!-- The removal is asked, and the held sign-in let go with it: the date,
    and the way back to the field. -->
        <form
          v-else-if="state.step === 'second_factor_reset_requested'"
          class="d-flex flex-column"
          novalidate
          @submit.prevent="submit()"
        >
          <div
            class="alert alert-warning small py-2"
            data-id="auth-reset-requested"
          >
            Two-step verification will be removed on
            <strong>{{ resetDate }}</strong
            >.
          </div>
          <p class="text-body-secondary small mb-3">
            We sent a notice to every channel you have. If this was not you,
            follow the link in it to cancel.
          </p>
          <div class="d-flex flex-column mt-auto" data-id="auth-step-actions">
            <LoadingButton
              type="submit"
              class="btn-primary w-100"
              :loading="pending"
              :disabled="!submittable"
              data-id="auth-submit"
            >
              {{ submitLabel }}
            </LoadingButton>
            <HilosAuthStepTail data-id="auth-step-tail" />
          </div>
        </form>

        <!-- The enrolment an administrator requires on the way in (HIL-494): the
    QR code and its key as text, a name for the app, and its first code. -->
        <form
          v-else-if="state.step === 'second_factor_setup'"
          class="d-flex flex-column"
          novalidate
          @submit.prevent="submit()"
        >
          <p class="small mb-3" data-id="auth-setup-lead">
            Your administrator requires two-step verification. Scan this code
            with an authenticator app, then enter the code the app shows.
          </p>
          <template v-if="setup !== null">
            <HilosQrCode
              :text="setup.otpauthUri"
              label="QR code for your authenticator app"
              class="mb-2"
            />
            <p class="small text-body-secondary text-center mb-1">
              Can't scan it? Enter this key in the app:
            </p>
            <p
              class="font-monospace small text-center text-break mb-3"
              data-id="auth-setup-secret"
            >
              {{ setup.secret }}
            </p>
          </template>
          <button
            v-else
            type="button"
            class="btn btn-outline-secondary w-100 mb-3"
            :disabled="pending"
            data-id="auth-setup-load"
            @click="loadSetup()"
          >
            Show the code to scan
          </button>
          <div class="mb-3">
            <label class="form-label small fw-semibold" for="auth-setup-label"
              >Name of this app</label
            >
            <input
              id="auth-setup-label"
              type="text"
              class="form-control"
              maxlength="64"
              placeholder="Authenticator app"
              data-id="auth-setup-label"
              :value="form.secondFactorLabel"
              @input="updateSecondFactorLabel($event)"
            />
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold" for="auth-code"
              >Code</label
            >
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
          </div>

          <HilosFormError :message="errorMessage" data-id="auth-error" />

          <div class="d-flex flex-column mt-auto" data-id="auth-step-actions">
            <LoadingButton
              type="submit"
              class="btn-primary w-100"
              :loading="pending"
              :disabled="!submittable || setup === null"
              data-id="auth-submit"
            >
              {{ submitLabel }}
            </LoadingButton>
            <HilosAuthStepTail data-id="auth-step-tail">
              <button
                type="button"
                class="btn btn-link btn-sm w-100"
                data-id="auth-restart"
                @click="backToIdentifier()"
              >
                Back
              </button>
            </HilosAuthStepTail>
          </div>
        </form>

        <!-- The backup codes of that enrolment, shown once: Continue lets the
    person in, and waits for "I have saved these codes". -->
        <form
          v-else-if="state.step === 'second_factor_codes'"
          class="d-flex flex-column"
          novalidate
          @submit.prevent="submit()"
        >
          <p class="small mb-3">
            Keep these codes somewhere safe. Each one signs you in once if you
            lose your authenticator app.
          </p>
          <HilosBackupCodes
            :codes="backupCodes"
            :saved="form.backupCodesSaved"
            @update:saved="updateBackupCodesSaved"
          />

          <HilosFormError :message="errorMessage" data-id="auth-error" />

          <div class="d-flex flex-column mt-auto" data-id="auth-step-actions">
            <LoadingButton
              type="submit"
              class="btn-primary w-100"
              :loading="pending"
              :disabled="!submittable"
              data-id="auth-submit"
            >
              {{ submitLabel }}
            </LoadingButton>
            <HilosAuthStepTail data-id="auth-step-tail" />
          </div>
        </form>

        <!-- Parked on a ceremony. A link waits on the inbox, everything else waits on
    the device; both are the same step and both can be taken back — cancelling
    ends the ceremony itself rather than merely forgetting its outcome. -->
        <div v-else-if="state.step === 'external'" class="d-flex flex-column">
          <div
            v-if="screenKey === 'check_inbox'"
            class="alert alert-success small py-2"
          >
            <i class="bi bi-envelope-check me-1" aria-hidden="true" />
            {{ LINK_SENT_LEAD }} <strong>{{ form.identifier }}</strong
            >. {{ LINK_SENT_TAIL }}
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

          <HilosFormError :message="errorMessage" data-id="auth-error" />

          <!-- The one button of a parked ceremony stands where a main button
      stands, so the card reads the same as on every other step. -->
          <div class="d-flex flex-column mt-auto" data-id="auth-step-actions">
            <button
              v-if="trip === null || trip.phase === 'authorizing'"
              type="button"
              class="btn btn-outline-secondary w-100"
              data-id="auth-cancel"
              @click="cancelMethod()"
            >
              Cancel
            </button>
            <HilosAuthStepTail data-id="auth-step-tail" />
          </div>
        </div>

        <!-- The end of a flow is a screen with a button, not a fading toast: what was
    achieved is said once, and Continue is what closes it and lets the page
    through. -->
        <div v-else-if="state.step === 'done'" class="d-flex flex-column">
          <div class="text-center py-3">
            <i
              class="bi bi-check-circle-fill text-success mb-3 fs-1"
              aria-hidden="true"
            />
            <p class="text-body-secondary small mb-4">
              <template v-if="screenKey === 'done_registered'">
                Your address is confirmed and you are signed in.
              </template>
              <template v-else-if="screenKey === 'done_password_changed'">
                Your new password is saved. Codes left on other devices no
                longer work.
              </template>
              <template v-else> You are signed in. </template>
            </p>
          </div>

          <div class="d-flex flex-column mt-auto" data-id="auth-step-actions">
            <LoadingButton
              type="button"
              class="btn-primary w-100"
              :loading="pending"
              data-id="auth-continue"
              @click="continueFromDone()"
            >
              {{ submitLabel }}
            </LoadingButton>
            <HilosAuthStepTail data-id="auth-step-tail" />
          </div>
        </div>
      </div>
    </template>
  </section>
</template>
