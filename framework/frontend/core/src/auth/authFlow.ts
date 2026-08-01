// The identifier-first auth flow state machine (HIL-413): the framework-agnostic
// "one identifier field, live account lookup, then the right next step" controller
// behind the redesigned sign-in surface (HIL-412). It supersedes the mode-first
// {@link ./authSurface} controller — instead of a login/register/recovery switcher,
// the user types a SINGLE identifier (email or phone), the machine looks the
// account up live, and the step that follows (enter a password, enter a code, set a
// new password, or hand off to an external method) is a function of two axes —
// {@link AuthStep} × {@link AuthIntent} — plus the chosen method, NEVER a branch on
// mode names (hleb's defect: flow logic scattered across a store's mutations). It
// lives in @hilos/core so the Vue default surface (HIL-423) and the React/Angular
// demos (424/425) bind the same one small machine.
//
// This leaf ships the pure core only: no DOM, no wire. Three seams are delegated to
// the project, mirroring authSurface's `onSubmit`: `onDetect` looks an identifier up
// (HIL-414), `onSubmit` dispatches the active step's form, and `onMethodAction` runs
// an icon method's ceremony (HIL-418/419). The machine owns identifier
// classification, the debounced last-reply-wins detection, the derived icon
// visibility matrix, and the atomic step transitions.
//
// Method descriptors are pure serializable DATA (no closures): HIL-427 phase 3 ships
// the enabled set from backend settings over the wire, so a closure could never
// arrive — behaviour is keyed by `key` and registered separately (onMethodAction),
// never carried on the descriptor. Change detection is `Object.is` (signal.ts):
// replace objects on update, never mutate them in place.
//
// Not exported from the barrel (src/index.ts): the clean names collide with the
// still-present authSurface until HIL-423 removes it and re-exports this module.

import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
} from '../state/signal.js'

/**
 * What an identifier looks like. Drives which icon methods apply (an email-only
 * magic link never shows for a phone) and which detection endpoint is meaningful.
 * `unknown` is an empty or unrecognised field — no lookup fires for it.
 */
export type IdentifierKind = 'email' | 'phone' | 'unknown'

/**
 * The active step of the flow — one axis of the state. `identifier` is the single
 * entry field; `credential` collects a password; `code` collects a one-time code
 * (recovery, or passwordless register); `set_password` sets a new password
 * (register, or a recovery reset); `external` parks while an icon method's ceremony
 * runs (HIL-418/419); `done` is terminal for a flow that doesn't finish by a
 * session upgrade. Reveal and the icon matrix are functions of this axis, not
 * branches on named modes.
 */
export type AuthStep =
  | 'identifier'
  | 'credential'
  | 'code'
  | 'set_password'
  | 'external'
  | 'done'

/**
 * The other axis of the state: what the flow is trying to do. `login` and
 * `recovery` act on an existing account; `register` creates one. The backend
 * confirms the intent on submit — e.g. an unknown phone resolves to `register`
 * (passwordless SMS code, contract only in this leaf; the branch's backend is a
 * later leaf).
 */
export type AuthIntent = 'login' | 'register' | 'recovery'

/**
 * The whole flow state as ONE object, so a transition is atomic (a view never
 * observes a half-applied step/intent pair). `methodKey` is the icon method a
 * ceremony is running for, or `null` on the primary identifier path.
 */
export interface AuthFlowState {
  /** The active step. */
  readonly step: AuthStep
  /** What the flow is trying to do. */
  readonly intent: AuthIntent
  /** The icon method being handed off to, or `null` on the identifier path. */
  readonly methodKey: string | null
  /** The classification of the current identifier field. */
  readonly identifierKind: IdentifierKind
}

/**
 * The flow's form fields. A single flat shape; each step reads only what it needs.
 * There is ONE `identifier` field (email or phone) rather than separate email and
 * phone inputs — that unification is the point of the redesign. Replace the object
 * to update, never mutate in place (the signal is shallow, signal.ts).
 */
export interface AuthFlowForm {
  /** The single identifier — an email or a phone number. */
  readonly identifier: string
  /** Password — the `credential` (login) step. */
  readonly password: string
  /** Password confirmation — the `set_password` (register) step. */
  readonly confirmPassword: string
  /** One-time code — the `code` (recovery / passwordless register) step. */
  readonly code: string
  /** New password — the `set_password` (register / recovery reset) step. */
  readonly newPassword: string
}

