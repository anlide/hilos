import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  createSignal,
  type ActionHandle,
  type HilosProfileSession,
  type HilosProfileSessionActions,
} from '@hilos/core'
import { HilosProfileSessions } from '../src/profile/HilosProfileSessions.js'

afterEach(() => {
  cleanup()
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

function byId(id: string): HTMLElement {
  const found = document.querySelector<HTMLElement>(`[data-id="${id}"]`)
  if (!found) throw new Error(`Missing ${id}`)
  return found
}

function makeSession(
  id: number,
  options: { current?: boolean; impersonated?: boolean } = {},
): HilosProfileSession {
  return {
    id,
    deviceName: `Device ${id}`,
    createdAt: '2026-09-30T10:00:00Z',
    lastSeenAt: '2026-09-30T10:00:00Z',
    expiresAt: '2026-10-30T10:00:00Z',
    current: options.current ?? false,
    impersonated: options.impersonated ?? false,
    tabs: [],
  }
}

function makeHandle(promise: Promise<unknown>): ActionHandle {
  return {
    requestId: 'req-1',
    done: promise as Promise<{ message?: string; reply?: unknown }>,
    loading: createSignal(false),
  }
}

function setup(
  initialSessions: readonly HilosProfileSession[] = [
    makeSession(1, { current: true }),
    makeSession(2),
  ],
  endOtherResult: () => Promise<unknown> = () =>
    Promise.resolve({ reply: null }),
) {
  const endOtherSessions = vi.fn(() => makeHandle(endOtherResult()))
  const actions: HilosProfileSessionActions = {
    endSession: vi.fn(),
    endOtherSessions,
  }
  const view = render(
    <HilosProfileSessions sessions={initialSessions} actions={actions} />,
  )
  return { actions, endOtherSessions, view }
}

describe('HilosProfileSessions (React)', () => {
  it('opens confirmation modal without calling endOtherSessions on button click', async () => {
    const { endOtherSessions } = setup()
    expect(
      document.querySelector('[data-id="profile-sessions-end-others-confirm"]'),
    ).toBeNull()

    await act(async () => {
      fireEvent.click(byId('profile-sessions-end-others'))
    })

    expect(
      document.querySelector('[data-id="profile-sessions-end-others-confirm"]'),
    ).not.toBeNull()
    expect(endOtherSessions).not.toHaveBeenCalled()
  })

  it('shows count 1 when sessions include this one, an impersonated session, and one regular session', async () => {
    setup([
      makeSession(1, { current: true }),
      makeSession(2, { impersonated: true }),
      makeSession(3),
    ])
    await act(async () => {
      fireEvent.click(byId('profile-sessions-end-others'))
    })

    expect(byId('profile-sessions-end-others-count').textContent?.trim()).toBe(
      '1',
    )
  })

  it('reactively updates count under the open modal and disables confirm when all other sessions end', async () => {
    const { actions, view } = setup([
      makeSession(1, { current: true }),
      makeSession(2),
    ])
    await act(async () => {
      fireEvent.click(byId('profile-sessions-end-others'))
    })
    expect(byId('profile-sessions-end-others-count').textContent?.trim()).toBe(
      '1',
    )

    await act(async () => {
      view.rerender(
        <HilosProfileSessions
          sessions={[
            makeSession(1, { current: true }),
            makeSession(2),
            makeSession(3),
          ]}
          actions={actions}
        />,
      )
    })
    expect(byId('profile-sessions-end-others-count').textContent?.trim()).toBe(
      '2',
    )

    await act(async () => {
      view.rerender(
        <HilosProfileSessions
          sessions={[makeSession(1, { current: true })]}
          actions={actions}
        />,
      )
    })
    expect(byId('profile-sessions-end-others-count').textContent?.trim()).toBe(
      '0',
    )
    expect(byId('profile-sessions-end-others-error').textContent).toContain(
      'Your other sessions have already ended',
    )
    expect(
      byId('profile-sessions-end-others-confirm').hasAttribute('disabled'),
    ).toBe(true)
  })

  it('calls endOtherSessions once on Sign out and closes modal on success', async () => {
    let resolveAction!: () => void
    const actionPromise = new Promise<{ reply: null }>((resolve) => {
      resolveAction = () => resolve({ reply: null })
    })
    const { endOtherSessions } = setup(
      [makeSession(1, { current: true }), makeSession(2)],
      () => actionPromise,
    )
    await act(async () => {
      fireEvent.click(byId('profile-sessions-end-others'))
    })

    await act(async () => {
      fireEvent.click(byId('profile-sessions-end-others-confirm'))
    })
    expect(endOtherSessions).toHaveBeenCalledTimes(1)

    await act(async () => {
      resolveAction()
      await actionPromise
    })

    expect(
      document.querySelector('[data-id="profile-sessions-end-others-confirm"]'),
    ).toBeNull()
  })

  it('keeps modal open and displays refusal on action failure', async () => {
    const { endOtherSessions } = setup(
      [makeSession(1, { current: true }), makeSession(2)],
      () =>
        Promise.reject(
          new ActionError(
            'hilos_sessions_end_others',
            'fail',
            'Denied by policy',
          ),
        ),
    )
    await act(async () => {
      fireEvent.click(byId('profile-sessions-end-others'))
    })

    await act(async () => {
      fireEvent.click(byId('profile-sessions-end-others-confirm'))
    })

    expect(endOtherSessions).toHaveBeenCalledTimes(1)
    expect(
      document.querySelector('[data-id="profile-sessions-end-others-confirm"]'),
    ).not.toBeNull()
    expect(byId('profile-sessions-end-others-error').textContent).toContain(
      'Denied by policy',
    )
  })

  it('closes modal on Cancel without calling endOtherSessions', async () => {
    const { endOtherSessions } = setup()
    await act(async () => {
      fireEvent.click(byId('profile-sessions-end-others'))
    })

    const cancel = document.querySelector<HTMLButtonElement>(
      '.modal.show .modal-footer .btn-secondary',
    )
    expect(cancel).not.toBeNull()
    await act(async () => {
      cancel?.click()
    })

    expect(
      document.querySelector('[data-id="profile-sessions-end-others-confirm"]'),
    ).toBeNull()
    expect(endOtherSessions).not.toHaveBeenCalled()
  })

  it('disables page button when there are no other sessions to end', () => {
    setup([makeSession(1, { current: true })])
    expect(byId('profile-sessions-end-others').hasAttribute('disabled')).toBe(
      true,
    )
  })
})
