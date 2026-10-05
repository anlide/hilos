import { StrictMode } from 'react'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  createSignal,
  bindProfileFlows,
  ScopeManager,
  type HilosConnection,
  type HilosRouter,
  type HilosSecondFactorContext,
  type ProjectSignal,
} from '@hilos/core'
import { HilosAccountDeletion } from '../src/profile/HilosAccountDeletion.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
})

/** The answer to a code request: the code is out, and another may follow at once. */
const SENT = {
  sent: true,
  resendAt: Date.now() - 1_000,
  expiresAt: Date.now() + 600_000,
}

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
    hilos_account_deletion_code: SENT,
  }
  const sendFlows = (flows: unknown[]) => {
    for (const listener of listeners) {
      listener({
        kind: 'project',
        type: 'hilos_profile_flows',
        data: { flows },
      } as unknown as ProjectSignal)
    }
  }
  const dispatch = vi.fn((action: string, payload?: unknown) => {
    dispatched.push({ action, payload })
    if (action === 'hilos_account_deletion_code') {
      sendFlows([
        {
          operation: 'delete_account',
          step: 'code_sent',
          address: 'me@example.test',
          target: null,
        },
      ])
    }
    if (
      action === 'hilos_account_deletion_start' ||
      action === 'hilos_profile_flow_cancel'
    )
      sendFlows([])
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
  bindProfileFlows(connection)
  sendFlows([])
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
    answers,
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
  it('starts the zone clock when a deletion appears', () => {
    let now = 1_700_000_000_000
    vi.spyOn(Date, 'now').mockImplementation(() => now)
    const { node, state } = setup()
    state({ deletion: null })
    now += 50_000
    state({
      deletion: { requestedAt: now, effectiveAt: now + 30 * 86_400_000 },
    })
    expect(node('account-deletion-scheduled').textContent).toContain(
      '30 days left',
    )
  })

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

  it('draws the send block on the code step and asks for another code from it', async () => {
    const { answers, node, dispatched, state } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'delete your account',
    }
    state({ deletion: null })
    await act(async () => {
      fireEvent.click(node('account-deletion-open'))
    })
    await act(async () => {
      fireEvent.click(node('account-deletion-continue'))
    })

    expect(node('account-deletion-send')).not.toBeNull()
    fireEvent.change(node('account-deletion-code'), {
      target: { value: '123456' },
    })
    const again = node('account-deletion-send-again') as HTMLButtonElement
    expect(again.disabled).toBe(false)
    await act(async () => {
      fireEvent.click(again)
    })
    expect(
      dispatched.filter((d) => d.action === 'hilos_account_deletion_code'),
    ).toHaveLength(2)
    expect((node('account-deletion-code') as HTMLInputElement).value).toBe('')
  })
})
