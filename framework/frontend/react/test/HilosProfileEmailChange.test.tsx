import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import {
  createHilosProfileEmailChangeFlow,
  type ActionLifecycle,
} from '@hilos/core'
import { HilosProfileEmailChange } from '../src/profile/HilosProfileEmailChange.js'

afterEach(cleanup)

function setup() {
  const dispatched: Array<{ action: string; payload?: unknown }> = []
  const answers: Record<string, unknown> = {
    hilos_step_up_start: {
      required: true,
      purpose: 'change your email',
      method: 'password',
    },
  }
  const flow = createHilosProfileEmailChangeFlow({
    actions: {
      dispatch: (action: string, payload?: unknown) => {
        dispatched.push({ action, payload })
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

  return { flow, dispatched, view, node }
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
})
