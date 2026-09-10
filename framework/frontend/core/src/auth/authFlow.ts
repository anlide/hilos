// The identifier-first auth flow state machine (HIL-413): the framework-agnostic
// "one identifier field, live account lookup, then the right next step" controller
// behind the redesigned sign-in surface (HIL-412, mockup framework/guest). It
// replaced the mode-first controller this module retired (HIL-423) — instead of
// a login/register/recovery switcher, the user types a SINGLE identifier (email or
// phone), the machine looks the account up live, and what happens next is a
// function of the axes {@link AuthStep} × {@link AuthIntent} plus the chosen
// method and code channel, NEVER a branch on mode names (hleb's defect: flow
// logic scattered across a store's mutations). It lives in @hilos/core so the
// Vue default surface (HIL-423) and the React/Angular demos (424/425) bind the
// same one small machine.
//
// A STEP IS A SCREEN CHANGE. There is no password step: the password reveals
// INSIDE the identifier step as a function of the detection reply (the mockup's
// known_email/new_email nodes are one screen with two fields). If the machine
// switched steps on a network reply the views would have to know that two steps
// share one layout — exactly the knowledge that must not spread. The one decided
// exception is a `pending` reservation, which parks the user on the code screen.
//
// THE REVEAL GROWS DOWNWARD, AND IT IS NOT TAKEN AWAY TO ASK AGAIN (HIL-646).
// Inside the identifier step the reveal is never removed for the duration of a
// new lookup: `pending` keeps the previous resolved reply, so editing a
// character across the "account exists ↔ free" border changes the composition
// ONCE, when the new reply lands, instead of twice. Everything that depends on
// the detection reply lives BELOW the identifier field; above it lives only the
// empty-field zone (the icon row, which leaves with the first character). An
// author adding a method keeps to that rule — a third place above the field
// brings the jump back the moment the next method is added.
//
// This leaf ships the pure core only: no DOM, no wire, no UI strings — the
// machine emits semantic keys ({@link AuthFlowScreen}, error codes) and the
// views/backend own all human-facing text. Three seams are delegated to the
// project: `onDetect` looks an identifier up (HIL-414), `onSubmit` dispatches
// the active step's form, and `onMethodAction` runs an icon method's ceremony
// (HIL-418/419). There is NO degraded detection state: transport fails as a
// whole and the connection gate owns that (rules-and-violations §A) — an
// unanswered lookup simply reveals nothing.
//
// Method descriptors are pure serializable DATA (no closures): HIL-427 phase 3
// ships the enabled set from backend settings over the wire, so a closure could
// never arrive — behavior is keyed by `key` and registered separately
// (onMethodAction). Code channels (HIL-492) mirror that: a channel is a
// descriptor plus a setting, never a surface edit. Change detection is
// `Object.is` (signal.ts): replace objects on update, never mutate them.

import { toLocal } from '../session/serverClock.js'
import { type PendingAuthStep } from '../session/sessionScope.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
} from '../state/signal.js'
import { type CodeSendProgress } from './authSendProgress.js'

/**
 * What an identifier looks like. Drives which icon methods and code channels
 * apply (an email-only magic link never shows for a phone) and whether a lookup
 * is meaningful. `unknown` is an empty or unrecognized field — no lookup fires
 * for it and the icon row hides while typing one.
 */
export type IdentifierKind = 'email' | 'phone' | 'unknown'

/**
 * The active step of the flow — one axis of the state, where a step means a
 * CHANGE OF SCREEN. `identifier` is the single entry field (password and all
 * reveal live inside it); `consent` is the registration terms screen; `code`
 * collects a one-time code (identifier confirmation, phone sign-in, recovery);
 * `code_expired` is that same screen after its countdown ran out — the field is
 * gone and one button offers a new code (HIL-828), a step of its own rather
 * than a flag because a step IS a screen here; `second_factor` is the two-step
 * verification code after a successful credential and before the session
 * upgrade (contract here, mechanism HIL-494); `set_password` chooses a new
 * password (recovery); `external` parks while an icon method's ceremony runs
 * (HIL-418/419); `done` is a real terminal screen with a Continue action.
 */
export type AuthStep =
  | 'identifier'
  | 'consent'
  | 'code'
  | 'code_expired'
  | 'second_factor'
  | 'set_password'
  | 'external'
  | 'done'

/**
 * The other axis of the state: what the flow is trying to do. `login` and
 * `recovery` act on an existing account; `register` creates one. The intent
 * derives from the detection reply (none → register, pending → register at the
 * code step, active → login) and the backend confirms it on submit.
 */
export type AuthIntent = 'login' | 'register' | 'recovery'

/**
 * The whole flow state as ONE object, so a transition is atomic (a view never
 * observes a half-applied pair of axes). `methodKey` is the icon method a
 * ceremony is running for; `channelKey` is the chosen code delivery channel
 * (HIL-492), named on the code screen; both are `null` outside their paths.
 */
export interface AuthFlowState {
  /** The active step (screen). */
  readonly step: AuthStep
  /** What the flow is trying to do. */
  readonly intent: AuthIntent
  /** The icon method being handed off to, or `null` on the identifier path. */
  readonly methodKey: string | null
  /** The classification of the current identifier field. */
  readonly identifierKind: IdentifierKind
  /** The chosen code delivery channel, or `null` before one is picked. */
  readonly channelKey: string | null
  /**
   * Where the code this screen is waiting for has got to, or `null` when there
   * is nothing to say (HIL-826).
   *
   * The server's own line, reported by whoever is carrying the code and
   * addressed to the browser SESSION — so it reads the same after a reload and
   * in a second tab, and nobody else sees it at all. The machine does not act on
   * it, for the reason it does not act on {@link AuthFlow.expiresAt}: what a
   * send is doing is the server's answer, and the screen only says it.
   *
   * `null` is the state and not an absence to paper over: a code screen with no
   * line is legal and reads as "nothing to say", never as an error.
   */
  readonly sendProgress: CodeSendProgress | null
}

/**
 * The flow's form fields. A single flat shape; each step reads only what it
 * needs. There is ONE `identifier` field (email or phone) — that unification is
 * the point of the redesign — and NO password confirmation (the mockup dropped
 * it). Replace the object to update, never mutate in place (signal.ts).
 */
export interface AuthFlowForm {
  /** The single identifier — an email or a phone number. */
  readonly identifier: string
  /** Password — revealed inside the `identifier` step by the detection reply. */
  readonly password: string
  /** One-time code — the `code` and `second_factor` steps. */
  readonly code: string
  /** New password — the `set_password` step. */
  readonly newPassword: string
  /** Whether the registration terms are accepted — the `consent` step. */
  readonly consentAccepted: boolean
  /** Whether the `second_factor` code is a backup code, not a generated one. */
  readonly usingBackupCode: boolean
  /** "Don't ask again on this device" on the `second_factor` step. */
  readonly trustDevice: boolean
}

/** A form field name, for the view's per-field update calls. */
export type AuthFlowField = keyof AuthFlowForm

/**
 * Where an icon method renders relative to the identifier/password inputs. The
 * list is CLOSED, and that is what anchors the layout (HIL-646): `icon_row`
 * stands above the field and lives only while the field is empty,
 * `password_adjacent` stands inside the reveal, below the field. Do not add a
 * third place above the field — anything above it that depends on the detection
 * reply appears and disappears as the person types, and the jump this leaf
 * removed comes back with the next method added.
 */
export type AuthMethodPlacement = 'icon_row' | 'password_adjacent'

/**
 * When an icon method is visible against the state of the identifier field. An
 * icon shows when the field is empty and `whenEmpty`, or non-empty and
 * `whenTyping` (and, if `identifierKinds` is set, the typed kind is in that
 * list). An unrecognized non-empty value hides ALL icons regardless. Pure data
 * so it can round-trip from HIL-427 backend settings.
 */
export interface AuthMethodVisibility {
  /** Show while the identifier field is empty (e.g. a discoverable passkey). */
  readonly whenEmpty?: boolean
  /** Show while the user is typing an identifier (e.g. a magic link). */
  readonly whenTyping?: boolean
  /** When typing, restrict to these identifier kinds (e.g. email-only). */
  readonly identifierKinds?: readonly IdentifierKind[]
}

/**
 * One enabled auth method as pure serializable DATA — the descriptor contract
 * that gates compatibility with HIL-427 (backend-declared method sets), which is
 * why it holds no closure. No behavior lives here: an icon
 * method's ceremony is registered by `key` via
 * {@link AuthFlowOptions.onMethodAction}.
 */
export interface AuthFlowMethodDescriptor {
  /** Stable method key, e.g. `password`, `oauth:github`, `passkey`. */
  readonly key: string
  /** Human-facing label. */
  readonly label: string
  /**
   * `identifier` takes the shared identifier field (the password path); `icon`
   * is a button (OAuth, passkey, magic link) rendered per {@link placement} and
   * gated by {@link visibility}.
   */
  readonly kind: 'identifier' | 'icon'
  /** Identifier kinds this method serves at all, e.g. a magic link serves `[email]`. */
  readonly identifierKinds?: readonly IdentifierKind[]
  /** Intents this method offers, if constrained (e.g. OAuth is login-only). */
  readonly intents?: readonly AuthIntent[]
  /** Icon visibility against the identifier field; absent means always visible. */
  readonly visibility?: AuthMethodVisibility
  /** Where an icon method renders; defaults to `icon_row`. */
  readonly placement?: AuthMethodPlacement
}

