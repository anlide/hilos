import { z } from 'zod'

import {
  CODE_SEND_PURPOSE_STEP_UP,
  codeSendReplySchema,
  hilosCodeSendProgressFor,
  type CodeSendProgress,
  type HilosCodeSendReply,
} from './authSendProgress.js'
import {
  ActionError,
  type ActionHandle,
  type ActionLifecycle,
} from '../connection/actionLifecycle.js'
import { toLocal } from '../session/serverClock.js'
import { getPasskey, type PasskeyRequestOptions } from './passkey.js'
import {
  computedSignal,
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
  readonly send?: HilosCodeSendReply
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
  send: codeSendReplySchema.optional(),
})

export interface HilosStepUpAnswer {
  readonly method: HilosStepUpMethod
  readonly code: string
  readonly backupCode: boolean
  readonly password: string
  readonly passkey: Record<string, unknown> | null
}

export interface HilosStepUpActions {
  start(operation: string): ActionHandle<HilosStepUpOpening>
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

/**
 * What opening a step answers: no step is needed, the step asks, or the
 * operation is refused before its own form begins.
 */
export type HilosStepUpOpenOutcome = 'skip' | 'ask' | 'refused'

export interface HilosStepUpStep {
  readonly opening: ReadonlySignal<HilosStepUpOpening | null>
  readonly code: WritableSignal<string>
  readonly password: WritableSignal<string>
  readonly backupCode: WritableSignal<boolean>
  readonly busy: ReadonlySignal<boolean>
  readonly refusal: ReadonlySignal<string | null>
  /** The session line of this identity confirmation, hidden while another code is ordered. */
  readonly sendProgress: ReadonlySignal<CodeSendProgress | null>
  /** Local-scale moment another code may be requested. */
  readonly resendAt: ReadonlySignal<number | null>
  open(operation: string): Promise<HilosStepUpOpenOutcome>
  /** Request the same operation's code again without resetting the confirmation step. */
  sendAgain(): Promise<void>
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
  onPassed?: () => void | Promise<void>,
): HilosStepUpStep {
  const opening = createSignal<HilosStepUpOpening | null>(null)
  const code = createSignal('')
  const password = createSignal('')
  const backupCode = createSignal(false)
  const busy = createSignal(false)
  const refusal = createSignal<string | null>(null)
  const reportedProgress = hilosCodeSendProgressFor(CODE_SEND_PURPOSE_STEP_UP)
  const hiddenTicket = createSignal<string | null>(null)
  const replyResendAt = createSignal<number | null>(null)
  const sendProgress = computedSignal(() => {
    const progress = reportedProgress.get()
    return progress !== null && progress.ticket === hiddenTicket.get()
      ? null
      : progress
  })
  const resendAt = computedSignal(
    () => sendProgress.get()?.resendAt ?? replyResendAt.get(),
  )
  let operation: string | null = null
  // Bumped by every open: a repeated send whose answer comes back after the
  // step was opened again for another operation must not write over it.
  let round = 0

  return {
    opening,
    code,
    password,
    backupCode,
    busy,
    refusal,
    sendProgress,
    resendAt,
    async open(nextOperation) {
      round += 1
      operation = nextOperation
      code.set('')
      password.set('')
      backupCode.set(false)
      busy.set(true)
      refusal.set(null)
      // The line of an earlier confirmation stays hidden until this one's own
      // send replaces it; every operation shares the step-up purpose.
      hiddenTicket.set(reportedProgress.get()?.ticket ?? null)
      replyResendAt.set(null)
      try {
        const result = await actions.start(nextOperation).done
        const next = result.reply as HilosStepUpOpening
        opening.set(next)
        if (next.send !== undefined)
          replyResendAt.set(toLocal(next.send.resendAt))

        return next.required ? 'ask' : 'skip'
      } catch (error) {
        opening.set(null)
        refusal.set(actionMessage(error))

        return 'refused'
      } finally {
        busy.set(false)
      }
    },
    async sendAgain() {
      const current = opening.get()
      if (
        operation === null ||
        busy.get() ||
        (current?.method !== 'email_code' && current?.method !== 'sms_code')
      )
        return
      const started = round
      code.set('')
      refusal.set(null)
      hiddenTicket.set(reportedProgress.get()?.ticket ?? null)
      busy.set(true)
      let passed = false
      try {
        const result = await actions.start(operation).done
        if (round !== started) return
        const next = result.reply as HilosStepUpOpening
        opening.set(next)
        if (next.send !== undefined)
          replyResendAt.set(toLocal(next.send.resendAt))
        passed = !next.required
      } catch (error) {
        if (round !== started) return
        refusal.set(actionMessage(error))
      } finally {
        if (round === started) busy.set(false)
      }
      if (passed) await onPassed?.()
    },
    async confirm() {
      const current = opening.get()
      if (operation === null || current?.method === undefined || busy.get()) {
        return false
      }

      if (
        (current.method === 'password' && password.get() === '') ||
        ((current.method === 'second_factor' ||
          current.method === 'email_code' ||
          current.method === 'sms_code') &&
          code.get().trim() === '')
      ) {
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
