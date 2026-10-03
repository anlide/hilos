// The one add-method dialog: choosing a method and proving its address stay in
// this flow. A closed or superseded round never consumes a late action outcome.
// Adding a way in is a protected operation (HIL-1138): the dialog opens on the
// server's word, at the confirmation step or straight at the chooser — or, when
// it was opened for one way (HIL-1166), straight at that way's first step.
import { type HilosAuthContext } from '../auth/authContext.js'
import { createOAuthLogin, describeOAuthError } from '../auth/oauthLogin.js'
import { createPasskeyCeremony } from '../auth/passkeyCeremony.js'
import {
  createHilosStepUpActions,
  createHilosStepUpStep,
  type HilosStepUpStep,
} from '../auth/stepUp.js'
import {
  ActionError,
  type ActionHandle,
} from '../connection/actionLifecycle.js'
import { createSignal, type ReadonlySignal } from '../state/signal.js'
import { hilosToasts } from '../state/toasts.js'
import {
  hilosProfilePasswordState,
  watchHilosProfilePasswordUpdated,
  type HilosProfileAddableWay,
  type HilosProfileSignInMethod,
} from './profileSignInMethods.js'
import { createHilosProfileSignInActions } from './signInMethods.js'

/** The step-up operation every add of a way in belongs to (PHP `StepUpOperationKey::ADD_SIGN_IN_METHOD`). */
export const ADD_SIGN_IN_METHOD_OPERATION = 'add_sign_in_method'

/**
 * The screens of the add-method dialog. It opens on the server's answer to the
 * confirmation start: `opening` while that answer is out, then `step-up`,
 * `refused` or straight to `choose`.
 */
export type HilosProfileAddSignInStep =
  | 'closed'
  | 'opening'
  | 'step-up'
  | 'refused'
  | 'choose'
  | 'password-new'
  | 'password-email'
  | 'password-code'
  | 'phone-number'
  | 'phone-code'

/** The dialog's state and commands, shared by the three view layers. */
export interface HilosProfileAddSignInFlow {
  readonly step: ReadonlySignal<HilosProfileAddSignInStep>
  /** The confirmation step the dialog opens on when the server asks for one. */
  readonly stepUp: HilosStepUpStep
  readonly busy: ReadonlySignal<boolean>
  readonly refusal: ReadonlySignal<string | null>
  readonly provider: ReadonlySignal<string | null>
  readonly email: ReadonlySignal<string>
  readonly phone: ReadonlySignal<string>
  /**
   * Ask the server whether a confirmation is needed and open on its answer.
   *
   * @param way The way to enter instead of the chooser once the confirmation
   *   is behind (HIL-1166); without it the dialog opens on the chooser.
   */
  open(way?: HilosProfileAddableWay): Promise<void>
  /** Send the confirmation step's proof; the chooser — or the way asked for at open — follows a success. */
  confirmStepUp(): Promise<void>
  choosePassword(): void
  choosePhone(): void
  chooseProvider(key: string): Promise<void>
  choosePasskey(): Promise<void>
  submitPasswordNew(newPassword: string): Promise<void>
  submitPasswordEmail(email: string): Promise<void>
  submitPasswordCode(code: string, newPassword: string): Promise<void>
  submitPhone(phone: string): Promise<void>
  submitPhoneCode(code: string): Promise<void>
  back(): void
  close(): void
  dispose(): void
}

/** Messages shared by all sign-in-section views. */
export const HILOS_PROFILE_SIGN_IN_COPY = {
  passwordAdded: 'Password added.',
  passwordDescription: 'Set a password and sign in with it.',
  phoneDescription: 'Link a phone number and receive a code.',
  providerDescription: 'Sign in through this provider.',
  passkeyDescription: 'Use a key on this device.',
  passwordHint:
    'At least 8 characters. Enter the same password in both fields.',
  passwordChanged: 'Password changed.',
  passkeyAdded: 'Passkey added. You can now sign in with it.',
  failed: 'The action failed. Please try again.',
  onlyMethod: 'You cannot remove your only login method',
  passkeyOnlyTitle: 'Only your passkeys can sign you in',
  passkeyOnlyReason:
    'If you lose them, your access cannot be restored automatically: there is no email address or phone number to send a recovery code to.',
  passkeyOnlyAdd: 'Add another way to sign in:',
  passkeyOnlyNone: 'No other way to sign in is available here.',
  addPassword: 'Add a password',
  addPhone: 'Add a phone',
  linkProvider: 'Link {name}',
} as const