/**
 * One code delivery channel as pure serializable DATA — the registry the code
 * screens offer (HIL-492: a new channel is a descriptor plus a setting, never a
 * surface edit). Choosing a channel IS sending the code
 * ({@link AuthFlow.chooseChannel}).
 */
export interface CodeChannelDescriptor {
  /** Stable channel key, e.g. `sms`, `telegram`. */
  readonly key: string
  /** Human-facing label. */
  readonly label: string
  /** Identifier kinds this channel serves; absent means all. */
  readonly identifierKinds?: readonly IdentifierKind[]
  /** Whether this channel is the default (drives the primary action). */
  readonly primary?: boolean
}

/**
 * The result of looking an identifier up — the contract with HIL-414. The
 * detection is FOUR-VALUED: `none` (no account — registration if `registerable`
 * offers it), `pending` (a reserved registration awaiting its code — the flow
 * parks on the code screen WITHOUT re-sending), `proven` (a reservation this
 * browser has already answered the code for — the flow goes to the password
 * screen that creates the account, HIL-825), `active` (sign in). `methods`
 * carries the account's available method keys — a passwordless account must
 * land on its passwordless method as the primary action, and only `methods` can
 * decide that.
 */
export interface IdentifierDetection {
  /**
   * The identifier EXACTLY as the lookup was asked (the request echo). Replies
   * are matched to the field by this value — never by `normalized`, so a
   * backend-normalized phone does not orphan its own reply.
   */
  readonly identifier: string
  /** The identifier as normalized by the backend (e.g. E.164 phone). */
  readonly normalized: string
  /** How the backend classified it. */
  readonly kind: 'email' | 'phone'
  /** The account status behind the identifier. */
  readonly status: 'none' | 'pending' | 'proven' | 'active'
  /** The method keys available to this account (empty for `none`). */
  readonly methods: readonly string[]
  /** The method keys registration is open with (consulted for `none`). */
  readonly registerable: readonly string[]
  /**
   * Why registration is not offered, or `null` when it is (HIL-830). Read only
   * for `none`, and only ever alongside an empty `registerable`: `closed` is a
   * decision somebody made, `no_channel` is this installation having nothing to
   * send a code with. The surface says a different sentence for each, and
   * resolving which is the backend's — never a comparison of flags here.
   */
  readonly registrationBlock: 'closed' | 'no_channel' | null
}

/**
 * The lifecycle of the live identifier lookup. There is deliberately NO
 * `unavailable` state: transport fails as a whole and the connection gate owns
 * that — an unanswered lookup leaves the flow at `idle` with nothing revealed.
 */
export type DetectionStatus = 'idle' | 'pending' | 'resolved'

/** The detection signal a view renders the reveal from. */
export interface DetectionState {
  /** The lookup lifecycle status. */
  readonly status: DetectionStatus
  /**
   * The lookup reply the reveal is drawn from. `null` in `idle`; in `pending`
   * it carries the PREVIOUS resolved reply when there was one, so the reveal is
   * not taken away for the duration of a new lookup (HIL-646) — the composition
   * changes exactly once, when the new reply lands.
   */
  readonly result: IdentifierDetection | null
}

/**
 * The inline error of the active step. Both parts are backend-supplied: the
 * machine never invents human-facing text (a dispatch that fails without a
 * message surfaces `code` alone and the view maps it).
 */
export interface AuthFlowError {
  /** The backend's human-facing message, or `null` when it sent none. */
  readonly message: string | null
  /**
   * The semantic error code, or `null`. Known codes include `rate_limited` and
   * `challenge_required` (HIL-420).
   */
  readonly code: string | null
}

/**
 * The outcome a delegated dispatch reports back. On failure `message`/`code`
 * surface inline (auth deliberately shows the backend reason). `next` is a
 * PARTIAL flow state merged over the current one whatever `ok` says — the
 * backend decides where the flow goes, and a refusal that knows where the person
 * belongs says so too (`AuthFlowOutcome::rejectTo()`, against the `refuse()` that
 * does not); omit it when a session upgrade closes the surface. `resendAt` arms
 * the resend gate ({@link AuthFlow.resend}).
 */
export interface AuthFlowSubmitOutcome {
  /** Whether the dispatch succeeded. */
  readonly ok: boolean
  /** The inline error message to show on failure. */
  readonly message?: string
  /** The semantic error code on failure, e.g. `rate_limited` (HIL-420). */
  readonly code?: string
  /** A partial next flow state to merge whatever `ok` says; omit to stay put. */
  readonly next?: Partial<AuthFlowState>
  /**
   * The SERVER moment a code re-send is allowed again, in epoch ms, when one was
   * just sent. A moment rather than a duration because a duration is spent by a
   * reload: nobody wrote down when the counting started (HIL-486).
   */
  readonly resendAt?: number
  /**
   * The SERVER moment the code or link the submit left on screen stops being
   * good, in epoch ms. Of the same nature as {@link resendAt} and absent for the
   * same reason — a submit that left nothing waiting has no moment to name
   * (HIL-486).
   */
  readonly expiresAt?: number
}

/**
 * The derived main control of the current screen: the submit button, an icon
 * method promoted to the primary button (a passwordless account's magic link),
 * a code channel (a phone signs in by code, so its channel IS the send), the way
 * back to a code this browser is already holding (`resume_code`, HIL-651), the
 * way on to the password of an address this browser has already proved
 * (`resume_password`, HIL-825), or nothing (`null` — e.g. an empty field or a
 * parked ceremony).
 */
export type AuthFlowPrimaryAction =
  | { readonly kind: 'submit' }
  | { readonly kind: 'method'; readonly key: string }
  | { readonly kind: 'channel'; readonly key: string }
  | { readonly kind: 'resume_code' }
  | { readonly kind: 'resume_password' }
  | null

/**
 * The derived semantic key of the active screen's HEADING — the mockup demands
 * the title come from the surface, not from the modal frame, and the machine is
 * the one place that knows which screen the axes add up to. The views map keys
 * to text; the core ships no UI strings.
 */
export type AuthFlowScreen =
  | 'sign_in'
  | 'create_account'
  | 'held_identifier'
  | 'proven_identifier'
  | 'terms'
  | 'confirm_identifier'
  | 'enter_code'
  | 'reset_code'
  | 'choose_password'
  | 'set_first_password'
  | 'two_step'
  | 'waiting_external'
  | 'check_inbox'
  | 'done_registered'
  | 'done_password_changed'
  | 'done_signed_in'

/** What a submit dispatch is: the step's form, or a code re-send. */
export type AuthSubmitAction = 'submit' | 'resend'

/** Wiring for {@link createAuthFlow}. */
export interface AuthFlowOptions {
  /** The project's ordered enabled methods; drives the field, icons and reveal. */
  methods: readonly AuthFlowMethodDescriptor[]
  /** The project's ordered code delivery channels (HIL-492); may be empty. */
  channels: readonly CodeChannelDescriptor[]
  /**
   * Look an identifier up over the project's transport (HIL-414). Called
   * debounced, only for a recognized, complete email/phone. A rejection reveals
   * nothing (the machine returns to `idle`) — the connection gate owns broken
   * transport, and a limiter refusal is an ordinary action error, not a state.
   *
   * @param identifier The current identifier value (echoed back in the reply).
   * @param kind Its classification.
   */
  onDetect: (
    identifier: string,
    kind: IdentifierKind,
  ) => Promise<IdentifierDetection>
  /**
   * Dispatch the active step's form (`submit`) or a code re-send (`resend`)
   * over the project's transport, resolving the outcome. The machine guards
   * re-entry and owns pending/error; the backend decides the next step via
   * {@link AuthFlowSubmitOutcome.next}.
   *
   * @param action What is being dispatched.
   * @param flow The current flow state (step/intent/channel tell it what to do).
   * @param form The current form values.
   */
  onSubmit: (
    action: AuthSubmitAction,
    flow: AuthFlowState,
    form: AuthFlowForm,
  ) => Promise<AuthFlowSubmitOutcome>
  /**
   * Run an icon method's registered behavior — its OAuth redirect or WebAuthn
   * ceremony (HIL-418/419). The flow parks in `external` while it runs.
   *
   * The signal is aborted by {@link AuthFlow.cancelMethod}, and a driver MUST
   * pass it down to the browser call it awaits: cancelling has to END the
   * ceremony, not merely orphan its outcome — a still-open device dialog that
   * a late finger satisfies would raise a session the user just refused.
   *
   * @param key The chosen method key.
   * @param form The current form values (e.g. a typed identifier a ceremony reuses).
   * @param signal Aborted when the user cancels the parked external step.
   */
  onMethodAction: (
    key: string,
    form: AuthFlowForm,
    signal: AbortSignal,
  ) => Promise<AuthFlowSubmitOutcome>
  /** Detection debounce in ms; defaults to {@link DEFAULT_DETECT_DEBOUNCE_MS}. */
  detectDebounceMs?: number
  /**
   * How long a canceled LOGIN ceremony's late success is still applied, in ms;
   * defaults to {@link DEFAULT_EXTERNAL_CANCEL_GRACE_MS}. Only the number is a
   * project's to choose — which intents accept a late outcome is the machine's
   * rule, because it turns on the intent only the machine knows.
   */
  externalCancelGraceMs?: number
}

