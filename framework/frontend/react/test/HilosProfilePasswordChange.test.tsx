import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  bindProfileFlows,
  createHilosProfilePasswordChangeFlow,
  SIGNAL_PROFILE_FLOWS,
  type ActionLifecycle,
  type HilosConnection,
  type ProjectSignal,
} from '@hilos/core'
import { HilosProfilePasswordChange } from '../src/profile/HilosProfilePasswordChange.js'

afterEach(cleanup)

const listeners: Array<(signal: ProjectSignal) => void> = []
bindProfileFlows({
  on: (_: string, listener: (signal: ProjectSignal) => void) => {
    listeners.push(listener)
    return () => undefined
  },
} as unknown as HilosConnection)

/**
 * Tell the session's record of the password change, as the frame carries it.
 *
 * @param step The record's step, or null when the session has none.
 */
function tell(step: string | null): void {
  const flows =
    step === null
      ? []
      : [
          {
            operation: 'change_password',
            step,
            address: 'a@example.test',
            target: null,
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

/** The record each action leaves behind, told before its answer arrives. */
const FRAME_AFTER: Record<string, string> = {
  profile_change_password_code_request: 'code_sent',
}

function setup() {
  tell(null)
  const dispatched: Array<{ action: string; payload?: unknown }> = []
  const answers: Record<string, unknown> = {
    hilos_step_up_start: {
      required: true,
      purpose: 'change your password',
      method: 'password',
    },
    profile_change_password_open: {
      channel: 'email',
      destination: 'a@example.test',
    },
    profile_change_password_code_request: SENT,
  }
  const flow = createHilosProfilePasswordChangeFlow({
    actions: {
      dispatch: (action: string, payload?: unknown) => {
        dispatched.push({ action, payload })
        if (action in FRAME_AFTER) tell(FRAME_AFTER[action] ?? null)
        return {
          done:
            typeof answers[action] === 'string'
              ? Promise.reject(new Error(answers[action] as string))
              : Promise.resolve({ reply: answers[action] ?? [] }),
        }
      },
    } as unknown as ActionLifecycle,
  })
  const view = render(<HilosProfilePasswordChange flow={flow} />)
  const node = (id: string) =>
    document.querySelector(`[data-id="${id}"]`) as HTMLElement

  return { flow, answers, dispatched, view, node }
}

describe('HilosProfilePasswordChange', () => {
  it('confirms step-up via form submit when credential is typed, ignores empty submit, and binds confirm button to form', async () => {
    const { flow, node, dispatched } = setup()
    await act(async () => {
      await flow.open()
    })

    const form = node('profile-password-step-up')
    const confirm = node('profile-password-step-up-confirm')

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

  it('draws the send block on the code step and asks for another code from it', async () => {
    const { flow, answers, node } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your password',
    }
    await act(async () => {
      await flow.open()
    })
    await act(async () => {
      fireEvent.click(node('profile-password-send-code'))
    })

    expect(node('profile-password-send')).not.toBeNull()
    expect(document.querySelectorAll('[inert] [data-id]')).toHaveLength(0)
    const sendAgain = vi.spyOn(flow, 'sendAgain')
    const again = node('profile-password-send-again') as HTMLButtonElement
    expect(again.disabled).toBe(false)
    await act(async () => {
      fireEvent.click(again)
    })
    expect(sendAgain).toHaveBeenCalledOnce()
  })
})
