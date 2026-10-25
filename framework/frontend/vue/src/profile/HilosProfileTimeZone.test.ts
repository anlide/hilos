import {
  ActionLifecycle,
  applyServerTime,
  bindImpersonation,
  bindSessionScope,
  bindSignOut,
  bindTimeZoneState,
  createHilosTimeZonePicker,
  hilosBrowserTimeZone,
  hilosOwnTimeZone,
  hilosToasts,
  ingest,
  ScopeManager,
  type ActionLifecycleSource,
  type ConnectionState,
  type HilosConnection,
} from '@hilos/core/base'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick, onUnmounted, ref } from 'vue'

import HilosProfileTimeZone from './HilosProfileTimeZone.vue'

const wrappers: VueWrapper[] = []
const releases: (() => void)[] = []

afterEach(() => {
  while (wrappers.length) wrappers.pop()?.unmount()
  while (releases.length) releases.pop()?.()
  document.body.innerHTML = ''
  hilosToasts.clear()
  vi.clearAllTimers()
  vi.useRealTimers()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  applyServerTime(Date.now())
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
  const resolved = new Intl.DateTimeFormat().resolvedOptions()
  vi.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockImplementation(
    () => ({ ...resolved, timeZone: 'Europe/Warsaw' }),
  )
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
  releases.push(bindTimeZoneState(connection))
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
  }
  handshake(own)
  expect(hilosBrowserTimeZone.get()).toBe(
    intlAvailable ? 'Europe/Warsaw' : null,
  )
  const picker = createHilosTimeZonePicker({ connection, actions, scopes })
  const start = vi.spyOn(picker, 'start')
  const stop = vi.spyOn(picker, 'stop')
  const dispose = vi.spyOn(picker, 'dispose')
  const readRows = vi.spyOn(picker.rows, 'get')
  const readAutomatic = vi.spyOn(picker.automatic, 'get')
  const shown = ref(false)
  const mounted = ref(true)
  const wrapper = mount(
    {
      components: { HilosProfileTimeZone },
      setup() {
        onUnmounted(() => picker.dispose())

        return { shown, mounted, picker }
      },
      template:
        '<button data-id="time-zone-opener" @click="shown = true">Change</button><HilosProfileTimeZone v-if="mounted" v-model="shown" :picker="picker" />',
    },
    { attachTo: document.body },
  )
  wrappers.push(wrapper)

  return {
    wrapper,
    picker,
    shown,
    mounted,
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
      await nextTick()
      await nextTick()
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
  await nextTick()
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
    await nextTick()
    expect(server.readRows).not.toHaveBeenCalled()
    expect(server.readAutomatic).not.toHaveBeenCalled()
    await server.open()
    expect(option('UTC').getAttribute('aria-current')).toBe('true')
    expect(server.readRows).toHaveBeenCalled()
    expect(server.readAutomatic).toHaveBeenCalled()
    byId('profile-time-zone-close').click()
    await flushPromises()
    server.readRows.mockClear()
    server.readAutomatic.mockClear()
    server.catalog(['UTC', 'Asia/Tokyo'])
    server.handshake('Asia/Tokyo')
    vi.setSystemTime(new Date('2026-01-01T01:23:42Z'))
    window.dispatchEvent(new Event('focus'))
    document.dispatchEvent(new Event('visibilitychange'))
    await nextTick()
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
    await nextTick()
    expect(automatic.hasAttribute('aria-current')).toBe(false)
    expect(option('Europe/Warsaw').getAttribute('aria-current')).toBe('true')
    automatic.click()
    expect(server.sendAction).toHaveBeenCalledExactlyOnceWith(
      'hilos_time_zone_pick',
      { timeZone: null },
      '1',
    )
    server.handshake(null)
    await nextTick()
    expect(automatic.getAttribute('aria-current')).toBe('true')
    expect(server.shown.value).toBe(true)
    server.reply('success')
    await flushPromises()
    expect(server.shown.value).toBe(false)
    await server.open()
    byId('profile-time-zone-automatic').click()
    expect(server.sendAction).toHaveBeenLastCalledWith(
      'hilos_time_zone_pick',
      { timeZone: null },
      '2',
    )
    server.reply('success')
    await flushPromises()
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
    await flushPromises()
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
      await flushPromises()
      expect(server.shown.value).toBe(false)
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
    await nextTick()
    expect(option('Asia/Tokyo').getAttribute('aria-current')).toBe('true')
    server.handshake('Europe/Warsaw')
    await nextTick()
    expect(option('Asia/Tokyo').hasAttribute('aria-current')).toBe(false)
    expect(search()).toBe(input)
    expect(search().value).toBe('asia')
    expect(server.shown.value).toBe(true)
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
      await nextTick()
      expect(modalExists()).toBe(false)
      expect(document.activeElement).toBe(byId('time-zone-opener'))
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
      const submitted =
        zone === null ? byId('profile-time-zone-automatic') : option(zone)
      submitted.click()
      option('Europe/Warsaw').click()
      expect(server.sendAction).toHaveBeenCalledOnce()
      await nextTick()
      expect(search().disabled).toBe(true)
      expect(search().closest('fieldset[disabled]')).not.toBeNull()
      const options = [
        ...dialog().querySelectorAll<HTMLButtonElement>('.list-group button'),
      ]
      expect(options.every((button) => button.disabled)).toBe(true)
      expect(submitted.getAttribute('aria-busy')).toBe('true')
      expect(option('Asia/Tokyo').getAttribute('aria-current')).toBe('true')
      expect(byId('profile-time-zone-close').disabled).toBe(true)
      expect(byId('modal-close').disabled).toBe(true)
      tryClosing()
      await nextTick()
      expect(server.shown.value).toBe(true)
      expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
      await vi.advanceTimersByTimeAsync(299)
      expect(
        document.querySelector('[data-id="profile-time-zone-loading"]'),
      ).toBeNull()
      await vi.advanceTimersByTimeAsync(1)
      expect(
        dialog().querySelectorAll('[data-id="profile-time-zone-loading"]'),
      ).toHaveLength(1)
      expect(byId('profile-time-zone-loading').parentElement).toBe(submitted)
      submitted.dispatchEvent(new MouseEvent('click', { bubbles: true }))
      expect(server.sendAction).toHaveBeenCalledOnce()
      server.reply('fail')
      await flushPromises()
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
      await flushPromises()
      const messages = {
        fail: 'This time zone is no longer available.',
        timeout: 'The action timed out. Please try again.',
        disconnected:
          'The connection dropped before the action completed. Please try again.',
      }
      expect(server.shown.value).toBe(true)
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
      await flushPromises()
      expect(server.shown.value).toBe(true)
      option('America/New_York').click()
      expect(server.sendAction).toHaveBeenCalledTimes(2)
      await nextTick()
      expect(
        document.querySelector('[data-id="hilos-action-error"]'),
      ).toBeNull()
      server.handshake('America/New_York')
      await nextTick()
      expect(option('America/New_York').getAttribute('aria-current')).toBe(
        'true',
      )
      expect(server.shown.value).toBe(true)
      server.reply('success')
      await flushPromises()
      expect(server.shown.value).toBe(false)
    },
  )

  it('clears the previous refusal on reopening and names its error details', async () => {
    const server = setup()
    await server.open()
    await setQuery('utc')
    option('UTC').click()
    server.reply('fail')
    await flushPromises()
    byId('hilos-action-error-details').click()
    await nextTick()
    expect(
      document.querySelector(
        '[role="dialog"][aria-label="Could not change time zone"]',
      ),
    ).not.toBeNull()
    byId('hilos-action-error-close').click()
    await nextTick()
    byId('profile-time-zone-close').click()
    await nextTick()
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
      await nextTick()
      expect(server.sendAction).not.toHaveBeenCalled()
      expect(server.shown.value).toBe(false)
      expect(modalExists()).toBe(false)
      expect(vi.getTimerCount()).toBe(0)
      await server.open()
      expect(server.shown.value).toBe(false)
      expect(modalExists()).toBe(false)
    },
  )

  it('keeps a later opening alive when the earlier admitted write succeeds', async () => {
    const server = setup()
    await server.open()
    await setQuery('utc')
    option('UTC').click()
    server.shown.value = false
    await nextTick()
    await server.open()
    expect(search().value).toBe('')
    expect(search().disabled).toBe(true)
    server.handshake('UTC')
    server.reply('success')
    await flushPromises()
    expect(server.shown.value).toBe(true)
    expect(search().disabled).toBe(false)
    expect(option('UTC').getAttribute('aria-current')).toBe('true')
    option('Asia/Tokyo').click()
    server.reply('success')
    await flushPromises()
    expect(server.shown.value).toBe(false)
    expect(hilosOwnTimeZone.get()).toBe('UTC')
  })

  it('stops when the SFC unmounts, ignores a late reply and lets its owner dispose', async () => {
    const server = setup()
    await server.open()
    option('UTC').click()
    const child = server.wrapper.getComponent(HilosProfileTimeZone)
    const emissions = child.emitted('update:modelValue')?.length ?? 0
    const stops = server.stop.mock.calls.length
    server.mounted.value = false
    await nextTick()
    expect(server.stop).toHaveBeenCalledTimes(stops + 1)
    expect(server.dispose).not.toHaveBeenCalled()
    expect(modalExists()).toBe(false)
    server.reply('success')
    await flushPromises()
    expect(child.emitted('update:modelValue')?.length ?? 0).toBe(emissions)
    expect(vi.getTimerCount()).toBe(0)
    const rows = server.picker.rows.get()
    vi.setSystemTime(new Date('2026-01-01T01:23:42Z'))
    window.dispatchEvent(new Event('focus'))
    document.dispatchEvent(new Event('visibilitychange'))
    expect(server.picker.rows.get()).toBe(rows)
    server.wrapper.unmount()
    wrappers.pop()
    expect(server.dispose).toHaveBeenCalledOnce()
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
    await flushPromises()
    expect(document.activeElement).toBe(byId('time-zone-opener'))
  })
})