/** The reactive identifier-first flow a view binds and drives. */
export interface AuthFlow {
  /** The whole flow state (all axes), atomic. */
  readonly flow: ReadonlySignal<AuthFlowState>
  /** The current form values. */
  readonly form: ReadonlySignal<AuthFlowForm>
  /** The live identifier lookup state. */
  readonly detection: ReadonlySignal<DetectionState>
  /** Whether a submit or ceremony is in flight (disables the controls). */
  readonly pending: ReadonlySignal<boolean>
  /** The active step's inline error, or `null` when clear. */
  readonly error: ReadonlySignal<AuthFlowError | null>
  /** Whether the active step's form is complete enough to submit. */
  readonly submittable: ReadonlySignal<boolean>
  /** The icon methods currently visible against the identifier field. */
  readonly icons: ReadonlySignal<readonly AuthFlowMethodDescriptor[]>
  /** The code channels applicable to the current identifier kind. */
  readonly channels: ReadonlySignal<readonly CodeChannelDescriptor[]>
  /** The derived main control of the current screen. */
  readonly primaryAction: ReadonlySignal<AuthFlowPrimaryAction>
  /** The derived semantic key of the active screen's heading. */
  readonly screenKey: ReadonlySignal<AuthFlowScreen>
  /**
   * The LOCAL epoch-ms moment a code re-send unblocks — the backend's `resendAt`
   * put on this browser's scale — or `null` when un-armed. The view draws the
   * countdown; the machine enforces the gate in {@link resend}.
   */
  readonly resendAvailableAt: ReadonlySignal<number | null>
  /**
   * The LOCAL epoch-ms moment the code on screen stops being good, or `null`
   * when nothing is counting down. Of the same nature as
   * {@link resendAvailableAt} and drawn the same way.
   *
   * The machine acts on it in exactly one way (HIL-828): when it passes, the
   * code step becomes `code_expired`. That is a statement about the SCREEN and
   * not about the code — what a code is worth stays the server's answer, and a
   * code typed a second before zero is still judged by the backend. The flip is
   * local because for a phone sign-in nothing on the server marks the moment at
   * all, and the one event that exists for a registration arrives on a cron rule
   * up to a minute late.
   */
  readonly expiresAt: ReadonlySignal<number | null>
  /**
   * Restore the step a session left unfinished, as the handshake reports it
   * (HIL-486, HIL-648). Parks the flow on the screen the node NAMES, with the
   * identifier back in the form, so a reload, a second tab and another device
   * all come back to where the session stands - a registration on its code
   * screen, a recovery on its code or its new-password screen.
   *
   * A `null` pending step does NOTHING, deliberately: a reconnect that lands
   * while somebody is halfway through typing an identifier must not wipe what
   * they are doing. A step is only ever taken AWAY by the server saying so
   * (the converge signal), never by a handshake that had nothing to say.
   *
   * @param pending The unfinished auth step, or `null` when the session stands
   *   on none.
   */
  resume(pending: PendingAuthStep | null): void
  /**
   * Put the server's reported send step on the code screen, or take the line
   * away with `null` (HIL-826).
   *
   * A verb rather than an option, and for the reason {@link resume} is one: the
   * frame arrives on the project's connection, which the machine deliberately
   * knows nothing about, so the surface that holds the socket reads it and hands
   * it in. What the machine adds is the LIFETIME - the line belongs to the code
   * the person is waiting for, so a new submit, a step back to the field and a
   * cancelled ceremony all end it here, in one place, rather than in each of the
   * three views.
   *
   * @param progress The reported step, or `null` when there is nothing to say.
   */
  reportSendProgress(progress: CodeSendProgress | null): void
  /**
   * Update one form field, typed by the field's name (the boolean flags take a
   * boolean, not a string). Editing `identifier` restarts the flow, clears the
   * rest of the form — the ONLY thing that does (input preservation) — and
   * (re)schedules the live lookup. Re-emitting the SAME identifier resets
   * nothing but retries a lookup that failed.
   *
   * @param field The field to set.
   * @param value The new value, of that field's type.
   */
  setField<F extends AuthFlowField>(field: F, value: AuthFlowForm[F]): void
  /**
   * Submit the active step. From the `identifier` step with a `register` intent
   * this is a LOCAL move to `consent` — nothing is created until the terms
   * screen submits. Everywhere else it dispatches. A no-op while pending.
   */
  submit(): Promise<void>
  /**
   * Re-send the active code. Blocked (a silent no-op) until
   * {@link resendAvailableAt}; the backend re-arms the gate via
   * `resendAt`. A no-op while pending.
   */
  resend(): Promise<void>
  /**
   * Order a new code from the `code_expired` screen (HIL-828).
   *
   * Not a re-send: the code ran out, and for a registration the hold on the
   * address died with it by design, so there is nothing left to top up and the
   * address is TAKEN AGAIN. What this dispatches is therefore the send that
   * STARTED the flow — a registration, a phone code, a magic link, a recovery
   * request — which for the last three is byte-identical to what their re-send
   * already dispatches.
   *
   * The send gate still rules it: it belongs to the address and outlives the
   * code, so this is a silent no-op inside the cooldown, exactly like
   * {@link resend}, and the screen draws that countdown instead of the button.
   * A no-op while pending.
   */
  renewCode(): Promise<void>
  /**
   * Hand off to an icon method's ceremony; parks the flow in `external` (a
   * magic link parks the same way — its screen differs by key). A no-op for an
   * unknown key, a non-icon method, or while pending.
   *
   * @param key The chosen icon method key.
   */
  chooseMethod(key: string): Promise<void>
  /**
   * Pick a code delivery channel — which IS sending the code (there is no
   * separate send control), except under a `register` intent on the identifier
   * step, where the choice is stored and the flow hops to consent locally:
   * registration dispatches nothing before the terms screen submits. The
   * chosen key lives in the state and the code screen names it. A no-op for an
   * unknown key, while pending, or while the resend cooldown blocks sending.
   *
   * @param key The chosen channel key.
   */
  chooseChannel(key: string): Promise<void>
  /**
   * Enter the recovery flow (the key icon next to the password; the view gates
   * it on an `active` detection whose methods include `password`). A local move
   * to the recovery code screen; the code email itself is the view's transport
   * concern.
   */
  startRecovery(): void
  /**
   * Return to the single identifier field, keeping everything typed. The
   * mockup's exactly-two return points: "Not that address?" on the registration
   * code screen, and "Back" on the consent screen. There is NO general back().
   *
   * The field is re-looked-up on arrival, so the screen that greets them states
   * what the address is NOW rather than what it was before they left; the answer
   * never moves the step, because the return itself was the choice (HIL-651).
   */
  backToIdentifier(): void
  /**
   * Go back to the code of a registration this browser already started — the
   * `resume_code` primary action of the `held_identifier` screen. A purely LOCAL
   * move to the code step under the register intent: nothing is sent, because a
   * code is already on its way, and no deadline is restored, because what a code
   * is worth is the server's answer (the code screen's own "Send a new code"
   * asks for a fresh one, held back by the server cooldown).
   *
   * A no-op nowhere: the screen that offers it is the only place it is drawn.
   */
  resumeHeldRegistration(): void
  /**
   * Go on to the password of a registration this browser has already proved —
   * the `resume_password` primary action of the `proven_identifier` screen. A
   * purely LOCAL move to the password step under the register intent: nothing is
   * sent, because the proof is already recorded on the hold and the account is
   * created by the save that comes next (HIL-825).
   *
   * A no-op nowhere: the screen that offers it is the only place it is drawn.
   */
  resumeProvenRegistration(): void
  /**
   * Cancel a running ceremony and return to the identifier field: aborts the
   * ceremony's signal and releases the pending guard (an abandoned ceremony may
   * never settle). It reaches wherever one runs — the `external` park a sign-in
   * waits on and the terms screen a registration sends from — and an `external`
   * step left without a ceremony (a reload, a converge) still returns to the
   * field. A late outcome that lands anyway is judged by intent — see
   * {@link AuthFlowOptions.externalCancelGraceMs}.
   */
  cancelMethod(): void
  /**
   * Apply an EXTERNAL flow transition (a ceremony's redirect landing, a
   * cross-tab converge) with the same merge as a backend `next`: clears the
   * shown error, never touches the form. Every step must survive being rebuilt
   * under the user's hands.
   *
   * @param next The partial flow state to merge.
   */
  applyExternal(next: Partial<AuthFlowState>): void
  /** Reset to the initial identifier step with an empty form — call on (re)mount. */
  reset(): void
}

/** Minimum password length; the mirror of HIL-164's server rule (length-only). */
export const PASSWORD_MIN_LENGTH = 8

/** Default detection debounce: quiet enough to skip mid-word lookups. */
export const DEFAULT_DETECT_DEBOUNCE_MS = 300

