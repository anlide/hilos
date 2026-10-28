import {
  ActionLifecycle,
  applyServerTime,
  bindImpersonation,
  bindSessionScope,
  bindSignOut,
  createHilosTimeZonePicker,
  hilosBrowserTimeZone,
  type HilosTimeZonePicker,
  hilosOwnTimeZone,
  hilosToasts,
  ingest,
  ScopeManager,
  type ActionLifecycleSource,
  type ConnectionState,
  type HilosConnection,
} from '@hilos/core/base'
import { Component, signal } from '@angular/core'
import { By } from '@angular/platform-browser'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { HilosProfileTimeZone } from '../src/profile/HilosProfileTimeZone.js'
import { bindTestTimeZone } from './timeZoneTestHarness.js'

@Component({
  selector: 'test-time-zone-row',
  imports: [HilosProfileTimeZone],
  template: `
    <button type="button" data-id="time-zone-opener" (click)="shown.set(true)">
      Change
    </button>
    @if (mounted()) {
      @if (picker(); as current) {
        <hilos-profile-time-zone [(open)]="shown" [picker]="current" />
      }
    }
  `,
})
class TimeZoneRow {
  readonly picker = signal<HilosTimeZonePicker | null>(null)
  readonly shown = signal(false)
  readonly mounted = signal(true)
}

let fixture: ComponentFixture<TimeZoneRow> | undefined

function update(): void {
  fixture?.detectChanges()
}

async function settle(): Promise<void> {
  update()
  await vi.advanceTimersByTimeAsync(0)
  update()
}

const releases: (() => void)[] = []

afterEach(() => {
  fixture?.destroy()
  fixture = undefined
  while (releases.length) releases.pop()?.()
  document.body.innerHTML = ''
  hilosToasts.clear()
  vi.clearAllTimers()
  vi.useRealTimers()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  applyServerTime(Date.now())
  TestBed.resetTestingModule()
})

function byId<T extends HTMLElement = HTMLButtonElement>(id: string): T {
  const element = document.querySelector<T>(`[data-id="${id}"]`)
  if (!element) throw new Error(`Missing ${id}`)

  return element
}

