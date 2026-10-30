import { By } from '@angular/platform-browser'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionError,
  createSignal,
  formatProfileDateTime,
  type ActionHandle,
  type HilosProfileSession,
  type HilosProfileSessionActions,
} from '@hilos/core'
import { type ModalController } from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { HilosProfileSessions } from '../src/profile/HilosProfileSessions.js'
import { HilosModal } from '../src/HilosModal.js'
import {
  bindTestTimeZone,
  type TimeZoneTestBinding,
} from './timeZoneTestHarness.js'

const BROWSER_TIME_ZONE = new Intl.DateTimeFormat().resolvedOptions().timeZone
let timeZoneBinding: TimeZoneTestBinding | null = null

afterEach(() => {
  if (timeZoneBinding !== null) {
    timeZoneBinding.receive(null)
    timeZoneBinding.release()
    timeZoneBinding = null
  }
  vi.restoreAllMocks()
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

async function settle(fixture: ComponentFixture<unknown>): Promise<void> {
  await fixture.whenStable()
  fixture.detectChanges()
}

async function flush(fixture: ComponentFixture<unknown>): Promise<void> {
  for (let tick = 0; tick < 10; tick += 1) await Promise.resolve()
  fixture.detectChanges()
}

function held<T>(): {
  promise: Promise<T>
  reject: (reason: unknown) => void
} {
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((_resolve, refuse) => {
    reject = refuse
  })

  return { promise, reject }
}

function byId(id: string): HTMLElement {
  const found = document.querySelector<HTMLElement>(`[data-id="${id}"]`)
  if (!found) throw new Error(`Missing ${id}`)
  return found
}

function classes(element: Element): string[] {
  return [...element.classList]
}

function sessionRows(): HTMLElement[] {
  return [
    ...document.querySelectorAll<HTMLElement>(
      '[data-id="profile-session-row"]',
    ),
  ]
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

function makeSession(
  id: number,
  options: {
    current?: boolean
    impersonated?: boolean
    browserTimeZone?: string | null
  } = {},
): HilosProfileSession {
  return {
    id,
    deviceName: `Device ${id}`,
    browserTimeZone: options.browserTimeZone ?? null,
    createdAt: 1790762400000,
    lastSeenAt: 1790762400000,
    expiresAt: 1793354400000,
    current: options.current ?? false,
    impersonated: options.impersonated ?? false,
    tabs: [],
  }
}

function bindReaderTimeZone(): TimeZoneTestBinding {
  timeZoneBinding = bindTestTimeZone(BROWSER_TIME_ZONE)
  return timeZoneBinding
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
  endSessionResult: () => Promise<unknown> = () =>
    Promise.resolve({ reply: null }),
) {
  const endSession = vi.fn(() => makeHandle(endSessionResult()))
  const endOtherSessions = vi.fn(() => makeHandle(endOtherResult()))
  const actions: HilosProfileSessionActions = {
    endSession,
    endOtherSessions,
  }
  const fixture = TestBed.createComponent(HilosProfileSessions)
  fixture.componentRef.setInput('sessions', initialSessions)
  fixture.componentRef.setInput('actions', actions)
  fixture.detectChanges()

  return { actions, endOtherSessions, endSession, fixture }
}

async function open(fixture: ComponentFixture<unknown>): Promise<void> {
  byId('profile-sessions-end-others').click()
  await settle(fixture)
}

function attemptClose(
  fixture: ComponentFixture<unknown>,
  cancel: HTMLButtonElement,
): void {
  byId('modal-close').click()
  cancel.click()
  byId('modal').dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
  )
  byId('modal').click()
  const modal = fixture.debugElement.query(By.directive(HilosModal))
    .componentInstance as unknown as { modal: ModalController }
  modal.modal.requestClose()
  fixture.detectChanges()
}

describe('HilosProfileSessions (Angular)', () => {
  it('formats a live tab directly from epoch milliseconds', async () => {
    const { fixture } = setup([
      {
        ...makeSession(1, { current: true }),
        tabs: [
          { acceptKey: 'ak-ms', connectedAt: 1710000000123, current: false },
        ],
      },
    ])
    byId('profile-session-tabs').click()
    await settle(fixture)
    const tab = byId('profile-session-tab')
    expect(tab.querySelector('code')?.textContent).toBe('ak-ms')
    expect(
      tab.querySelector('span.small.text-body-secondary')?.textContent,
    ).toBe(`opened ${formatProfileDateTime(1710000000123)}`)
  })

  it('shows a stored browser time zone only when the session has one', () => {
    setup([makeSession(1, { browserTimeZone: 'Europe/Kyiv' }), makeSession(2)])

    const firstDevice = sessionRows()[0]!.querySelector(
      '[data-id="profile-session-device"]',
    )!
    expect(firstDevice.textContent?.replace(/\s+/g, ' ').trim()).toBe(
      'Device 1 · Europe/Kyiv',
    )
    expect(firstDevice.querySelector('span')?.className).toBe(
      'text-body-secondary',
    )

    const secondDevice = sessionRows()[1]!.querySelector(
      '[data-id="profile-session-device"]',
    )!
    expect(secondDevice.textContent?.trim()).toBe('Device 2')
    expect(secondDevice.querySelector('span')).toBeNull()
  })

  it('reformats session and tab moments when the reader zone changes with UI open', async () => {
    const timeZone = bindReaderTimeZone()
    timeZone.receive('Asia/Tokyo')
    const session = {
      ...makeSession(2, { browserTimeZone: 'Europe/Kyiv' }),
      createdAt: 0,
      expiresAt: null,
      tabs: [makeTab('tab-zone', { connectedAt: 0 })],
    }
    const { fixture } = setup([makeSession(1, { current: true }), session])
    const row = sessionRows()[1]!
    const tabsButton = row.querySelector<HTMLButtonElement>(
      '[data-id="profile-session-tabs"]',
    )!
    tabsButton.click()
    await settle(fixture)

    const sessionDate = row.querySelector(
      '.flex-grow-1 > .small.text-body-secondary',
    )!
    const tab = row.querySelector('[data-id="profile-session-tab"]')!
    const tabDate = tab.querySelector('span.small.text-body-secondary')!
    const device = row.querySelector('[data-id="profile-session-device"]')!
    const deviceZone = device.querySelector('span')!
    const initialSessionDate = sessionDate.textContent
    const initialTabDate = tabDate.textContent
    expect(sessionDate.textContent).toContain(
      `created ${formatProfileDateTime(0)}`,
    )
    expect(sessionDate.textContent).toContain('expires never')
    expect(tabDate.textContent).toContain(`opened ${formatProfileDateTime(0)}`)

    row
      .querySelector<HTMLButtonElement>('[data-id="profile-session-revoke"]')!
      .click()
    await settle(fixture)
    const confirm = byId('profile-session-end-confirm')
    const dialog = confirm.closest('[role="dialog"]')!
    expect(dialog).not.toBeNull()

    timeZone.receive('America/Los_Angeles')
    await settle(fixture)

    expect(sessionRows()[1]).toBe(row)
    expect(row.querySelector('.flex-grow-1 > .small.text-body-secondary')).toBe(
      sessionDate,
    )
    expect(sessionDate.textContent).not.toBe(initialSessionDate)
    expect(sessionDate.textContent).toContain(
      `created ${formatProfileDateTime(0)}`,
    )
    expect(sessionDate.textContent).toContain('expires never')
    expect(row.querySelector('[data-id="profile-session-tab"]')).toBe(tab)
    expect(tab.querySelector('span.small.text-body-secondary')).toBe(tabDate)
    expect(tabDate.textContent).not.toBe(initialTabDate)
    expect(tabDate.textContent).toContain(`opened ${formatProfileDateTime(0)}`)
    expect(tabsButton.getAttribute('aria-expanded')).toBe('true')
    expect(row.querySelector('[data-id="profile-session-device"]')).toBe(device)
    expect(device.querySelector('span')).toBe(deviceZone)
    expect(deviceZone.textContent).toBe('· Europe/Kyiv')
    expect(byId('profile-session-end-confirm')).toBe(confirm)
    expect(confirm.closest('[role="dialog"]')).toBe(dialog)
  })

  it('opens confirmation modal without calling endOtherSessions on button click', async () => {
    const { endOtherSessions, fixture } = setup()
    expect(
      document.querySelector('[data-id="profile-sessions-end-others-confirm"]'),
    ).toBeNull()

    await open(fixture)

    expect(
      document.querySelector('[data-id="profile-sessions-end-others-confirm"]'),
    ).not.toBeNull()
    expect(endOtherSessions).not.toHaveBeenCalled()
  })

  it('shows count 1 when sessions include this one, an impersonated session, and one regular session', async () => {
    const { fixture } = setup([
      makeSession(1, { current: true }),
      makeSession(2, { impersonated: true }),
      makeSession(3),
    ])
    await open(fixture)

    expect(byId('profile-sessions-end-others-count').textContent?.trim()).toBe(
      '1',
    )
  })

  it('reactively updates count under the open modal and disables confirm when all other sessions end', async () => {
    const { actions, fixture } = setup()
    await open(fixture)
    expect(byId('profile-sessions-end-others-count').textContent?.trim()).toBe(
      '1',
    )

    fixture.componentRef.setInput('sessions', [
      makeSession(1, { current: true }),
      makeSession(2),
      makeSession(3),
    ])
    await settle(fixture)
    expect(byId('profile-sessions-end-others-count').textContent?.trim()).toBe(
      '2',
    )

    fixture.componentRef.setInput('sessions', [
      makeSession(1, { current: true }),
    ])
    await settle(fixture)
    expect(byId('profile-sessions-end-others-count').textContent?.trim()).toBe(
      '0',
    )
    expect(byId('profile-sessions-end-others-error').textContent).toContain(
      'Your other sessions have already ended',
    )
    expect(
      byId('profile-sessions-end-others-confirm').hasAttribute('disabled'),
    ).toBe(true)
    expect(actions.endOtherSessions).not.toHaveBeenCalled()
  })

  it('calls endOtherSessions once on Sign out and closes modal on success', async () => {
    const { endOtherSessions, fixture } = setup()
    await open(fixture)

    byId('profile-sessions-end-others-confirm').click()
    await settle(fixture)

    expect(endOtherSessions).toHaveBeenCalledTimes(1)
    expect(
      document.querySelector('[data-id="profile-sessions-end-others-confirm"]'),
    ).toBeNull()
  })

  it.each(['one session', 'other sessions'] as const)(
    'holds the %s dialog through the action, refusal, and retry',
    async (scope) => {
      const response = held<unknown>()
      let next = () => response.promise
      const { endOtherSessions, endSession, fixture } = setup(
        [makeSession(1, { current: true }), makeSession(2)],
        () => next(),
        () => next(),
      )
      const isSingle = scope === 'one session'
      if (isSingle) byId('profile-session-revoke').click()
      else await open(fixture)
      await settle(fixture)

      const dialog = byId('modal')
      const cancel = dialog.querySelector<HTMLButtonElement>(
        '.modal-footer .btn-secondary',
      )!
      const body = dialog.querySelector('.modal-body > fieldset')!
      const confirmId = isSingle
        ? 'profile-session-end-confirm'
        : 'profile-sessions-end-others-confirm'
      const confirm = byId(confirmId) as HTMLButtonElement
      confirm.click()
      await flush(fixture)

      expect(body.matches(':disabled')).toBe(true)
      expect(cancel.matches(':disabled')).toBe(true)
      expect(byId('modal-close').matches(':disabled')).toBe(true)
      expect(confirm.getAttribute('aria-busy')).toBe('true')
      attemptClose(fixture, cancel)
      expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
      expect(byId('modal')).toBe(dialog)
      confirm.click()
      await flush(fixture)
      expect(isSingle ? endSession : endOtherSessions).toHaveBeenCalledTimes(1)

      const message = isSingle ? 'Session is still busy' : 'Try again later'
      response.reject(
        new ActionError(
          isSingle ? 'hilos_sessions_end' : 'hilos_sessions_end_others',
          'fail',
          message,
        ),
      )
      await settle(fixture)

      expect(byId('modal')).toBe(dialog)
      expect(dialog.querySelector('.modal-body > fieldset')).toBe(body)
      expect(body.matches(':disabled')).toBe(false)
      expect(cancel.matches(':disabled')).toBe(false)
      expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
      const errorId = isSingle
        ? 'profile-session-end-error'
        : 'profile-sessions-end-others-error'
      expect(byId(errorId).textContent).toContain(message)

      next = () => Promise.resolve({ reply: null })
      byId(confirmId).click()
      await settle(fixture)
      expect(isSingle ? endSession : endOtherSessions).toHaveBeenCalledTimes(2)
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
    },
  )

  it('keeps End other sessions modal open and displays refusal on action failure with permanent live region', async () => {
    let rejectAction = true
    let rejectPending!: (reason: unknown) => void
    const { endOtherSessions, fixture } = setup(
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
    await open(fixture)

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
    await settle(fixture)

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
    await settle(fixture)

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
    await settle(fixture)

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
    const { endSession, fixture } = setup(
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
    await settle(fixture)

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
    await settle(fixture)

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
    await settle(fixture)

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
    await settle(fixture)

    const nextError = byId('profile-session-end-error')
    expect(nextError.textContent).toContain('Session is still busy')
    expect(nextError.hasAttribute('role')).toBe(false)
    expect(nextError.hasAttribute('aria-live')).toBe(false)
    expect(slot.textContent).toContain('Session is still busy')
    expect(slot.textContent).not.toContain('Failed to end session')
    expect(byId('profile-session-end-error-slot')).toBe(slot)
  })

  it('closes modal on Cancel without calling endOtherSessions', async () => {
    const { endOtherSessions, fixture } = setup()
    await open(fixture)

    const cancel = document.querySelector<HTMLButtonElement>(
      '.modal.show .modal-footer .btn-secondary',
    )
    expect(cancel).not.toBeNull()
    cancel?.click()
    await settle(fixture)

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

  it('keeps the intro and drops the cluster lag note', () => {
    setup([])
    const section = document.querySelector('section')
    expect(section?.getAttribute('aria-label')).toBe('Sessions')
    expect(document.querySelector('h1')).toBeNull()
    const intro = section?.querySelector('p')
    expect(classes(intro!)).toEqual(['small', 'text-body-secondary'])
    expect(intro?.textContent?.trim()).toBe(
      'A session is a browser that signed in. Its open tabs are listed inside it. Ending a session takes effect at once.',
    )
    expect(section?.textContent).toContain('No sessions.')
    expect(document.querySelector('[data-id="profile-session-row"]')).toBeNull()
    expect(document.querySelector('.alert')).toBeNull()
    expect(section?.textContent).not.toContain('can lag behind')
    const endOthers = byId('profile-sessions-end-others')
    expect(classes(endOthers)).toEqual([
      'btn',
      'btn-outline-danger',
      'btn-sm',
      'mt-3',
    ])
    expect(endOthers.hasAttribute('disabled')).toBe(true)
  })

  it('paints ordinary, current, and impersonated rows from their flags', () => {
    setup([
      makeSession(1),
      makeSession(2, { current: true }),
      makeSession(3, { impersonated: true }),
      makeSession(4, { current: true, impersonated: true }),
    ])
    expect(document.querySelector('.alert-info')).toBeNull()
    expect(document.querySelector('.card')).toBeNull()
    expect(document.querySelector('.card-body')).toBeNull()
    const rows = sessionRows()
    expect(rows).toHaveLength(4)

    const ordinary = rows[0]!
    expect(classes(ordinary)).toEqual(['border-bottom'])
    expect(ordinary.getAttribute('style')).toBeNull()
    expect(classes(ordinary.querySelector('.d-flex')!)).toEqual([
      'd-flex',
      'flex-wrap',
      'align-items-center',
      'gap-2',
      'py-3',
    ])
    expect(classes(ordinary.querySelector('i.bi')!)).toEqual([
      'bi',
      'bi-window',
      'fs-5',
      'text-body-secondary',
    ])
    expect(ordinary.querySelector('i.bi')?.getAttribute('aria-hidden')).toBe(
      'true',
    )
    expect(classes(ordinary.querySelector('.flex-grow-1')!)).toEqual([
      'flex-grow-1',
      'text-break',
    ])
    expect(ordinary.querySelector('h3')?.textContent?.trim()).toBe('Session #1')
    expect(
      ordinary
        .querySelector('[data-id="profile-session-device"]')
        ?.textContent?.trim(),
    ).toBe('Device 1')
    expect(
      ordinary.querySelector('[data-id="profile-session-this"]'),
    ).toBeNull()
    expect(
      ordinary.querySelector('[data-id="profile-session-impersonated"]'),
    ).toBeNull()
    expect(
      [...ordinary.querySelectorAll('button')].map((button) =>
        button.getAttribute('data-id'),
      ),
    ).toEqual(['profile-session-tabs', 'profile-session-revoke'])

    const current = rows[1]!
    expect(classes(current.querySelector('i.bi')!)).toContain('bi-window')
    const thisBadge = current
      .querySelector('h3')
      ?.querySelector('[data-id="profile-session-this"]')
    expect(thisBadge?.textContent).toBe('this one')
    expect(classes(thisBadge!)).toEqual(['badge', 'text-bg-success', 'ms-1'])
    expect(
      current.querySelector('[data-id="profile-session-impersonated"]'),
    ).toBeNull()
    expect(
      current.querySelector('[data-id="profile-session-revoke"]'),
    ).toBeNull()

    const impersonated = rows[2]!
    expect(classes(impersonated.querySelector('i.bi')!)).toEqual([
      'bi',
      'bi-people-fill',
      'fs-5',
      'text-warning-emphasis',
    ])
    expect(
      impersonated.querySelector('i.bi')?.getAttribute('aria-hidden'),
    ).toBe('true')
    const adminBadge = impersonated
      .querySelector('h3')
      ?.querySelector('[data-id="profile-session-impersonated"]')
    expect(adminBadge?.textContent).toBe('an administrator is working as you')
    expect(classes(adminBadge!)).toEqual([
      'badge',
      'bg-warning-subtle',
      'text-warning-emphasis',
      'ms-1',
    ])
    expect(
      impersonated.querySelector('[data-id="profile-session-this"]'),
    ).toBeNull()
    expect(
      impersonated.querySelector('[data-id="profile-session-revoke"]'),
    ).toBeNull()

    const both = rows[3]!
    expect(classes(both.querySelector('i.bi')!)).toContain('bi-people-fill')
    expect(
      both
        .querySelector('h3')
        ?.querySelector('[data-id="profile-session-this"]'),
    ).not.toBeNull()
    expect(
      both
        .querySelector('h3')
        ?.querySelector('[data-id="profile-session-impersonated"]'),
    ).not.toBeNull()
    expect(both.querySelector('[data-id="profile-session-revoke"]')).toBeNull()
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
    const { fixture } = setup([first, second])
    const button = (index: number): HTMLButtonElement =>
      sessionRows()[index]!.querySelector('[data-id="profile-session-tabs"]')!

    expect(button(0).getAttribute('aria-expanded')).toBe('false')
    expect(classes(button(0).querySelector('i')!)).toEqual([
      'bi',
      'bi-caret-right-fill',
    ])
    expect(button(0).querySelector('i')?.getAttribute('aria-hidden')).toBe(
      'true',
    )
    expect(button(0).textContent?.trim()).toBe('tabs: 1')
    expect(classes(button(0))).toEqual([
      'btn',
      'btn-link',
      'btn-sm',
      'p-0',
      'text-decoration-none',
    ])

    button(0).click()
    await settle(fixture)
    expect(button(0).getAttribute('aria-expanded')).toBe('true')
    expect(classes(button(0).querySelector('i')!)).toEqual([
      'bi',
      'bi-caret-down-fill',
    ])
    expect(button(1).getAttribute('aria-expanded')).toBe('false')
    expect(classes(button(1).querySelector('i')!)).toContain(
      'bi-caret-right-fill',
    )

    button(1).click()
    await settle(fixture)
    expect(button(0).getAttribute('aria-expanded')).toBe('true')
    expect(button(1).getAttribute('aria-expanded')).toBe('true')
    expect(button(1).textContent?.trim()).toBe('tabs: 2')

    button(0).click()
    await settle(fixture)
    expect(button(0).getAttribute('aria-expanded')).toBe('false')
    expect(classes(button(0).querySelector('i')!)).toContain(
      'bi-caret-right-fill',
    )
    expect(button(1).getAttribute('aria-expanded')).toBe('true')
    expect(classes(button(1).querySelector('i')!)).toContain(
      'bi-caret-down-fill',
    )

    fixture.componentRef.setInput('sessions', [
      { ...first, tabs: [...first.tabs, makeTab('tab-d')] },
      second,
    ])
    await settle(fixture)
    expect(button(0).getAttribute('aria-expanded')).toBe('false')
    expect(button(1).getAttribute('aria-expanded')).toBe('true')
    expect(button(0).textContent?.trim()).toBe('tabs: 2')
    expect(button(1).textContent?.trim()).toBe('tabs: 2')
    const openTabs = [
      ...document.querySelectorAll<HTMLElement>(
        '[data-id="profile-session-tab"]',
      ),
    ]
    expect(openTabs).toHaveLength(2)
    expect(openTabs[0]!.querySelector('code')?.textContent).toBe('tab-b')
    expect(openTabs[1]!.querySelector('code')?.textContent).toBe('tab-c')
  })

  it('explains an expanded session that has no open tabs', async () => {
    const { fixture } = setup([makeSession(4)])
    byId('profile-session-tabs').click()
    await settle(fixture)
    const panel = document.querySelector('.bg-body-tertiary')
    expect(classes(panel!)).toEqual([
      'bg-body-tertiary',
      'rounded-2',
      'mb-2',
      'px-2',
      'py-1',
    ])
    expect(panel?.querySelector('p')?.textContent?.trim()).toBe(
      'No open tabs: the sign-in stays valid, but the browser is closed right now.',
    )
    expect(panel?.querySelector('[data-id="profile-session-tab"]')).toBeNull()
    expect(panel?.querySelector('ul')).toBeNull()
  })

  it('shortens a long tab key for display and keeps each full key', async () => {
    const longKey = 'x3Jb123456789f2a'
    const sameEdges = 'x3Jb0000000009f2a'
    const four = 'ab12'
    const eight = 'abcd1234'
    const { fixture } = setup([
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
    byId('profile-session-tabs').click()
    await settle(fixture)
    const tabs = [
      ...document.querySelectorAll<HTMLElement>(
        '[data-id="profile-session-tab"]',
      ),
    ]
    expect(tabs).toHaveLength(4)
    expect(tabs[0]!.querySelector('code')?.textContent).toBe('x3Jb…9f2a')
    expect(tabs[1]!.querySelector('code')?.textContent).toBe('x3Jb…9f2a')
    expect(tabs[2]!.querySelector('code')?.textContent).toBe(four)
    expect(tabs[3]!.querySelector('code')?.textContent).toBe(eight)
    expect(tabs[0]!.querySelector('code')?.textContent).not.toBe(longKey)
    expect(
      tabs[0]!.querySelector('span.small.text-body-secondary')?.textContent,
    ).toBe(`opened ${formatProfileDateTime(1767227520000)}`)
    expect(
      tabs[1]!.querySelector('span.small.text-body-secondary')?.textContent,
    ).toBe(`opened ${formatProfileDateTime(1767227580000)}`)

    const plug = tabs[0]!.querySelector('i')
    expect(classes(plug!)).toEqual(['bi', 'bi-plug', 'text-body-secondary'])
    expect(plug?.getAttribute('aria-hidden')).toBe('true')
    expect(classes(tabs[0]!)).toEqual([
      'd-flex',
      'flex-wrap',
      'align-items-center',
      'gap-2',
      'py-1',
    ])
    expect(classes(tabs[0]!)).not.toContain('list-group-item')
    const list = document.querySelector('ul')
    expect(classes(list!)).toEqual(['list-unstyled', 'mb-0'])
    expect(list?.parentElement).toBe(
      document.querySelector('.bg-body-tertiary'),
    )

    const thisTab = tabs[0]!.querySelector(
      '[data-id="profile-session-tab-this"]',
    )
    expect(thisTab?.textContent).toBe('this tab')
    expect(tabs[0]!.textContent?.replace(/\s+/g, ' ').trim()).toContain(
      `opened ${formatProfileDateTime(1767227520000)} this tab`,
    )
    expect(classes(thisTab!)).toEqual(['small', 'text-body-secondary'])
    expect(classes(thisTab!)).not.toContain('badge')
    expect(
      tabs[1]!.querySelector('[data-id="profile-session-tab-this"]'),
    ).toBeNull()
    expect(
      tabs[2]!.querySelector('[data-id="profile-session-tab-this"]'),
    ).toBeNull()
    expect(
      tabs[3]!.querySelector('[data-id="profile-session-tab-this"]'),
    ).toBeNull()
  })
})