/**
 * Default window in which a canceled LOGIN ceremony's late success still counts.
 * "Pressed Cancel and put the finger down anyway" is an ordinary sequence, and
 * the worst it costs is a session the person can sign out of. A canceled
 * REGISTER ceremony's late success is dropped whatever this is set to: it would
 * create the very account the person just refused, and that is not undoable.
 */
export const DEFAULT_EXTERNAL_CANCEL_GRACE_MS = 30000

/** The `password` method key — the shared-identifier-field method (HIL-416). */
export const PASSWORD_METHOD_KEY = 'password'

/**
 * The `magic_link` method key. Core-known because the machine derives the
 * `check_inbox` screen (vs the generic `waiting_external`) from it.
 */
export const MAGIC_LINK_METHOD_KEY = 'magic_link'

/** A full-email shape — the gate for firing a lookup, not backend validation. */
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

/**
 * Phone digit-count bounds mirroring the backend E.164 normalizer
 * ({@link \Hilos\Auth\PhoneNumber}); client-side gating only.
 */
const PHONE_MIN_DIGITS = 8
const PHONE_MAX_DIGITS = 15

/** Cosmetic phone separators stripped before counting digits. */
const PHONE_SEPARATORS = /[\s\-().]/g

/** The starting flow state — the single identifier field, no intent decided yet. */
const INITIAL_FLOW: AuthFlowState = {
  step: 'identifier',
  intent: 'login',
  methodKey: null,
  identifierKind: 'unknown',
  channelKey: null,
  sendProgress: null,
}

/** An empty form — the starting and identifier-change reset value. */
const EMPTY_FORM: AuthFlowForm = {
  identifier: '',
  password: '',
  code: '',
  newPassword: '',
  consentAccepted: false,
  usingBackupCode: false,
  trustDevice: false,
}

/** The detection signal before or without a lookup. */
const IDLE_DETECTION: DetectionState = { status: 'idle', result: null }

/**
 * The detection signal while a lookup is in flight, holding on to the reply the
 * reveal is currently drawn from (HIL-646).
 *
 * @param previous The last resolved reply to keep showing, or `null` to reveal
 *   nothing while the lookup runs.
 */
function pendingDetection(
  previous: IdentifierDetection | null,
): DetectionState {
  return { status: 'pending', result: previous }
}

/**
 * The default identifier (email/phone + password) method — the shared
 * identifier field. A project lists it first; icon methods are added around it.
 */
export const PASSWORD_FLOW_METHOD: AuthFlowMethodDescriptor = {
  key: PASSWORD_METHOD_KEY,
  label: 'Email or phone',
  kind: 'identifier',
}

/**
 * Builds one OAuth provider's icon method — shown only while the field is EMPTY
 * (HIL-419 revision: tapping a provider mid-address discards the typed
 * identifier, so once the user chose the long road the surface develops it),
 * login-only.
 *
 * A factory and not a constant per provider: the SHAPE of the descriptor is the
 * core's to keep identical across every provider and every view, while WHICH
 * providers exist and what their buttons say is the project's to declare — it is
 * the project that wired the credentials. A hard-coded provider here would also
 * be a name three view packages could drift apart on.
 *
 * @param providerKey The provider key as the backend registry stores it, e.g. `oauth:github`.
 * @param label The button caption, e.g. `Continue with GitHub`.
 * @returns The icon-row descriptor for that provider.
 */
export function oauthFlowMethod(
  providerKey: string,
  label: string,
): AuthFlowMethodDescriptor {
  return {
    key: providerKey,
    label,
    kind: 'icon',
    intents: ['login'],
    visibility: { whenEmpty: true, whenTyping: false },
    placement: 'icon_row',
  }
}

/**
 * The discoverable passkey icon method — shown only while the field is EMPTY (a
 * discoverable credential needs no identifier); it disappears as the user types.
 */
export const PASSKEY_FLOW_METHOD: AuthFlowMethodDescriptor = {
  key: 'passkey',
  label: 'Sign in with a passkey',
  kind: 'icon',
  visibility: { whenEmpty: true, whenTyping: false },
  placement: 'icon_row',
}

/**
 * The email magic-link icon method — shown only while typing an EMAIL, rendered
 * next to the password so it reads as a passwordless alternative for that
 * account.
 */
export const MAGIC_LINK_FLOW_METHOD: AuthFlowMethodDescriptor = {
  key: MAGIC_LINK_METHOD_KEY,
  label: 'Email me a sign-in link',
  kind: 'icon',
  identifierKinds: ['email'],
  visibility: {
    whenEmpty: false,
    whenTyping: true,
    identifierKinds: ['email'],
  },
  placement: 'password_adjacent',
}

/**
 * The SMS code channel — the one every project has, and the default a phone
 * code goes over (HIL-492). Primary because SMS reaches a number with no prior
 * relationship, which is exactly what a stranger signing in has; a messenger
 * cannot promise that, so promoting one would default most people to a channel
 * that cannot reach them.
 *
 * The backend half is `SmsCodeChannel`; the two agree on key, label and
 * identifier kinds, and nothing else about a channel crosses to the browser.
 */
export const SMS_CODE_CHANNEL: CodeChannelDescriptor = {
  key: 'sms',
  label: 'SMS',
  identifierKinds: ['phone'],
  primary: true,
}

/**
 * The Telegram code channel (HIL-492), delivered through the Telegram Gateway.
 * Not primary: a number is on Telegram only if its owner put it there, so it is
 * offered beside SMS rather than in front of it — the surface dims it when the
 * backend reports the number unreachable.
 */
export const TELEGRAM_CODE_CHANNEL: CodeChannelDescriptor = {
  key: 'telegram',
  label: 'Telegram',
  identifierKinds: ['phone'],
}

/**
 * Classify an identifier as email, phone, or unknown — a pure core function so
 * the views never re-implement it. An `@` reads as an email; an otherwise
 * all-digit value (with cosmetic separators) reads as a phone; anything else is
 * unknown. This is lenient (it fires while typing, e.g. `a@` is already
 * `email`) — the stricter completeness gate for firing a lookup is separate.
 *
 * @param value The raw identifier field value.
 */
export function classifyIdentifier(value: string): IdentifierKind {
  const trimmed = value.trim()
  if (trimmed === '') {
    return 'unknown'
  }
  if (trimmed.includes('@')) {
    return 'email'
  }
  if (/^\+?\d+$/.test(trimmed.replace(PHONE_SEPARATORS, ''))) {
    return 'phone'
  }

  return 'unknown'
}

/**
 * Whether an identifier is complete enough to spend a lookup on: a full email,
 * or a phone whose digit count is within the backend's bounds. A
 * recognized-but-partial value (`a@`, a three-digit number) is not.
 *
 * @param value The raw identifier field value.
 * @param kind Its classification.
 */
function isIdentifierComplete(value: string, kind: IdentifierKind): boolean {
  const trimmed = value.trim()
  if (kind === 'email') {
    return EMAIL_PATTERN.test(trimmed)
  }
  if (kind === 'phone') {
    return new RegExp(
      `^\\+?\\d{${PHONE_MIN_DIGITS},${PHONE_MAX_DIGITS}}$`,
    ).test(trimmed.replace(PHONE_SEPARATORS, ''))
  }

  return false
}

/**
 * Whether one icon method is visible against the identifier field's state.
 * Absent visibility means always visible; otherwise it shows when empty and
 * `whenEmpty`, or when typing and `whenTyping` (and, when `identifierKinds` is
 * set, only for a kind in that list).
 *
 * @param descriptor The icon method descriptor.
 * @param empty Whether the identifier field is empty.
 * @param kind The current identifier classification (only consulted when typing).
 */
function isIconVisible(
  descriptor: AuthFlowMethodDescriptor,
  empty: boolean,
  kind: IdentifierKind,
): boolean {
  const visibility = descriptor.visibility
  if (visibility === undefined) {
    return true
  }
  if (empty) {
    return visibility.whenEmpty ?? false
  }
  if (!(visibility.whenTyping ?? false)) {
    return false
  }
  if (
    visibility.identifierKinds !== undefined &&
    !visibility.identifierKinds.includes(kind)
  ) {
    return false
  }

  return true
}

/**
 * The icon methods visible given the identifier field, its kind, and the
 * current intent, in registry order — a pure derivation the machine exposes as
 * a signal and the view only renders (splitting by
 * {@link AuthFlowMethodDescriptor.placement}). A non-empty UNRECOGNIZED value
 * hides the whole row: no icon serves an identifier that is neither an email
 * nor a phone, and a half-typed value must not flicker the row. The
 * descriptor-level constraints are honored here too: a method constrained by
 * `intents` hides outside them (OAuth is login-only), and one constrained by
 * its top-level `identifierKinds` hides while typing a kind it does not serve.
 *
 * @param methods The project's ordered method descriptors.
 * @param identifier The current identifier field value.
 * @param kind The current identifier classification.
 * @param intent The current flow intent.
 */
export function visibleMethodIcons(
  methods: readonly AuthFlowMethodDescriptor[],
  identifier: string,
  kind: IdentifierKind,
  intent: AuthIntent,
): readonly AuthFlowMethodDescriptor[] {
  const empty = identifier.trim() === ''
  if (!empty && kind === 'unknown') {
    return []
  }

  return methods.filter(
    (method) =>
      method.kind === 'icon' &&
      (method.intents === undefined || method.intents.includes(intent)) &&
      (empty ||
        method.identifierKinds === undefined ||
        method.identifierKinds.includes(kind)) &&
      isIconVisible(method, empty, kind),
  )
}

