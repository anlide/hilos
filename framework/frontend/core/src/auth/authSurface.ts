// @deprecated Superseded by the identifier-first {@link ./authFlow} machine
// (HIL-413): the mode-first switcher contradicts the agreed guest design
// (mockup framework/guest). Kept only until HIL-423 rebinds the Vue surface and
// removes this module together with its barrel exports. Do not build on it.
//
// The auth surface state machine: the framework-agnostic "which mode is showing,
// what's in the form, is it submitting, did it fail" controller behind the
// project's sign-in component (HIL-364). The concrete component (a Vue/React/
// Angular view) renders the modes and owns the DOM; this owns only the reactive
// state and the flow between modes — one small machine, NOT flow logic scattered
// across a store's mutations (hleb's defect, fixed here). It lives in @hilos/core
// so HIL-361's framework-default surface and the React/Angular demos reuse it.
//
// The method registry is the thin extension point: the project declares an
// ORDERED list of enabled method descriptors, each naming the switcher entries it
// enables (login / register / recovery). Only email+password is implemented now;
// an OAuth or passkey descriptor is added later WITHOUT editing this core, fixing
// hleb's end-to-end email/password hardcode. The switcher shows an entry when any
// enabled method enables it.
//
// Submit is delegated: the machine never knows the wire protocol. The view wires
// `onSubmit(mode, form)` to its own action dispatch and returns the outcome; the
// machine owns only pending/error and the post-success mode advance. Login and
// register succeed by upgrading the session — the auth gate (HIL-165) watches the
// current-user signal and closes the surface — so their success needs no advance
// here; the recovery steps advance mode-to-mode against HIL-365's contract.

import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
} from '../state/signal.js'

/**
 * The switcher entries a method descriptor can enable. These are the coarse
 * capabilities the login↔register↔recovery switcher offers, distinct from the
 * finer {@link AuthMode} the surface is actually in at any moment.
 */
export type AuthEntry = 'login' | 'register' | 'recovery' | 'sms' | 'magic_link'

/**
 * The surface's active mode. `login` / `register` are the two direct entries;
 * register runs through a second step of its own (`register_confirm`, the emailed
 * code that creates the account — HIL-415 reserves the address at submit and only
 * the code registers anybody), which succeeds by upgrading the session like login;
 * the recovery entry runs through its three steps; the sms entry runs through its
 * two (`sms_request` then `sms_confirm`, which succeeds by upgrading the session
 * like login); the magic-link entry has a single `magic_link_request` mode (its
 * confirm is a clicked email link handled on a dedicated SPA route, not a form
 * step here); `done` is the terminal state a
 * completed non-session flow (e.g. a recovery that drops to login) can land in.
 */
export type AuthMode =
  | 'login'
  | 'register'
  | 'register_confirm'
  | 'recovery_request'
  | 'recovery_confirm'
  | 'recovery_set'
  | 'sms_request'
  | 'sms_confirm'
  | 'magic_link_request'
  | 'done'

/**
 * One enabled auth method in the project's ordered registry (the thin contract):
 * a stable `key`, a human `label`, and the switcher entries it enables. The
 * password method enables all three; an additive method (OAuth, magic-link,
 * passkey) ships its own descriptor without touching the surface core.
 */
export interface AuthMethodDescriptor {
  /** Stable method key, e.g. `password`. */
  readonly key: string
  /** Human-facing method label, e.g. `Email & password`. */
  readonly label: string
  /** The switcher entries this method enables. */
  readonly modes: readonly AuthEntry[]
}

/**
 * The per-mode form fields. A single flat shape holds every mode's inputs; each
 * mode reads only the fields it needs. Replace the object to update — never
 * mutate in place (the signal is shallow, signal.ts).
 */
export interface AuthFormState {
  /** Account email — login, register, recovery_request. */
  readonly email: string
  /** Password — login, register. */
  readonly password: string
  /** Password confirmation — register. */
  readonly confirmPassword: string
  /** Verification code — recovery_confirm, sms_confirm, register_confirm. */
  readonly code: string
  /** New password — recovery_set. */
  readonly newPassword: string
  /** Phone number — sms_request, sms_confirm. */
  readonly phone: string
}

/** A form field name, for the view's per-field update calls. */
export type AuthField = keyof AuthFormState

/**
 * The outcome the view's submit reports back. On failure, `message` is shown
 * inline in the active mode (auth deliberately surfaces the backend reason — a
 * sign-in says which of the three ways it failed, and a taken email is
 * legitimately disclosed on register). On success, `next` optionally advances
 * the mode (the recovery steps); login/register omit it — the session upgrade
 * closes the surface through the gate.
 */
export interface AuthSubmitOutcome {
  /** Whether the submit succeeded. */
  readonly ok: boolean
  /** The inline error message to show on failure. */
  readonly message?: string
  /** The mode to advance to on success (recovery stepping); omit to stay. */
  readonly next?: AuthMode
  /**
   * The SERVER moment the code or link this submit left waiting stops being
   * good, in epoch ms; omit when the submit left nothing waiting (HIL-486). The
   * machine does not act on it — what a code is worth is the server's answer,
   * and a screen reaching zero is only what the person sees — so it passes
   * through to the view, which draws the countdown.
   */
  readonly expiresAt?: number
}

