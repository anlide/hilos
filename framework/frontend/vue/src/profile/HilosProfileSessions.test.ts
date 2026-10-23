import {
  ActionError,
  createSignal,
  type ActionHandle,
  type HilosProfileSession,
  type HilosProfileSessionActions,
} from '@hilos/core/base'
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

// The fieldset the modal stands its body in; a request in flight disables it.
function bodyLock(): HTMLFieldSetElement | null {
  return document.querySelector('[data-id="modal"] .modal-body > fieldset')
}

/** The window's Cancel: the plain button of its footer. */
function cancelButton(): HTMLButtonElement {
  const found = document.querySelector<HTMLButtonElement>(
    '[data-id="modal"] .modal-footer .btn-secondary',
  )
  if (!found) throw new Error('Missing Cancel')
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
  endSessionResult: (id: number) => Promise<unknown> = () =>
    Promise.resolve({ reply: null }),
) {
  const sessions = ref(initialSessions)
  const endOtherSessions = vi.fn(() => makeHandle(endOtherResult()))
  const endSession = vi.fn((id: number) => makeHandle(endSessionResult(id)))
  const actions: HilosProfileSessionActions = {
    endSession,
    endOtherSessions,
  }
  const wrapper = mount(HilosProfileSessions, {
    props: {
      sessions: sessions.value,
      actions,
    },
    attachTo: document.body,
  })
  return { sessions, actions, endOtherSessions, endSession, wrapper }
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
    let rejectAction = true
    let rejectPending!: (reason: unknown) => void
    const { endOtherSessions } = setup(
      [makeSession(1, { current: true }), makeSession(2)],
      () =>
        rejectAction
          ? Promise.reject(
              new ActionError(
                'hilos_sessions_end_others',
                'fail',
                'Denied by policy',
              ),
            )
          : new Promise<{ reply: null }>((_resolve, reject) => {
              rejectPending = reject
            }),
    )
    byId('profile-sessions-end-others').click()
    await nextTick()

    const slot = byId('profile-sessions-end-others-error-slot')
    expect(slot.getAttribute('role')).toBe('alert')
    expect(slot.getAttribute('aria-live')).toBe('assertive')
    expect(slot.closest('[role="dialog"][aria-modal="true"]')).not.toBeNull()
    expect(
      document.querySelector('[data-id="profile-sessions-end-others-error"]'),
    ).toBeNull()
    const idle = byId('profile-sessions-end-others-error-idle')
    expect(idle.getAttribute('aria-hidden')).toBe('true')
    expect(
      slot.querySelector('[data-id="profile-sessions-end-others-error"]'),
    ).toBeNull()
    expect(slot.textContent?.trim()).toBe('')

    byId('profile-sessions-end-others-confirm').click()
    await flushPromises()
    await nextTick()

    expect(endOtherSessions).toHaveBeenCalledTimes(1)
    expect(
      document.querySelector('[data-id="profile-sessions-end-others-confirm"]'),
    ).not.toBeNull()
    const visibleError = byId('profile-sessions-end-others-error')
    expect(visibleError.textContent).toContain('Denied by policy')
    expect(visibleError.hasAttribute('role')).toBe(false)
    expect(visibleError.hasAttribute('aria-live')).toBe(false)
    expect(slot.textContent).toContain('Denied by policy')
    expect(byId('profile-sessions-end-others-error-slot')).toBe(slot)

    rejectAction = false
    byId('profile-sessions-end-others-confirm').click()
    await nextTick()

    expect(
      document.querySelector('[data-id="profile-sessions-end-others-error"]'),
    ).toBeNull()
    const idleAfter = byId('profile-sessions-end-others-error-idle')
    expect(idleAfter.getAttribute('aria-hidden')).toBe('true')
    expect(
      slot.querySelector('[data-id="profile-sessions-end-others-error"]'),
    ).toBeNull()
    expect(slot.textContent?.trim()).toBe('')
    expect(byId('profile-sessions-end-others-error-slot')).toBe(slot)

    rejectPending(
      new ActionError('hilos_sessions_end_others', 'fail', 'Try again later'),
    )
    await flushPromises()
    await nextTick()

    const nextError = byId('profile-sessions-end-others-error')
    expect(nextError.textContent).toContain('Try again later')
    expect(nextError.hasAttribute('role')).toBe(false)
    expect(nextError.hasAttribute('aria-live')).toBe(false)
    expect(slot.textContent).toContain('Try again later')
    expect(slot.textContent).not.toContain('Denied by policy')
    expect(byId('profile-sessions-end-others-error-slot')).toBe(slot)
  })

  it('keeps End session modal open and displays refusal on action failure with permanent live region', async () => {
    let rejectAction = true
    let rejectPending!: (reason: unknown) => void
    const { endSession } = setup(
      [makeSession(1, { current: true }), makeSession(2)],
      () => Promise.resolve({ reply: null }),
      () =>
        rejectAction
          ? Promise.reject(
              new ActionError(
                'hilos_sessions_end',
                'fail',
                'Failed to end session',
              ),
            )
          : new Promise<{ reply: null }>((_resolve, reject) => {
              rejectPending = reject
            }),
    )
    byId('profile-session-revoke').click()
    await nextTick()

    const slot = byId('profile-session-end-error-slot')
    expect(slot.getAttribute('role')).toBe('alert')
    expect(slot.getAttribute('aria-live')).toBe('assertive')
    expect(slot.closest('[role="dialog"][aria-modal="true"]')).not.toBeNull()
    expect(
      document.querySelector('[data-id="profile-session-end-error"]'),
    ).toBeNull()
    const idle = byId('profile-session-end-error-idle')
    expect(idle.getAttribute('aria-hidden')).toBe('true')
    expect(
      slot.querySelector('[data-id="profile-session-end-error"]'),
    ).toBeNull()
    expect(slot.textContent?.trim()).toBe('')

    byId('profile-session-end-confirm').click()
    await flushPromises()
    await nextTick()

    expect(endSession).toHaveBeenCalledWith(2)
    expect(
      document.querySelector('[data-id="profile-session-end-confirm"]'),
    ).not.toBeNull()
    const visibleError = byId('profile-session-end-error')
    expect(visibleError.textContent).toContain('Failed to end session')
    expect(visibleError.hasAttribute('role')).toBe(false)
    expect(visibleError.hasAttribute('aria-live')).toBe(false)
    expect(slot.textContent).toContain('Failed to end session')
    expect(byId('profile-session-end-error-slot')).toBe(slot)

    rejectAction = false
    byId('profile-session-end-confirm').click()
    await nextTick()

    expect(
      document.querySelector('[data-id="profile-session-end-error"]'),
    ).toBeNull()
    const idleAfter = byId('profile-session-end-error-idle')
    expect(idleAfter.getAttribute('aria-hidden')).toBe('true')
    expect(
      slot.querySelector('[data-id="profile-session-end-error"]'),
    ).toBeNull()
    expect(slot.textContent?.trim()).toBe('')
    expect(byId('profile-session-end-error-slot')).toBe(slot)

    rejectPending(
      new ActionError('hilos_sessions_end', 'fail', 'Session is still busy'),
    )
    await flushPromises()
    await nextTick()

    const nextError = byId('profile-session-end-error')
    expect(nextError.textContent).toContain('Session is still busy')
    expect(nextError.hasAttribute('role')).toBe(false)
    expect(nextError.hasAttribute('aria-live')).toBe(false)
    expect(slot.textContent).toContain('Session is still busy')
    expect(slot.textContent).not.toContain('Failed to end session')
    expect(byId('profile-session-end-error-slot')).toBe(slot)
  })

  it.each([
    {
      window: 'End session',
      open: 'profile-session-revoke',
      action: 'hilos_sessions_end',
      confirm: 'profile-session-end-confirm',
      error: 'profile-session-end-error',
    },
    {
      window: 'Sign out everywhere else',
      open: 'profile-sessions-end-others',
      action: 'hilos_sessions_end_others',
      confirm: 'profile-sessions-end-others-confirm',
      error: 'profile-sessions-end-others-error',
    },
  ])(
    'holds the $window window while it is out, and frees it after a refusal',
    async ({ open, action, confirm, error }) => {
      const answers: Array<{
        resolve(): void
        reject(reason: unknown): void
      }> = []
      const held = () =>
        new Promise<unknown>((resolve, reject) => {
          answers.push({ resolve: () => resolve({ reply: null }), reject })
        })
      setup([makeSession(1, { current: true }), makeSession(2)], held, held)
      byId(open).click()
      await nextTick()
      const body = bodyLock()
      const slot = byId(`${error}-slot`)
      const close = byId('modal-close') as HTMLButtonElement
      expect(body?.contains(slot)).toBe(true)
      expect(body?.disabled).toBe(false)
      expect(cancelButton().disabled).toBe(false)

      byId(confirm).click()
      await nextTick()
      expect(answers).toHaveLength(1)
      expect(body?.disabled).toBe(true)
      expect(cancelButton().disabled).toBe(true)
      expect(close.disabled).toBe(true)
      close.click()
      cancelButton().click()
      byId('modal').dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
      )
      byId('modal').click()
      await flushPromises()
      expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
      expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
      expect(byId(`${error}-slot`)).toBe(slot)
      expect(slot.getAttribute('role')).toBe('alert')

      answers[0]!.reject(new ActionError(action, 'fail', 'Try again later'))
      await flushPromises()
      expect(bodyLock()).toBe(body)
      expect(body?.disabled).toBe(false)
      expect(cancelButton().disabled).toBe(false)
      expect(close.disabled).toBe(false)
      expect(byId(error).textContent).toContain('Try again later')
      expect(byId(`${error}-slot`)).toBe(slot)

      byId(confirm).click()
      await nextTick()
      answers[1]!.resolve()
      await flushPromises()
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
    },
  )

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