/** A form field name, for the view's per-field update calls. */
export type AuthFlowField = keyof AuthFlowForm

/** Where an icon method renders relative to the identifier/password inputs. */
export type AuthMethodPlacement = 'icon_row' | 'password_adjacent'

/**
 * When an icon method is visible against the state of the identifier field. An
 * icon shows when the field is empty and `whenEmpty`, or non-empty and `whenTyping`
 * (and, if `identifierKinds` is set, the typed kind is in that list). This is pure
 * data so it can round-trip from HIL-427 backend settings.
 */
export interface AuthMethodVisibility {
  /** Show while the identifier field is empty (e.g. a discoverable passkey). */
  readonly whenEmpty?: boolean
  /** Show while the user is typing an identifier (e.g. OAuth, a magic link). */
  readonly whenTyping?: boolean
  /** When typing, restrict to these identifier kinds (e.g. a magic link is email-only). */
  readonly identifierKinds?: readonly IdentifierKind[]
}

/**
 * One enabled auth method as pure serializable DATA — the new descriptor contract
 * that replaces authSurface's `AuthMethodDescriptor` and gates compatibility with
 * HIL-427 (backend-declared method sets). No behaviour lives here: an icon method's
 * ceremony is registered by `key` via {@link AuthFlowOptions.onMethodAction}.
 */