/** Wiring for {@link createAuthSurface}. */
export interface AuthSurfaceOptions {
  /** The project's ordered enabled methods; drives the switcher entries. */
  methods: readonly AuthMethodDescriptor[]
  /**
   * Submit the active mode's form over the project's transport, resolving the
   * outcome. The machine guards re-entry and owns pending/error; this only
   * dispatches and maps the reply.
   *
   * @param mode The mode being submitted.
   * @param form The current form values.
   */
  onSubmit: (mode: AuthMode, form: AuthFormState) => Promise<AuthSubmitOutcome>
  /** The mode to start in; defaults to `login`. */
  initialMode?: AuthMode
}

/** The reactive auth surface a view binds and drives. */
export interface AuthSurface {
  /** The active mode. */
  readonly mode: ReadonlySignal<AuthMode>
  /** The current form values. */
  readonly form: ReadonlySignal<AuthFormState>
  /** Whether a submit is in flight (disables the submit control). */
  readonly pending: ReadonlySignal<boolean>
  /** The active mode's inline error, or null when clear. */
  readonly error: ReadonlySignal<string | null>
  /** Whether the active mode's form is complete enough to submit. */
  readonly submittable: ReadonlySignal<boolean>
  /** The switcher entries enabled across all methods, e.g. `['login','register','recovery']`. */
  readonly entries: readonly AuthEntry[]
  /**
   * Update one form field.
   *
   * @param field The field to set.
   * @param value The new value.
   */
  setField(field: AuthField, value: string): void
  /**
   * Switch to a mode from the switcher: clear the error and the sensitive fields
   * (the typed email is kept for convenience across login↔register).
   *
   * @param mode The mode to switch to.
   */
  switchTo(mode: AuthMode): void
  /** Submit the active mode; a no-op while already pending. */
  submit(): Promise<void>
  /** Reset to the initial mode with an empty form and no error — call on (re)mount. */
  reset(): void
}

/** Minimum password length; the mirror of HIL-164's server rule (length-only). */
export const PASSWORD_MIN_LENGTH = 8

/** The default email+password method descriptor — the one method implemented now. */
export const PASSWORD_AUTH_METHOD: AuthMethodDescriptor = {
  key: 'password',
  label: 'Email & password',
  modes: ['login', 'register', 'recovery'],
}

/**
 * The SMS one-time-code method descriptor (HIL-280). Enables the `sms` switcher
 * entry; a project adds it to its registry alongside {@link PASSWORD_AUTH_METHOD}
 * to offer phone sign-in without touching the surface core.
 */
export const SMS_AUTH_METHOD: AuthMethodDescriptor = {
  key: 'sms',
  label: 'Phone number',
  modes: ['sms'],
}

/**
 * The email magic-link method descriptor (HIL-283). Enables the `magic_link`
 * switcher entry; a project adds it to its registry alongside
 * {@link PASSWORD_AUTH_METHOD} to offer passwordless email sign-in. The surface
 * only owns the request step — the emailed link is confirmed on the project's
 * dedicated `/auth/magic` route — so this method contributes no in-form confirm
 * mode.
 */
export const MAGIC_LINK_AUTH_METHOD: AuthMethodDescriptor = {
  key: 'magic_link',
  label: 'Email me a sign-in link',
  modes: ['magic_link'],
}

/**
 * The GitHub OAuth method descriptor (HIL-281). Login-only — an external provider
 * has no in-app register or recovery step — so it enables only the `login`
 * switcher entry; a project adds it to its registry alongside
 * {@link PASSWORD_AUTH_METHOD} to offer "Continue with GitHub". OAuth contributes
 * no {@link AuthMode} of its own: the surface renders the descriptor as a redirect
 * button (its `key` names the backend provider), and the browser leaves for the
 * provider and returns on the project's dedicated `/auth/callback` route rather
 * than stepping a form here.
 */
export const OAUTH_GITHUB_AUTH_METHOD: AuthMethodDescriptor = {
  key: 'oauth:github',
  label: 'Continue with GitHub',
  modes: ['login'],
}

/**
 * The usernameless / discoverable passkey method descriptor (HIL-400) — since
 * HIL-418 retired the username-first one, the only passkey there is. It takes no
 * email and contributes no {@link AuthMode} of its own: like OAuth, the surface
 * renders it as an action button on the `login` entry, and a click runs the whole
 * discoverable round-trip (empty-allowCredentials options →
 * `navigator.credentials.get` with the OS picker → confirm) which upgrades the
 * session. A project adds it alongside {@link PASSWORD_AUTH_METHOD} to offer
 * one-tap passkey sign-in.
 */
