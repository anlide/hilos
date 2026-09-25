import { z } from 'zod'

import {
  ActionError,
  type ActionLifecycle,
} from '../connection/actionLifecycle.js'
import { getPasskey, type PasskeyRequestOptions } from './passkey.js'
import {
  createSignal,
  type ReadonlySignal,
  type WritableSignal,
} from '../state/signal.js'

export const HILOS_STEP_UP_START_ACTION = 'hilos_step_up_start'
export const HILOS_STEP_UP_CONFIRM_ACTION = 'hilos_step_up_confirm'

export type HilosStepUpMethod =
  | 'second_factor'
  | 'password'
  | 'email_code'
  | 'sms_code'
  | 'passkey'

export interface HilosStepUpOpening {
  readonly required: boolean
  readonly purpose: string
  readonly method?: HilosStepUpMethod
  readonly destination?: string
  readonly signedChallenge?: string
  readonly publicKeyOptions?: PasskeyRequestOptions
}

const openingSchema = z.object({
  required: z.boolean(),
  purpose: z.string(),
  method: z
    .enum(['second_factor', 'password', 'email_code', 'sms_code', 'passkey'])
    .optional(),
  destination: z.string().optional(),
  signedChallenge: z.string().optional(),
  publicKeyOptions: z.custom<PasskeyRequestOptions>().optional(),
})

export interface HilosStepUpAnswer {
  readonly method: HilosStepUpMethod
  readonly code: string
  readonly backupCode: boolean
  readonly password: string
  readonly passkey: Record<string, unknown> | null
}

export interface HilosStepUpActions {
  start(operation: string): ReturnType<ActionLifecycle['dispatch']>
  confirm(
    operation: string,
    answer: HilosStepUpAnswer,
  ): ReturnType<ActionLifecycle['dispatch']>
}

export function createHilosStepUpActions(
  actions: ActionLifecycle,
): HilosStepUpActions {
  return {
    start(operation) {
      return actions.dispatch(
        HILOS_STEP_UP_START_ACTION,
        { operation },
        { replySchema: openingSchema },
      )
    },
    confirm(operation, answer) {
      return actions.dispatch(HILOS_STEP_UP_CONFIRM_ACTION, {
        operation,
        ...answer,
      })
    },
  }
}

export interface HilosStepUpStep {
  readonly opening: ReadonlySignal<HilosStepUpOpening | null>
  readonly code: WritableSignal<string>
  readonly password: WritableSignal<string>
  readonly backupCode: WritableSignal<boolean>
  readonly busy: ReadonlySignal<boolean>
  readonly refusal: ReadonlySignal<string | null>
  open(operation: string): Promise<'skip' | 'ask' | 'refused'>
  confirm(): Promise<boolean>
}

export const HILOS_STEP_UP_COPY = {
  title: "Confirm it's you",
  secondFactor: 'To {purpose}, enter the code from your authenticator app.',
  codeHint: 'Six digits, they change every 30 seconds.',
  useBackup: 'Use a backup code',
  useApp: 'Use the app code',
  password: 'To {purpose}, enter your password.',
  code: 'To {purpose}, we sent a code to {destination}.',
  passkey: 'To {purpose}, confirm with your device key.',
  passkeyNotConfirmed: 'The device key was not confirmed',
  confirm: 'Confirm',
  cancel: 'Cancel',
} as const

function actionMessage(error: unknown): string {
  return error instanceof ActionError ? error.message : 'The action failed.'
}

export function createHilosStepUpStep(
  actions: HilosStepUpActions,
): HilosStepUpStep {
  const opening = createSignal<HilosStepUpOpening | null>(null)
  const code = createSignal('')
  const password = createSignal('')
  const backupCode = createSignal(false)
  const busy = createSignal(false)
  const refusal = createSignal<string | null>(null)
  let operation: string | null = null

  return {
    opening,
    code,
    password,
    backupCode,
    busy,
    refusal,
    async open(nextOperation) {
      operation = nextOperation
      code.set('')
      password.set('')
      backupCode.set(false)
      busy.set(true)
      refusal.set(null)
      try {
        const result = await actions.start(nextOperation).done
        const next = result.reply as HilosStepUpOpening
        opening.set(next)

        return next.required ? 'ask' : 'skip'
      } catch (error) {
        opening.set(null)
        refusal.set(actionMessage(error))

        return 'refused'
      } finally {
        busy.set(false)
      }
    },
    async confirm() {
      const current = opening.get()
      if (operation === null || current?.method === undefined || busy.get()) {
        return false
      }

      busy.set(true)
      refusal.set(null)
      try {
        let passkey: Record<string, unknown> | null = null
        if (current.method === 'passkey') {
          if (
            current.publicKeyOptions === undefined ||
            current.signedChallenge === undefined
          ) {
            refusal.set(HILOS_STEP_UP_COPY.passkeyNotConfirmed)
            return false
          }
          try {
            passkey = {
              signedChallenge: current.signedChallenge,
              ...(await getPasskey(current.publicKeyOptions)),
            }
          } catch {
            refusal.set(HILOS_STEP_UP_COPY.passkeyNotConfirmed)
            return false
          }
        }

        await actions.confirm(operation, {
          method: current.method,
          code: code.get(),
          backupCode: backupCode.get(),
          password: password.get(),
          passkey,
        }).done

        return true
      } catch (error) {
        refusal.set(actionMessage(error))
        return false
      } finally {
        busy.set(false)
      }
    },
  }
}
