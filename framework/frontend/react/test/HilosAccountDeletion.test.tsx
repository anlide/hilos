import { StrictMode } from 'react'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  createSignal,
  ScopeManager,
  type HilosConnection,
  type HilosRouter,
  type HilosSecondFactorContext,
  type ProjectSignal,
} from '@hilos/core'
import { HilosAccountDeletion } from '../src/profile/HilosAccountDeletion.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

afterEach(cleanup)

function setup() {
  const listeners = new Set<(signal: ProjectSignal) => void>()
  const dispatched: Array<{ action: string; payload?: unknown }> = []
  const answers: Record<string, unknown> = {
    hilos_step_up_start: {
      required: true,
      purpose: 'delete your account',
      method: 'password',
    },
    hilos_account_deletion_open: {
      graceDays: 30,
      channel: 'email',
      destination: 'me@example.test',
    },
  }
  const dispatch = vi.fn((action: string, payload?: unknown) => {
    dispatched.push({ action, payload })
    return {
      requestId: action,
      loading: createSignal(false),
      done: Promise.resolve(
        answers[action] !== undefined ? { reply: answers[action] } : {},
      ),
    }
  })
  const connection = {
    on: (event: string, handler: (signal: ProjectSignal) => void) => {
      if (event === 'projectSignal') {
        listeners.add(handler)
        return () => listeners.delete(handler)
      }
      return () => undefined
    },
  } as unknown as HilosConnection
  const context = {
    connection,
    scopes: new ScopeManager(),
    actions: { dispatch },
  } as unknown as HilosSecondFactorContext
  const router = {
    currentPath: createSignal('/profile'),
    navigate: vi.fn(),
  } as unknown as HilosRouter

  const { unmount } = render(
    <StrictMode>
      <HilosRouterContext.Provider value={router}>
        <HilosAccountDeletion context={context} />
      </HilosRouterContext.Provider>
    </StrictMode>,
  )
  const node = (id: string) =>
    document.querySelector(`[data-id="${id}"]`) as HTMLElement

  return {
    dispatch,
    dispatched,
    node,
    unmount,
    state(data: unknown) {
      act(() => {
        for (const listener of listeners) {
          listener({
            kind: 'project',
            type: 'hilos_account_deletion_state',
            data,
          } as unknown as ProjectSignal)
        }
      })
    },
  }
}

describe('HilosAccountDeletion', () => {
  it('confirms step-up via form submit when credential is typed, ignores empty submit, and binds confirm button to form', async () => {
    const { node, dispatched, state } = setup()
    state({ deletion: null })

    await act(async () => {
      fireEvent.click(node('account-deletion-open'))
    })

    const form = node('account-deletion-step-up')
    const confirm = node('account-deletion-confirm')

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