/** Real picker, normalized catalog, session echo and action lifecycle. */
function setup(own: string | null = 'Asia/Tokyo', intlAvailable = true) {
  vi.useFakeTimers({ toFake: ['Date', 'setTimeout', 'clearTimeout'] })
  vi.setSystemTime(new Date('2026-01-01T00:00:10Z'))
  applyServerTime(Date.now())
  const timeZones = bindTestTimeZone('Europe/Warsaw')
  releases.push(timeZones.release)
  if (!intlAvailable) vi.stubGlobal('Intl', undefined)
  const listeners = new Map<string, Set<(payload: never) => void>>()
  const emit = (event: string, payload: unknown): void => {
    for (const listener of listeners.get(event) ?? [])
      listener(payload as never)
  }
  const sendAction = vi.fn<
    (action: string, data: unknown, requestId?: string) => boolean
  >(() => true)
  const source = {
    projectTimeZone: 'Asia/Kathmandu',
    sendAction,
    on(event: string, listener: (payload: never) => void): () => void {
      const callbacks = listeners.get(event) ?? new Set()
      callbacks.add(listener)
      listeners.set(event, callbacks)

      return () => callbacks.delete(listener)
    },
  }
  const connection = source as unknown as HilosConnection
  const actions = new ActionLifecycle(source as ActionLifecycleSource)
  const scopes = new ScopeManager()
  bindSessionScope(connection, scopes)
  releases.push(bindSignOut(scopes, actions))
  releases.push(bindImpersonation(scopes, actions))
  const page = scopes.openPage('hilos_profile')
  function catalog(zones: readonly string[]): void {
    ingest(page, {
      lists: {
        profileTimeZones: {
          cleared: true,
          items: zones.map((timeZone) => ({
            itemKey: timeZone,
            slots: { timeZone },
          })),
        },
      },
    })
  }
  catalog([
    'UTC',
    'Europe/Warsaw',
    'Asia/Tokyo',
    'America/New_York',
    'Unknown/Zone',
  ])
  function handshake(timeZone: string | null, userId: number | null = 1): void {
    emit('projectSignal', {
      type: 'handshake_response',
      data: {
        entities: {
          currentUser: userId === null ? null : { id: userId, timeZone },
        },
      },
    })
    timeZones.receive(timeZone)
  }
  handshake(own)
  expect(hilosBrowserTimeZone.get()).toBe(
    intlAvailable ? 'Europe/Warsaw' : null,
  )
  const context = { connection, actions, scopes }
  const picker = createHilosTimeZonePicker(context)
  const start = vi.spyOn(picker, 'start')
  const stop = vi.spyOn(picker, 'stop')
  const dispose = vi.spyOn(picker, 'dispose')
  const readRows = vi.spyOn(picker.rows, 'get')
  const readAutomatic = vi.spyOn(picker.automatic, 'get')
  releases.push(() => picker.dispose())
  fixture = TestBed.createComponent(TimeZoneRow)
  fixture.componentInstance.picker.set(picker)
  fixture.detectChanges()
  const host = fixture.componentInstance

  return {
    host,
    context,
    picker,
    start,
    stop,
    dispose,
    readRows,
    readAutomatic,
    sendAction,
    handshake,
    catalog,
    impersonate: () =>
      scopes.session.data.set('impersonatedBy', { type: 'user', id: 9 }),
    state: (next: ConnectionState) => emit('state', next),
    reply: (
      outcome: 'success' | 'fail',
      requestId = sendAction.mock.lastCall?.[2],
    ) =>
      emit(outcome === 'success' ? 'actionSuccess' : 'actionError', {
        action: 'hilos_time_zone_pick',
        requestId,
        reason: 'This time zone is no longer available.',
      }),
    async open(): Promise<void> {
      byId('time-zone-opener').focus()
      byId('time-zone-opener').click()
      await settle()
    },
  }
}

function dialog(): HTMLElement {
  const element = byId('profile-time-zone-modal').closest<HTMLElement>(
    '[role="dialog"]',
  )
  if (!element) throw new Error('Missing time zone dialog')

  return element
}

function search(): HTMLInputElement {
  return byId<HTMLInputElement>('profile-time-zone-search')
}

async function setQuery(query: string): Promise<void> {
  search().value = query
  search().dispatchEvent(new Event('input', { bubbles: true }))
  update()
}

function option(timeZone: string): HTMLButtonElement {
  return byId(`profile-time-zone-option-${timeZone}`)
}

function modalExists(): boolean {
  return document.querySelector('[data-id="profile-time-zone-modal"]') !== null
}

/** All user close paths use the modal's busy guard. */
function tryClosing(): void {
  byId('modal-close').click()
  byId('profile-time-zone-close').click()
  dialog().dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
  )
  dialog().click()
}

