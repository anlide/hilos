// The one add-method dialog: choosing a method and proving its address stay in
// this flow. A closed or superseded round never consumes a late action outcome.
import { type HilosAuthContext } from '../auth/authContext.js'
import { createOAuthLogin, describeOAuthError } from '../auth/oauthLogin.js'
import { createPasskeyCeremony } from '../auth/passkeyCeremony.js'
import {
  ActionError,
  type ActionHandle,
} from '../connection/actionLifecycle.js'
import { createSignal, type ReadonlySignal } from '../state/signal.js'
import { hilosToasts } from '../state/toasts.js'
import {
  hilosProfilePasswordState,
  watchHilosProfilePasswordUpdated,
  type HilosProfileSignInMethod,
} from './profileSignInMethods.js'
import { createHilosProfileSignInActions } from './signInMethods.js'

/** The ordinary screens of the add-method dialog. */
export type HilosProfileAddSignInStep =
  | 'closed'
  | 'choose'
  | 'password-new'
  | 'password-email'
  | 'password-code'
  | 'phone-number'
  | 'phone-code'

/** The dialog's state and commands, shared by the three view layers. */
export interface HilosProfileAddSignInFlow {
  readonly step: ReadonlySignal<HilosProfileAddSignInStep>
  readonly busy: ReadonlySignal<boolean>
  readonly refusal: ReadonlySignal<string | null>
  readonly provider: ReadonlySignal<string | null>
  readonly email: ReadonlySignal<string>
  readonly phone: ReadonlySignal<string>
  open(): void
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

  function close(): void {
    round += 1
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

  return {
    step,
    busy,
    refusal,
    provider,
    email,
    phone,
    open() {
      if (step.get() !== 'closed') return
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
      step.set('choose')
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
      if (busy.get() || step.get() === 'closed') return
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
}