/**
 * The code channels applicable to an identifier kind, in registry order — the
 * core-side selection HIL-492 requires (a new channel must never mean a surface
 * edit).
 *
 * @param channels The project's ordered channel descriptors.
 * @param kind The current identifier classification.
 */
export function applicableChannels(
  channels: readonly CodeChannelDescriptor[],
  kind: IdentifierKind,
): readonly CodeChannelDescriptor[] {
  return channels.filter(
    (channel) =>
      channel.identifierKinds === undefined ||
      channel.identifierKinds.includes(kind),
  )
}

/**
 * Whether an active step's form is complete enough to submit. Client-side
 * gating for the button state only — the backend stays the source of truth. On
 * the `identifier` step nothing is submittable until the detection resolved —
 * including while a re-ask is in flight over a held reply (HIL-646): a
 * login submits a non-empty password into the field its account reveals, a
 * password registration submits as soon as the lookup answers that the address
 * is free and registrable — there is no password there to measure since HIL-825,
 * because a registration asks for one only after the code. A phone never submits
 * from this step (its channel choice is the send), a passwordless registration
 * goes through its method's ceremony rather than submit, and a `proven` address
 * offers its primary action instead — all three mirrored by the primary action.
 * `external` and `code_expired` are never submittable (the second has no field
 * left to fill); `done` always is (its Continue).
 *
 * @param flow The current flow state.
 * @param form The current form values.
 * @param detection The current detection state (the identifier step's reveal).
 */
export function isFlowSubmittable(
  flow: AuthFlowState,
  form: AuthFlowForm,
  detection: DetectionState,
): boolean {
  switch (flow.step) {
    case 'identifier': {
      if (detection.status !== 'resolved') {
        // The reveal is held across a new lookup (HIL-646), so the held reply
        // would otherwise make the form submittable on a stale verdict. This is
        // the one price of the holding, and the only sign of it on screen.
        return false
      }
      const result = detection.result
      if (result === null || result.kind !== 'email') {
        return false
      }
      if (
        result.status === 'active' &&
        result.methods.includes(PASSWORD_METHOD_KEY)
      ) {
        return form.password !== ''
      }
      if (
        result.status === 'none' &&
        result.registerable.includes(PASSWORD_METHOD_KEY)
      ) {
        return true
      }

      return false
    }
    case 'consent':
      return form.consentAccepted
    case 'code':
    case 'second_factor':
      return form.code.trim() !== ''
    case 'code_expired':
      // The screen has no field at all (HIL-828): its one control is the button
      // that orders a new code, and that is not a submit of this step.
      return false
    case 'set_password':
      return form.newPassword.length >= PASSWORD_MIN_LENGTH
    case 'external':
      return false
    case 'done':
      return true
  }
}

/**
 * The code screen per intent: the registration code proves the address, the
 * login code signs a phone in, the recovery code resets a password.
 */
const CODE_SCREENS: Record<AuthIntent, AuthFlowScreen> = {
  register: 'confirm_identifier',
  recovery: 'reset_code',
  login: 'enter_code',
}

/** The terminal screen per intent — each flow ends on its own done screen. */
const DONE_SCREENS: Record<AuthIntent, AuthFlowScreen> = {
  register: 'done_registered',
  recovery: 'done_password_changed',
  login: 'done_signed_in',
}

/**
 * The derived semantic heading key — the one place the axes add up to a screen.
 * A magic link waits on `check_inbox` through BOTH of its steps — the send in
 * flight (`external`) and the code screen that follows it — while every other
 * ceremony waits on `waiting_external`; the remaining code and done screens
 * split by intent ({@link CODE_SCREENS}, {@link DONE_SCREENS}).
 *
 * The identifier step is the one screen a LOOKUP has a say in: an address this
 * browser is already holding a code for earns `held_identifier` instead of the
 * registration offer, because offering to create an account there would deny a
 * reservation the same person just made (HIL-651), and one it has already proved
 * earns `proven_identifier` — the code is spent, so sending them back to it would
 * ask for something that no longer works (HIL-825).
 *
 * The password step is the other: one screen serves both the recovery that ends
 * on it and the registration that is CREATED by it, and the two differ in nothing
 * but what they say, so the intent names them apart the way it does the code and
 * done screens.
 *
 * @param flow The current flow state.
 * @param result The resolved lookup behind the field, or `null` when the
 *   identifier step has nothing resolved (and on every other step, which does
 *   not consult it).
 */
export function screenKeyOf(
  flow: AuthFlowState,
  result: IdentifierDetection | null = null,
): AuthFlowScreen {
  switch (flow.step) {
    case 'identifier':
      if (result?.status === 'pending') {
        return 'held_identifier'
      }
      if (result?.status === 'proven') {
        return 'proven_identifier'
      }

      return flow.intent === 'register' ? 'create_account' : 'sign_in'
    case 'consent':
      return 'terms'
    case 'code':
    case 'code_expired':
      // The heading must not change under the person when the letter goes out
      // (HIL-606): they asked for a letter and they are still waiting on it, so
      // the screen stays `check_inbox` and merely grows a field. Every other
      // code screen is named by its intent, which a magic link has no use for —
      // one letter serves both signing in and registering.
      //
      // A code running out changes the controls and not the errand (HIL-828), so
      // the expired screen keeps the heading its code screen had: the person is
      // still confirming the same address.
      return flow.methodKey === MAGIC_LINK_METHOD_KEY
        ? 'check_inbox'
        : CODE_SCREENS[flow.intent]
    case 'second_factor':
      return 'two_step'
    case 'set_password':
      return flow.intent === 'register'
        ? 'set_first_password'
        : 'choose_password'
    case 'external':
      return flow.methodKey === MAGIC_LINK_METHOD_KEY
        ? 'check_inbox'
        : 'waiting_external'
    case 'done':
      return DONE_SCREENS[flow.intent]
  }
}

/**
 * Where a successful method send leaves the surface.
 *
 * The core decides this, not the backend (HIL-606): the answer to a send says
 * only that a letter went out, and which screen that earns is a property of the
 * METHOD. A magic link earns the code step, because its letter can also be
 * answered by hand; every other method has nothing for the person to do but wait
 * on the ceremony it started.
 *
 * @param methodKey The icon method whose send just succeeded.
 * @returns The step to move to.
 */
function stepAfterMethodSend(methodKey: string): AuthStep {
  return methodKey === MAGIC_LINK_METHOD_KEY ? 'code' : 'external'
}

/**
 * One icon method's ceremony while it runs: the controller a cancel aborts, and
 * what that cancel was — the moment and the intent it happened under, which is
 * all the machine needs to judge an outcome that lands after it.
 */
interface CeremonyRun {
  /** The method key that started it. */
  readonly key: string
  /** Aborted by a cancel and handed to the driver as its {@link AbortSignal}. */
  readonly controller: AbortController
  /** The cancel, once it happens; `null` while the ceremony is still wanted. */
  canceled: { readonly at: number; readonly intent: AuthIntent } | null
}

/**
 * Create the identifier-first auth flow machine over the project's method and
 * channel registries and delegated transport callbacks.
 *
 * @param options The registries, the detect/submit/method-action dispatches,
 *   the detection debounce and the late-outcome window.
 */
