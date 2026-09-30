import {
  ActionError,
  createSignal,
  type ActionHandle,
  type HilosProfileSession,
  type HilosProfileSessionActions,
} from '@hilos/core'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'

import HilosProfileSessions from './HilosProfileSessions.vue'

afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})
enableAutoUnmount(afterEach)

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
  const sessions = ref(initialSessions)
  const endOtherSessions = vi.fn(() => makeHandle(endOtherResult()))
  const actions: HilosProfileSessionActions = {
    endSession: vi.fn(),
    endOtherSessions,
  }
  const wrapper = mount(HilosProfileSessions, {
    props: {
      sessions: sessions.value,
      actions,
    },
    attachTo: document.body,
  })
  return { sessions, actions, endOtherSessions, wrapper }
}

describe('HilosProfileSessions (Vue)', () => {
  it('opens confirmation modal without calling endOtherSessions on button click', async () => {
    const { endOtherSessions } = setup()
    expect(
      document.querySelector('[data-id="profile-sessions-end-others-confirm"]'),
    ).toBeNull()

    byId('profile-sessions-end-others').click()
    await nextTick()

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
    byId('profile-sessions-end-others').click()
    await nextTick()

    expect(byId('profile-sessions-end-others-count').textContent?.trim()).toBe(
      '1',
    )
  })

  it('reactively updates count under the open modal and disables confirm when all other sessions end', async () => {
    const { wrapper } = setup([
      makeSession(1, { current: true }),
      makeSession(2),
    ])
    byId('profile-sessions-end-others').click()
    await nextTick()
    expect(byId('profile-sessions-end-others-count').textContent?.trim()).toBe(
      '1',
    )

    await wrapper.setProps({
      sessions: [
        makeSession(1, { current: true }),
        makeSession(2),
        makeSession(3),
      ],
    })
    expect(byId('profile-sessions-end-others-count').textContent?.trim()).toBe(
      '2',
    )

    await wrapper.setProps({
      sessions: [makeSession(1, { current: true })],
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
    byId('profile-sessions-end-others').click()
    await nextTick()

    byId('profile-sessions-end-others-confirm').click()
    await nextTick()
    expect(endOtherSessions).toHaveBeenCalledTimes(1)

    resolveAction()
    await flushPromises()
    await nextTick()

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
    byId('profile-sessions-end-others').click()
    await nextTick()

    byId('profile-sessions-end-others-confirm').click()
    await flushPromises()
    await nextTick()

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
    byId('profile-sessions-end-others').click()
    await nextTick()

    const cancel = document.querySelector<HTMLButtonElement>(
      '.modal.show .modal-footer .btn-secondary',
    )
    expect(cancel).not.toBeNull()
    cancel?.click()
    await nextTick()

    expect(
      document.querySelector('[data-id="profile-sessions-end-others-confirm"]'),
    ).toBeNull()
    expect(endOtherSessions).not.toHaveBeenCalled()
  })

  it('disables page button when there are no other sessions to end', async () => {
    setup([makeSession(1, { current: true })])
    expect(byId('profile-sessions-end-others').hasAttribute('disabled')).toBe(
      true,
    )
  })
})