describe('HilosProfileTimeZone', () => {
  it('reads previews only while open and resumes with the current session and clock', async () => {
    const server = setup()
    const idleTimers = vi.getTimerCount()
    expect(server.readRows).not.toHaveBeenCalled()
    expect(server.readAutomatic).not.toHaveBeenCalled()
    server.catalog(['UTC', 'Europe/Warsaw'])
    server.handshake('UTC')
    update()
    expect(server.readRows).not.toHaveBeenCalled()
    expect(server.readAutomatic).not.toHaveBeenCalled()
    await server.open()
    expect(option('UTC').getAttribute('aria-current')).toBe('true')
    expect(server.readRows).toHaveBeenCalled()
    expect(server.readAutomatic).toHaveBeenCalled()
    byId('profile-time-zone-close').click()
    await settle()
    server.readRows.mockClear()
    server.readAutomatic.mockClear()
    server.catalog(['UTC', 'Asia/Tokyo'])
    server.handshake('Asia/Tokyo')
    vi.setSystemTime(new Date('2026-01-01T01:23:42Z'))
    window.dispatchEvent(new Event('focus'))
    document.dispatchEvent(new Event('visibilitychange'))
    await settle()
    expect(server.readRows).not.toHaveBeenCalled()
    expect(server.readAutomatic).not.toHaveBeenCalled()
    expect(vi.getTimerCount()).toBe(idleTimers)
    await server.open()
    expect(option('Asia/Tokyo').getAttribute('aria-current')).toBe('true')
    expect(option('UTC').textContent).toContain('01:23')
    expect(vi.getTimerCount()).toBe(idleTimers + 1)
  })

  it('focuses the named search in a named modal, with live clocks and only Close', async () => {
    const server = setup()
    expect(server.start).not.toHaveBeenCalled()
    await server.open()
    expect(dialog().getAttribute('aria-label')).toBe('Time zone')
    expect(dialog().getAttribute('aria-modal')).toBe('true')
    expect(dialog().querySelector('.modal-dialog-scrollable')).not.toBeNull()
    expect(search().type).toBe('search')
    expect(search().getAttribute('aria-label')).toBe('Search time zones')
    expect(search().hasAttribute('data-autofocus')).toBe(true)
    expect(document.activeElement).toBe(search())
    expect(server.start).toHaveBeenCalledOnce()
    const automatic = byId('profile-time-zone-automatic')
    expect(automatic.textContent).toContain('Automatic')
    expect(automatic.textContent).toContain('Europe/Warsaw — from this browser')
    expect(automatic.textContent).toContain('01:00')
    expect(automatic.hasAttribute('aria-current')).toBe(false)
    expect(dialog().textContent).toContain('All time zones')
    expect(dialog().textContent).not.toContain('Your region')
    expect(option('Asia/Tokyo').textContent).toContain('09:00')
    expect(option('Asia/Tokyo').getAttribute('aria-current')).toBe('true')
    expect(option('Asia/Tokyo').classList.contains('active')).toBe(true)
    expect(
      option('Asia/Tokyo').querySelector('i')?.getAttribute('aria-hidden'),
    ).toBe('true')
    expect(option('UTC').hasAttribute('aria-current')).toBe(false)
    const footer = dialog().querySelector('.modal-footer')
    expect(footer?.querySelectorAll('button')).toHaveLength(1)
    expect(footer?.textContent?.trim()).toBe('Close')
    await vi.advanceTimersByTimeAsync(50_000)
    update()
    expect(automatic.textContent).toContain('01:01')
    expect(automatic.closest('[aria-live]')).toBeNull()
    expect(automatic.querySelector('[aria-live], [role="status"]')).toBeNull()
    expect(
      option('UTC').querySelector('[aria-live], [role="status"]'),
    ).toBeNull()
    expect(server.sendAction).not.toHaveBeenCalled()
  })

  it('marks Automatic separately even when the own choice equals this browser', async () => {
    const server = setup(null)
    await server.open()
    const automatic = byId('profile-time-zone-automatic')
    expect(automatic.getAttribute('aria-current')).toBe('true')
    expect(option('Europe/Warsaw').hasAttribute('aria-current')).toBe(false)
    server.handshake('Europe/Warsaw')
    update()
    expect(automatic.hasAttribute('aria-current')).toBe(false)
    expect(option('Europe/Warsaw').getAttribute('aria-current')).toBe('true')
    automatic.click()
    expect(server.sendAction).toHaveBeenCalledExactlyOnceWith(
      'hilos_time_zone_pick',
      { timeZone: null },
      '1',
    )
    server.handshake(null)
    update()
    expect(automatic.getAttribute('aria-current')).toBe('true')
    expect(server.host.shown()).toBe(true)
    server.reply('success')
    await settle()
    expect(server.host.shown()).toBe(false)
    await server.open()
    byId('profile-time-zone-automatic').click()
    expect(server.sendAction).toHaveBeenLastCalledWith(
      'hilos_time_zone_pick',
      { timeZone: null },
      '2',
    )
    server.reply('success')
    await settle()
  })

  it.each([' New York ', 'new_york', 'AMERICA/NEW_YORK'])(
    'renders core search for %s and clears it without another catalog request',
    async (query) => {
      const server = setup()
      await server.open()
      await setQuery(query)
      expect(search().value).toBe(query)
      expect(server.picker.query.get()).toBe(query)
      expect(dialog().textContent).toContain('Found')
      expect(dialog().textContent).not.toContain('All time zones')
      expect(
        document.querySelector('[data-id="profile-time-zone-automatic"]'),
      ).toBeNull()
      expect(dialog().querySelectorAll('.list-group button')).toHaveLength(1)
      expect(option('America/New_York').textContent).toContain(
        'America/New_York',
      )
      await setQuery('no such city')
      expect(dialog().querySelectorAll('.list-group button')).toHaveLength(0)
      expect(byId('profile-time-zone-empty').textContent?.trim()).toBe(
        'No time zones match',
      )
      expect(byId('profile-time-zone-empty').hasAttribute('role')).toBe(false)
      await setQuery(' _ ')
      expect(dialog().textContent).toContain('All time zones')
      expect(byId('profile-time-zone-automatic')).toBeDefined()
      await setQuery('')
      expect(search().value).toBe('')
      expect(dialog().querySelectorAll('.list-group button')).toHaveLength(6)
      expect(
        document.querySelector('[data-id="profile-time-zone-empty"]'),
      ).toBeNull()
      expect(server.sendAction).not.toHaveBeenCalled()
    },
  )

  it('keeps an unavailable exact ID readable and disabled without substituting its clock', async () => {
    const server = setup()
    await server.open()
    const unavailable = option('Unknown/Zone')
    expect(unavailable.textContent).toContain('Unknown/Zone')
    expect(unavailable.textContent).toContain('Unavailable in this browser')
    expect(unavailable.textContent).toContain('—')
    expect(unavailable.disabled).toBe(true)
    unavailable.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    expect(server.sendAction).not.toHaveBeenCalled()
  })

  it('keeps Automatic reset enabled with an unavailable clock when Intl is absent', async () => {
    const server = setup(null, false)
    await server.open()
    expect(byId('profile-time-zone-automatic').disabled).toBe(false)
    expect(byId('profile-time-zone-automatic').textContent).toContain('—')
    expect(option('UTC').disabled).toBe(true)
    byId('profile-time-zone-automatic').click()
    expect(server.sendAction).toHaveBeenCalledExactlyOnceWith(
      'hilos_time_zone_pick',
      { timeZone: null },
      '1',
    )
    server.reply('success')
    await settle()
  })

  it.each(['America/New_York', 'US/Eastern', 'Asia/Tokyo'])(
    'sends the exact %s, including a saved alias or repeated choice, with no optimistic mark',
    async (zone) => {
      const server = setup(zone === 'US/Eastern' ? zone : 'Asia/Tokyo')
      await server.open()
      option(zone).click()
      expect(server.sendAction).toHaveBeenCalledExactlyOnceWith(
        'hilos_time_zone_pick',
        { timeZone: zone },
        '1',
      )
      expect(hilosOwnTimeZone.get()).toBe(
        zone === 'US/Eastern' ? zone : 'Asia/Tokyo',
      )
      expect(modalExists()).toBe(true)
      server.reply('success')
      await settle()
      expect(server.host.shown()).toBe(false)
      expect(hilosOwnTimeZone.get()).toBe(
        zone === 'US/Eastern' ? zone : 'Asia/Tokyo',
      )
      expect(document.activeElement).toBe(byId('time-zone-opener'))
      expect(hilosToasts.toasts.get()).toEqual([])
    },
  )

  it('moves the authoritative mark from another device while preserving search and the opening', async () => {
    const server = setup(null)
    await server.open()
    await setQuery('asia')
    const input = search()
    expect(option('Asia/Tokyo').hasAttribute('aria-current')).toBe(false)
    server.handshake('Asia/Tokyo')
    update()
    expect(option('Asia/Tokyo').getAttribute('aria-current')).toBe('true')
    server.handshake('Europe/Warsaw')
    update()
    expect(option('Asia/Tokyo').hasAttribute('aria-current')).toBe(false)
    expect(search()).toBe(input)
    expect(search().value).toBe('asia')
    expect(server.host.shown()).toBe(true)
    expect(server.start).toHaveBeenCalledOnce()
    expect(server.sendAction).not.toHaveBeenCalled()
  })

  it.each(['close', 'escape', 'backdrop', 'header'] as const)(
    'closes via %s, stops the clock, resets search on reopening and returns focus',
    async (method) => {
      const server = setup()
      await server.open()
      await setQuery('tokyo')
      if (method === 'close') byId('profile-time-zone-close').click()
      else if (method === 'header') byId('modal-close').click()
      else if (method === 'backdrop') dialog().click()
      else
        dialog().dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        )
      update()
      expect(modalExists()).toBe(false)
      expect(document.activeElement).toBe(byId('time-zone-opener'))
      await settle()
      expect(vi.getTimerCount()).toBe(0)
      await server.open()
      expect(search().value).toBe('')
      expect(document.activeElement).toBe(search())
      expect(server.start).toHaveBeenCalledTimes(2)
      expect(server.sendAction).not.toHaveBeenCalled()
    },
  )

  it.each([null, 'UTC'])(
    'locks search, all choices and every close path immediately for %s, with one deferred spinner',
    async (zone) => {
      const server = setup()
      await server.open()
      await setQuery(' _ ')
      const input = search()
      const room = byId('hilos-action-error-slot')
      const submitted =
        zone === null ? byId('profile-time-zone-automatic') : option(zone)
      submitted.click()
      option('Europe/Warsaw').click()
      expect(server.sendAction).toHaveBeenCalledOnce()
      update()
      expect(search()).toBe(input)
      expect(search().value).toBe(' _ ')
      expect(byId('hilos-action-error-slot')).toBe(room)
      expect(search().disabled).toBe(true)
      const options = [
        ...dialog().querySelectorAll<HTMLButtonElement>('.list-group button'),
      ]
      expect(options.every((button) => button.disabled)).toBe(true)
      expect(submitted.getAttribute('aria-busy')).toBe('true')
      expect(option('Asia/Tokyo').getAttribute('aria-current')).toBe('true')
      expect(byId('profile-time-zone-close').disabled).toBe(true)
      expect(byId('modal-close').disabled).toBe(true)
      tryClosing()
      update()
      expect(server.host.shown()).toBe(true)
      expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
      await vi.advanceTimersByTimeAsync(299)
      update()
      expect(
        document.querySelector('[data-id="profile-time-zone-loading"]'),
      ).toBeNull()
      await vi.advanceTimersByTimeAsync(1)
      update()
      expect(
        dialog().querySelectorAll('[data-id="profile-time-zone-loading"]'),
      ).toHaveLength(1)
      expect(byId('profile-time-zone-loading').parentElement).toBe(submitted)
      submitted.dispatchEvent(new MouseEvent('click', { bubbles: true }))
      expect(server.sendAction).toHaveBeenCalledOnce()
      server.reply('fail')
      await settle()
      expect(search().disabled).toBe(false)
      expect(byId('profile-time-zone-close').disabled).toBe(false)
      expect(option('UTC').disabled).toBe(false)
      expect(option('Unknown/Zone').disabled).toBe(true)
      expect(
        document.querySelector('[data-id="profile-time-zone-loading"]'),
      ).toBeNull()
    },
  )

  it.each(['fail', 'timeout', 'disconnected'] as const)(
    'keeps query, confirmed choice and window on %s, rejects late success and permits retry',
    async (outcome) => {
      const server = setup()
      await server.open()
      await setQuery('new_york')
      const input = search()
      const room = byId('hilos-action-error-slot')
      expect(
        byId('hilos-action-error-idle').classList.contains('invisible'),
      ).toBe(true)
      option('America/New_York').click()
      if (outcome === 'fail') server.reply('fail')
      else if (outcome === 'timeout') await vi.advanceTimersByTimeAsync(30_000)
      else server.state('reconnecting')
      await settle()
      const messages = {
        fail: 'This time zone is no longer available.',
        timeout: 'The action timed out. Please try again.',
        disconnected:
          'The connection dropped before the action completed. Please try again.',
      }
      expect(server.host.shown()).toBe(true)
      expect(search()).toBe(input)
      expect(search().value).toBe('new_york')
      expect(hilosOwnTimeZone.get()).toBe('Asia/Tokyo')
      expect(option('America/New_York').hasAttribute('aria-current')).toBe(
        false,
      )
      expect(byId('hilos-action-error-slot')).toBe(room)
      expect(room.getAttribute('role')).toBe('alert')
      expect(room.getAttribute('aria-live')).toBe('assertive')
      expect(byId('hilos-action-error').textContent).toContain(
        messages[outcome],
      )
      expect(hilosToasts.toasts.get()).toMatchObject([
        { message: messages[outcome], severity: 'error' },
      ])
      expect(option('America/New_York').disabled).toBe(false)
      server.state('connected')
      server.reply('success', '1')
      await settle()
      expect(server.host.shown()).toBe(true)
      option('America/New_York').click()
      expect(server.sendAction).toHaveBeenCalledTimes(2)
      update()
      expect(
        document.querySelector('[data-id="hilos-action-error"]'),
      ).toBeNull()
      server.handshake('America/New_York')
      update()
      expect(option('America/New_York').getAttribute('aria-current')).toBe(
        'true',
      )
      expect(server.host.shown()).toBe(true)
      server.reply('success')
      await settle()
      expect(server.host.shown()).toBe(false)
    },
  )

  it('clears the previous refusal on reopening and names its error details', async () => {
    const server = setup()
    await server.open()
    await setQuery('utc')
    option('UTC').click()
    server.reply('fail')
    await settle()
    byId('hilos-action-error-details').click()
    update()
    expect(
      document.querySelector(
        '[role="dialog"][aria-label="Could not change time zone"]',
      ),
    ).not.toBeNull()
    byId('hilos-action-error-close').click()
    update()
    byId('profile-time-zone-close').click()
    update()
    await server.open()
    expect(search().value).toBe('')
    expect(document.querySelector('[data-id="hilos-action-error"]')).toBeNull()
  })

  it.each(['signout', 'impersonation'] as const)(
    'closes on %s and refuses a stale choose or opening gesture',
    async (change) => {
      const server = setup()
      await server.open()
      const stale = option('UTC')
      if (change === 'signout') server.handshake(null, null)
      else server.impersonate()
      stale.click()
      update()
      expect(server.sendAction).not.toHaveBeenCalled()
      expect(server.host.shown()).toBe(false)
      expect(modalExists()).toBe(false)
      await settle()
      expect(vi.getTimerCount()).toBe(0)
      await server.open()
      expect(server.host.shown()).toBe(false)
      expect(modalExists()).toBe(false)
    },
  )

  it('keeps a later opening alive when the earlier admitted write succeeds', async () => {
    const server = setup()
    await server.open()
    await setQuery('utc')
    option('UTC').click()
    server.host.shown.set(false)
    update()
    await server.open()
    expect(search().value).toBe('')
    expect(search().disabled).toBe(true)
    server.handshake('UTC')
    server.reply('success')
    await settle()
    expect(server.host.shown()).toBe(true)
    expect(search().disabled).toBe(false)
    expect(option('UTC').getAttribute('aria-current')).toBe('true')
    option('Asia/Tokyo').click()
    server.reply('success')
    await settle()
    expect(server.host.shown()).toBe(false)
    expect(hilosOwnTimeZone.get()).toBe('UTC')
  })

  it('stops when the component unmounts, ignores a late reply and lets its owner dispose', async () => {
    const server = setup()
    await server.open()
    option('UTC').click()
    const changes = vi.fn()
    const child = fixture!.debugElement.query(
      By.directive(HilosProfileTimeZone),
    ).componentInstance as HilosProfileTimeZone
    child.open.subscribe(changes)
    const stops = server.stop.mock.calls.length
    server.host.mounted.set(false)
    update()
    expect(server.stop).toHaveBeenCalledTimes(stops + 1)
    expect(server.dispose).not.toHaveBeenCalled()
    expect(modalExists()).toBe(false)
    server.reply('success')
    await settle()
    expect(changes).not.toHaveBeenCalled()
    expect(vi.getTimerCount()).toBe(0)
    const rows = server.picker.rows.get()
    vi.setSystemTime(new Date('2026-01-01T01:23:42Z'))
    window.dispatchEvent(new Event('focus'))
    document.dispatchEvent(new Event('visibilitychange'))
    expect(server.picker.rows.get()).toBe(rows)
    fixture?.destroy()
    fixture = undefined
    server.picker.dispose()
    expect(server.dispose).toHaveBeenCalledOnce()
  })

  it('releases old picker bridges and clock on replacement and ignores its pending success', async () => {
    const server = setup()
    await server.open()
    option('UTC').click()
    const scopes = new ScopeManager()
    const page = scopes.openPage('hilos_profile')
    ingest(page, {
      lists: {
        profileTimeZones: {
          cleared: true,
          items: [
            { itemKey: 'Europe/London', slots: { timeZone: 'Europe/London' } },
          ],
        },
      },
    })
    const next = createHilosTimeZonePicker({ ...server.context, scopes })
    releases.push(() => next.dispose())
    const removeFocus = vi.spyOn(globalThis, 'removeEventListener')
    const removeVisibility = vi.spyOn(document, 'removeEventListener')
    const stops = server.stop.mock.calls.length
    server.host.picker.set(next)
    update()
    expect(server.stop).toHaveBeenCalledTimes(stops + 1)
    expect(removeFocus).toHaveBeenCalledWith('focus', expect.any(Function))
    expect(removeVisibility).toHaveBeenCalledWith(
      'visibilitychange',
      expect.any(Function),
    )
    expect(option('Europe/London')).toBeDefined()
    server.readRows.mockClear()
    server.readAutomatic.mockClear()
    server.catalog(['UTC'])
    server.picker.setQuery('old search')
    server.handshake('UTC')
    window.dispatchEvent(new Event('focus'))
    update()
    expect(server.readRows).not.toHaveBeenCalled()
    expect(server.readAutomatic).not.toHaveBeenCalled()
    expect(search().value).toBe('')
    server.reply('success')
    await settle()
    expect(server.host.shown()).toBe(true)
    expect(search().disabled).toBe(false)
    expect(vi.getTimerCount()).toBe(1)
    byId('profile-time-zone-close').click()
    await settle()
    expect(vi.getTimerCount()).toBe(0)
  })

  it('traps Tab in both directions and returns focus after a successful choice', async () => {
    const server = setup()
    await server.open()
    byId('profile-time-zone-close').focus()
    byId('profile-time-zone-close').dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }),
    )
    expect(document.activeElement).toBe(byId('modal-close'))
    byId('modal-close').dispatchEvent(
      new KeyboardEvent('keydown', {
        key: 'Tab',
        shiftKey: true,
        bubbles: true,
      }),
    )
    expect(document.activeElement).toBe(byId('profile-time-zone-close'))
    option('UTC').focus()
    option('UTC').click()
    server.reply('success')
    await settle()
    expect(document.activeElement).toBe(byId('time-zone-opener'))
  })
})