export const PASSKEY_DISCOVERABLE_AUTH_METHOD: AuthMethodDescriptor = {
  key: 'passkey:discoverable',
  label: 'Sign in with a passkey',
  modes: ['login'],
}

/**
 * Phone digit-count bounds mirroring the backend E.164 normalizer
 * ({@link \Hilos\Auth\PhoneNumber}); client-side gating only.
 */
const PHONE_MIN_DIGITS = 8
const PHONE_MAX_DIGITS = 15

/** SMS one-time code length; the mirror of the backend code length. */
const SMS_CODE_LENGTH = 6

/** Registration confirmation code length; the mirror of the backend code length. */
const REGISTER_CODE_LENGTH = 6

/** An empty form — the starting and switch-reset value. */
const EMPTY_FORM: AuthFormState = {
  email: '',
  password: '',
  confirmPassword: '',
  code: '',
  newPassword: '',
  phone: '',
}

/** The default generic failure message when the view reports none. */
const DEFAULT_ERROR = 'The request could not be completed. Please try again.'

/**
 * Whether a mode's form is complete enough to submit. Client-side gating for the
 * button state only — the backend stays the source of truth and re-validates.
 * The password rule mirrors {@link PASSWORD_MIN_LENGTH} (HIL-164).
 *
 * @param mode The mode being validated.
 * @param form The current form values.
 */
export function isAuthSubmittable(
  mode: AuthMode,
  form: AuthFormState,
): boolean {
  switch (mode) {
    case 'login':
      return form.email.trim() !== '' && form.password !== ''
    case 'register':
      return (
        form.email.trim() !== '' &&
        form.password.length >= PASSWORD_MIN_LENGTH &&
        form.confirmPassword === form.password
      )
    case 'register_confirm':
      return new RegExp(`^\\d{${REGISTER_CODE_LENGTH}}$`).test(form.code.trim())
    case 'recovery_request':
      return form.email.trim() !== ''
    case 'recovery_confirm':
      return form.code.trim() !== ''
    case 'recovery_set':
      return form.newPassword.length >= PASSWORD_MIN_LENGTH
    case 'sms_request':
      return new RegExp(
        `^\\+?\\d{${PHONE_MIN_DIGITS},${PHONE_MAX_DIGITS}}$`,
      ).test(form.phone.replace(/[\s\-().]/g, ''))
    case 'sms_confirm':
      return new RegExp(`^\\d{${SMS_CODE_LENGTH}}$`).test(form.code.trim())
    case 'magic_link_request':
      return form.email.trim() !== ''
    case 'done':
      return false
  }
}

/**
 * The switcher entries enabled across an ordered method registry, de-duplicated
 * in first-seen order. An entry shows when any enabled method enables it.
 *
 * @param methods The project's ordered method descriptors.
 */
export function authEntries(
  methods: readonly AuthMethodDescriptor[],
): readonly AuthEntry[] {
  const seen: AuthEntry[] = []
  for (const method of methods) {
    for (const entry of method.modes) {
      if (!seen.includes(entry)) {
        seen.push(entry)
      }
    }
  }

  return seen
}

/**
 * Create the auth surface state machine over the project's method registry and
 * submit callback.
 *
 * @param options The method registry, the submit dispatch, and the initial mode.
 */
export function createAuthSurface(options: AuthSurfaceOptions): AuthSurface {
  const initialMode = options.initialMode ?? 'login'
  const mode = createSignal<AuthMode>(initialMode)
  const form = createSignal<AuthFormState>(EMPTY_FORM)
  const pending = createSignal(false)
  const error = createSignal<string | null>(null)
  const submittable = computedSignal(() =>
    isAuthSubmittable(mode.get(), form.get()),
  )
  const entries = authEntries(options.methods)

  function switchTo(next: AuthMode): void {
    error.set(null)
    // Keep the typed email across login↔register↔recovery; clear the secrets so a
    // password never carries silently into another mode.
    form.set({ ...EMPTY_FORM, email: form.get().email })
    mode.set(next)
  }

  async function submit(): Promise<void> {
    if (pending.get()) {
      return
    }
    error.set(null)
    pending.set(true)
    try {
      const outcome = await options.onSubmit(mode.get(), form.get())
      if (!outcome.ok) {
        error.set(outcome.message ?? DEFAULT_ERROR)

        return
      }
      // Success: advance the mode when the flow steps forward (recovery); login
      // and register close the surface externally via the session upgrade, so
      // they report no next mode and this leaves the surface as-is.
      if (outcome.next !== undefined) {
        mode.set(outcome.next)
      }
    } finally {
      pending.set(false)
    }
  }

  return {
    mode,
    form,
    pending,
    error,
    submittable,
    entries,
    setField(field: AuthField, value: string): void {
      form.set({ ...form.get(), [field]: value })
    },
    switchTo,
    submit,
    reset(): void {
      mode.set(initialMode)
      form.set(EMPTY_FORM)
      pending.set(false)
      error.set(null)
    },
  }
}