export function createAuthFlow(options: AuthFlowOptions): AuthFlow {
  const debounceMs = options.detectDebounceMs ?? DEFAULT_DETECT_DEBOUNCE_MS
  const cancelGraceMs =
    options.externalCancelGraceMs ?? DEFAULT_EXTERNAL_CANCEL_GRACE_MS
  const flow = createSignal<AuthFlowState>(INITIAL_FLOW)
  const form = createSignal<AuthFlowForm>(EMPTY_FORM)
  const detection = createSignal<DetectionState>(IDLE_DETECTION)
  const pending = createSignal(false)
  const error = createSignal<AuthFlowError | null>(null)
  const resendAvailableAt = createSignal<number | null>(null)
  const expiresAt = createSignal<number | null>(null)
  const submittable = computedSignal(() =>
    isFlowSubmittable(flow.get(), form.get(), detection.get()),
  )
  const icons = computedSignal(() =>
    visibleMethodIcons(
      options.methods,
      form.get().identifier,
      flow.get().identifierKind,
      flow.get().intent,
    ),
  )
  const channels = computedSignal(() =>
    applicableChannels(options.channels, flow.get().identifierKind),
  )
  const screenKey = computedSignal(() =>
    screenKeyOf(flow.get(), detection.get().result),
  )
  const primaryAction = computedSignal<AuthFlowPrimaryAction>(() => {
    const state = flow.get()
    switch (state.step) {
      case 'external':
        return null
      case 'code_expired':
        // Not a submit and not a method: the one control of this screen orders a
        // new code through {@link AuthFlow.renewCode}, which no primary action
        // names (HIL-828).
        return null
      case 'identifier': {
        const result = detection.get().result
        if (result === null) {
          return null
        }
        if (result.status === 'pending') {
          // Judged BEFORE the kind splits: a held number is held exactly as a
          // held address is, and its channel choice would send a SECOND code
          // for a reservation that already has one (HIL-651).
          return { kind: 'resume_code' }
        }
        if (result.status === 'proven') {
          // Judged in the same place and for the same reason (HIL-825): the
          // address is proved whatever kind it is, and the one thing left to do
          // with it is choose the password that creates the account.
          return { kind: 'resume_password' }
        }
        if (result.kind === 'phone') {
          // A phone never reveals a password; its channel choice IS the send —
          // and only when the account signs in or registration is open.
          if (result.status === 'none' && result.registerable.length === 0) {
            return null
          }
          const applicable = channels.get()
          const primary =
            applicable.find((channel) => channel.primary === true) ??
            applicable[0]

          return primary === undefined
            ? null
            : { kind: 'channel', key: primary.key }
        }
        if (result.status === 'active') {
          if (result.methods.includes(PASSWORD_METHOD_KEY)) {
            return { kind: 'submit' }
          }
          // A passwordless account promotes its first enabled passwordless
          // method (registry order) to the primary button.
          const method = options.methods.find(
            (descriptor) =>
              descriptor.kind === 'icon' &&
              result.methods.includes(descriptor.key),
          )

          return method === undefined
            ? null
            : { kind: 'method', key: method.key }
        }
        if (result.status === 'none' && result.registerable.length > 0) {
          if (result.registerable.includes(PASSWORD_METHOD_KEY)) {
            return { kind: 'submit' }
          }
          // A passwordless-only registration goes through its method's
          // ceremony — mirror of the active-account promotion above, kept in
          // step with isFlowSubmittable (which never enables submit here).
          const method = options.methods.find(
            (descriptor) =>
              descriptor.kind === 'icon' &&
              result.registerable.includes(descriptor.key),
          )

          return method === undefined
            ? null
            : { kind: 'method', key: method.key }
        }

        return null
      }
      default:
        return { kind: 'submit' }
    }
  })

  let debounceTimer: ReturnType<typeof setTimeout> | null = null
  // The lookup sequence orders replies: the echo guard alone cannot tell two
  // in-flight lookups of the SAME text apart (type, edit away, retype), and
  // reset() must orphan in-flight replies, not only the debounce timer.
  let detectSeq = 0
  // The dispatch generation orphans stale submit/ceremony outcomes: without it
  // a slow onSubmit resolution would merge its `next` into whatever flow exists
  // by then, and its pending-clear would release a NEWER dispatch's guard.
  let dispatchSeq = 0
  // The icon ceremony currently owning the external step, so a cancel can reach
  // INTO it (abort its signal) instead of only forgetting it.
  let ceremony: CeremonyRun | null = null
  // The one timer that turns a code screen into the expired one (HIL-828). It
  // lives here rather than in the three views for the reason every rule does:
  // Vue, React and Angular would each hold a copy of it, and their clocks tick
  // for the m:ss text alone.
  let expiryTimer: ReturnType<typeof setTimeout> | null = null

  function cancelDetect(): void {
    detectSeq += 1
    if (debounceTimer !== null) {
      clearTimeout(debounceTimer)
      debounceTimer = null
    }
  }

  /** Whether a lookup reply still matches the field — the ECHO race guard. */
  function isCurrentReply(identifier: string): boolean {
    return identifier === form.get().identifier
  }

  /**
   * Ask the lookup for an identifier the person just TYPED, after the debounce.
   *
   * @param identifier The field's current value.
   * @param kind Its classification.
   * @param moveOnPending Whether a `pending` reply may move to the code step.
   */
  function scheduleDetect(
    identifier: string,
    kind: IdentifierKind,
    moveOnPending: boolean,
  ): void {
    cancelDetect()
    // An empty or partial field rolls detection back to idle without spending a
    // lookup; a stale in-flight reply is dropped by the sequence+echo guards.
    if (!isIdentifierComplete(identifier, kind)) {
      detection.set(IDLE_DETECTION)

      return
    }
    const seq = detectSeq
    // The reveal stays on the reply it was drawn from until the new one lands:
    // taking it away for the flight is the flicker this leaf removes (HIL-646).
    detection.set(pendingDetection(detection.get().result))
    debounceTimer = setTimeout(() => {
      debounceTimer = null
      void runDetect(seq, identifier, kind, moveOnPending)
    }, debounceMs)
  }

  /**
   * Ask the lookup for the field's CURRENT identifier the moment the flow comes
   * BACK to the identifier step, with no debounce: the person is looking at a
   * screen drawn from an answer that predates whatever they did in between, and
   * a stale answer is exactly the defect (HIL-651). The reply may not move the
   * step — returning to the field must not bounce them out of it again.
   */
  function refreshDetect(): void {
    cancelDetect()
    const identifier = form.get().identifier
    const kind = classifyIdentifier(identifier)
    // Same roll-back to idle an empty or partial field gets from
    // scheduleDetect: there is nothing to ask about and nothing to reveal.
    if (!isIdentifierComplete(identifier, kind)) {
      detection.set(IDLE_DETECTION)

      return
    }
    const seq = detectSeq
    // No holding here (HIL-646): showing the verdict that predates leaving the
    // field is the very defect HIL-651 closed.
    detection.set(pendingDetection(null))
    void runDetect(seq, identifier, kind, false)
  }

  /**
   * Run one lookup and apply its reply, unless a newer request or another field
   * value orphaned it.
   *
   * @param seq The request's sequence number, checked against the newest.
   * @param identifier The identifier asked about.
   * @param kind Its classification.
   * @param moveOnPending Whether a `pending` reply may move the flow to the code
   *   step — true for a typed identifier, false for a return to the field.
   */
  async function runDetect(
    seq: number,
    identifier: string,
    kind: IdentifierKind,
    moveOnPending: boolean,
  ): Promise<void> {
    try {
      const result = await options.onDetect(identifier, kind)
      // Replies are matched by the request ECHO, not by `normalized` — a
      // backend-normalized phone must not orphan its own reply (the plan's
      // rule) — and ordered by the sequence, which alone can drop a stale
      // reply whose text matches the field again.
      if (seq !== detectSeq || !isCurrentReply(result.identifier)) {
        return
      }
      detection.set({ status: 'resolved', result })
      applyDetection(result, moveOnPending)
    } catch {
      if (seq === detectSeq) {
        // NO degraded state: an unanswered lookup reveals nothing and the
        // connection gate owns broken transport (rules-and-violations §A).
        detection.set(IDLE_DETECTION)
      }
    }
  }

  /**
   * Derive the intent from a resolved lookup: none → register (when
   * registration is open), pending → register parked on the code screen
   * WITHOUT re-sending (the reservation already sent one), active → login.
   *
   * What tells a return apart from an edit is the REQUEST, not any memory of the
   * address: a lookup asked because the field changed may park the flow on the
   * code step, a lookup asked because the flow came back to the field may not —
   * that would take the step away the person just chose (HIL-651). Either way
   * the reply lands in the detection signal, so the screen is drawn from it.
   *
   * @param result The resolved lookup.
   * @param moveOnPending Whether a `pending` reply may move to the code step.
   */
  function applyDetection(
    result: IdentifierDetection,
    moveOnPending: boolean,
  ): void {
    const state = flow.get()
    if (state.step !== 'identifier') {
      return
    }
    if (result.status === 'pending') {
      if (moveOnPending) {
        flow.set({ ...state, step: 'code', intent: 'register' })
      }

      return
    }
    if (result.status === 'proven') {
      // The same rule one line up, one step further along: a lookup asked
      // because the field changed carries the person to where their own
      // registration stands, and one asked because they walked BACK to the
      // field may not take that choice away again (HIL-825).
      if (moveOnPending) {
        flow.set({ ...state, step: 'set_password', intent: 'register' })
      }

      return
    }
    if (result.status === 'none' && result.registerable.length > 0) {
      flow.set({ ...state, intent: 'register' })

      return
    }
    if (state.intent !== 'login') {
      flow.set({ ...state, intent: 'login' })
    }
  }

  /**
   * Apply one outcome — the single place a dispatch's answer reaches the surface.
   *
   * A REFUSAL moves the flow too (HIL-672): the backend answers 'this did not
   * work AND here is where you should be' with `rejectTo()`, and dropping the
   * second half stranded the person on a screen whose only way out was editing
   * the identifier. The error is set BEFORE the merge so the sentence and the
   * screen it belongs to land in one paint. The two moments stay success-only:
   * a refusal sent no code, so arming a countdown off it would time a letter
   * nobody received — while countdowns ALREADY running are left alone, the
   * refusal having cancelled nothing.
   */
  function applyOutcome(outcome: AuthFlowSubmitOutcome): void {
    if (!outcome.ok) {
      error.set({
        message: outcome.message ?? null,
        code: outcome.code ?? null,
      })
    }
    if (outcome.next !== undefined) {
      flow.set({ ...flow.get(), ...outcome.next })
    }
    if (!outcome.ok) {
      return
    }
    if (outcome.resendAt !== undefined) {
      resendAvailableAt.set(toLocal(outcome.resendAt))
    }
    if (outcome.expiresAt !== undefined) {
      const moment = toLocal(outcome.expiresAt)
      expiresAt.set(moment)
      armExpiry(moment)
    }
  }

  /**
   * Judge a ceremony outcome that landed AFTER the user canceled it. Only a
   * LOGIN success inside the grace window is still applied: it merely raises a
   * session, which can be left. A REGISTER success is dropped unconditionally —
   * it would create the account the person refused — and a late FAILURE is
   * dropped under either intent, being an error about an abandoned operation.
   *
   * @param run The ceremony the outcome belongs to.
   * @param outcome What it resolved to.
   */
  function applyLateOutcome(
    run: CeremonyRun,
    outcome: AuthFlowSubmitOutcome,
  ): void {
    const canceled = run.canceled
    // Superseded rather than canceled (a reset, an identifier edit, a newer
    // ceremony): nothing is late here, the outcome is simply orphaned.
    if (canceled === null || ceremony !== run) {
      return
    }
    if (!outcome.ok || canceled.intent !== 'login') {
      return
    }
    if (Date.now() - canceled.at > cancelGraceMs) {
      return
    }
    applyOutcome(outcome)
  }

  /**
   * Run one guarded dispatch: clear the error, hold pending, apply the outcome.
   * A dispatch orphaned by a newer generation (an identifier edit, a reset, a
   * canceled ceremony) applies nothing and releases nothing.
   *
   * The applied outcome is returned for the caller that has a move of its own to
   * make on top of it (consent parking a started ceremony); `undefined` says the
   * dispatch was orphaned, so that move must not happen either.
   */
  async function dispatch(
    run: () => Promise<AuthFlowSubmitOutcome>,
  ): Promise<AuthFlowSubmitOutcome | undefined> {
    error.set(null)
    // Every dispatch is a new ask, and the line on screen is about the old one
    // (HIL-826). Cleared HERE rather than in each caller so a submit, a resend
    // and a channel pick cannot drift apart on it; the server's own `queued`
    // lands a tick later and says the same thing with authority.
    flow.set({ ...flow.get(), sendProgress: null })
    pending.set(true)
    const seq = ++dispatchSeq
    try {
      const outcome = await run()
      if (seq !== dispatchSeq) {
        return undefined
      }
      applyOutcome(outcome)

      return outcome
    } finally {
      if (seq === dispatchSeq) {
        pending.set(false)
      }
    }
  }

  /**
   * Send a phone code with the code screen ALREADY open (HIL-826, Design D7).
   *
   * The screen used to wait for the transport to report success before opening,
   * and that was compensation for having no progress line: "enter the code we
   * sent via Telegram" was a promise the transport had not made. With the line
   * the screen promises nothing - it says queued, then sending - so it opens the
   * moment the send is ordered, exactly as the email path always has. One line,
   * one screen, one behaviour for every channel.
   *
   * What is paid for it: a person can see the code field and be taken back a
   * second later, which is what this rollback is. A channel that cannot be
   * reached returns to the step the send was ordered from - the identifier field
   * for a sign-in, the terms screen for a registration - and the surface dims
   * that channel, exactly as it did when the screen had not opened yet. A server
   * that NAMES where to go is obeyed instead: its answer is later than ours.
   *
   * @param from The step to return to when the send is refused.
   */
  async function sendPhoneCodeFrom(from: AuthStep): Promise<void> {
    // The SENDING state is what the wire is given, and the moved one is what the
    // person sees. They part on purpose: which action a submit becomes is keyed
    // by the step it was ordered from, and handing the wire a state that has
    // already hopped forward would turn a send into a confirm of an empty code.
    const sending = flow.get()
    flow.set({ ...sending, step: 'code' })
    const outcome = await dispatch(() =>
      options.onSubmit('submit', sending, form.get()),
    )
    if (outcome === undefined || outcome.ok || outcome.next !== undefined) {
      return
    }

    flow.set({ ...flow.get(), step: from })
  }

  /** Whether the backend-armed cooldown still blocks sending a code. */
  function isResendBlocked(): boolean {
    const availableAt = resendAvailableAt.get()

    return availableAt !== null && Date.now() < availableAt
  }

  /** Drop any standing expiry timer — the code it was counting is gone. */
  function disarmExpiry(): void {
    if (expiryTimer !== null) {
      clearTimeout(expiryTimer)
      expiryTimer = null
    }
  }

  /**
   * (Re)arm the flip for a code's deadline, replacing whatever stood before.
   *
   * A moment already in the past is not a special case: `setTimeout` treats a
   * non-positive delay as "as soon as possible", which is exactly right for a
   * tab restored onto a code that died while it was closed.
   *
   * @param moment The LOCAL epoch-ms deadline, or `null` to arm nothing.
   */
  function armExpiry(moment: number | null): void {
    disarmExpiry()
    if (moment === null) {
      return
    }
    expiryTimer = setTimeout(expireCode, moment - Date.now())
  }

  /**
   * Turn the code screen into the expired one (HIL-828).
   *
   * The typed code goes with it — a code typed for a challenge that is over must
   * not reappear in the next one — and so does the inline error, which was about
   * that same dead challenge. The cooldown and the deadline are left alone: the
   * send gate belongs to the ADDRESS and keeps running across the flip, and the
   * deadline is what the screen is standing on. Nothing is dispatched; expiry is
   * not news the backend needs.
   */
  function expireCode(): void {
    expiryTimer = null
    if (flow.get().step !== 'code') {
      // A flow that moved on does not own this timer. It fires anyway on the
      // paths that leave the code step without clearing the moment (an accepted
      // code opens the password screen on the SAME deadline), and there it must
      // do nothing at all.
      return
    }
    form.set({ ...form.get(), code: '' })
    error.set(null)
    flow.set({ ...flow.get(), step: 'code_expired' })
  }

  return {
    flow,
    form,
    detection,
    pending,
    error,
    submittable,
    icons,
    channels,
    primaryAction,
    screenKey,
    resendAvailableAt,
    expiresAt,
    resume(pending: PendingAuthStep | null): void {
      if (pending === null) {
        return
      }
      flow.set({
        ...flow.get(),
        step: pending.step,
        intent: pending.intent,
        methodKey: null,
        identifierKind: pending.kind,
        channelKey: pending.channel,
      })
      form.set({ ...form.get(), identifier: pending.identifier })
      expiresAt.set(pending.expiresAt)
      // A code that died while the tab was closed flips at once (HIL-828), which
      // is the whole of what a past moment means here.
      armExpiry(pending.expiresAt)
    },
    setField<F extends AuthFlowField>(field: F, value: AuthFlowForm[F]): void {
      if (field === 'identifier') {
        const identifier = value as string
        if (identifier === form.get().identifier) {
          // A same-value re-emit is not an edit — nothing resets — but it does
          // retry a lookup that failed (idle after a rejection): without this
          // the surface would stay dead until the text actually changed.
          if (detection.get().status === 'idle') {
            scheduleDetect(identifier, classifyIdentifier(identifier), true)
          }

          return
        }
        // Editing the identifier itself restarts the flow, clears the rest of
        // the form (input-preservation rule: ONLY an identifier change clears —
        // stepping forward never does), and orphans any in-flight dispatch (its
        // outcome belongs to the abandoned identifier); then (re)schedule the
        // lookup.
        const kind = classifyIdentifier(identifier)
        dispatchSeq += 1
        pending.set(false)
        flow.set({ ...INITIAL_FLOW, identifierKind: kind })
        form.set({ ...EMPTY_FORM, identifier })
        error.set(null)
        resendAvailableAt.set(null)
        expiresAt.set(null)
        disarmExpiry()
        scheduleDetect(identifier, kind, true)

        return
      }
      form.set({ ...form.get(), [field]: value })
    },
    async submit(): Promise<void> {
      if (pending.get()) {
        return
      }
      const state = flow.get()
      if (state.step === 'identifier' && state.intent === 'register') {
        // Registration does NOT create an account from the identifier step —
        // it moves locally to the terms screen; the real dispatch is consent's.
        error.set(null)
        flow.set({ ...state, step: 'consent' })

        return
      }
      const methodKey = state.methodKey
      if (state.step === 'consent' && methodKey !== null) {
        // A method chosen BEFORE the terms starts here, not where it was picked
        // (HIL-417): accepting the terms is what sends the magic link. Parking in
        // `external` is the same move `chooseMethod` makes for a login, so the
        // check_inbox screen is derived the one way; a refusal that names
        // nowhere else leaves the person on the terms screen with the reason,
        // nothing having been sent.
        //
        // It is a ceremony like the one a login starts, so it is registered as
        // one (HIL-418): a reset has to END what it started, not merely orphan
        // the outcome, and a register outcome landing after that is dropped by
        // dispatch's generation guard — the same verdict {@link applyLateOutcome}
        // reaches for a register.
        const run: CeremonyRun = {
          key: methodKey,
          controller: new AbortController(),
          canceled: null,
        }
        ceremony = run
        try {
          const outcome = await dispatch(() =>
            options.onMethodAction(
              methodKey,
              form.get(),
              run.controller.signal,
            ),
          )
          if (outcome !== undefined && outcome.ok) {
            // A foreign challenge's code must not make this screen submittable
            // the moment it appears, which is what `startRecovery` clears it for.
            form.set({ ...form.get(), code: '' })
            flow.set({
              ...flow.get(),
              step: stepAfterMethodSend(methodKey),
            })
          }
        } finally {
          if (ceremony === run) {
            ceremony = null
          }
        }

        return
      }
      if (state.step === 'consent' && state.identifierKind === 'phone') {
        // A registration by phone sends from the terms screen, so the code
        // screen opens from HERE the same way it does for a sign-in - and a
        // refusal comes back to the terms, not to the field (HIL-826).
        await sendPhoneCodeFrom('consent')

        return
      }
      await dispatch(() => options.onSubmit('submit', flow.get(), form.get()))
    },
    async resend(): Promise<void> {
      if (pending.get() || isResendBlocked()) {
        return
      }
      await dispatch(() => options.onSubmit('resend', flow.get(), form.get()))
    },
    async renewCode(): Promise<void> {
      if (pending.get() || isResendBlocked()) {
        return
      }
      // The action name stays `resend` and no new wire string appears: the STEP
      // is what selects the branch, and from `code_expired` that branch is the
      // flow's FIRST send rather than a re-send into a hold that is gone.
      const outcome = await dispatch(() =>
        options.onSubmit('resend', flow.get(), form.get()),
      )
      if (outcome === undefined || !outcome.ok || outcome.next !== undefined) {
        // Orphaned, refused, or already told where to go. A refusal that names
        // nowhere - the send cap - deliberately leaves the person here, with the
        // sentence and the button under the gate.
        return
      }
      if (flow.get().step !== 'code_expired') {
        return
      }
      // A send that named no step is not silence: a magic link and a recovery
      // both answer `sent()` with a life and a gate and nothing else, because
      // where their letter lands has always been the CORE's to decide (HIL-606)
      // - the method picks the screen on a first send, and this is that same
      // send again. A registration and a phone code name the step themselves and
      // were obeyed above.
      flow.set({ ...flow.get(), step: 'code' })
    },
    async chooseMethod(key: string): Promise<void> {
      if (pending.get()) {
        return
      }
      const descriptor = options.methods.find((method) => method.key === key)
      if (descriptor === undefined || descriptor.kind !== 'icon') {
        return
      }
      error.set(null)
      const chosenFrom = flow.get()
      if (chosenFrom.intent === 'register') {
        // Registration dispatches NOTHING before the terms screen submits — the
        // method choice is stored and the flow hops to consent locally, exactly
        // as a channel choice does (HIL-417); consent's submit is the send. An
        // account cannot be made by a click that never showed the terms.
        flow.set({ ...chosenFrom, step: 'consent', methodKey: key })

        return
      }
      // Park in `external` while the ceremony runs; on a failure that names
      // nowhere else fall back to the identifier field so the user can retry
      // another way. A superseded ceremony's late outcome is ignored (the
      // generation and park guards); a CANCELED one's is judged by intent
      // ({@link applyLateOutcome}).
      flow.set({ ...chosenFrom, step: 'external', methodKey: key })
      pending.set(true)
      const seq = ++dispatchSeq
      const run: CeremonyRun = {
        key,
        controller: new AbortController(),
        canceled: null,
      }
      ceremony = run
      try {
        const outcome = await options.onMethodAction(
          key,
          form.get(),
          run.controller.signal,
        )
        if (seq !== dispatchSeq) {
          applyLateOutcome(run, outcome)

          return
        }
        const state = flow.get()
        if (state.step !== 'external' || state.methodKey !== key) {
          return
        }
        // One outcome application for ceremonies and submits alike — a
        // hand-rolled copy here already diverged once (it dropped the
        // resendAt a magic-link send replies with).
        applyOutcome(outcome)
        if (!outcome.ok) {
          // The fall-back is exactly that (HIL-672): a refusal that NAMED a step
          // has already been applied above and wins, and only one that named
          // nothing (`refuse()`, e.g. send_cap_reached) leaves the person here
          // with nowhere to be but the field. The state is re-read rather than
          // taken from the snapshot above, which predates the merge and would
          // roll the intent back along with the step.
          if (outcome.next === undefined) {
            flow.set({ ...flow.get(), step: 'identifier', methodKey: null })
          }

          return
        }
        // The park in `external` held the flight, where a cancel can still end
        // the ceremony; the send having landed, the method says where the person
        // goes next. The code field is cleared first, for the same reason
        // `startRecovery` clears it: a code left over from another challenge
        // would make the screen submittable before anything was typed.
        form.set({ ...form.get(), code: '' })
        flow.set({ ...flow.get(), step: stepAfterMethodSend(key) })
      } finally {
        if (ceremony === run) {
          ceremony = null
        }
        if (seq === dispatchSeq) {
          pending.set(false)
        }
      }
    },
    async chooseChannel(key: string): Promise<void> {
      if (pending.get() || isResendBlocked()) {
        return
      }
      const channel = channels
        .get()
        .find((descriptor) => descriptor.key === key)
      if (channel === undefined) {
        return
      }
      const state = flow.get()
      if (state.step === 'identifier' && state.intent === 'register') {
        // Registration dispatches NOTHING before the terms screen submits —
        // the channel choice is stored and the flow hops to consent locally;
        // consent's submit is the send (channelKey rides in the state).
        error.set(null)
        flow.set({ ...state, channelKey: key, step: 'consent' })

        return
      }
      // Choosing the channel IS sending the code: the key goes into the state
      // first (the code screen names it), then the send dispatches and the
      // backend replies with the resend gate. The screen itself opens now rather
      // than on the transport's word (HIL-826) - see sendPhoneCodeFrom().
      flow.set({ ...state, channelKey: key })
      await sendPhoneCodeFrom(state.step)
    },
    startRecovery(): void {
      error.set(null)
      resendAvailableAt.set(null)
      expiresAt.set(null)
      disarmExpiry()
      // The recovery challenge starts clean: a code or new password lingering
      // from another challenge must not pre-fill it as already-submittable
      // (input preservation protects the identifier and password, not a
      // foreign challenge's fields).
      form.set({ ...form.get(), code: '', newPassword: '' })
      flow.set({
        ...flow.get(),
        intent: 'recovery',
        step: 'code',
        methodKey: null,
        channelKey: null,
        sendProgress: null,
      })
    },
    backToIdentifier(): void {
      error.set(null)
      resendAvailableAt.set(null)
      expiresAt.set(null)
      disarmExpiry()
      // Back to the single field with everything typed preserved — the form is
      // NOT cleared (only an identifier edit clears it).
      flow.set({
        ...flow.get(),
        step: 'identifier',
        methodKey: null,
        channelKey: null,
        sendProgress: null,
      })
      refreshDetect()
    },
    resumeHeldRegistration(): void {
      flow.set({ ...flow.get(), step: 'code', intent: 'register' })
    },
    resumeProvenRegistration(): void {
      flow.set({ ...flow.get(), step: 'set_password', intent: 'register' })
    },
    reportSendProgress(progress: CodeSendProgress | null): void {
      flow.set({ ...flow.get(), sendProgress: progress })
    },
    cancelMethod(): void {
      const state = flow.get()
      // A cancel reaches every screen that WAITS on a ceremony, not just the
      // external park: a registration sends from the terms screen (HIL-417), and
      // a send nobody can take back would leave the rule "a canceled REGISTER
      // applies no late success" (HIL-418) without a single path to reach it. An
      // `external` step with no ceremony left (a reload, a converge landing)
      // still returns to the field, exactly as it did when the step alone
      // decided; a ceremony merely ORPHANED elsewhere (an identifier edit) stays
      // orphaned rather than becoming a cancel whose late success would apply.
      const sending = state.step === 'consent' && ceremony !== null
      if (state.step !== 'external' && !sending) {
        return
      }
      // Abort FIRST: cancelling has to end the ceremony itself, so the device
      // dialog closes and a late finger cannot sign anything. Orphaning the
      // outcome alone (what this used to do) left the OS prompt up.
      if (ceremony !== null) {
        ceremony.controller.abort()
        ceremony.canceled = { at: Date.now(), intent: state.intent }
      }
      // The abandoned ceremony may never settle (a closed OAuth popup), so the
      // cancel itself releases pending and orphans the ceremony's outcome —
      // otherwise every control would stay dead behind the pending guard.
      dispatchSeq += 1
      pending.set(false)
      error.set(null)
      flow.set({
        ...state,
        step: 'identifier',
        methodKey: null,
        sendProgress: null,
      })
      refreshDetect()
    },
    applyExternal(next: Partial<AuthFlowState>): void {
      // The converge entry: an external transition lands with the same merge as
      // a backend `next` — clears the shown error, never touches the form, and
      // any step must survive being rebuilt under the user's hands.
      error.set(null)
      flow.set({ ...flow.get(), ...next })
    },
    reset(): void {
      cancelDetect()
      // A (re)mount forgets everything, so a ceremony still running under the old
      // surface is ENDED, not merely orphaned: leaving the controller alone would
      // keep the device dialog up, and leaving `ceremony` set would let a canceled
      // ceremony's late success land on the flow this call just emptied.
      ceremony?.controller.abort()
      ceremony = null
      dispatchSeq += 1
      flow.set(INITIAL_FLOW)
      form.set(EMPTY_FORM)
      detection.set(IDLE_DETECTION)
      pending.set(false)
      error.set(null)
      resendAvailableAt.set(null)
      expiresAt.set(null)
      disarmExpiry()
    },
  }
}
