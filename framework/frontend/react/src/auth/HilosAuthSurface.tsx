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
import { useContext, useEffect, useMemo, useRef, useState } from 'react'
import type { ChangeEvent, FormEvent } from 'react'
import {
  AUTH_CONVERGE_SIGNAL,
  authAckToFlowPatch,
  authConvergeSignalSchema,
  createAuthActions,
  createAuthFlow,
  createOAuthLogin,
  MAGIC_LINK_FLOW_METHOD,
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
  type ProjectSignal,
} from '@hilos/core'

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
      createAuthFlow({
        methods: context.methods,
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
  const pendingRegistration = useMemo(
    () => sessionPendingRegistration(context.scopes),
    [context],
  )
  const pendingAck = useMemo(() => sessionPendingAck(context.scopes), [context])

  // The gate defaults to null on purpose: on a 401 this surface stands IN PLACE
  // of the page, where there may be no provider at all, and then Continue simply
  // has nothing to close.
  const gate = useContext(HilosAuthGateContext)

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
  const ack = useSignal(pendingAck)

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

  // The inline refusal: the backend's own sentence when it sent one, its
  // semantic code turned into ours when it did not.
  const errorMessage =
    error === null
      ? null
      : (error.message ??
        (error.code === null ? null : CODE_MESSAGES[error.code]) ??
        GENERIC_ERROR)

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

  // The password reveals INSIDE the identifier step as a function of the reply:
  // an account that signs in with one asks for it, a free address that may be
  // registered with one offers it, and everything else (a phone, a passwordless
  // account) never shows the field at all.
  const detected = detection.result
  const showPassword =
    state.step === 'identifier' &&
    detected !== null &&
    detected.kind === 'email'
      ? detected.status === 'active'
        ? detected.methods.includes(PASSWORD_METHOD_KEY)
        : detected.status === 'none' &&
          detected.registerable.includes(PASSWORD_METHOD_KEY)
      : false

  // The recovery key sits beside the password and only for an account that HAS
  // one: there is nothing to reset for an address that signs in by link, and
  // nothing at all for one that has no account yet.
  const showRecovery =
    showPassword &&
    detected !== null &&
    detected.status === 'active' &&
    detected.methods.includes(PASSWORD_METHOD_KEY)

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
      return 'Your email address or phone number.'
    }
    if (state.identifierKind === 'unknown') {
      return 'That does not look like an email address or a phone number yet.'
    }
    if (detected === null) {
      return null
    }
    if (detected.status === 'none') {
      return detected.registerable.length > 0
        ? 'No account yet — this creates one.'
        : 'No account for this, and registration is closed.'
    }
    // A held address has no account to describe yet. The field is reachable with
    // one under the cursor — "Not that address?" comes back here and keeps what
    // the lookup found (HIL-486) — and saying "this account has no password"
    // about a registration nobody confirmed is worse than saying nothing.
    if (detected.status !== 'active') {
      return null
    }

    return showPassword ? null : 'This account has no password.'
  }

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

  /** The method the machine promoted to the main button, or null. */
  const primaryMethod =
    primaryAction === null || primaryAction.kind !== 'method'
      ? null
      : (context.methods.find((method) => method.key === primaryAction.key) ??
        null)

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

  // "Not that address?": give up the registration this session started — every
  // tab of it goes back to the field — while the hold on the address itself is
  // left alone, so one session cannot free an address another is registering.
  // What was typed survives; only the reservation is dropped.
  function abandon(): void {
    void authActions.abandonRegistration()
    auth.backToIdentifier()
  }

  // Continue on a finished flow: clear the announcement on the server, then let
  // the gate play out the resume it was holding — closing the surface and
  // un-gating the page in one move.
  //
  // Only when the server heard it. The ack is a ROW (HIL-422), so a surface
  // closed over a refused dispatch would leave the mark standing and meet the
  // person again with the same panel on the next handshake — while the error
  // explaining why is gone with the screen that held it.
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
  useEffect(() => {
    const patch = authAckToFlowPatch(ack)
    if (patch !== null) {
      auth.applyExternal(patch)
    }
  }, [ack, auth])

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

    /**
     * Apply what the server says this surface should be showing, whoever asked
     * for it: a converge about the address being waited on, or an ack this
     * connection still owes its person.
     *
     * @param step The step off the wire.
     * @param intent The intent off the wire.
     * @param code The semantic reason of a rollback, or null.
     */
    const applyFromServer = (
      step: unknown,
      intent: unknown,
      code: string | null,
    ): void => {
      const patch = toFlowPatch(step, intent)
      if (patch === null) {
        return
      }
      auth.applyExternal(patch)
      setNotice(code === null ? null : (CODE_MESSAGES[code] ?? null))
    }

    // Start every mount clean: the surface may be re-shown for a new gated
    // action.
    auth.reset()
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

    const clock = setInterval(() => {
      setNow(Date.now())
    }, COUNTDOWN_TICK_MS)

    const pendingLink = oauth.peekOAuthLink()
    setLinkPrompt(pendingLink !== null)
    if (pendingLink !== null) {
      auth.setField('identifier', pendingLink.email)
    }
    // The unfinished registration comes back from the SESSION, not from anything
    // this tab remembers, so a reload, a second tab and another device all
    // resume the same screen. Both pending facts are read from their signals
    // rather than from this render, so neither becomes a dependency that would
    // re-run the whole mount.
    auth.resume(pendingRegistration.get())
    const patch = authAckToFlowPatch(pendingAck.get())
    if (patch !== null) {
      auth.applyExternal(patch)
    }

    return () => {
      stopWatchingChannels()
      stopWatchingConverge()
      clearInterval(clock)
    }
  }, [auth, authActions, oauth, context, pendingRegistration, pendingAck])

  return (
    <section data-id="auth-surface" className="mx-auto" style={MAX_WIDTH}>
      <h2 className="h5 mb-3" data-id="auth-heading">
        {heading}
      </h2>

      {/* OAuth email-collision re-auth prompt (HIL-282): the provider address
          already has an account, so ask the person to sign in with an existing
          method to finish linking. The pending link token is redeemed globally
          once the session upgrades. */}
      {linkPrompt && state.step === 'identifier' ? (
        <div
          className="alert alert-info py-2"
          role="status"
          data-id="auth-link-prompt"
        >
          That email already has an account. Sign in to finish linking it.
        </div>
      ) : null}

      {/* News about a move nobody on this screen asked for. Its own region,
          above the form: it is not this step's refusal, and the step it lands on
          is usually the identifier field, where the error region belongs to what
          is typed next. */}
      {notice ? (
        <div
          className="alert alert-warning py-2"
          role="status"
          data-id="auth-notice"
        >
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
            {identifierHint ? (
              <div className="form-text" data-id="auth-identifier-hint">
                {identifierHint}
              </div>
            ) : null}
          </div>

          {/* The password lives inside this step, revealed by the reply. Beside
              it stand the ways past it: the envelope for an account that would
              rather have a link, the key for one that forgot the password. */}
          {showPassword ? (
            <div className="mb-3">
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
                  autoComplete={
                    state.intent === 'register'
                      ? 'new-password'
                      : 'current-password'
                  }
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
                    <i className={methodIcon(method.key)} aria-hidden="true" />
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
              {state.intent === 'register' ? (
                <div className="form-text">
                  At least {PASSWORD_MIN_LENGTH} characters.
                </div>
              ) : null}
            </div>
          ) : null}

          {errorMessage ? (
            <div
              className="alert alert-danger py-2"
              role="alert"
              aria-live="polite"
              data-id="auth-error"
            >
              {errorMessage}
            </div>
          ) : null}

          {/* The main control is whatever the machine says it is: the submit, a
              passwordless method promoted to the button, or a code channel — for
              a phone the channel choice IS the send, so there is no separate
              button. */}
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
                        title={
                          unavailableChannels.has(channel.key)
                            ? `${channel.label} cannot reach this number`
                            : channel.label
                        }
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

          {errorMessage ? (
            <div
              className="alert alert-danger py-2"
              role="alert"
              aria-live="polite"
              data-id="auth-error"
            >
              {errorMessage}
            </div>
          ) : null}

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
            {state.intent === 'register' ? (
              <button
                type="button"
                className="btn btn-sm btn-link p-0 small"
                data-id="auth-restart"
                onClick={abandon}
              >
                Not that address?
              </button>
            ) : null}
          </div>

          {deliveredChannel ? (
            <p
              className="text-body-secondary small mb-3"
              data-id="auth-delivered-channel"
            >
              Sent via {deliveredChannel}.
            </p>
          ) : null}

          {/* The letter went out with two ways back in it, so the screen says so
              before it asks for one: the link is still the shorter road for
              whoever can click it, and the field below is for whoever cannot. */}
          {screenKey === 'check_inbox' ? (
            <div
              className="alert alert-success small py-2"
              role="status"
              data-id="auth-link-sent"
            >
              <i className="bi bi-envelope-check me-1" aria-hidden="true" />
              We've sent a sign-in link to <strong>{form.identifier}</strong>.
              Open it to continue.
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

          {errorMessage ? (
            <div
              className="alert alert-danger py-2"
              role="alert"
              aria-live="polite"
              data-id="auth-error"
            >
              {errorMessage}
            </div>
          ) : null}

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
        </form>
      ) : null}

      {/* The new password of a recovery. The address is not asked for again: the
          accepted code left a grant on this session, and that is what names the
          account. */}
      {state.step === 'set_password' ? (
        <form noValidate onSubmit={submit}>
          <p className="text-body-secondary small mb-3">
            The code was accepted. Choose a new password.
          </p>

          <div className="mb-3">
            <label
              className="form-label small fw-semibold"
              htmlFor="auth-new-password"
            >
              New password
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

          {errorMessage ? (
            <div
              className="alert alert-danger py-2"
              role="alert"
              aria-live="polite"
              data-id="auth-error"
            >
              {errorMessage}
            </div>
          ) : null}

          <LoadingButton
            type="submit"
            className="btn-primary w-100"
            loading={pending}
            disabled={!submittable}
            data-id="auth-submit"
          >
            {submitLabel}
          </LoadingButton>
        </form>
      ) : null}

      {/* Parked on a ceremony. A link waits on the inbox, everything else waits
          on the device; both are the same step and both can be taken back —
          cancelling ends the ceremony itself rather than merely forgetting its
          outcome. */}
      {state.step === 'external' ? (
        <>
          {screenKey === 'check_inbox' ? (
            <div className="alert alert-success small py-2" role="status">
              <i className="bi bi-envelope-check me-1" aria-hidden="true" />
              We've sent a sign-in link to <strong>{form.identifier}</strong>.
              Open it to continue.
            </div>
          ) : (
            <div className="text-center py-4">
              <div className="spinner-border text-primary mb-3" role="status">
                <span className="visually-hidden">Waiting</span>
              </div>
              <div className="small text-body-secondary">
                Waiting for your device…
              </div>
            </div>
          )}

          {errorMessage ? (
            <div
              className="alert alert-danger py-2"
              role="alert"
              aria-live="polite"
              data-id="auth-error"
            >
              {errorMessage}
            </div>
          ) : null}

          <button
            type="button"
            className="btn btn-outline-secondary w-100"
            data-id="auth-cancel"
            onClick={() => auth.cancelMethod()}
          >
            Cancel
          </button>
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
