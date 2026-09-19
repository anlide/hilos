// The framework's React default of the identifier-first sign-in surface
// (HIL-424), the peer of framework/frontend/vue/src/auth/HilosAuthSurface.vue
// (HIL-423, mockup framework/guest). The framework auth-gate slot (HIL-165)
// mounts it in place of ErrorPage on an anonymous 401 and in the HilosModal for
// a gated action; it is presentation-agnostic and owns no route of its own —
// the gate resumes the page / closes the modal off the session upgrade with no
// navigation.
//
// There is no login/register/recovery switcher any more. One "email or phone"
// field is typed, the @hilos/core flow machine (authFlow) looks it up live, and
// what is revealed is a function of the reply. Everything with a rule to it —
// the lookup debounce, the re-entry and echo guards, pending, the error, the
// resend gate, the late-outcome verdict, which screen the axes add up to — lives
// in the machine; this component reads its signals, draws them, and hands three
// seams (`authActions`) back to it.
//
// Nothing here branches on a method key: the icon rows render `flow.icons`, the
// channel controls render `flow.channels`, and the main control is whatever
// `primaryAction` says it is. That is what lets HIL-427 ship the enabled set
// from settings without touching this file.
//
// The structure, the texts and every `data-id` are 1:1 with the Vue peer, and
// deliberately so: HIL-427 and the i18n stage edit both surfaces with one
// feature, and HIL-426's parity specs are only parity specs while the name set
// is shared.
//
// Bootstrap classes only, no CSS of its own (styling-rules.md).
import {
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react'
import type { ChangeEvent, FormEvent } from 'react'
import {
  AUTH_CONVERGE_SIGNAL,
  AUTH_SURFACE_HEADING_ID,
  SIGNAL_HANDSHAKE_RESPONSE,
  authAckToFlowPatch,
  authConvergeSignalSchema,
  CODE_SEND_STATE_FAILED,
  CODE_SEND_STATE_NOT_SENT,
  CODE_SEND_STATE_QUEUED,
  CODE_SEND_STATE_SENDING,
  CODE_SEND_STATE_SENT,
  createAuthActions,
  createAuthFlow,
  createOAuthLogin,
  hilosCodeSendProgress,
  handshakeResponseAck,
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
  shouldLowerAckPanel,
  SMS_CODE_CHANNEL,
  TELEGRAM_CODE_CHANNEL,
  toFlowPatch,
  type AuthFlowScreen,
  type CodeSendProgress,
  type HilosAuthContext,
  type OAuthTripOutcome,
  type PendingAuthStep,
  type ProjectSignal,
} from '@hilos/core'

import { HilosFormError } from '../HilosFormError.js'
import { HilosLongText } from '../HilosLongText.js'
import { HilosModal } from '../HilosModal.js'
import { LoadingButton } from '../LoadingButton.js'
import { useSignal } from '../useSignal.js'
import { HilosAuthGateContext } from './hilosAuthGateContext.js'

/** How often the countdowns redraw — one second, the smallest unit they show. */
const COUNTDOWN_TICK_MS = 1000

/** Milliseconds in a second, for reading a remaining span as a clock. */
const MS_PER_SECOND = 1000

/** Seconds in a minute, for the same. */
const SECONDS_PER_MINUTE = 60

/** The column the surface stands in, the width the mockup gives it. */
const MAX_WIDTH = { maxWidth: '24rem' }

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
 * The line under the identifier row: where the code being waited for has got to,
 * or null when there is nothing to say (HIL-826).
 *
 * A missing line is a legal state and reads as silence, never as an error - the
 * server takes it away with an empty frame, and a state this build has no words
 * for is treated the same way rather than drawn as a raw key.
 *
 * @param progress The reported step, or null when the session is owed no line.
 * @param target The address or number the code is going to.
 * @returns What to draw, or null when there is nothing to draw.
 */
function sendProgressLine(
  progress: CodeSendProgress | null,
  target: string,
): { icon: string; tone: string; text: string } | null {
  if (progress === null) {
    return null
  }
  const copy = SEND_PROGRESS_COPY[progress.state]
  if (copy === undefined) {
    return null
  }
  const text = copy.text(target)

  return {
    icon: copy.icon,
    tone: copy.tone,
    text: progress.detail === null ? text : `${text}: ${progress.detail}`,
  }
}

/**
 * A server moment read as the `m:ss` still to run, or null once it is spent.
 *
 * @param moment The local-scale epoch-ms moment, or null when nothing is armed.
 * @param now The clock the span is measured against — the ticking one, so the
 *   number keeps counting down instead of freezing where the screen opened.
 * @returns The remaining span as a clock, or null.
 */
function remaining(moment: number | null, now: number): string | null {
  if (moment === null) {
    return null
  }
  const left = moment - now
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

/** Props for {@link HilosAuthSurface}. */
export interface HilosAuthSurfaceProps {
  /** The project context: its stores, its method registry, its terms paths. */
  context: HilosAuthContext
}

/**
 * The identifier-first sign-in surface: one field, and whatever the lookup makes
 * of it.
 *
 * @param props The project's auth context.
 */
export function HilosAuthSurface({ context }: HilosAuthSurfaceProps) {
  // The framework wire, bound to that context: the three seams the machine
  // delegates, plus this surface's own dispatches and the OAuth link it peeks
  // at. Everything born from the context is created ONCE — `useSignal`
  // resubscribes whenever its source changes, and the two session factories
  // below return a NEW signal per call, so an unmemoized one would resubscribe
  // the surface on every render.
  const authActions = useMemo(() => createAuthActions(context), [context])
  const oauth = useMemo(() => createOAuthLogin(context), [context])
  const auth = useMemo(
    () =>
      // The set is the installation's, live from the session scope (HIL-427):
      // an administrator switching a method off reshapes this surface on the frame.
      createAuthFlow({
        authMethods: sessionAuthMethods(context.scopes),
        channels: context.channels,
        onDetect: (identifier) => authActions.onDetect(identifier),
        onSubmit: authActions.onSubmit,
        onMethodAction: authActions.onMethodAction,
      }),
    [context, authActions],
  )
  // The two pending facts the surface resumes from are DERIVED from the session
  // scope by the framework's own factories, never handed in: a project cannot
  // pass a stale copy of state the framework already owns.
  const pendingAuthStep = useMemo(
    () => sessionPendingAuthStep(context.scopes),
    [context],
  )
  const pendingAck = useMemo(() => sessionPendingAck(context.scopes), [context])
  // What this installation can send a one-time code to, derived the same way and
  // for the same reason (HIL-830): the browser half cannot know the backend's
  // mail and channel configuration, so it is told rather than asked.
  const codeDeliverySignal = useMemo(
    () => sessionCodeDelivery(context.scopes),
    [context],
  )

  // The gate defaults to null on purpose: on a 401 this surface stands IN PLACE
  // of the page, where there may be no provider at all, and then Continue simply
  // has nothing to close.
  const gate = useContext(HilosAuthGateContext)

  // The line is bound at boot (HIL-826), so what a mounting surface reads is the
  // value already held rather than the next frame to arrive - which is the whole
  // of the reload case, where the handshake was answered before this component
  // existed. The machine is told at once and on every change, and it owns the
  // lifetime: what the line stops being about is a decision about the code, not
  // about a tab.
  const reportedProgress = useSignal(hilosCodeSendProgress)
  useEffect(() => {
    auth.reportSendProgress(reportedProgress)
  }, [auth, reportedProgress])

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
  const ack = useSignal(pendingAck)
  const reportedStep = useSignal(pendingAuthStep)
  const codeDelivery = useSignal(codeDeliverySignal)
  // Whether THIS panel was raised by the mark. A handshake that says the session
  // owes nothing lowers a panel only when the mark raised it: the initiator
  // stands on done from the action reply before the frame carrying the mark
  // arrives, and until that frame lands its panel is not the mark's to take
  // (HIL-955). A ref, not state — the screen does not draw it.
  const panelRaisedByAck = useRef(false)

  // Set on mount when an OAuth email collision armed a pending link (HIL-282):
  // the account already exists, so the surface pre-fills its address and shows a
  // "finish linking" prompt asking the person to sign in with an existing
  // method. The token replay itself is the global watcher's job (oauthLogin).
  const [linkPrompt, setLinkPrompt] = useState(false)

  // Channels that answered "cannot reach this number" (HIL-492). Client state,
  // not stored anywhere: it is true of a number and not of an account, so it is
  // cleared the moment the number changes. The Set is REBUILT on add, or React
  // never sees the change.
  const [unavailableChannels, setUnavailableChannels] = useState(
    () => new Set<string>(),
  )

  // What a converge said when it moved this surface without being asked (an
  // expired reservation, a password another device already changed). It is view
  // state because the machine's external door clears the error region on purpose
  // — a step rebuilt under somebody's hands must not inherit the old screen's
  // complaint — while this sentence is the news ABOUT that move and belongs on
  // the screen it lands on. The next dispatch clears it: by then the person is
  // acting on the new screen, and news about the past is over.
  const [notice, setNotice] = useState<string | null>(null)

  // Whether the send line's details panel is open (HIL-977). The panel shows the
  // line as it is now, not a snapshot of it: a state change rewrites the text in
  // place, and only a line that went away altogether closes it.
  const [sendDetailOpen, setSendDetailOpen] = useState(false)

  // The OAuth trip running behind this screen, when the parked ceremony is one
  // (HIL-633). The park is the same step for every icon method, but an OAuth wait
  // is the one that has somewhere for the person to LOOK — another window — so it
  // says where, names the provider, and drops its Cancel once the window has
  // closed itself. Read from the trip and not from the flow, because the phase is
  // the trip's own and the flow parks in `external` for both of them.
  const trip = useSignal(oauthTrip)

  // The clock the countdowns are read against, ticked by the interval below: a
  // bare Date.now() in the render would freeze the number at whatever it was
  // when the screen opened.
  const [now, setNow] = useState(() => Date.now())

  const identifierInput = useRef<HTMLInputElement>(null)
  const codeInput = useRef<HTMLInputElement>(null)
  const newPasswordInput = useRef<HTMLInputElement>(null)
  const consentInput = useRef<HTMLInputElement>(null)

  const heading =
    screenKey === 'confirm_identifier' && state.identifierKind === 'phone'
      ? 'Confirm your number'
      : HEADINGS[screenKey]

  const submitLabel = SUBMIT_LABELS[screenKey]

  // The way past the password, gated by both halves of the same question:
  // whether the project mounted a passwordless way in that serves an address
  // (the machine knows the registry) and whether this installation can mail at
  // all (the handshake answers that, the same key the recovery key reads).
  // Either half missing and the exit would create an account nobody could get
  // back into.
  const showFinishWithoutPassword =
    canFinishWithoutPassword && codeDelivery.email

  // The password screen says which of its two endings this is. A recovery is
  // replacing a password that exists; a registration is about to create the
  // account, and the sentence has to say so before the button does (HIL-825).
  // A registration that is ALSO offered the way past the password says so here
  // too (HIL-1008): the sentence is the only place the second road is
  // explained, and promising it where the exit is not offered would be a lie.
  const setPasswordLead = setPasswordLeadOf()

  /**
   * The sentence under the password screen's heading, by intent and by whether
   * the way past the password is on offer.
   *
   * @returns The lead sentence this screen opens with.
   */
  function setPasswordLeadOf(): string {
    if (state.intent !== 'register') {
      return 'The code was accepted. Choose a new password.'
    }

    return showFinishWithoutPassword
      ? 'Your address is confirmed. Choose a password, or create the account without one and sign in by a mailed link instead.'
      : 'Your address is confirmed. Choose a password — your account is created when you save it.'
  }

  const newPasswordLabel =
    state.intent === 'register' ? 'Password' : 'New password'

  // An account this installation offers no way into, said as the refusal it is
  // (HIL-973): the person cannot get into their OWN account, so the sentence
  // goes to the refusal row and not to the calm hint under the field. Why it is
  // empty was resolved on the backend, never by comparing flags here.
  const signInRefusal =
    state.step !== 'identifier' ||
    detection.result === null ||
    detection.result.status !== 'active' ||
    detection.result.signInBlock !== 'no_channel'
      ? null
      : detection.result.kind === 'phone'
        ? 'This account signs in by a code, and this installation has nothing to send it with. Whoever runs it can set that up.'
        : 'This account signs in by a mailed link, and this installation has nothing to send it with. Whoever runs it can set that up.'

  // The inline refusal: the backend's own sentence when it sent one, its
  // semantic code turned into ours when it did not, and an account left with no
  // way in when no action was refused.
  const errorMessage =
    error === null
      ? signInRefusal
      : (error.message ??
        (error.code === null ? null : CODE_MESSAGES[error.code]) ??
        GENERIC_ERROR)

  /** The channel the main button sends over, or null when the screen has none. */
  const primaryChannel =
    primaryAction === null || primaryAction.kind !== 'channel'
      ? null
      : (channels.find((channel) => channel.key === primaryAction.key) ?? null)

  /** The other channels this number can be reached on, offered as icons. */
  const otherChannels =
    primaryChannel === null
      ? []
      : channels.filter((channel) => channel.key !== primaryChannel.key)

  /**
   * Why each dark channel icon is dark, one line per channel. The icon's title
   * cannot carry it — a disabled button gets no mouse events, so the title never
   * shows — and the line stands visible under the row instead (accessibility.md).
   * Declared above the calm region's news, which announces the same lines.
   */
  const channelUnavailableLines = otherChannels
    .filter((channel) => unavailableChannels.has(channel.key))
    .map((channel) => ({
      key: channel.key,
      text: `${channel.label} cannot reach this number`,
    }))

  // What the calm region says: the screen's news, in the order they stand on it
  // from the top down. More than one can be true at once, so it is a list and
  // not a sentence — each line keyed by the source it came from, the way the
  // toast stack keys its cards.
  const announcedNews = useMemo(() => {
    const news: { key: string; text: string }[] = []

    if (linkPrompt && state.step === 'identifier') {
      news.push({ key: 'link_prompt', text: LINK_PROMPT_MESSAGE })
    }
    if (notice !== null) {
      news.push({ key: 'notice', text: notice })
    }
    if (screenKey === 'check_inbox') {
      news.push({
        key: 'link_sent',
        text: `${LINK_SENT_LEAD} ${form.identifier}. ${LINK_SENT_TAIL}`,
      })
    }
    // The send line is news by nature - it changes under a person who is not
    // touching anything - and it is the one thing on this screen that says why
    // nothing has arrived yet.
    const progress = sendProgressLine(state.sendProgress, form.identifier)
    if (progress !== null && state.step === 'code') {
      news.push({ key: 'send_progress', text: progress.text })
    }
    if (state.step === 'code_expired') {
      news.push({ key: 'code_expired', text: CODE_EXPIRED_MESSAGE })
    }
    // The line under the channel row only shows; this region is what says it
    // aloud, and a block carrying aria-live of its own would be read twice
    // (accessibility.md, "Live regions").
    if (state.step === 'identifier') {
      for (const line of channelUnavailableLines) {
        news.push({ key: `channel_unavailable_${line.key}`, text: line.text })
      }
    }

    return news
  }, [
    linkPrompt,
    state.step,
    state.sendProgress,
    notice,
    screenKey,
    form.identifier,
    channelUnavailableLines,
  ])

  // The icon row above the field, and the passwordless exits that live next to
  // the password itself. Both are the machine's visible set split by placement —
  // which icons are visible at all (empty field, typed kind, intent) was decided
  // there.
  const rowIcons = icons.filter(
    (method) => method.placement !== 'password_adjacent',
  )
  const adjacentIcons = icons.filter(
    (method) => method.placement === 'password_adjacent',
  )

  // The password reveals INSIDE the identifier step for one reply only: an
  // account that signs in with one. A free address does not offer it any more
  // (HIL-825) — a registration asks for a password after the code, on the screen
  // that creates the account, so nobody invents a credential for an inbox they
  // have not proved.
  const detected = detection.result
  const showPassword =
    state.step === 'identifier' &&
    detected !== null &&
    detected.kind === 'email' &&
    detected.status === 'active' &&
    detected.methods.includes(PASSWORD_METHOD_KEY)

  // The room under the identifier field is taken from the first character typed
  // and never given back while something is in the field: the reveal and the
  // hint are mutually exclusive, so without it the step would change height on
  // every reply the lookup brings (styling-rules.md, "The room a live message
  // takes"). An empty field is deliberately outside it - the icon row above
  // leaves with the first character, there is nothing to answer yet, and the
  // longest sentence of all lives there (an installation with no channel at
  // all).
  const roomHeld = form.identifier.trim() !== ''

  // The recovery key sits beside the password and only for an account that HAS
  // one: there is nothing to reset for an address that signs in by link, and
  // nothing at all for one that has no account yet. Its whole road is mail, so
  // an installation that cannot mail does not offer it (HIL-973) — and says
  // nothing about it, since the password beside it still works.
  const showRecovery =
    showPassword &&
    detected !== null &&
    detected.status === 'active' &&
    detected.methods.includes(PASSWORD_METHOD_KEY) &&
    codeDelivery.email

  const identifierHint = identifierHintOf()

  /**
   * The line under the identifier field. It never says "wrong": an unrecognized
   * value is answered by describing what the field takes, and a resolved lookup
   * says what it found — which is the whole conversation the reveal is having.
   *
   * @returns The hint, or null where the field says nothing.
   */
  function identifierHintOf(): string | null {
    if (state.step !== 'identifier') {
      return null
    }
    if (form.identifier.trim() === '') {
      // Silence unless NEITHER kind can be reached: a deployment with mail and
      // no phone channel would otherwise lie to whoever was about to type the
      // kind that works, and the partial case is named after the kind is known.
      return codeDelivery.email || codeDelivery.phone
        ? 'Your email address or phone number.'
        : 'Your email address or phone number. New accounts cannot be created here — there is nothing to send a code with.'
    }
    if (state.identifierKind === 'unknown') {
      return 'That does not look like an email address or a phone number yet.'
    }
    if (detected === null) {
      return null
    }
    if (detected.status === 'none') {
      if (detected.registerable.length > 0) {
        return 'No account yet — this creates one.'
      }

      // Two reasons, two sentences: a locked door somebody locked, and a
      // deployment that simply has nothing to send with. Which one it is was
      // resolved on the backend, never by comparing flags here (HIL-830).
      return detected.registrationBlock === 'no_channel'
        ? 'No account for this, and there is nothing to send a code with.'
        : 'No account for this, and registration is closed.'
    }
    // A held address has no account to describe — it has a code in flight, and
    // saying so is the whole point of the screen the return lands on (HIL-651).
    if (detected.status === 'pending') {
      return detected.kind === 'phone'
        ? 'A code is already on its way to this number.'
        : 'A code is already on its way to this address.'
    }
    // A proved one has no code left either: what is owed is the password that
    // creates the account (HIL-825).
    if (detected.status === 'proven') {
      return 'You confirmed this address. Choose a password to finish.'
    }

    // An account refused a way in is told so in the refusal row; the same fact
    // in grey here would read as a second problem (HIL-973).
    if (signInRefusal !== null) {
      return null
    }

    return showPassword ? null : 'This account has no password.'
  }

  /** The method the machine promoted to the main button, or null. */
  const primaryMethod =
    primaryAction === null || primaryAction.kind !== 'method'
      ? null
      : (methods.find((method) => method.key === primaryAction.key) ?? null)

  const sendProgress = sendProgressLine(state.sendProgress, form.identifier)

  // A line the server took away takes its panel with it: showing the text of
  // something no longer on the screen would be news about nothing.
  const sendProgressGone = sendProgress === null
  useEffect(() => {
    if (sendProgressGone) {
      setSendDetailOpen(false)
    }
  }, [sendProgressGone])

  /** The channel a delivered code went over, named on the code screen. */
  const deliveredChannel =
    state.channelKey === null
      ? null
      : (context.channels.find((channel) => channel.key === state.channelKey)
          ?.label ?? state.channelKey)

  const resendIn = remaining(resendAvailableAt, now)
  const expiresIn = remaining(expiresAt, now)

  /**
   * Mirror the identifier field into the machine, which restarts the flow from
   * it.
   *
   * @param event The change event.
   */
  function updateIdentifier(event: ChangeEvent<HTMLInputElement>): void {
    auth.setField('identifier', event.target.value)
    // A dimmed channel is dimmed about a NUMBER, not about the person: editing
    // the number makes every channel worth asking again.
    setUnavailableChannels(new Set())
    setNotice(null)
  }

  /**
   * Hand the step's form to the machine.
   *
   * @param event The submit event, whose default reload is what a step never
   *   wants: the surface owns no route.
   */
  function submit(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault()
    void auth.submit()
  }

  // The key icon: enter recovery and ask for the code in one move, so the first
  // send and every re-send afterwards travel the same path.
  function startRecovery(): void {
    auth.startRecovery()
    void auth.resend()
  }

  // The way out of a code screen, whichever intent opened it: every tab of this
  // session goes back to the field and the server frees what this browser was
  // holding. One call on every path — the surface names no address, because
  // which holds exist is something only the server knows. What was typed
  // survives.
  function cancelRegistration(): void {
    void authActions.cancelRegistration()
    auth.backToIdentifier()
  }

  // The way past the password on the screen that asks for one: the account is
  // created here and now, with the mailed link as its way in. The machine
  // dispatches it, so a refusal lands in the error row and rolls the surface
  // back exactly as the password save's does.
  function completeWithoutPassword(): void {
    void auth.finishWithoutPassword()
  }

  // The way back into a code this browser is already holding, offered by the
  // screen a return to a held address draws. Purely local: the code is already
  // in flight, so nothing is sent and no second letter is ordered.
  function resumeHeldRegistration(): void {
    auth.resumeHeldRegistration()
  }

  // And the way on from a return to an address this browser already PROVED.
  // Local for the same reason: the proof is on the hold, so nothing is sent and
  // no code is spent (HIL-825).
  function resumeProvenRegistration(): void {
    auth.resumeProvenRegistration()
  }

  // Continue on a finished flow: clear the announcement on the server, then close
  // the surface — dismiss reads the session at that moment, so the panel goes and
  // a 401'd page is let through in one move. The OTHER tabs of the session learn
  // of it the only way they can, by the cleared mark arriving through the
  // projection.
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
      external: null,
      done: null,
    }[state.step]
    field?.current?.focus()
  }

  // A step CHANGE moves focus; a reveal inside the identifier step deliberately
  // does not. The password appears 300ms after a keystroke, and taking the
  // cursor out of the field somebody is still typing in would be the surface
  // fighting them. The previous step is remembered because a React effect also
  // runs on mount, where Vue's watch does not — and the mount is the modal frame's
  // to focus, not this component's.
  const previousStep = useRef(state.step)
  useEffect(() => {
    if (previousStep.current === state.step) {
      return
    }
    if (previousStep.current === 'done' && state.step !== 'done') {
      panelRaisedByAck.current = false
    }
    previousStep.current = state.step
    focusStep()
  }, [state.step])

  // Any dispatch settles the news about a move nobody asked for: by then the
  // person is acting on the new screen and whatever answers them belongs in the
  // error region instead.
  useEffect(() => {
    if (pending) {
      setNotice(null)
    }
  }, [pending])

  // The ack is what a finished flow left to say — including in a tab that
  // finished nothing (another window of the same session). The gate opens the
  // surface for it; this is what draws the right panel.
  //
  // The mark going empty is answered here too — as the fallback: the dismissal
  // arrives as a handshake response, and the listener on that frame usually
  // lowers the panel before this runs (HIL-955). It answers on the TRANSITION
  // rather than on the state: the initiator's machine stands on `done` from the server's
  // reply before the frame carrying the mark arrives, and this effect also runs
  // on mount — either way a tab reacting to "the mark is empty" would take its
  // own panel away. The step is read from the machine rather than from `state`
  // so that a step change does not re-run the effect (HIL-865). And only from
  // `done`: the tab may have LEFT the panel and be typing an address again, and
  // somebody else's dismissal must not pull that screen out from under them.
  const previousAck = useRef(ack)
  useEffect(() => {
    const patch = authAckToFlowPatch(ack)
    const previous = previousAck.current
    previousAck.current = ack
    if (patch !== null) {
      panelRaisedByAck.current = true
      auth.applyExternal(patch)

      return
    }
    if (previous === null || auth.flow.get().step !== 'done') {
      return
    }
    // reset() orphans the answers of requests already sent, so the reply to the
    // dispatch that cleared the mark cannot land on the emptied machine. It
    // comes first: dismiss() usually unmounts the surface, and a reset after it
    // would run for nothing wherever the surface does stay (the modal an
    // anonymous session keeps).
    panelRaisedByAck.current = false
    auth.reset()
    gate?.dismiss()
  }, [ack, auth, gate])

  /**
   * Apply what the server says this surface should be showing, whoever asked
   * for it: a converge about the address being waited on, an ack this connection
   * still owes its person, or the step the handshake reports.
   *
   * @param step The step off the wire.
   * @param intent The intent off the wire.
   * @param code The semantic reason of a rollback, or null.
   */
  const applyFromServer = useCallback(
    (step: unknown, intent: unknown, code: string | null): void => {
      const patch = toFlowPatch(step, intent)
      if (patch === null) {
        return
      }
      auth.applyExternal(patch)
      setNotice(code === null ? null : (CODE_MESSAGES[code] ?? null))
    },
    [auth],
  )

  /**
   * Apply a step the session was MOVED to rather than one it left off on
   * (HIL-833): the address this browser was registering went to somebody else
   * while it was away, and the handshake says so by naming a REASON on the step.
   *
   * Narrow on purpose. A step with no reason is an ordinary resume — the code
   * screen a session is still standing on — and applying one anywhere but on
   * mount would rebuild the screen under the hands of somebody typing on it.
   *
   * @param step The step the session stands on, or `null` when it stands on
   *   none.
   */
  const applyReportedStep = useCallback(
    (step: PendingAuthStep | null): void => {
      if (step === null || step.code === null) {
        return
      }
      applyFromServer(step.step, step.intent, step.code)
    },
    [applyFromServer],
  )

  // Mount and unmount, in one effect. Its dependencies are the memos above, so
  // it runs once per context and not once per render. demo/tasks mounts
  // under StrictMode, so in dev it runs twice — safe precisely because every
  // action here is local (machine + subscriptions) and the cleanup is complete;
  // nothing on mount touches the wire.
  useEffect(() => {
    /**
     * Whether a converge is about the identifier this surface is waiting on.
     *
     * The server converges on the NORMALIZED identifier (a lowercased address),
     * so the comparison is case-insensitive and also accepts the normalized form
     * the lookup answered with — otherwise a person who typed their address in
     * capitals would never be told their own registration finished elsewhere.
     *
     * Both sides are read from the MACHINE rather than from this render's
     * mirrors: the listener is registered once and would otherwise compare
     * against whatever was typed at mount, which is nothing.
     *
     * @param identifier The identifier the converge names.
     * @returns Whether it is the one on screen.
     */
    const isCurrentIdentifier = (identifier: string): boolean => {
      const typed = auth.form.get().identifier.trim().toLowerCase()
      const normalized =
        auth.detection.get().result?.normalized.toLowerCase() ?? null
      const converged = identifier.trim().toLowerCase()

      return converged === typed || converged === normalized
    }

    // Start every mount clean: the surface may be re-shown for a new gated
    // action.
    auth.reset()
    // Except for what the server is still saying (HIL-826): the reset empties the
    // flow the line lives on, and the line is not this surface's to forget - it
    // belongs to the session, and the frame that carried it may be minutes old.
    auth.reportSendProgress(hilosCodeSendProgress.get())
    setNotice(null)
    setUnavailableChannels(new Set())

    const stopWatchingChannels = authActions.subscribeCodeChannelUnavailable(
      (channel) => {
        setUnavailableChannels((current) => new Set(current).add(channel))
      },
    )

    // Liveness (HIL-415/416/486): the step can be taken away by somebody else —
    // another tab confirming the code, a reservation expiring, a recovery
    // finished on another device. A converge about a different address is
    // ignored rather than applied to whatever is being typed here.
    const stopWatchingConverge = context.connection.on(
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

    const stopWatchingHandshake = context.connection.on(
      'projectSignal',
      (signal: ProjectSignal) => {
        if (signal.type !== SIGNAL_HANDSHAKE_RESPONSE) {
          return
        }
        if (
          !shouldLowerAckPanel({
            ackOnHandshake: handshakeResponseAck(signal.data),
            panelRaisedByAck: panelRaisedByAck.current,
            step: auth.flow.get().step,
          })
        ) {
          return
        }
        panelRaisedByAck.current = false
        auth.reset()
        gate?.dismiss()
      },
    )

    const clock = setInterval(() => {
      setNow(Date.now())
    }, COUNTDOWN_TICK_MS)

    /**
     * Show the pending link the collision arm armed: pre-fill the colliding
     * address and ask the person to sign in with a method they already have
     * (HIL-282).
     */
    const promptToFinishLink = (): void => {
      const pendingLink = oauth.peekOAuthLink()
      setLinkPrompt(pendingLink !== null)
      if (pendingLink !== null) {
        auth.setField('identifier', pendingLink.email)
      }
    }

    // Answer an OAuth trip that ended while this screen was parked on it
    // (HIL-633). Only a park is answered: a trip can also be a profile link
    // running in another page of the same tab, and that one is somebody else's
    // wait. A trip on the park answers the person's own click on an icon of THIS
    // screen, so a trip that failed is a refusal of this form and lands on its
    // refusal line (`mockups/components/form-error`, the sign-in card) — the same
    // place a refusal of that click before the trip started lands (HIL-926). The
    // notice region stays for news nobody on the screen asked for (a converge).
    const stopWatchingTrip = oauth.subscribeOAuthOutcome(
      (outcome: OAuthTripOutcome) => {
        if (auth.flow.get().step !== 'external') {
          return
        }
        if (outcome.kind === 'signed_in') {
          // The gate closes this surface on the upgrade; saying anything here
          // would be saying it to a screen already on its way out (HIL-422).
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
      },
    )

    promptToFinishLink()
    // The unfinished registration comes back from the SESSION, not from anything
    // this tab remembers, so a reload, a second tab and another device all
    // resume the same screen. Both pending facts are read from their signals
    // rather than from this render, so neither becomes a dependency that would
    // re-run the whole mount.
    auth.resume(pendingAuthStep.get())
    const patch = authAckToFlowPatch(pendingAck.get())
    if (patch !== null) {
      panelRaisedByAck.current = true
      auth.applyExternal(patch)
    }

    return () => {
      stopWatchingChannels()
      stopWatchingConverge()
      stopWatchingHandshake()
      stopWatchingTrip()
      clearInterval(clock)
    }
  }, [
    auth,
    authActions,
    oauth,
    context,
    gate,
    pendingAuthStep,
    pendingAck,
    applyFromServer,
  ])

  // News of a lost race reaches a tab whose socket merely BLINKED, not only one
  // that reloaded: resume() runs in the mount effect and nowhere else
  // (deliberately), so a step arriving on a reconnect would otherwise sit in the
  // session unread. This effect also runs on mount, which is what says WHY the
  // screen moved there - resume() moves it and stays silent - and it is written
  // AFTER the mount effect for exactly that: that one clears the notice, and the
  // effects of one render run in the order they stand in.
  useEffect(() => {
    applyReportedStep(reportedStep)
  }, [applyReportedStep, reportedStep])

  return (
    <section data-id="auth-surface" className="mx-auto" style={MAX_WIDTH}>
      <h2
        id={AUTH_SURFACE_HEADING_ID}
        className="h5 mb-3"
        data-id="auth-heading"
      >
        {heading}
      </h2>

      {/* The two live regions of the screen, declared in advance and on the
          section rather than inside a form: the five forms replace one another
          as the machine steps, so a region living in one of them would die with
          its step — the very illness this cures. Two of them, because only a
          refusal is allowed to interrupt what the listener is hearing. The
          visible blocks below carry the same words for the eye and no role of
          their own. */}
      <div
        className="visually-hidden"
        role="alert"
        aria-live="assertive"
        data-id="auth-live-assertive"
      >
        {errorMessage}
      </div>
      <div
        className="visually-hidden"
        role="status"
        aria-live="polite"
        data-id="auth-live-polite"
      >
        {announcedNews.map((item) => (
          <div key={item.key}>{item.text}</div>
        ))}
      </div>

      {/* OAuth email-collision re-auth prompt (HIL-282): the provider address
          already has an account, so ask the person to sign in with an existing
          method to finish linking. The pending link token is redeemed globally
          once the session upgrades. */}
      {linkPrompt && state.step === 'identifier' ? (
        <div className="alert alert-info py-2" data-id="auth-link-prompt">
          {LINK_PROMPT_MESSAGE}
        </div>
      ) : null}

      {/* News about a move nobody on this screen asked for. Its own region,
          above the form: it is not this step's refusal, and the step it lands on
          is usually the identifier field, where the error region belongs to what
          is typed next. */}
      {notice ? (
        <div className="alert alert-warning py-2" data-id="auth-notice">
          {notice}
        </div>
      ) : null}

      {/* The single identifier field: one screen, whatever it turns out to be.
          The icon row stands FIRST because a device key and a provider are the
          short road and the field is the long one; both live only on an empty
          field. */}
      {state.step === 'identifier' ? (
        <form noValidate onSubmit={submit}>
          {rowIcons.length > 0 ? (
            <>
              <div className="d-flex justify-content-center gap-2">
                {rowIcons.map((method) => (
                  <LoadingButton
                    key={method.key}
                    type="button"
                    className="btn-outline-secondary"
                    loading={pending && state.methodKey === method.key}
                    disabled={pending}
                    aria-label={method.label}
                    title={method.label}
                    data-id={methodDataId(method.key)}
                    onClick={() => void auth.chooseMethod(method.key)}
                  >
                    <i className={methodIcon(method.key)} aria-hidden="true" />
                  </LoadingButton>
                ))}
              </div>
              <div className="d-flex align-items-center gap-2 my-3">
                <hr className="flex-grow-1 my-0" />
                <span className="small text-body-secondary">or</span>
                <hr className="flex-grow-1 my-0" />
              </div>
            </>
          ) : null}

          <div className="mb-3">
            <label
              className="form-label small fw-semibold"
              htmlFor="auth-identifier"
            >
              Email or phone
            </label>
            <input
              id="auth-identifier"
              ref={identifierInput}
              type="text"
              className="form-control"
              autoComplete="username"
              placeholder="you@example.com"
              data-autofocus
              data-id="auth-identifier"
              value={form.identifier}
              onChange={updateIdentifier}
            />
            {/* One room under the field for the whole conversation with it: the
                reveal when the reply is an account that signs in with a
                password, the grey line otherwise, and nothing while the first
                lookup runs. The reveal is the tallest of the three, so an
                invisible twin of it holds the room and the line lies over that
                twin — the content it covers is the twin and nothing else
                (styling-rules.md, "The room a live message takes").
                A free address has no row here at all: its one way on is the
                main button (mockup node `new_email`). A found account has one
                because the envelope and the key walk past the FIELD standing
                beside them — with no field there is nothing to walk past. */}
            <div className="position-relative" data-id="auth-reveal-slot">
              {showPassword ? (
                <>
                  <label
                    className="form-label small fw-semibold"
                    htmlFor="auth-password"
                  >
                    Password
                  </label>
                  <div className="d-flex align-items-center gap-2">
                    <input
                      id="auth-password"
                      type="password"
                      className="form-control"
                      autoComplete="current-password"
                      data-id="auth-password"
                      value={form.password}
                      onChange={(event) =>
                        auth.setField('password', event.target.value)
                      }
                    />
                    {adjacentIcons.map((method) => (
                      <LoadingButton
                        key={method.key}
                        type="button"
                        className="btn-outline-secondary"
                        loading={pending && state.methodKey === method.key}
                        disabled={pending}
                        aria-label={method.label}
                        title={method.label}
                        data-id={methodDataId(method.key)}
                        onClick={() => void auth.chooseMethod(method.key)}
                      >
                        <i
                          className={methodIcon(method.key)}
                          aria-hidden="true"
                        />
                      </LoadingButton>
                    ))}
                    {showRecovery ? (
                      <button
                        type="button"
                        className="btn btn-outline-secondary"
                        aria-label="Forgot your password?"
                        title="Forgot your password?"
                        data-id="auth-recovery"
                        onClick={startRecovery}
                      >
                        <i className="bi bi-key" aria-hidden="true" />
                      </button>
                    ) : null}
                  </div>
                </>
              ) : (
                <>
                  {roomHeld ? (
                    <div
                      className="invisible"
                      aria-hidden="true"
                      data-id="auth-reveal-idle"
                    >
                      {/* Spans and divs where the real row has a control: the
                          twin holds room, it does not take focus or name
                          anything. The label stays a `label` because
                          Bootstrap's reboot makes that one inline-block, and a
                          div in its place is two pixels shorter — which is a
                          jump, since this is what the room is measured by. One
                          icon is enough: the row is a flex of equally tall
                          things, so their number is not its height. */}
                      <label className="form-label small fw-semibold">
                        Password
                      </label>
                      <div className="d-flex align-items-center gap-2">
                        <div className="form-control">&nbsp;</div>
                        <span className="btn position-relative btn-outline-secondary">
                          <span>
                            <i className="bi bi-envelope" aria-hidden="true" />
                          </span>
                        </span>
                      </div>
                    </div>
                  ) : null}
                  {identifierHint ? (
                    <div
                      className={`form-text${
                        roomHeld ? ' position-absolute top-0 start-0 w-100' : ''
                      }`}
                      data-id="auth-identifier-hint"
                    >
                      {identifierHint}
                    </div>
                  ) : null}
                </>
              )}
            </div>
          </div>

          <HilosFormError message={errorMessage} dataId="auth-error" />

          {/* The main control is whatever the machine says it is: the submit, a
              passwordless method promoted to the button, or a code channel — for
              a phone the channel choice IS the send, so there is no separate
              button. Both resume controls act on the reply the reveal is drawn
              from, and that reply is HELD while a new lookup runs (HIL-646), so
              they go out for as long as the machine is re-asking about it,
              exactly as the submit does. Their gate is `disabled` and not
              `loading`: `pending` is set on the keystroke, before the debounce,
              and the spinner delay equals that debounce, so a spinner would
              blink on every pause in typing. */}
          {primaryAction?.kind === 'submit' ? (
            <LoadingButton
              type="submit"
              className="btn-primary w-100"
              loading={pending}
              disabled={!submittable}
              data-id="auth-submit"
            >
              {submitLabel}
            </LoadingButton>
          ) : primaryAction?.kind === 'resume_code' ? (
            <LoadingButton
              type="button"
              className="btn-primary w-100"
              disabled={detection.status !== 'resolved'}
              data-id="auth-resume-code"
              onClick={resumeHeldRegistration}
            >
              {submitLabel}
            </LoadingButton>
          ) : primaryAction?.kind === 'resume_password' ? (
            <LoadingButton
              type="button"
              className="btn-primary w-100"
              disabled={detection.status !== 'resolved'}
              data-id="auth-resume-password"
              onClick={resumeProvenRegistration}
            >
              {submitLabel}
            </LoadingButton>
          ) : primaryMethod ? (
            <LoadingButton
              type="button"
              className="btn-primary w-100"
              loading={pending}
              disabled={pending}
              data-id={methodDataId(primaryMethod.key)}
              onClick={() => void auth.chooseMethod(primaryMethod.key)}
            >
              <i
                className={`${methodIcon(primaryMethod.key)} me-2`}
                aria-hidden="true"
              />
              {primaryMethod.label}
            </LoadingButton>
          ) : primaryChannel ? (
            <>
              <LoadingButton
                type="button"
                className="btn-primary w-100"
                loading={pending}
                disabled={
                  pending || unavailableChannels.has(primaryChannel.key)
                }
                data-id={channelDataId(primaryChannel.key)}
                onClick={() => void auth.chooseChannel(primaryChannel.key)}
              >
                Send a code by {primaryChannel.label}
              </LoadingButton>

              {otherChannels.length > 0 ? (
                <>
                  <div className="d-flex align-items-center gap-2 my-3">
                    <hr className="flex-grow-1 my-0" />
                    <span className="small text-body-secondary">
                      or send it to
                    </span>
                    <hr className="flex-grow-1 my-0" />
                  </div>
                  <div className="d-flex justify-content-center gap-2">
                    {otherChannels.map((channel) => (
                      <LoadingButton
                        key={channel.key}
                        type="button"
                        className="btn-outline-secondary"
                        loading={pending && state.channelKey === channel.key}
                        disabled={
                          pending || unavailableChannels.has(channel.key)
                        }
                        aria-label={`Send the code via ${channel.label}`}
                        title={`Send the code via ${channel.label}`}
                        data-id={channelDataId(channel.key)}
                        onClick={() => void auth.chooseChannel(channel.key)}
                      >
                        <i
                          className={channelIcon(channel.key)}
                          aria-hidden="true"
                        />
                      </LoadingButton>
                    ))}
                  </div>
                  {channelUnavailableLines.length > 0 ? (
                    <div
                      className="small text-body-secondary mt-2"
                      data-id="auth-channel-unavailable"
                    >
                      {channelUnavailableLines.map((line) => (
                        <div
                          key={line.key}
                          data-id={`auth-channel-unavailable-${line.key}`}
                        >
                          {line.text}
                        </div>
                      ))}
                    </div>
                  ) : null}
                </>
              ) : null}
            </>
          ) : null}
        </form>
      ) : null}

      {/* The terms screen. Registration is unreachable without it: the machine's
          submit on the identifier step moves here, and the dispatch that creates
          anything happens from this button.

          STOPGAP (HIL-499 in epic HIL-496 replaces it): one never-pre-ticked
          checkbox covering both documents, links to their full texts, and NO
          acceptance record of any kind — a record names a revision, and
          revisions do not exist yet. */}
      {state.step === 'consent' ? (
        <form noValidate onSubmit={submit}>
          <p className="text-body-secondary small mb-3">
            This project runs on the standard Hilos terms.
          </p>

          <div className="form-check mb-3">
            <input
              id="auth-consent-accept"
              ref={consentInput}
              className="form-check-input"
              type="checkbox"
              data-id="auth-consent-accept"
              checked={form.consentAccepted}
              onChange={(event) =>
                auth.setField('consentAccepted', event.target.checked)
              }
            />
            <label
              className="form-check-label small"
              htmlFor="auth-consent-accept"
            >
              I agree to the{' '}
              <a href={context.termsPath} target="_blank" rel="noopener">
                Terms
              </a>{' '}
              and the{' '}
              <a href={context.privacyPath} target="_blank" rel="noopener">
                Privacy Policy
              </a>
              .
            </label>
          </div>

          <HilosFormError message={errorMessage} dataId="auth-error" />

          <LoadingButton
            type="submit"
            className="btn-primary w-100 mb-2"
            loading={pending}
            disabled={!submittable}
            data-id="auth-submit"
          >
            {submitLabel}
          </LoadingButton>

          <button
            type="button"
            className="btn btn-link btn-sm w-100"
            data-id="auth-restart"
            onClick={() => auth.backToIdentifier()}
          >
            Back
          </button>
        </form>
      ) : null}

      {/* The one code screen, whichever code it is: confirming an address,
          signing a number in, proving a mailbox for a reset, or typing the
          digits that came in a sign-in letter (HIL-606). What differs is the
          heading, the line naming where the code went, and the way out. */}
      {state.step === 'code' ? (
        <form noValidate onSubmit={submit}>
          <div className="d-flex align-items-center gap-2 mb-3 px-3 py-2 rounded bg-body-tertiary">
            <i
              className="bi bi-envelope text-body-secondary"
              aria-hidden="true"
            />
            <span className="small fw-semibold flex-grow-1">
              {form.identifier}
            </span>
          </div>

          {deliveredChannel ? (
            <p
              className="text-body-secondary small mb-3"
              data-id="auth-delivered-channel"
            >
              Sent via {deliveredChannel}.
            </p>
          ) : null}

          {/* The send line holds its room from the moment the code screen
              opens (HIL-977, styling-rules.md "The room a live message
              takes"): the slot always holds exactly one row, the line itself
              or its invisible twin of the very same markup, so neither the
              line's arrival nor a provider's long sentence moves the code
              field. The text is truncated to one line and the whole of it
              sits behind the details button, in every state. */}
          <div data-id="auth-send-progress-slot">
            {sendProgress ? (
              <div
                className={`${SEND_PROGRESS_ROW_CLASS} ${sendProgress.tone}`}
                data-id="auth-send-progress"
              >
                <i
                  className={`bi flex-shrink-0 ${sendProgress.icon}`}
                  aria-hidden="true"
                />
                <span className="flex-grow-1 text-truncate">
                  {sendProgress.text}
                </span>
                <button
                  type="button"
                  className={SEND_PROGRESS_DETAILS_CLASS}
                  aria-label="Show the full message"
                  title="Show the full message"
                  data-id="auth-send-progress-details"
                  onClick={() => setSendDetailOpen(true)}
                >
                  <i className="bi bi-info-circle" aria-hidden="true" />
                </button>
              </div>
            ) : (
              <div
                className={`${SEND_PROGRESS_ROW_CLASS} invisible`}
                aria-hidden="true"
                data-id="auth-send-progress-idle"
              >
                <i
                  className="bi bi-hourglass-split flex-shrink-0"
                  aria-hidden="true"
                />
                <span className="flex-grow-1 text-truncate">&nbsp;</span>
                {/* A span, not a button: the twin holds room, it does not take
                    focus. */}
                <span className={SEND_PROGRESS_DETAILS_CLASS}>
                  <i className="bi bi-info-circle" aria-hidden="true" />
                </span>
              </div>
            )}
          </div>
          <HilosModal
            open={sendDetailOpen}
            title="Send details"
            initialFocus="dialog"
            onClose={() => setSendDetailOpen(false)}
            actions={({ requestClose }) => (
              <button
                type="button"
                className="btn btn-secondary"
                data-id="auth-send-progress-close"
                onClick={requestClose}
              >
                Close
              </button>
            )}
          >
            <HilosLongText
              kind="prose"
              text={sendProgress?.text ?? ''}
              dataId="auth-send-progress-full"
            />
          </HilosModal>

          {/* The letter went out with two ways back in it, so the screen says so
              before it asks for one: the link is still the shorter road for
              whoever can click it, and the field below is for whoever cannot. */}
          {screenKey === 'check_inbox' ? (
            <div
              className="alert alert-success small py-2"
              data-id="auth-link-sent"
            >
              <i className="bi bi-envelope-check me-1" aria-hidden="true" />
              {LINK_SENT_LEAD} <strong>{form.identifier}</strong>.{' '}
              {LINK_SENT_TAIL}
            </div>
          ) : null}

          <div className="mb-3">
            <label className="form-label small fw-semibold" htmlFor="auth-code">
              Code
            </label>
            <input
              id="auth-code"
              ref={codeInput}
              type="text"
              inputMode="numeric"
              className="form-control"
              autoComplete="one-time-code"
              data-id="auth-code"
              value={form.code}
              onChange={(event) => auth.setField('code', event.target.value)}
            />
            {expiresIn ? (
              <div className="form-text" data-id="auth-expires-in">
                <i className="bi bi-clock me-1" aria-hidden="true" />
                Expires in {expiresIn}.
              </div>
            ) : null}
          </div>

          <HilosFormError message={errorMessage} dataId="auth-error" />

          <LoadingButton
            type="submit"
            className="btn-primary w-100 mb-2"
            loading={pending}
            disabled={!submittable}
            data-id="auth-submit"
          >
            {submitLabel}
          </LoadingButton>

          {/* The gate is the backend's (the address owns the cooldown, not this
              tab): while it holds, the button is a countdown instead. */}
          {resendIn ? (
            <div
              className="small text-body-secondary text-center"
              data-id="auth-resend-in"
            >
              <i className="bi bi-clock me-1" aria-hidden="true" />
              Send a new code in {resendIn}
            </div>
          ) : (
            <button
              type="button"
              className="btn btn-link btn-sm w-100"
              disabled={pending}
              data-id="auth-resend"
              onClick={() => void auth.resend()}
            >
              <i className="bi bi-arrow-clockwise me-1" aria-hidden="true" />
              Send a new code
            </button>
          )}

          {/* The way out, and the last thing on the card because it is the
              answer to "not this, then": a registration says so out loud and in
              red, and what it cancels is freed - the same address typed again
              starts over. A sign-in or a recovery has nothing to give back, so
              it says Back and wears the word the consent step already uses for
              the same move (HIL-829). */}
          {state.intent === 'register' ? (
            <button
              type="button"
              className="btn btn-link btn-sm w-100 text-danger"
              data-id="auth-cancel-registration"
              onClick={cancelRegistration}
            >
              Cancel registration
            </button>
          ) : (
            <button
              type="button"
              className="btn btn-link btn-sm w-100"
              data-id="auth-restart"
              onClick={cancelRegistration}
            >
              Back
            </button>
          )}
        </form>
      ) : null}

      {/* The same screen after its countdown ran out (HIL-828). The heading and
          the address block above do not move - the person is still doing the
          thing they came to do - and what changes is everything under them: no
          field, no Confirm, one line saying the code is dead and one button
          offering a new one. Not a form: there is nothing here to submit. */}
      {state.step === 'code_expired' ? (
        <div>
          <div className="d-flex align-items-center gap-2 mb-3 px-3 py-2 rounded bg-body-tertiary">
            <i
              className="bi bi-envelope text-body-secondary"
              aria-hidden="true"
            />
            <span className="small fw-semibold flex-grow-1">
              {form.identifier}
            </span>
          </div>

          <div
            className="alert alert-warning small py-2 mb-3"
            data-id="auth-code-expired"
          >
            <i className="bi bi-clock-history me-1" aria-hidden="true" />
            {CODE_EXPIRED_MESSAGE}
          </div>

          {/* The gate outlives the code it was armed for: it belongs to the
              address, so a person cannot spend a code, watch it expire and
              re-take the address inside the cooldown the gate exists to hold. */}
          {resendIn ? (
            <div
              className="small text-body-secondary text-center"
              data-id="auth-resend-in"
            >
              <i className="bi bi-clock me-1" aria-hidden="true" />
              Send a new code in {resendIn}
            </div>
          ) : (
            <LoadingButton
              type="button"
              className="btn-primary w-100 mb-2"
              loading={pending}
              data-id="auth-code-renew"
              onClick={() => void auth.renewCode()}
            >
              <i className="bi bi-arrow-clockwise me-1" aria-hidden="true" />
              Send a new code
            </LoadingButton>
          )}

          {/* The way out, and the last thing on the card because it is the
              answer to "not this, then": a registration says so out loud and in
              red, and what it cancels is freed - the same address typed again
              starts over. A sign-in or a recovery has nothing to give back, so
              it says Back and wears the word the consent step already uses for
              the same move (HIL-829). */}
          {state.intent === 'register' ? (
            <button
              type="button"
              className="btn btn-link btn-sm w-100 text-danger"
              data-id="auth-cancel-registration"
              onClick={cancelRegistration}
            >
              Cancel registration
            </button>
          ) : (
            <button
              type="button"
              className="btn btn-link btn-sm w-100"
              data-id="auth-restart"
              onClick={cancelRegistration}
            >
              Back
            </button>
          )}
        </div>
      ) : null}

      {/* One screen for two endings (HIL-825): a recovery writes the new
          password of an account that exists, a registration CREATES the account
          on the address it just proved. The address is not asked for again
          either way — what the accepted code left on this session names it. */}
      {state.step === 'set_password' ? (
        <form noValidate onSubmit={submit}>
          <p className="text-body-secondary small mb-3">{setPasswordLead}</p>

          {/* The address, for the password manager and for nobody else: a saved
              entry with no login against it is an entry its owner cannot use.
              Hidden rather than absent, because what the manager files the
              password under is the field beside it. */}
          <input
            type="text"
            hidden
            autoComplete="username"
            data-id="auth-username"
            value={form.identifier}
            readOnly
          />

          <div className="mb-3">
            <label
              className="form-label small fw-semibold"
              htmlFor="auth-new-password"
            >
              {newPasswordLabel}
            </label>
            <input
              id="auth-new-password"
              ref={newPasswordInput}
              type="password"
              className="form-control"
              autoComplete="new-password"
              data-id="auth-new-password"
              value={form.newPassword}
              onChange={(event) =>
                auth.setField('newPassword', event.target.value)
              }
            />
            <div className="form-text">
              At least {PASSWORD_MIN_LENGTH} characters.
            </div>
          </div>

          <HilosFormError message={errorMessage} dataId="auth-error" />

          <LoadingButton
            type="submit"
            className="btn-primary w-100 mb-2"
            loading={pending}
            disabled={!submittable}
            data-id="auth-submit"
          >
            {submitLabel}
          </LoadingButton>

          {/* Two ways to FINISH the registration, then the way to drop it, and
              the order carries that meaning (HIL-1008). Unemphasized rather
              than a second primary: choosing a password is still the road this
              screen is named after. */}
          {showFinishWithoutPassword ? (
            <button
              type="button"
              className="btn btn-link btn-sm w-100"
              disabled={pending}
              data-id="auth-complete-passwordless"
              onClick={completeWithoutPassword}
            >
              Create it without a password
            </button>
          ) : null}

          {/* The same way out the code screen carries, for the same reason: this
              screen has no address field and no step behind it, so whoever
              changed their mind here would otherwise be shut in (HIL-825). The
              hold is alive and proved at this point, so on a registration the
              cancel is the one that really gives the address back. */}
          {state.intent === 'register' ? (
            <button
              type="button"
              className="btn btn-link btn-sm w-100 text-danger"
              data-id="auth-cancel-registration"
              onClick={cancelRegistration}
            >
              Cancel registration
            </button>
          ) : (
            <button
              type="button"
              className="btn btn-link btn-sm w-100"
              data-id="auth-restart"
              onClick={cancelRegistration}
            >
              Back
            </button>
          )}
        </form>
      ) : null}

      {/* Parked on a ceremony. A link waits on the inbox, everything else waits
          on the device; both are the same step and both can be taken back —
          cancelling ends the ceremony itself rather than merely forgetting its
          outcome. */}
      {state.step === 'external' ? (
        <>
          {screenKey === 'check_inbox' ? (
            <div className="alert alert-success small py-2">
              <i className="bi bi-envelope-check me-1" aria-hidden="true" />
              {LINK_SENT_LEAD} <strong>{form.identifier}</strong>.{' '}
              {LINK_SENT_TAIL}
            </div>
          ) : (
            <div className="text-center py-4">
              <div className="spinner-border text-primary mb-3" role="status">
                <span className="visually-hidden">Waiting</span>
              </div>
              {trip ? (
                <div className="fw-semibold mb-1">{oauthTripTitle(trip)}</div>
              ) : null}
              <div className="small text-body-secondary">
                {trip ? oauthTripMessage(trip) : 'Waiting for your device…'}
              </div>
            </div>
          )}

          <HilosFormError message={errorMessage} dataId="auth-error" />

          {trip === null || trip.phase === 'authorizing' ? (
            <button
              type="button"
              className="btn btn-outline-secondary w-100"
              data-id="auth-cancel"
              onClick={() => auth.cancelMethod()}
            >
              Cancel
            </button>
          ) : null}
        </>
      ) : null}

      {/* The end of a flow is a screen with a button, not a fading toast: what
          was achieved is said once, and Continue is what closes it and lets the
          page through. */}
      {state.step === 'done' ? (
        <div className="text-center py-3">
          <i
            className="bi bi-check-circle-fill text-success mb-3 fs-1"
            aria-hidden="true"
          />
          <p className="text-body-secondary small mb-4">
            {screenKey === 'done_registered'
              ? 'Your address is confirmed and you are signed in.'
              : screenKey === 'done_password_changed'
                ? 'Your new password is saved. Codes left on other devices no longer work.'
                : 'You are signed in.'}
          </p>

          <LoadingButton
            type="button"
            className="btn-primary w-100"
            loading={pending}
            data-id="auth-continue"
            onClick={() => void continueFromDone()}
          >
            {submitLabel}
          </LoadingButton>
        </div>
      ) : null}
    </section>
  )
}