/**
 * Create the add-method flow over the existing account commands and ceremonies.
 *
 * @param context The project's authentication context.
 * @param methods The live methods of the current account.
 */
export function createHilosProfileAddSignInFlow(
  context: HilosAuthContext,
  methods: ReadonlySignal<readonly HilosProfileSignInMethod[]>,
): HilosProfileAddSignInFlow {
  const actions = createHilosProfileSignInActions(context)
  const oauth = createOAuthLogin(context)
  const passkeys = createPasskeyCeremony(context)
  const stepUp = createHilosStepUpStep(
    createHilosStepUpActions(context.actions),
  )
  const step = createSignal<HilosProfileAddSignInStep>('closed')
  const busy = createSignal(false)
  const refusal = createSignal<string | null>(null)
  const provider = createSignal<string | null>(null)
  const email = createSignal('')
  const phone = createSignal('')
  let round = 0
  let stopPassword: (() => void) | null = null
  let stopTrip: (() => void) | null = null
  let tripAbort: AbortController | null = null
  let pendingWay: HilosProfileAddableWay | null = null

  function close(): void {
    round += 1
    pendingWay = null
    stopPassword?.()
    stopPassword = null
    stopTrip?.()
    stopTrip = null
    tripAbort?.abort()
    tripAbort = null
    step.set('closed')
    busy.set(false)
    refusal.set(null)
    provider.set(null)
    email.set('')
    phone.set('')
  }

  function choose(next: HilosProfileAddSignInStep): void {
    if (busy.get() || step.get() !== 'choose') return
    refusal.set(null)
    step.set(next)
  }

  /**
   * Land on the chooser, then step into the way the dialog was opened for, if
   * any. Entering goes through the chooser's own commands, so Back from the
   * way's first step returns to the chooser exactly as after a choice.
   */
  function enterChooser(): void {
    step.set('choose')
    const way = pendingWay
    pendingWay = null
    if (way === null) return
    switch (way.kind) {
      case 'password':
        flow.choosePassword()
        break
      case 'phone':
        flow.choosePhone()
        break
      case 'provider':
        void flow.chooseProvider(way.key)
        break
      case 'passkey':
        void flow.choosePasskey()
        break
    }
  }

  async function submit(
    expected: HilosProfileAddSignInStep,
    send: () => ActionHandle,
    accepted: () => void,
    waitForPassword = false,
  ): Promise<void> {
    if (busy.get() || step.get() !== expected) return
    const started = round
    busy.set(true)
    refusal.set(null)
    try {
      await send().done
      if (round === started) accepted()
    } catch (error) {
      if (round === started) {
        refusal.set(
          error instanceof ActionError
            ? error.message
            : HILOS_PROFILE_SIGN_IN_COPY.failed,
        )
      }
    } finally {
      if (round === started && (!waitForPassword || refusal.get() !== null))
        busy.set(false)
    }
  }

  const flow: HilosProfileAddSignInFlow = {
    step,
    stepUp,
    busy,
    refusal,
    provider,
    email,
    phone,
    async open(way) {
      if (step.get() !== 'closed') return
      pendingWay = way ?? null
      stopPassword = watchHilosProfilePasswordUpdated(
        context.connection,
        () => {
          if (
            step.get() === 'password-new' ||
            step.get() === 'password-code' ||
            step.get() === 'password-email'
          )
            close()
        },
      )

      refusal.set(null)
      step.set('opening')
      busy.set(true)
      const started = round
      const verdict = await stepUp.open(ADD_SIGN_IN_METHOD_OPERATION)
      if (round !== started) return
      busy.set(false)
      if (verdict === 'refused') refusal.set(stepUp.refusal.get())
      if (verdict === 'skip') enterChooser()
      else step.set(verdict === 'ask' ? 'step-up' : 'refused')
    },
    async confirmStepUp() {
      if (busy.get() || step.get() !== 'step-up') return
      busy.set(true)
      const started = round
      const confirmed = await stepUp.confirm()
      if (round !== started) return
      busy.set(false)
      if (confirmed) enterChooser()
    },
    choosePassword() {
      if (hilosProfilePasswordState(methods.get()).hasPassword) return
      choose(
        hilosProfilePasswordState(methods.get()).verifiedEmail === null
          ? 'password-email'
          : 'password-new',
      )
    },
    choosePhone() {
      choose('phone-number')
    },
    async chooseProvider(key) {
      if (busy.get() || step.get() !== 'choose') return
      const started = round
      busy.set(true)
      refusal.set(null)
      provider.set(key)
      tripAbort = new AbortController()
      stopTrip = oauth.subscribeOAuthOutcome((outcome) => {
        if (round !== started) return
        stopTrip?.()
        stopTrip = null
        tripAbort = null
        busy.set(false)
        provider.set(null)
        if (outcome.kind === 'error') refusal.set(outcome.message)
        else if (outcome.kind === 'linked' || outcome.kind === 'reauth_pending')
          close()
      })
      try {
        await oauth.startOAuthLink(key, tripAbort.signal)
      } catch (error) {
        if (round !== started) return
        stopTrip?.()
        stopTrip = null
        tripAbort = null
        busy.set(false)
        provider.set(null)
        refusal.set(describeOAuthError(error))
      }
    },
    async choosePasskey() {
      if (busy.get() || step.get() !== 'choose') return
      const started = round
      busy.set(true)
      refusal.set(null)
      try {
        const outcome = await passkeys.runPasskeyRegister()
        if (round !== started) return
        if (outcome.ok) {
          close()
          hilosToasts.push(HILOS_PROFILE_SIGN_IN_COPY.passkeyAdded, {
            severity: 'success',
          })
        } else refusal.set(outcome.message ?? HILOS_PROFILE_SIGN_IN_COPY.failed)
      } catch (error) {
        if (round === started)
          refusal.set(
            error instanceof ActionError
              ? error.message
              : HILOS_PROFILE_SIGN_IN_COPY.failed,
          )
      } finally {
        if (round === started) busy.set(false)
      }
    },
    submitPasswordNew(newPassword) {
      return submit(
        'password-new',
        () => actions.setPassword(newPassword),
        () => {},
        true,
      )
    },
    submitPasswordEmail(address) {
      return submit(
        'password-email',
        () => actions.requestPasswordAdd(address),
        () => {
          email.set(address)
          step.set('password-code')
        },
      )
    },
    submitPasswordCode(code, newPassword) {
      return submit(
        'password-code',
        () => actions.confirmPasswordAdd(email.get(), code, newPassword),
        () => {},
        true,
      )
    },
    submitPhone(number) {
      return submit(
        'phone-number',
        () => actions.requestSmsAdd(number),
        () => {
          phone.set(number)
          step.set('phone-code')
        },
      )
    },
    submitPhoneCode(code) {
      return submit(
        'phone-code',
        () => actions.confirmSmsAdd(phone.get(), code),
        close,
      )
    },
    back() {
      if (
        busy.get() ||
        step.get() === 'closed' ||
        step.get() === 'opening' ||
        step.get() === 'step-up' ||
        step.get() === 'refused' ||
        step.get() === 'choose'
      )
        return
      round += 1
      refusal.set(null)
      step.set(
        step.get() === 'phone-code'
          ? 'phone-number'
          : step.get() === 'password-code'
            ? 'password-email'
            : 'choose',
      )
    },
    close,
    dispose() {
      close()
    },
  }

  return flow
}
