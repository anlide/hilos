import {
  ActionError,
  bindTimeZoneState,
  createSignal,
  type ActionHandle,
  type HilosConnection,
  type HilosProfileSession,
  type HilosProfileSessionActions,
} from '@hilos/core/base'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'

import HilosProfileSessions from './HilosProfileSessions.vue'

let releaseTimeZone: (() => void) | undefined

afterEach(() => {
  releaseTimeZone?.()
  releaseTimeZone = undefined
  vi.restoreAllMocks()
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
    browserTimeZone: null,
    createdAt: 1790762400000,
    lastSeenAt: 1790762400000,
    expiresAt: 1793354400000,
    current: options.current ?? false,
    impersonated: options.impersonated ?? false,
    tabs: [],
  }
}

function makeTab(
  acceptKey: string,
  options: { current?: boolean; connectedAt?: number } = {},
): HilosProfileSession['tabs'][number] {
  return {
    acceptKey,
    connectedAt: options.connectedAt ?? 1790762400000,
    current: options.current ?? false,
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
  it.each([
    ['Chrome on macOS', 'Europe/Kyiv', 'Chrome on macOS · Europe/Kyiv'],
    [null, 'US/Eastern', 'Unknown device · US/Eastern'],
    ['Firefox on Linux', null, 'Firefox on Linux'],
    [null, null, 'Unknown device'],
  ])(
    'shows device %s with its stored browser zone %s',
    (deviceName, browserTimeZone, caption) => {
      const { wrapper } = setup([
        { ...makeSession(1), deviceName, browserTimeZone },
      ])
      const device = wrapper.get('[data-id="profile-session-device"]')
      expect(device.text()).toBe(caption)
      expect(device.find('span.text-body-secondary').exists()).toBe(
        browserTimeZone !== null,
      )
    },
  )

  it.each([
    ['profile-session-revoke', 'profile-session-end-confirm'],
    ['profile-sessions-end-others', 'profile-sessions-end-others-confirm'],
  ])(
    'reformats numeric moments while keeping tabs and %s pending',
    async (open, confirm) => {
      let browserZone = 'Asia/Tokyo'
      const resolved = new Intl.DateTimeFormat().resolvedOptions()
      vi.spyOn(
        Intl.DateTimeFormat.prototype,
        'resolvedOptions',
      ).mockImplementation(() => ({ ...resolved, timeZone: browserZone }))
      releaseTimeZone = bindTimeZoneState({
        on: () => () => {},
      } as unknown as HilosConnection)
      let resolveAction!: () => void
      const pending = new Promise<{ reply: null }>((resolve) => {
        resolveAction = () => resolve({ reply: null })
      })
      const sessions = [
        {
          ...makeSession(1, { current: true }),
          browserTimeZone: 'US/Eastern',
          createdAt: 1767227400000,
          expiresAt: 1767313800000,
          tabs: [
            {
              acceptKey: 'tab-current',
              connectedAt: 1767227520000,
              current: true,
            },
          ],
        },
        { ...makeSession(2), lastSeenAt: 1767227460000, expiresAt: null },
      ]
      const { wrapper, endSession, endOtherSessions } = setup(
        sessions,
        () => pending,
        () => pending,
      )
      const originalSessions = wrapper.props('sessions')
      const rows = wrapper.findAll('[data-id="profile-session-row"]')
      expect(rows[0]!.text()).toContain('created 1/1/2026, 9:30:00 AM')
      expect(rows[0]!.text()).toContain('last seen now')
      expect(rows[0]!.text()).toContain('expires 1/2/2026, 9:30:00 AM')
      expect(rows[1]!.text()).toContain('last seen 1/1/2026, 9:31:00 AM')
      expect(rows[1]!.text()).toContain('expires never')
      await rows[0]!.get('[data-id="profile-session-tabs"]').trigger('click')
      const tab = byId('profile-session-tab')
      expect(tab.textContent).toContain('opened 1/1/2026, 9:32:00 AM')
      byId(open).click()
      await nextTick()
      const modal = byId('modal')
      byId(confirm).click()
      await nextTick()
      expect(bodyLock()?.disabled).toBe(true)

      browserZone = 'America/New_York'
      window.dispatchEvent(new Event('focus'))
      await nextTick()

      expect(wrapper.props('sessions')).toBe(originalSessions)
      expect(rows[0]!.text()).toContain('created 12/31/2025, 7:30:00 PM')
      expect(rows[0]!.text()).toContain('expires 1/1/2026, 7:30:00 PM')
      expect(rows[1]!.text()).toContain('last seen 12/31/2025, 7:31:00 PM')
      expect(byId('profile-session-tab')).toBe(tab)
      expect(tab.textContent).toContain('opened 12/31/2025, 7:32:00 PM')
      expect(
        rows[0]!
          .get('[data-id="profile-session-tabs"]')
          .attributes('aria-expanded'),
      ).toBe('true')
      expect(rows[0]!.get('[data-id="profile-session-device"]').text()).toBe(
        'Device 1 · US/Eastern',
      )
      expect(byId('modal')).toBe(modal)
      expect(bodyLock()?.disabled).toBe(true)
      expect(endSession).toHaveBeenCalledTimes(
        open === 'profile-session-revoke' ? 1 : 0,
      )
      expect(endOtherSessions).toHaveBeenCalledTimes(
        open === 'profile-sessions-end-others' ? 1 : 0,
      )
      resolveAction()
      await flushPromises()
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
    },
  )

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

  it('keeps the intro and drops the cluster lag note', () => {
    const { wrapper } = setup([])
    const intro = wrapper.get('p')
    expect(intro.classes()).toEqual(['small', 'text-body-secondary'])
    expect(intro.text()).toBe(
      'A session is a browser that signed in. Its open tabs are listed inside it. Ending a session takes effect at once.',
    )
    expect(wrapper.text()).toContain('No sessions.')
    expect(wrapper.find('[data-id="profile-session-row"]').exists()).toBe(false)
    expect(wrapper.find('.alert').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('can lag behind')
    expect(wrapper.get('section').attributes('aria-label')).toBe('Sessions')
    expect(wrapper.find('h1').exists()).toBe(false)
    const endOthers = wrapper.get('[data-id="profile-sessions-end-others"]')
    expect(endOthers.classes()).toEqual([
      'btn',
      'btn-outline-danger',
      'btn-sm',
      'mt-3',
    ])
    expect(endOthers.attributes('disabled')).toBeDefined()
  })

  it('paints ordinary, current, and impersonated rows from their flags', () => {
    const { wrapper } = setup([
      makeSession(1),
      makeSession(2, { current: true }),
      makeSession(3, { impersonated: true }),
      makeSession(4, { current: true, impersonated: true }),
    ])
    expect(wrapper.find('.alert-info').exists()).toBe(false)
    expect(wrapper.find('.card').exists()).toBe(false)
    expect(wrapper.find('.card-body').exists()).toBe(false)
    const rows = wrapper.findAll('[data-id="profile-session-row"]')
    expect(rows).toHaveLength(4)

    const ordinary = rows[0]!
    expect(ordinary.classes()).toEqual(['border-bottom'])
    expect(ordinary.attributes('style')).toBeUndefined()
    expect(ordinary.get('.d-flex').classes()).toEqual([
      'd-flex',
      'flex-wrap',
      'align-items-center',
      'gap-2',
      'py-3',
    ])
    expect(ordinary.get('i.bi').classes()).toEqual([
      'bi',
      'bi-window',
      'fs-5',
      'text-body-secondary',
    ])
    expect(ordinary.get('i.bi').attributes('aria-hidden')).toBe('true')
    expect(ordinary.get('.flex-grow-1').classes()).toEqual([
      'flex-grow-1',
      'text-break',
    ])
    expect(ordinary.get('h3').text()).toBe('Session #1')
    expect(ordinary.find('[data-id="profile-session-this"]').exists()).toBe(
      false,
    )
    expect(
      ordinary.find('[data-id="profile-session-impersonated"]').exists(),
    ).toBe(false)
    expect(
      [...ordinary.element.querySelectorAll('button')].map((button) =>
        button.getAttribute('data-id'),
      ),
    ).toEqual(['profile-session-tabs', 'profile-session-revoke'])

    const current = rows[1]!
    expect(current.get('i.bi').classes()).toContain('bi-window')
    const thisBadge = current.get('h3').get('[data-id="profile-session-this"]')
    expect(thisBadge.text()).toBe('this one')
    expect(thisBadge.classes()).toEqual(['badge', 'text-bg-success', 'ms-1'])
    expect(
      current.find('[data-id="profile-session-impersonated"]').exists(),
    ).toBe(false)
    expect(current.find('[data-id="profile-session-revoke"]').exists()).toBe(
      false,
    )

    const impersonated = rows[2]!
    expect(impersonated.get('i.bi').classes()).toEqual([
      'bi',
      'bi-people-fill',
      'fs-5',
      'text-warning-emphasis',
    ])
    expect(impersonated.get('i.bi').attributes('aria-hidden')).toBe('true')
    const adminBadge = impersonated
      .get('h3')
      .get('[data-id="profile-session-impersonated"]')
    expect(adminBadge.text()).toBe('an administrator is working as you')
    expect(adminBadge.classes()).toEqual([
      'badge',
      'bg-warning-subtle',
      'text-warning-emphasis',
      'ms-1',
    ])
    expect(impersonated.find('[data-id="profile-session-this"]').exists()).toBe(
      false,
    )
    expect(
      impersonated.find('[data-id="profile-session-revoke"]').exists(),
    ).toBe(false)

    const both = rows[3]!
    expect(both.get('i.bi').classes()).toContain('bi-people-fill')
    expect(
      both.get('h3').find('[data-id="profile-session-this"]').exists(),
    ).toBe(true)
    expect(
      both.get('h3').find('[data-id="profile-session-impersonated"]').exists(),
    ).toBe(true)
    expect(both.find('[data-id="profile-session-revoke"]').exists()).toBe(false)
  })

  it('toggles one row and keeps the other open when the tab list changes', async () => {
    const first = {
      ...makeSession(1),
      tabs: [makeTab('tab-a')],
    }
    const second = {
      ...makeSession(2),
      tabs: [makeTab('tab-b'), makeTab('tab-c')],
    }
    const { wrapper } = setup([first, second])
    const button = (index: number) =>
      wrapper
        .findAll('[data-id="profile-session-row"]')
        [index]!.get('[data-id="profile-session-tabs"]')

    expect(button(0).attributes('aria-expanded')).toBe('false')
    expect(button(0).get('i').classes()).toEqual(['bi', 'bi-caret-right-fill'])
    expect(button(0).get('i').attributes('aria-hidden')).toBe('true')
    expect(button(0).text()).toBe('tabs: 1')
    expect(button(0).classes()).toEqual([
      'btn',
      'btn-link',
      'btn-sm',
      'p-0',
      'text-decoration-none',
    ])

    await button(0).trigger('click')
    expect(button(0).attributes('aria-expanded')).toBe('true')
    expect(button(0).get('i').classes()).toEqual(['bi', 'bi-caret-down-fill'])
    expect(button(1).attributes('aria-expanded')).toBe('false')
    expect(button(1).get('i').classes()).toContain('bi-caret-right-fill')

    await button(1).trigger('click')
    expect(button(0).attributes('aria-expanded')).toBe('true')
    expect(button(1).attributes('aria-expanded')).toBe('true')
    expect(button(1).text()).toBe('tabs: 2')

    await button(0).trigger('click')
    expect(button(0).attributes('aria-expanded')).toBe('false')
    expect(button(0).get('i').classes()).toContain('bi-caret-right-fill')
    expect(button(1).attributes('aria-expanded')).toBe('true')
    expect(button(1).get('i').classes()).toContain('bi-caret-down-fill')

    await wrapper.setProps({
      sessions: [{ ...first, tabs: [...first.tabs, makeTab('tab-d')] }, second],
    })
    expect(button(0).attributes('aria-expanded')).toBe('false')
    expect(button(1).attributes('aria-expanded')).toBe('true')
    expect(button(0).text()).toBe('tabs: 2')
    expect(button(1).text()).toBe('tabs: 2')
    const openTabs = wrapper.findAll('[data-id="profile-session-tab"]')
    expect(openTabs).toHaveLength(2)
    expect(openTabs[0]!.get('code').text()).toBe('tab-b')
    expect(openTabs[1]!.get('code').text()).toBe('tab-c')
  })

  it('explains an expanded session that has no open tabs', async () => {
    const { wrapper } = setup([makeSession(4)])
    await wrapper.get('[data-id="profile-session-tabs"]').trigger('click')
    const panel = wrapper.get('.bg-body-tertiary')
    expect(panel.classes()).toEqual([
      'bg-body-tertiary',
      'rounded-2',
      'mb-2',
      'px-2',
      'py-1',
    ])
    expect(panel.get('p').text()).toBe(
      'No open tabs: the sign-in stays valid, but the browser is closed right now.',
    )
    expect(panel.find('[data-id="profile-session-tab"]').exists()).toBe(false)
    expect(panel.find('ul').exists()).toBe(false)
  })

  it('shortens a long tab key for display and keeps each full key', async () => {
    const resolved = new Intl.DateTimeFormat().resolvedOptions()
    vi.spyOn(
      Intl.DateTimeFormat.prototype,
      'resolvedOptions',
    ).mockImplementation(() => ({ ...resolved, timeZone: 'Asia/Tokyo' }))
    releaseTimeZone = bindTimeZoneState({
      on: () => () => {},
    } as unknown as HilosConnection)
    const longKey = 'x3Jb123456789f2a'
    const sameEdges = 'x3Jb0000000009f2a'
    const four = 'ab12'
    const eight = 'abcd1234'
    const { wrapper } = setup([
      {
        ...makeSession(1, { current: true }),
        tabs: [
          makeTab(longKey, { current: true, connectedAt: 1767227520000 }),
          makeTab(sameEdges, { connectedAt: 1767227580000 }),
          makeTab(four, { connectedAt: 1767227640000 }),
          makeTab(eight, { connectedAt: 1767227700000 }),
        ],
      },
    ])
    await wrapper.get('[data-id="profile-session-tabs"]').trigger('click')
    const tabs = wrapper.findAll('[data-id="profile-session-tab"]')
    expect(tabs).toHaveLength(4)
    expect(tabs[0]!.get('code').text()).toBe('x3Jb…9f2a')
    expect(tabs[1]!.get('code').text()).toBe('x3Jb…9f2a')
    expect(tabs[2]!.get('code').text()).toBe(four)
    expect(tabs[3]!.get('code').text()).toBe(eight)
    expect(tabs[0]!.get('code').text()).not.toContain('2026')
    expect(tabs[0]!.findAll('span.small.text-body-secondary')[0]!.text()).toBe(
      'opened 1/1/2026, 9:32:00 AM',
    )
    expect(tabs[1]!.findAll('span.small.text-body-secondary')[0]!.text()).toBe(
      'opened 1/1/2026, 9:33:00 AM',
    )

    const plug = tabs[0]!.get('i')
    expect(plug.classes()).toEqual(['bi', 'bi-plug', 'text-body-secondary'])
    expect(plug.attributes('aria-hidden')).toBe('true')
    expect(tabs[0]!.classes()).toEqual([
      'd-flex',
      'flex-wrap',
      'align-items-center',
      'gap-2',
      'py-1',
    ])
    expect(tabs[0]!.classes()).not.toContain('list-group-item')
    const list = wrapper.get('ul')
    expect(list.classes()).toEqual(['list-unstyled', 'mb-0'])
    expect(list.element.parentElement).toBe(
      wrapper.get('.bg-body-tertiary').element,
    )

    const thisTab = tabs[0]!.get('[data-id="profile-session-tab-this"]')
    expect(thisTab.text()).toBe('this tab')
    expect(tabs[0]!.element.textContent?.replace(/\s+/g, ' ').trim()).toContain(
      'opened 1/1/2026, 9:32:00 AM this tab',
    )
    expect(thisTab.classes()).toEqual(['small', 'text-body-secondary'])
    expect(thisTab.classes()).not.toContain('badge')
    expect(tabs[1]!.find('[data-id="profile-session-tab-this"]').exists()).toBe(
      false,
    )
    expect(tabs[2]!.find('[data-id="profile-session-tab-this"]').exists()).toBe(
      false,
    )
    expect(tabs[3]!.find('[data-id="profile-session-tab-this"]').exists()).toBe(
      false,
    )
  })

  it('opens Revoke without ending the session and leaves it on Cancel', async () => {
    const { endSession } = setup()
    byId('profile-session-revoke').click()
    await nextTick()
    expect(
      document.querySelector('[data-id="profile-session-end-confirm"]'),
    ).not.toBeNull()
    expect(endSession).not.toHaveBeenCalled()

    cancelButton().click()
    await nextTick()
    expect(
      document.querySelector('[data-id="profile-session-end-confirm"]'),
    ).toBeNull()
    expect(endSession).not.toHaveBeenCalled()
  })

  it('says the session already ended when it leaves while its window is open', async () => {
    const { wrapper, endSession } = setup([
      makeSession(1, { current: true }),
      makeSession(2),
    ])
    byId('profile-session-revoke').click()
    await nextTick()

    await wrapper.setProps({
      sessions: [makeSession(1, { current: true })],
    })
    expect(byId('profile-session-end-error').textContent).toContain(
      'This session has already ended',
    )
    expect(byId('profile-session-end-confirm').hasAttribute('disabled')).toBe(
      true,
    )
    byId('profile-session-end-confirm').click()
    await nextTick()
    expect(endSession).not.toHaveBeenCalled()
  })
})
