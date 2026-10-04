import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  bindProfileFlows,
  createHilosProfileEmailChangeFlow,
  SIGNAL_PROFILE_FLOWS,
  type ActionLifecycle,
  type HilosConnection,
  type ProjectSignal,
} from '@hilos/core'
import { HilosProfileEmailChange } from '../src/profile/HilosProfileEmailChange.js'

afterEach(cleanup)

const listeners: Array<(signal: ProjectSignal) => void> = []
bindProfileFlows({
  on: (_: string, listener: (signal: ProjectSignal) => void) => {
    listeners.push(listener)
    return () => undefined
  },
} as unknown as HilosConnection)

/**
 * Tell the session's record of the email change, as the frame carries it.
 *
 * @param step The record's step, or null when the session has none.
 * @param target The new address on the last step, null on the others.
 */
function tell(step: string | null, target: string | null = null): void {
  const flows =
    step === null
      ? []
      : [
          {
            operation: 'change_email',
            step,
            address: 'old@example.test',
            target,
          },
        ]
  for (const listener of listeners)
    listener({
      kind: 'project',
      type: SIGNAL_PROFILE_FLOWS,
      data: { flows },
    } as ProjectSignal)
}

/** The answer to a code request: the code is out, and another may follow at once. */
const SENT = {
  sent: true,
  resendAt: Date.now() - 1_000,
  expiresAt: Date.now() + 600_000,
}

function setup() {
  tell(null)
  const dispatched: Array<{ action: string; payload?: unknown }> = []
  const answers: Record<string, unknown> = {
    hilos_step_up_start: {
      required: true,
      purpose: 'change your email',
      method: 'password',
    },
    profile_change_email_current_request: SENT,
    profile_change_email_new_request: SENT,
  }
  const flow = createHilosProfileEmailChangeFlow({
    actions: {
      dispatch: (action: string, payload?: unknown) => {
        dispatched.push({ action, payload })
        if (action === 'profile_change_email_current_request')
          tell('current_sent')
        if (action === 'profile_change_email_new_request')
          tell('new_sent', 'new@example.test')
        return {
          done:
            typeof answers[action] === 'string'
              ? Promise.reject(new Error(answers[action] as string))
              : Promise.resolve({ reply: answers[action] ?? [] }),
        }
      },
    } as unknown as ActionLifecycle,
  })
  const view = render(<HilosProfileEmailChange flow={flow} />)
  const node = (id: string) =>
    document.querySelector(`[data-id="${id}"]`) as HTMLElement

  return { flow, answers, dispatched, view, node }
}

describe('HilosProfileEmailChange', () => {
  it('confirms step-up via form submit when credential is typed, ignores empty submit, and binds confirm button to form', async () => {
    const { flow, node, dispatched } = setup()
    await act(async () => {
      await flow.open('old@example.test')
    })

    const form = node('profile-email-step-up')
    const confirm = node('profile-email-step-up-confirm')

    expect(confirm.getAttribute('type')).toBe('submit')
    expect(confirm.getAttribute('form')).toBe(form.id)

    await act(async () => {
      fireEvent.submit(form)
    })
    expect(dispatched.some((d) => d.action === 'hilos_step_up_confirm')).toBe(
      false,
    )

    fireEvent.change(node('step-up-password'), { target: { value: 'secret' } })
    await act(async () => {
      fireEvent.submit(form)
    })
    const call = dispatched.find((d) => d.action === 'hilos_step_up_confirm')
    expect(call).toBeDefined()
    expect(call?.payload).toMatchObject({
      password: 'secret',
    })
  })

  it('draws the send block on the current address code step and asks for another code from it', async () => {
    const { flow, answers, node } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your email',
    }
    await act(async () => {
      await flow.open('old@example.test')
    })
    await act(async () => {
      fireEvent.click(node('profile-email-send-current'))
    })

    expect(node('profile-email-current-send')).not.toBeNull()
    const sendAgain = vi.spyOn(flow, 'sendAgain')
    const again = node('profile-email-current-send-again') as HTMLButtonElement
    expect(again.disabled).toBe(false)
    await act(async () => {
      fireEvent.click(again)
    })
    expect(sendAgain).toHaveBeenCalledOnce()
  })

  it('draws the send block on the new address code step and asks for another code from it', async () => {
    const { flow, answers, node } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your email',
    }
    tell('current_proven')
    await act(async () => {
      await flow.open('old@example.test')
    })
    fireEvent.change(node('profile-email-new'), {
      target: { value: 'new@example.test' },
    })
    await act(async () => {
      fireEvent.click(node('profile-email-send-new'))
    })

    expect(node('profile-email-new-send')).not.toBeNull()
    const sendAgain = vi.spyOn(flow, 'sendAgain')
    const again = node('profile-email-new-send-again') as HTMLButtonElement
    expect(again.disabled).toBe(false)
    await act(async () => {
      fireEvent.click(again)
    })
    expect(sendAgain).toHaveBeenCalledOnce()
  })
})
