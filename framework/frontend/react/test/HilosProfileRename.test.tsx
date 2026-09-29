import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import {
  createHilosProfileRenameFlow,
  createSignal,
  type ActionLifecycle,
  type HilosProfileRename as HilosProfileRenameBinding,
} from '@hilos/core'
import { HilosProfileRename } from '../src/profile/HilosProfileRename.js'

afterEach(cleanup)

function setup() {
  const dispatched: Array<{ action: string; payload?: unknown }> = []
  const name = createSignal('Ann')
  const refusal = createSignal<string | null>(null)
  const rename: HilosProfileRenameBinding = {
    send: () => true,
    refusal,
    clearRefusal: () => refusal.set(null),
    stepUpOperation: 'change_name',
    minLength: 2,
    maxLength: 64,
  }
  const flow = createHilosProfileRenameFlow(
    {
      actions: {
        dispatch: (action: string, payload?: unknown) => {
          dispatched.push({ action, payload })
          return {
            done:
              action === 'hilos_step_up_start'
                ? Promise.resolve({
                    reply: {
                      required: true,
                      purpose: 'change your name',
                      method: 'password',
                    },
                  })
                : action === 'hilos_step_up_confirm'
                  ? Promise.resolve({ reply: [] })
                  : Promise.reject(new Error(`unexpected ${action}`)),
          }
        },
      } as unknown as ActionLifecycle,
    },
    name,
    rename,
  )
  const view = render(<HilosProfileRename flow={flow} />)
  const node = (id: string) =>
    document.querySelector(`[data-id="${id}"]`) as HTMLElement

  return { flow, dispatched, view, node }
}

describe('HilosProfileRename', () => {
  it('confirms step-up via form submit when credential is typed, ignores empty submit, and binds confirm button to form', async () => {
    const { flow, node, dispatched } = setup()
    await act(async () => {
      await flow.open()
    })

    const form = node('profile-name-step-up')
    const confirm = node('profile-name-step-up-confirm')

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
