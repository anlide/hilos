import {
  ActionLifecycle,
  applyServerTime,
  bindImpersonation,
  bindSessionScope,
  bindSignOut,
  bindTimeZoneState,
  createHilosTimeZonePicker,
  hilosOwnTimeZone,
  hilosToasts,
  ingest,
  ScopeManager,
  type ActionLifecycleSource,
  type ConnectionState,
  type HilosConnection,
} from '@hilos/core/base'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { StrictMode, useState } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosProfileTimeZone } from '../src/profile/HilosProfileTimeZone.js'

const releases: (() => void)[] = []

afterEach(() => {
  cleanup()
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
  const node = document.querySelector<T>(`[data-id="${id}"]`)
  if (!node) throw new Error(`Missing ${id}`)
  return node
}

function maybe(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function search(): HTMLInputElement {
  return byId('profile-time-zone-search')
}

function setQuery(value: string): void {
  fireEvent.change(search(), { target: { value } })
}

function option(zone: string): HTMLButtonElement {
  return byId(`profile-time-zone-option-${zone}`)
}

function dialog(): HTMLElement {
  return byId('profile-time-zone-modal').closest<HTMLElement>(
    '[role="dialog"]',
  )!
}

/** A real normalized catalog, reader binding, session and correlated actions. */
function setup(
  own: string | null = 'Asia/Tokyo',
  options: { intl?: boolean; browser?: string; strict?: boolean } = {},
) {
  vi.useFakeTimers({ toFake: ['Date', 'setTimeout', 'clearTimeout'] })
  vi.setSystemTime(new Date('2026-01-01T00:00:10Z'))
  applyServerTime(Date.now())
  const resolved = new Intl.DateTimeFormat().resolvedOptions()
  vi.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockReturnValue({
    ...resolved,
    timeZone: options.browser ?? 'Europe/Warsaw',
  })
  if (options.intl === false) vi.stubGlobal('Intl', undefined)
  const listeners = new Map<string, Set<(payload: never) => void>>()
  const emit = (event: string, payload: unknown): void => {
    act(() => {
      for (const listener of listeners.get(event) ?? [])
        listener(payload as never)
    })
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
    act(() =>
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
      }),
    )
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
  const picker = createHilosTimeZonePicker({ connection, actions, scopes })
  releases.push(() => picker.dispose())
  const start = vi.spyOn(picker, 'start')
  const stop = vi.spyOn(picker, 'stop')
  const readRows = vi.spyOn(picker.rows, 'get')
  const readAutomatic = vi.spyOn(picker.automatic, 'get')
  const closed = vi.fn()
  let show: (value: boolean) => void = () => {}
  function Window() {
    const [shown, setShown] = useState(options.strict === true)
    show = setShown
    return (
      <>
        <button data-id="time-zone-opener" onClick={() => setShown(true)}>
          Change
        </button>
        <HilosProfileTimeZone
          open={shown}
          picker={picker}
          onClose={() => {
            closed()
            setShown(false)
          }}
        />
      </>
    )
  }
  const view = render(
    options.strict ? (
      <StrictMode>
        <Window />
      </StrictMode>
    ) : (
      <Window />
    ),
  )
  return {
    view,
    picker,
    start,
    stop,
    readRows,
    readAutomatic,
    sendAction,
    closed,
    handshake,
    catalog,
    force: (shown: boolean) => act(() => show(shown)),
    impersonate: (active = true) =>
      act(() =>
        scopes.session.data.set(
          'impersonatedBy',
          active ? { type: 'user', id: 9 } : null,
        ),
      ),
    state: (next: ConnectionState) => emit('state', next),
    async reply(
      outcome: 'success' | 'fail',
      requestId = sendAction.mock.lastCall?.[2],
    ): Promise<void> {
      await act(async () => {
        emit(outcome === 'success' ? 'actionSuccess' : 'actionError', {
          action: 'hilos_time_zone_pick',
          requestId,
          reason: 'This time zone is no longer available.',
        })
      })
    },
    open(): void {
      byId('time-zone-opener').focus()
      fireEvent.click(byId('time-zone-opener'))
    },
  }
}

describe('HilosProfileTimeZone', () => {
  it('focuses the named search, renders core clocks and selection without a clock live region', () => {
    const server = setup()
    server.open()
    expect(dialog().getAttribute('aria-label')).toBe('Time zone')
    expect(dialog().getAttribute('aria-modal')).toBe('true')
    expect(search().getAttribute('aria-label')).toBe('Search time zones')
    expect(search().hasAttribute('data-autofocus')).toBe(true)
    expect(document.activeElement).toBe(search())
    expect(byId('profile-time-zone-automatic').textContent).toContain(
      'Europe/Warsaw — from this browser',
    )
    expect(byId('profile-time-zone-automatic').textContent).toContain('01:00')
    expect(option('Asia/Tokyo').textContent).toContain('09:00')
    expect(option('Asia/Tokyo').getAttribute('aria-current')).toBe('true')
    expect(option('Asia/Tokyo').classList.contains('active')).toBe(true)
    expect(
      option('Asia/Tokyo').querySelector('i')?.getAttribute('aria-hidden'),
    ).toBe('true')
    expect(option('UTC').hasAttribute('aria-current')).toBe(false)
    expect(
      option('UTC').querySelector('[aria-live], [role="status"]'),
    ).toBeNull()
    expect(dialog().textContent).toContain('All time zones')
    expect(dialog().textContent).not.toContain('Your region')
    expect(dialog().querySelector('.modal-footer')?.textContent?.trim()).toBe(
      'Close',
    )
    expect(server.sendAction).not.toHaveBeenCalled()
  })

  it('keeps Automatic separate from an explicit browser zone and resets only on echo', async () => {
    const server = setup(null)
    server.open()
    const automatic = byId('profile-time-zone-automatic')
    expect(automatic.getAttribute('aria-current')).toBe('true')
    expect(option('Europe/Warsaw').hasAttribute('aria-current')).toBe(false)
    server.handshake('Europe/Warsaw')
    expect(automatic.hasAttribute('aria-current')).toBe(false)
    expect(option('Europe/Warsaw').getAttribute('aria-current')).toBe('true')
    fireEvent.click(automatic)
    expect(server.sendAction).toHaveBeenCalledExactlyOnceWith(
      'hilos_time_zone_pick',
      { timeZone: null },
      '1',
    )
    expect(hilosOwnTimeZone.get()).toBe('Europe/Warsaw')
    server.handshake(null)
    expect(automatic.getAttribute('aria-current')).toBe('true')
    expect(maybe('profile-time-zone-modal')).not.toBeNull()
    await server.reply('success')
    expect(maybe('profile-time-zone-modal')).toBeNull()
    expect(document.activeElement).toBe(byId('time-zone-opener'))
  })

  it.each([' New York ', 'new_york', 'AMERICA/NEW_YORK'])(
    'renders core search for %s, including empty and normalized blank search',
    (query) => {
      const server = setup()
      server.open()
      setQuery(query)
      expect(search().value).toBe(query)
      expect(server.picker.query.get()).toBe(query)
      expect(dialog().textContent).toContain('Found')
      expect(dialog().querySelectorAll('.list-group button')).toHaveLength(1)
      expect(option('America/New_York')).toBeDefined()
      expect(maybe('profile-time-zone-automatic')).toBeNull()
      setQuery('no such city')
      expect(dialog().querySelectorAll('.list-group button')).toHaveLength(0)
      expect(byId('profile-time-zone-empty').textContent?.trim()).toBe(
        'No time zones match',
      )
      setQuery(' _ ')
      expect(dialog().textContent).toContain('All time zones')
      expect(byId('profile-time-zone-automatic')).toBeDefined()
      expect(maybe('profile-time-zone-empty')).toBeNull()
      expect(server.sendAction).not.toHaveBeenCalled()
    },
  )

  it('keeps unsupported names readable and disabled', () => {
    const server = setup()
    server.open()
    const unavailable = option('Unknown/Zone')
    expect(unavailable.textContent).toContain('Unavailable in this browser')
    expect(unavailable.textContent).toContain('—')
    expect(unavailable.disabled).toBe(true)
    fireEvent.click(unavailable)
    expect(server.sendAction).not.toHaveBeenCalled()
  })

  it('uses the project fallback when the browser has no usable zone', () => {
    const server = setup(null, { browser: 'Unknown/Zone' })
    server.open()
    expect(byId('profile-time-zone-automatic').textContent).toContain(
      'Asia/Kathmandu — from this browser',
    )
    expect(byId('profile-time-zone-automatic').textContent).toContain('05:45')
  })

  it('permits Automatic without Intl while keeping named choices disabled', async () => {
    const server = setup(null, { intl: false })
    server.open()
    const automatic = byId('profile-time-zone-automatic')
    expect(automatic.textContent).toContain('UTC — from this browser')
    expect(automatic.textContent).toContain('—')
    expect(automatic.disabled).toBe(false)
    expect(option('UTC').disabled).toBe(true)
    fireEvent.click(automatic)
    await server.reply('success')
    expect(server.sendAction).toHaveBeenCalledExactlyOnceWith(
      'hilos_time_zone_pick',
      { timeZone: null },
      '1',
    )
  })

  it.each(['America/New_York', 'US/Eastern', 'Asia/Tokyo'])(
    'sends exact %s, including aliases and repeated picks, without an optimistic mark',
    async (zone) => {
      const own = zone === 'US/Eastern' ? zone : 'Asia/Tokyo'
      const server = setup(own)
      server.open()
      fireEvent.click(option(zone))
      expect(server.sendAction).toHaveBeenCalledExactlyOnceWith(
        'hilos_time_zone_pick',
        { timeZone: zone },
        '1',
      )
      expect(hilosOwnTimeZone.get()).toBe(own)
      expect(option(own).getAttribute('aria-current')).toBe('true')
      await server.reply('success')
      expect(maybe('profile-time-zone-modal')).toBeNull()
      expect(hilosOwnTimeZone.get()).toBe(own)
      expect(hilosToasts.toasts.get()).toEqual([])
    },
  )

  it('follows another tab echo without replacing the input or resetting search', () => {
    const server = setup(null)
    server.open()
    setQuery('asia')
    const input = search()
    server.handshake('Asia/Tokyo')
    expect(option('Asia/Tokyo').getAttribute('aria-current')).toBe('true')
    server.handshake('Europe/Warsaw')
    expect(option('Asia/Tokyo').hasAttribute('aria-current')).toBe(false)
    expect(search()).toBe(input)
    expect(search().value).toBe('asia')
    expect(server.start).toHaveBeenCalledOnce()
    expect(server.sendAction).not.toHaveBeenCalled()
  })

  it('locks double presses in one tick and every close path, with a delayed spinner on the sent row', async () => {
    const server = setup()
    server.open()
    act(() => {
      option('UTC').click()
      option('Europe/Warsaw').click()
      byId('profile-time-zone-automatic').click()
    })
    expect(server.sendAction).toHaveBeenCalledExactlyOnceWith(
      'hilos_time_zone_pick',
      { timeZone: 'UTC' },
      '1',
    )
    expect(search().disabled).toBe(true)
    for (const button of dialog().querySelectorAll<HTMLButtonElement>('button'))
      expect(button.disabled).toBe(true)
    expect(option('UTC').getAttribute('aria-busy')).toBe('true')
    expect(option('Asia/Tokyo').hasAttribute('aria-busy')).toBe(false)
    fireEvent.click(byId('modal-close'))
    fireEvent.click(byId('profile-time-zone-close'))
    fireEvent.keyDown(dialog(), { key: 'Escape' })
    fireEvent.click(dialog())
    expect(server.closed).not.toHaveBeenCalled()
    await act(async () => {
      await vi.advanceTimersByTimeAsync(299)
    })
    expect(maybe('profile-time-zone-loading')).toBeNull()
    await act(async () => {
      await vi.advanceTimersByTimeAsync(1)
    })
    expect(byId('profile-time-zone-loading').closest('button')).toBe(
      option('UTC'),
    )
    expect(option('Asia/Tokyo').getAttribute('aria-current')).toBe('true')
    await server.reply('fail')
    expect(search().disabled).toBe(false)
    expect(byId('modal-close').disabled).toBe(false)
    expect(maybe('profile-time-zone-loading')).toBeNull()
  })

  it.each(['fail', 'timeout', 'disconnect'] as const)(
    'keeps search and the confirmed mark after %s, then retries',
    async (outcome) => {
      const server = setup()
      server.open()
      setQuery('a')
      fireEvent.click(option('Europe/Warsaw'))
      if (outcome === 'fail') await server.reply('fail')
      else if (outcome === 'timeout')
        await act(async () => {
          await vi.advanceTimersByTimeAsync(30000)
        })
      else await act(async () => server.state('disconnected'))
      expect(maybe('profile-time-zone-modal')).not.toBeNull()
      expect(search().value).toBe('a')
      expect(option('Asia/Tokyo').getAttribute('aria-current')).toBe('true')
      expect(option('Europe/Warsaw').hasAttribute('aria-current')).toBe(false)
      const text =
        outcome === 'fail'
          ? 'This time zone is no longer available.'
          : outcome === 'timeout'
            ? 'The action timed out. Please try again.'
            : 'The connection dropped before the action completed. Please try again.'
      expect(byId('hilos-action-error').textContent).toContain(text)
      expect(hilosToasts.toasts.get()).toEqual([])
      fireEvent.click(option('Europe/Warsaw'))
      expect(server.sendAction).toHaveBeenCalledTimes(2)
      await server.reply('success')
      expect(maybe('profile-time-zone-modal')).toBeNull()
      server.open()
      expect(search().value).toBe('')
      expect(maybe('hilos-action-error')).toBeNull()
    },
  )

  it('clears the previous refusal and query only when a new opening starts', async () => {
    const server = setup()
    server.open()
    setQuery('utc')
    fireEvent.click(option('UTC'))
    await server.reply('fail')
    fireEvent.click(byId('hilos-action-error-details'))
    expect(
      document.querySelector(
        '[role="dialog"][aria-label="Could not change time zone"]',
      ),
    ).not.toBeNull()
    fireEvent.click(byId('hilos-action-error-close'))
    expect(search().value).toBe('utc')
    fireEvent.click(byId('profile-time-zone-close'))
    server.open()
    expect(search().value).toBe('')
    expect(maybe('hilos-action-error')).toBeNull()
    expect(option('Asia/Tokyo').getAttribute('aria-current')).toBe('true')
  })

  it('does not close a later opening on an earlier success', async () => {
    const server = setup()
    server.open()
    fireEvent.click(option('UTC'))
    server.force(false)
    server.open()
    expect(search().disabled).toBe(true)
    await server.reply('success')
    expect(maybe('profile-time-zone-modal')).not.toBeNull()
    expect(search().disabled).toBe(false)
    expect(server.closed).not.toHaveBeenCalled()
    fireEvent.click(option('Europe/Warsaw'))
    await server.reply('success')
    expect(maybe('profile-time-zone-modal')).toBeNull()
  })

  it.each(['signout', 'impersonation'] as const)(
    'closes on %s and rejects a click before React can render the lock',
    async (cause) => {
      const server = setup()
      server.open()
      act(() => {
        if (cause === 'signout') server.handshake(null, null)
        else server.impersonate()
        option('UTC').click()
      })
      expect(server.sendAction).not.toHaveBeenCalled()
      expect(maybe('profile-time-zone-modal')).toBeNull()
      server.impersonate(false)
      server.handshake('Asia/Tokyo')
      expect(maybe('profile-time-zone-modal')).toBeNull()
    },
  )

  it.each(['close', 'escape', 'header', 'backdrop'] as const)(
    'stops on %s and returns focus, then resets only when reopened',
    (method) => {
      const server = setup()
      server.open()
      setQuery('asia')
      if (method === 'close') fireEvent.click(byId('profile-time-zone-close'))
      else if (method === 'header') fireEvent.click(byId('modal-close'))
      else if (method === 'escape')
        fireEvent.keyDown(dialog(), { key: 'Escape' })
      else fireEvent.click(dialog())
      expect(maybe('profile-time-zone-modal')).toBeNull()
      expect(document.activeElement).toBe(byId('time-zone-opener'))
      expect(server.stop).toHaveBeenCalled()
      act(() => vi.advanceTimersByTime(0))
      expect(vi.getTimerCount()).toBe(0)
      server.open()
      expect(search().value).toBe('')
      expect(server.sendAction).not.toHaveBeenCalled()
    },
  )

  it('leaves previews lazy while closed, releases clock listeners, and resumes at the current time', () => {
    const server = setup()
    expect(server.readRows).not.toHaveBeenCalled()
    expect(server.readAutomatic).not.toHaveBeenCalled()
    server.catalog(['UTC', 'Europe/Warsaw'])
    server.handshake('UTC')
    expect(server.readRows).not.toHaveBeenCalled()
    expect(server.readAutomatic).not.toHaveBeenCalled()
    server.open()
    expect(option('UTC').getAttribute('aria-current')).toBe('true')
    fireEvent.click(byId('profile-time-zone-close'))
    server.readRows.mockClear()
    server.readAutomatic.mockClear()
    server.catalog(['UTC', 'Asia/Tokyo'])
    server.handshake('Asia/Tokyo')
    vi.setSystemTime(new Date('2026-01-01T01:23:42Z'))
    act(() => {
      window.dispatchEvent(new Event('focus'))
      document.dispatchEvent(new Event('visibilitychange'))
    })
    expect(server.readRows).not.toHaveBeenCalled()
    expect(server.readAutomatic).not.toHaveBeenCalled()
    act(() => vi.advanceTimersByTime(0))
    expect(vi.getTimerCount()).toBe(0)
    server.open()
    expect(option('UTC').textContent).toContain('01:23')
    expect(option('Asia/Tokyo').getAttribute('aria-current')).toBe('true')
    act(() => vi.advanceTimersByTime(0))
    expect(vi.getTimerCount()).toBe(1)
  })

  it('stops on unmount and ignores the pending success', async () => {
    const server = setup()
    server.open()
    fireEvent.click(option('UTC'))
    server.view.unmount()
    expect(server.stop).toHaveBeenCalled()
    await server.reply('success')
    expect(server.closed).not.toHaveBeenCalled()
    act(() => vi.advanceTimersByTime(0))
    expect(vi.getTimerCount()).toBe(0)
    server.readRows.mockClear()
    server.readAutomatic.mockClear()
    server.handshake('UTC')
    expect(server.readRows).not.toHaveBeenCalled()
    expect(server.readAutomatic).not.toHaveBeenCalled()
  })

  it('keeps live previews, search and a real choice working after StrictMode effect replay', async () => {
    const server = setup(null, { strict: true })
    expect(server.start).toHaveBeenCalledTimes(2)
    act(() => vi.advanceTimersByTime(0))
    expect(vi.getTimerCount()).toBe(1)
    await act(async () => {
      await vi.advanceTimersByTimeAsync(50000)
    })
    expect(option('UTC').textContent).toContain('00:01')
    setQuery('new_york')
    expect(dialog().querySelectorAll('.list-group button')).toHaveLength(1)
    fireEvent.click(option('America/New_York'))
    server.handshake('America/New_York')
    expect(option('America/New_York').getAttribute('aria-current')).toBe('true')
    expect(search().value).toBe('new_york')
    await server.reply('success')
    expect(maybe('profile-time-zone-modal')).toBeNull()
    server.open()
    expect(option('America/New_York').getAttribute('aria-current')).toBe('true')
    fireEvent.click(byId('profile-time-zone-automatic'))
    server.handshake(null)
    await server.reply('success')
    expect(server.sendAction.mock.calls.map((call) => call[1])).toEqual([
      { timeZone: 'America/New_York' },
      { timeZone: null },
    ])
    act(() => vi.advanceTimersByTime(0))
    expect(vi.getTimerCount()).toBe(0)
  })
})