export interface AuthFlowMethodDescriptor {
  /** Stable method key, e.g. `password`, `oauth:github`, `passkey`. */
  readonly key: string
  /** Human-facing label. */
  readonly label: string
  /**
   * `identifier` takes the shared identifier field (the password path); `icon` is a
   * button (OAuth, passkey, magic link) rendered per {@link placement} and gated by
   * {@link visibility}.
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
 * The result of looking an identifier up — the contract with HIL-414. It carries
 * the account's available method keys, NOT just `exists`: a passwordless account
 * (HIL-417) must land on a "sign in by link" hint rather than a password field, and
 * the view can only decide that from `methods`.
 */
export interface IdentifierDetection {
  /** The identifier as normalized by the backend (e.g. E.164 phone). */
  readonly identifier: string
  /** How the backend classified it. */
  readonly kind: IdentifierKind
  /** Whether an account already exists for it. */
  readonly exists: boolean
  /** The method keys available to this identifier (drives the credential-step hint). */
  readonly methods: readonly string[]
}

/** The lifecycle of the live identifier lookup. */
export type DetectionStatus = 'idle' | 'pending' | 'resolved' | 'unavailable'

/**
 * The detection signal. `idle` before/without a lookup; `pending` while one is in
 * flight; `resolved` carries the {@link IdentifierDetection}; `unavailable` is the
 * degraded state (offline, or a rate-limit rejection from HIL-420) in which the flow
 * does NOT block — the view falls back to password + explicit login/register.
 */
export interface DetectionState {
  /** The lookup lifecycle status. */
  readonly status: DetectionStatus
  /** The resolved lookup, or `null` in every non-`resolved` status. */
  readonly result: IdentifierDetection | null
}

/**
 * The outcome a delegated dispatch ({@link AuthFlowOptions.onSubmit} or
 * `onMethodAction`) reports back. On failure `message` is surfaced inline (auth
 * deliberately shows the backend reason). On success `next` is a PARTIAL flow state
 * merged over the current one — the backend decides where the flow goes (existing
 * account → `credential`, new email → `set_password`, …); omit `next` when a session
 * upgrade closes the surface (login/register success).
 */
export interface AuthFlowSubmitOutcome {
  /** Whether the dispatch succeeded. */
  readonly ok: boolean
  /** The inline error message to show on failure. */
  readonly message?: string
  /** A partial next flow state to merge on success; omit to stay put. */
  readonly next?: Partial<AuthFlowState>
}

/** Wiring for {@link createAuthFlow}. */
export interface AuthFlowOptions {
  /** The project's ordered enabled methods; drives the identifier field and icons. */
  methods: readonly AuthFlowMethodDescriptor[]
  /**
   * Look an identifier up over the project's transport (HIL-414). Called debounced,
   * only for a recognised, complete email/phone. Reject to signal the lookup is
   * unavailable — the machine degrades rather than blocks.
   *
   * @param identifier The current identifier value.
   * @param kind Its classification.
   */
  onDetect: (
    identifier: string,
    kind: IdentifierKind,
  ) => Promise<IdentifierDetection>
  /**
   * Dispatch the active step's form over the project's transport, resolving the
   * outcome. The machine guards re-entry and owns pending/error; this dispatches and
   * maps the reply (including the next step via {@link AuthFlowSubmitOutcome.next}).
   *
   * @param flow The current flow state (step/intent tell the dispatch what to do).
   * @param form The current form values.
   */
  onSubmit: (
    flow: AuthFlowState,
    form: AuthFlowForm,
  ) => Promise<AuthFlowSubmitOutcome>
  /**
   * Run an icon method's registered behaviour — its OAuth redirect or WebAuthn
   * ceremony (HIL-418/419). The flow parks in `external` while it runs.
   *
   * @param key The chosen method key.
   * @param form The current form values (e.g. a typed identifier a ceremony reuses).
   */
  onMethodAction: (
    key: string,
    form: AuthFlowForm,
  ) => Promise<AuthFlowSubmitOutcome>
  /** Detection debounce in ms; defaults to {@link DEFAULT_DETECT_DEBOUNCE_MS}. */
  detectDebounceMs?: number
}

/** The reactive identifier-first flow a view binds and drives. */
export interface AuthFlow {
  /** The whole flow state (step/intent/methodKey/identifierKind), atomic. */
  readonly flow: ReadonlySignal<AuthFlowState>
  /** The current form values. */
  readonly form: ReadonlySignal<AuthFlowForm>
  /** The live identifier lookup state. */
  readonly detection: ReadonlySignal<DetectionState>
  /** Whether a submit or ceremony is in flight (disables the submit control). */
  readonly pending: ReadonlySignal<boolean>
  /** The active step's inline error, or null when clear. */
  readonly error: ReadonlySignal<string | null>
  /** Whether the active step's form is complete enough to submit. */
  readonly submittable: ReadonlySignal<boolean>
  /** The icon methods currently visible, given the identifier field and its kind. */
  readonly icons: ReadonlySignal<readonly AuthFlowMethodDescriptor[]>
  /**
   * Update one form field. Editing `identifier` resets the flow to the top, clears
   * the secrets, and (re)schedules the live lookup.
   *
   * @param field The field to set.
   * @param value The new value.
   */
  setField(field: AuthFlowField, value: string): void
  /** Submit the active step; a no-op while already pending. */
  submit(): Promise<void>
  /**
   * Hand off to an icon method's ceremony; parks the flow in `external`. A no-op for
   * an unknown key, a non-icon method, or while already pending.
   *
   * @param key The chosen icon method key.
   */
  chooseMethod(key: string): Promise<void>
  /**
   * Enter the recovery flow ("forgot password?"). The view gates this control on
   * `detection.result.exists && methods.includes('password')` (HIL-417). Requesting
   * the emailed code is the view's transport concern; the machine holds the step.
   */
  startRecovery(): void
  /** Return to the single identifier field, keeping the identifier and its lookup. */
  back(): void
  /** Reset to the initial identifier step with an empty form — call on (re)mount. */
  reset(): void
}

/** Minimum password length; the mirror of HIL-164's server rule (length-only). */
export const PASSWORD_MIN_LENGTH = 8

/** Default detection debounce: quiet enough to skip mid-word lookups. */
export const DEFAULT_DETECT_DEBOUNCE_MS = 300

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

/** The default generic failure message when a dispatch reports none. */
const DEFAULT_ERROR = 'The request could not be completed. Please try again.'

/** The starting flow state — the single identifier field, no intent decided yet. */
const INITIAL_FLOW: AuthFlowState = {
  step: 'identifier',
  intent: 'login',
  methodKey: null,
  identifierKind: 'unknown',
}

/** An empty form — the starting and identifier-change reset value. */
const EMPTY_FORM: AuthFlowForm = {
  identifier: '',
  password: '',
  confirmPassword: '',
  code: '',
  newPassword: '',
}

/** The detection signal before or without a lookup. */
const IDLE_DETECTION: DetectionState = { status: 'idle', result: null }

/** The detection signal while a lookup is in flight. */
const PENDING_DETECTION: DetectionState = { status: 'pending', result: null }

/** The degraded detection signal (lookup unavailable). */
const UNAVAILABLE_DETECTION: DetectionState = {
  status: 'unavailable',
  result: null,
}

/**
 * The default identifier (email/phone + password) method — the shared identifier
 * field. A project lists it first; icon methods are added around it.
 */
export const PASSWORD_FLOW_METHOD: AuthFlowMethodDescriptor = {
  key: 'password',
  label: 'Email or phone',
  kind: 'identifier',
}

/**
 * The GitHub OAuth icon method — an icon shown both before and while typing (an
 * external provider is offered regardless of what's in the field), login-only.
 */
export const OAUTH_GITHUB_FLOW_METHOD: AuthFlowMethodDescriptor = {
  key: 'oauth:github',
  label: 'Continue with GitHub',
  kind: 'icon',
  intents: ['login'],
  visibility: { whenEmpty: true, whenTyping: true },
  placement: 'icon_row',
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
 * The email magic-link icon method — shown only while typing an EMAIL, rendered next
 * to the password so it reads as a passwordless alternative for that account.
 */
export const MAGIC_LINK_FLOW_METHOD: AuthFlowMethodDescriptor = {
  key: 'magic_link',
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
 * Classify an identifier as email, phone, or unknown — a pure core function so the
 * views never re-implement it. An `@` reads as an email; an otherwise all-digit
 * value (with cosmetic separators) reads as a phone; anything else is unknown. This
 * is lenient (it fires while typing, e.g. `a@` is already `email`) — the stricter
 * completeness gate for firing a lookup is separate.
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
 * Whether an identifier is complete enough to spend a lookup on: a full email, or a
 * phone whose digit count is within the backend's bounds. A recognised-but-partial
 * value (`a@`, a three-digit number) is not.
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
 * Whether an active step's form is complete enough to submit. Client-side gating
 * for the button state only — the backend stays the source of truth. `external` and
 * `done` are never submittable (the ceremony / terminal states have no form).
 *
 * @param flow The current flow state.
 * @param form The current form values.
 */
export function isFlowSubmittable(
  flow: AuthFlowState,
  form: AuthFlowForm,
): boolean {
  switch (flow.step) {
    case 'identifier':
      return form.identifier.trim() !== ''
    case 'credential':
      return form.password !== ''
    case 'code':
      return form.code.trim() !== ''
    case 'set_password':
      return (
        form.newPassword.length >= PASSWORD_MIN_LENGTH &&
        form.confirmPassword === form.newPassword
      )
    case 'external':
    case 'done':
      return false
  }
}

/**
 * Whether one icon method is visible against the identifier field's state. Absent
 * visibility means always visible; otherwise it shows when empty and `whenEmpty`, or
 * when typing and `whenTyping` (and, when `identifierKinds` is set, only for a kind
 * in that list). The derived signal the view renders — the applicability matrix.
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
 * The icon methods visible given the identifier field and its kind, in registry
 * order — a pure derivation the machine exposes as a signal and the view only
 * renders (splitting by {@link AuthFlowMethodDescriptor.placement}).
 *
 * @param methods The project's ordered method descriptors.
 * @param identifier The current identifier field value.
 * @param kind The current identifier classification.
 */
export function visibleMethodIcons(
  methods: readonly AuthFlowMethodDescriptor[],
  identifier: string,
  kind: IdentifierKind,
): readonly AuthFlowMethodDescriptor[] {
  const empty = identifier.trim() === ''

  return methods.filter(
    (method) => method.kind === 'icon' && isIconVisible(method, empty, kind),
  )
}

/**
 * Create the identifier-first auth flow machine over the project's method registry
 * and delegated transport callbacks.
 *
 * @param options The method registry, the detect/submit/method-action dispatches,
 *   and the detection debounce.
 */
export function createAuthFlow(options: AuthFlowOptions): AuthFlow {
  const debounceMs = options.detectDebounceMs ?? DEFAULT_DETECT_DEBOUNCE_MS
  const flow = createSignal<AuthFlowState>(INITIAL_FLOW)
  const form = createSignal<AuthFlowForm>(EMPTY_FORM)
  const detection = createSignal<DetectionState>(IDLE_DETECTION)
  const pending = createSignal(false)
  const error = createSignal<string | null>(null)
  const submittable = computedSignal(() =>
    isFlowSubmittable(flow.get(), form.get()),
  )
  const icons = computedSignal(() =>
    visibleMethodIcons(
      options.methods,
      form.get().identifier,
      flow.get().identifierKind,
    ),
  )

  // Detection race guard: every new identifier value bumps the sequence, and a
  // reply is applied only when its sequence is still current — last reply wins.
  let detectSeq = 0
  let debounceTimer: ReturnType<typeof setTimeout> | null = null

  function cancelDetect(): void {
    if (debounceTimer !== null) {
      clearTimeout(debounceTimer)
      debounceTimer = null
    }
  }

  function scheduleDetect(identifier: string, kind: IdentifierKind): void {
    cancelDetect()
    // Any new value invalidates an in-flight reply, and an empty or partial field
    // rolls detection back to idle without spending a lookup.
    detectSeq += 1
    if (!isIdentifierComplete(identifier, kind)) {
      detection.set(IDLE_DETECTION)

      return
    }
    const seq = detectSeq
    detection.set(PENDING_DETECTION)
    debounceTimer = setTimeout(() => {
      debounceTimer = null
      void runDetect(seq, identifier, kind)
    }, debounceMs)
  }

  async function runDetect(
    seq: number,
    identifier: string,
    kind: IdentifierKind,
  ): Promise<void> {
    try {
      const result = await options.onDetect(identifier, kind)
      if (seq !== detectSeq) {
        return
      }
      detection.set({ status: 'resolved', result })
    } catch {
      if (seq !== detectSeq) {
        return
      }
      detection.set(UNAVAILABLE_DETECTION)
    }
  }

  function applyOutcome(outcome: AuthFlowSubmitOutcome): void {
    if (!outcome.ok) {
      error.set(outcome.message ?? DEFAULT_ERROR)

      return
    }
    if (outcome.next !== undefined) {
      flow.set({ ...flow.get(), ...outcome.next })
    }
  }

  return {
    flow,
    form,
    detection,
    pending,
    error,
    submittable,
    icons,
    setField(field: AuthFlowField, value: string): void {
      if (field === 'identifier') {
        // Editing the identifier itself restarts the flow and clears the secrets
        // (input-preservation rule: only an identifier change clears the form —
        // stepping forward never does); then (re)schedule the live lookup.
        const kind = classifyIdentifier(value)
        flow.set({ ...INITIAL_FLOW, identifierKind: kind })
        form.set({ ...EMPTY_FORM, identifier: value })
        error.set(null)
        scheduleDetect(value, kind)

        return
      }
      form.set({ ...form.get(), [field]: value })
    },
    async submit(): Promise<void> {
      if (pending.get()) {
        return
      }
      error.set(null)
      pending.set(true)
      try {
        applyOutcome(await options.onSubmit(flow.get(), form.get()))
      } finally {
        pending.set(false)
      }
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
      // Park in `external` while the method's ceremony runs; on failure fall back to
      // the identifier field so the user can retry another way.
      flow.set({ ...flow.get(), step: 'external', methodKey: key })
      pending.set(true)
      try {
        const outcome = await options.onMethodAction(key, form.get())
        if (!outcome.ok) {
          error.set(outcome.message ?? DEFAULT_ERROR)
          flow.set({ ...flow.get(), step: 'identifier', methodKey: null })
        } else if (outcome.next !== undefined) {
          flow.set({ ...flow.get(), ...outcome.next })
        }
      } finally {
        pending.set(false)
      }
    },
    startRecovery(): void {
      error.set(null)
      flow.set({
        ...flow.get(),
        intent: 'recovery',
        step: 'code',
        methodKey: null,
      })
      form.set({
        ...form.get(),
        password: '',
        confirmPassword: '',
        code: '',
        newPassword: '',
      })
    },
    back(): void {
      error.set(null)
      flow.set({ ...INITIAL_FLOW, identifierKind: flow.get().identifierKind })
    },
    reset(): void {
      cancelDetect()
      detectSeq += 1
      flow.set(INITIAL_FLOW)
      form.set(EMPTY_FORM)
      detection.set(IDLE_DETECTION)
      pending.set(false)
      error.set(null)
    },
  }
}
