// The Status Overview in React: the real page, the real core state and incident
// controller, the real shared head and LoadingButton; only the wire is fake.
// Scenarios mirror framework/frontend/vue/src/admin/status/HilosStatusPage.test.ts.
import { act, fireEvent } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  HIDDEN_VALUE,
  HilosPages,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  applyServerTime,
  bindTimeZoneState,
  formatMoment,
  hilosToasts,
} from '@hilos/core/base'
import type { HilosStatusPublicWork } from '@hilos/core/admin/status'

import { HilosStatusPage } from '../src/admin/status/HilosStatusPage.js'
import { readerTimeZone } from './readerTimeZone.js'
import { incident, overview, statusHarness } from './statusFixtures.js'

const cleanups: (() => void)[] = []
beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(1800000000000)
  applyServerTime(Date.now())
  const zone = readerTimeZone()
  act(() => zone.choose('Europe/Warsaw'))
  cleanups.push(zone.release)
})
afterEach(() => {
  for (const cleanup of cleanups.splice(0).reverse()) act(cleanup)
  hilosToasts.clear()
  vi.useRealTimers()
})

function work(
  overrides: Partial<HilosStatusPublicWork> = {},
): HilosStatusPublicWork {
  return {
    id: 'public-work',
    title: 'Database upgrade',
    description: null,
    componentKeys: ['service'],
    startsAt: 1800000060000,
    endsAt: 1800003600000,
    ...overrides,
  }
}

function find(
  id: string,
  root: ParentNode = document.body,
): HTMLElement | null {
  return root.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

function get(id: string, root: ParentNode = document.body): HTMLElement {
  const element = find(id, root)
  if (element === null) throw new Error(`Missing [data-id="${id}"]`)
  return element
}

function text(id: string, root: ParentNode = document.body): string {
  return get(id, root).textContent ?? ''
}

/**
 * The words an element gives its reader: its text without the parts hidden
 * from assistive technology, such as the room a live message keeps.
 *
 * @param element The element to read.
 */
function heard(element: Element): string {
  const copy = element.cloneNode(true) as Element
  for (const hidden of copy.querySelectorAll('[aria-hidden="true"]'))
    hidden.remove()
  return copy.textContent ?? ''
}

function page(data?: unknown, strict = false) {
  const h = statusHarness()
  cleanups.push(h.dispose)
  if (data !== undefined) h.setOverview(data)
  const view = h.mount(<HilosStatusPage context={h.context} />, strict)
  let mounted = true
  const unmount = () => {
    if (!mounted) return
    mounted = false
    view.unmount()
  }
  cleanups.push(unmount)
  return { ...h, view, unmount }
}

describe('HilosStatusPage', () => {
  it('links to History and follows its authoritative zero, count and unavailable without another timer', () => {
    const { setOverview, sent, viewports } = page(
      overview({ historyCount: null }),
    )
    const tile = get('hilos-status-history-tile')
    const timers = vi.getTimerCount()
    expect(get('hilos-status-history-link', tile).getAttribute('href')).toBe(
      '/hilos/status/history',
    )
    expect(text('hilos-status-history-count', tile)).toBe('—')
    expect(text('hilos-status-history-unavailable', tile)).toBe(
      'History data is unavailable.',
    )
    for (const count of [0, 713, 1, null]) {
      setOverview(overview({ historyCount: count }))
      expect(text('hilos-status-history-count', tile)).toBe(
        count === null ? '—' : String(count),
      )
      expect(find('hilos-status-history-unavailable', tile) !== null).toBe(
        count === null,
      )
      expect(get('hilos-status-history-tile')).toBe(tile)
    }
    expect(vi.getTimerCount()).toBe(timers)
    expect(sent).toEqual([])
    expect(viewports).toEqual([])
  })

  it('names its tab row for the shared head and links to component health and settings', () => {
    const { view, sent } = page(overview())
    expect(
      view.getAllByRole('navigation', { name: 'Status pages' }),
    ).toHaveLength(1)
    expect(text('hilos-status-components-link')).toBe(
      '0 / 2 operational · View component health',
    )
    expect(get('hilos-status-settings-link').getAttribute('href')).toBe(
      '/hilos/settings',
    )
    expect(sent).toEqual([])
  })

  it('links to Subscribers and renders authoritative counts, zero and unavailable in the same tile frame', () => {
    const { setOverview, sent, viewports } = page(
      overview({ subscribers: null }),
    )
    const tile = get('hilos-status-subscribers-tile')
    const confirmed = () =>
      text('hilos-status-subscribers-tile-confirmed', tile)
    const pending = () => text('hilos-status-subscribers-tile-pending', tile)
    expect([confirmed(), pending()]).toEqual(['—', '—'])
    expect(text('hilos-status-subscribers-tile-unavailable', tile)).toBe(
      'Subscriber data is unavailable.',
    )
    expect(
      get('hilos-status-subscribers-link', tile).getAttribute('href'),
    ).toBe('/hilos/status/subscribers')
    for (const counts of [
      { confirmedCount: 0, pendingCount: 0 },
      { confirmedCount: 214, pendingCount: 3 },
    ]) {
      setOverview(overview({ subscribers: counts }))
      expect([confirmed(), pending()]).toEqual([
        String(counts.confirmedCount),
        String(counts.pendingCount),
      ])
      expect(find('hilos-status-subscribers-tile-unavailable', tile)).toBeNull()
      expect(get('hilos-status-subscribers-tile')).toBe(tile)
    }
    setOverview(overview({ subscribers: null }))
    expect([confirmed(), pending()]).toEqual(['—', '—'])
    expect(tile.querySelector('[aria-hidden="true"]')?.textContent).toBe(
      text('hilos-status-subscribers-tile-unavailable', tile),
    )
    expect(sent).toEqual([])
    expect(viewports).toEqual([])
  })

  it('reads work counts independently of a table window and recovers from unavailable through zero and named next work', () => {
    const { setOverview, sent, viewports, viewer } = page(overview())
    const tile = get('hilos-status-works-tile')
    const reservation = tile.querySelector('[aria-hidden="true"][inert]')
    expect(reservation).not.toBeNull()
    expect(tile.querySelector('h2')?.textContent).toBe('Scheduled maintenance')
    expect(text('hilos-status-works-count', tile)).toBe('—')
    expect(text('hilos-status-works-unavailable', tile)).toBe(
      'Scheduled maintenance data is unavailable.',
    )
    expect(find('hilos-status-works-next', tile)).toBeNull()
    expect(find('hilos-status-works-link', tile)).toBeNull()
    const nextWork = work({ title: '<b>Database & upgrade</b>' })
    for (const scheduledWorks of [
      { count: 0, nextWork: null },
      { count: 7, nextWork },
      { count: 2, nextWork: work({ title: 'New server title' }) },
    ]) {
      setOverview(overview({ scheduledWorks }))
      expect(text('hilos-status-works-count', tile)).toBe(
        String(scheduledWorks.count),
      )
      expect(find('hilos-status-works-unavailable', tile)).toBeNull()
      if (scheduledWorks.nextWork === null) {
        expect(text('hilos-status-works-empty', tile)).toBe(
          'No maintenance is scheduled.',
        )
        expect(find('hilos-status-works-next', tile)).toBeNull()
      } else {
        const next = get('hilos-status-works-next', tile)
        expect(next.textContent).toContain(scheduledWorks.nextWork.title)
        expect(next.querySelector('b')).toBeNull()
        expect(next.textContent).toContain(
          formatMoment(scheduledWorks.nextWork.startsAt),
        )
        expect(next.textContent).toContain(
          formatMoment(scheduledWorks.nextWork.endsAt),
        )
        expect(find('hilos-status-works-empty', tile)).toBeNull()
      }
      expect(get('hilos-status-works-tile')).toBe(tile)
      expect(tile.querySelector('[aria-hidden="true"][inert]')).toBe(
        reservation,
      )
    }
    viewer()
    setOverview(overview({ scheduledWorks: null }))
    expect(text('hilos-status-works-count', tile)).toBe('—')
    expect(find('hilos-status-works-unavailable', tile)).not.toBeNull()
    expect(find('hilos-status-works-next', tile)).toBeNull()
    expect(tile.querySelector('button')).toBeNull()
    expect(sent).toEqual([])
    expect(viewports).toEqual([])
  })

  it('uses a named native Works link when that route is served', () => {
    const { router, setOverview, sent } = page(overview())
    const resolve = router.resolvePath
    vi.spyOn(router, 'resolvePath').mockImplementation((key, params) =>
      key === HilosPages.STATUS_WORKS
        ? '/hilos/status/works'
        : resolve(key, params),
    )
    const navigate = vi.spyOn(router, 'navigate')
    setOverview(overview({ scheduledWorks: { count: 1, nextWork: work() } }))
    const link = get('hilos-status-works-link')
    expect(link.tagName).toBe('A')
    expect(link.textContent).toBe('Scheduled maintenance')
    expect(link.getAttribute('href')).toBe('/hilos/status/works')
    expect(link.getAttribute('aria-current')).toBeNull()
    fireEvent.click(link, { button: 0 })
    expect(navigate).toHaveBeenCalledExactlyOnceWith('/hilos/status/works')
    expect(sent).toEqual([])
  })

  it('projects each announcement verdict live beside the existing administrative maintenance facts', () => {
    const h = page(overview())
    const fact = {
      phase: 'verify',
      operation: 'restore',
      startedAt: 1800000000000,
    }
    const planned = work({ title: '<b>Saved announcement</b>' })
    for (const [announcement, label] of [
      [null, 'Announcement details are unavailable.'],
      [{ known: false, work: null }, 'Advance announcement unknown'],
      [{ known: true, work: null }, 'Not announced in advance'],
      [{ known: true, work: planned }, planned.title],
    ] as const) {
      h.setOverview(overview({ maintenance: { ...fact, announcement } }))
      const card = get('hilos-status-maintenance')
      const words = get('hilos-status-maintenance-announcement', card)
      const visible = heard(words)
      expect(visible).toContain(label)
      if (announcement?.work == null) expect(visible).toBe(label)
      expect(words.querySelector('b')).toBeNull()
      if (announcement?.work) {
        expect(visible).toContain(formatMoment(planned.startsAt))
        expect(visible).toContain(formatMoment(planned.endsAt))
      } else {
        expect(visible).not.toContain(formatMoment(planned.endsAt))
      }
      expect(card.textContent).toContain('verify')
      expect(card.textContent).toContain('restore')
      expect(card.textContent).toContain(formatMoment(fact.startedAt))
      expect(card.querySelector('h2')?.textContent).toBe(
        'Maintenance in progress',
      )
      expect(card.querySelector('a')?.getAttribute('href')).toBe(
        '/hilos/maintenance',
      )
      expect(card.querySelector('[role="alert"], [aria-live]')).toBeNull()
    }
    h.viewer()
    expect(text('hilos-status-maintenance-announcement')).toContain(
      planned.title,
    )
    expect(h.sent).toEqual([])
    expect(h.viewports).toEqual([])
  })

  it('updates both planned intervals when the reader changes zone without another page request', () => {
    const planned = work()
    const { sent } = page(
      overview({
        scheduledWorks: { count: 1, nextWork: planned },
        maintenance: {
          phase: 'verify',
          operation: 'restore',
          startedAt: 1800000000000,
          announcement: { known: true, work: planned },
        },
      }),
    )
    const before = text('hilos-status-works-next')
    const zone = readerTimeZone()
    cleanups.push(zone.release)
    act(() => zone.choose('Asia/Tokyo'))
    expect(text('hilos-status-works-next')).not.toBe(before)
    for (const id of [
      'hilos-status-works-next',
      'hilos-status-maintenance-announcement',
    ]) {
      const interval = text(id)
      expect(interval).toContain(formatMoment(planned.startsAt))
      expect(interval).toContain(formatMoment(planned.endsAt))
    }
    expect(sent).toEqual([])
  })

  it('waits for data and paints green only for fully known operational health', () => {
    const { setOverview } = page()
    expect(find('hilos-status-overview')).toBeNull()
    setOverview(
      overview({
        incidents: [],
        components: { operationalCount: 2, totalCount: 2 },
      }),
    )
    const banner = get('hilos-status-operational')
    expect(text('hilos-status-operational-label', banner)).toBe(
      'All systems operational',
    )
    const publicLink = get('hilos-status-public-link', banner)
    expect(publicLink.tagName).toBe('A')
    expect(publicLink.getAttribute('href')).toBe('/status')
    expect(publicLink.textContent).toBe('Public status page')
    expect(publicLink.getAttribute('aria-hidden')).toBeNull()
    expect(publicLink.getAttribute('tabindex')).toBeNull()
    setOverview(
      overview({
        healthKnown: false,
        incidents: null,
        publishDelaySeconds: null,
      }),
    )
    expect(find('hilos-status-operational')).toBeNull()
    expect(find('hilos-status-public-link')).toBeNull()
    expect(text('hilos-status-unknown')).toBe('Status is being checked')
    expect(text('hilos-status-incidents-unavailable')).toBe(
      'Incident data is unavailable.',
    )
    expect(text('hilos-status-delay')).toBe('Publication delay is unavailable.')
    expect(text('hilos-status-components-link')).toContain('0 / 2')
  })

  it('retains multiple facts under unknown health and suppression removes only its timer and actions', () => {
    page(
      overview({
        healthKnown: false,
        incidents: [
          incident({ suppressedAt: 1800000000000 }),
          incident({
            incidentId: 12,
            componentKey: 'mail',
            componentName: 'Mail',
            publishedAt: 1800000000000,
            severity: 'partial_outage',
            updates: [
              {
                id: 1,
                kind: 'team',
                severity: 'partial_outage',
                text: HIDDEN_VALUE,
                at: 1800000000000,
              },
            ],
          }),
        ],
      }),
    )
    expect(find('hilos-status-unknown')).not.toBeNull()
    const suppressed = get('hilos-status-incident-11')
    expect(suppressed.textContent).toContain('Checking')
    expect(suppressed.textContent).toContain('Publication canceled manually')
    expect(suppressed.querySelector('button')).toBeNull()
    expect(find('hilos-status-countdown-11', suppressed)).toBeNull()
    const published = get('hilos-status-incident-12')
    expect(published.textContent).toContain('Partial outage')
    expect(text('hilos-hidden', published)).toContain('Hidden')
    expect(find('hilos-status-add-update-12', published)).not.toBeNull()
  })

  it('gives maintenance priority over every health summary while the available incident cards stay', () => {
    const maintenance = {
      phase: 'verify',
      operation: 'restore',
      startedAt: 1800000000000,
      announcement: null,
    }
    const { setOverview } = page(overview({ maintenance }))
    expect(text('hilos-status-maintenance')).toContain('restore')
    expect(get('hilos-status-maintenance-link').getAttribute('href')).toBe(
      '/hilos/maintenance',
    )
    expect(text('hilos-status-incident-11')).toContain('Service')
    expect(find('hilos-status-components-link')).not.toBeNull()
    for (const [facts, summary] of [
      [
        { incidents: [], components: { operationalCount: 2, totalCount: 2 } },
        'hilos-status-operational',
      ],
      [{ healthKnown: false }, 'hilos-status-unknown'],
      [{ incidents: [] }, 'hilos-status-checking'],
    ] as const) {
      setOverview(overview(facts))
      expect(find(summary)).not.toBeNull()
      expect(find('hilos-status-maintenance')).toBeNull()
      setOverview(overview({ ...facts, maintenance }))
      expect(find(summary)).toBeNull()
      expect(text('hilos-status-maintenance')).toContain('restore')
    }
  })

  it('keeps an expired candidate Checking without announcing every countdown tick', async () => {
    page(overview())
    const timer = get('hilos-status-countdown-11')
    expect(timer.getAttribute('aria-live')).toBeNull()
    expect(timer.getAttribute('role')).toBeNull()
    await act(async () => {
      await vi.advanceTimersByTimeAsync(61000)
    })
    expect(text('hilos-status-awaiting-11')).toContain('Waiting for the server')
    expect(find('hilos-status-publish-now-11')).not.toBeNull()
    expect(text('hilos-status-incident-11')).toContain('Checking')
  })

  it('updates moments when the reader changes time zone, preserving hidden settings', () => {
    const { context, emit } = page(
      overview({ publishDelaySeconds: HIDDEN_VALUE }),
    )
    cleanups.push(bindTimeZoneState(context.connection))
    const before = text('hilos-status-incident-11')
    act(() =>
      emit('projectSignal', {
        type: 'handshake_response',
        data: { entities: { currentUser: { id: 1, timeZone: 'Asia/Tokyo' } } },
      }),
    )
    expect(text('hilos-status-incident-11')).not.toBe(before)
    expect(text('hilos-status-delay')).toBe('Hidden')
  })

  it('uses real disabled mutation controls described by the viewer banner', () => {
    const { viewer, sent } = page(overview())
    viewer()
    for (const id of ['cancel-publication', 'publish-now']) {
      const button = get(`hilos-status-${id}-11`) as HTMLButtonElement
      expect(button.disabled).toBe(true)
      expect(button.getAttribute('aria-describedby')).toBe(
        HILOS_VIEW_MODE_STRIP_TEXT_ID,
      )
      fireEvent.click(button)
    }
    expect(find('modal')).toBeNull()
    expect(sent).toEqual([])
  })

  it('escapes team updates as plain text in the server timeline order', () => {
    page(
      overview({
        incidents: [
          incident({
            publishedAt: 1800000000000,
            updates: [
              {
                id: 2,
                kind: 'team',
                severity: 'major_outage',
                text: '<b>New team update</b>',
                at: 1800000002000,
              },
              {
                id: 1,
                kind: 'published',
                severity: 'major_outage',
                text: 'Original publication',
                at: 1800000001000,
              },
            ],
          }),
        ],
      }),
    )
    const timeline = get('hilos-status-timeline-11')
    expect(
      [...timeline.querySelectorAll('li')].map((li) => li.textContent),
    ).toEqual([
      expect.stringContaining('<b>New team update</b>'),
      expect.stringContaining('Original publication'),
    ])
    expect(timeline.querySelector('b')).toBeNull()
  })
})

describe('HilosStatusPage lifecycle', () => {
  it('gives the StrictMode replay one countdown of its own and leaves nothing after unmount', () => {
    const baseline = vi.getTimerCount()
    const plain = page(overview())
    const plainTimers = vi.getTimerCount() - baseline
    expect(plainTimers).toBeGreaterThan(0)
    plain.unmount()
    expect(vi.getTimerCount()).toBe(baseline)

    const strict = page(overview(), true)
    expect(vi.getTimerCount() - baseline).toBe(plainTimers)
    expect(find('hilos-status-countdown-11')).not.toBeNull()
    strict.unmount()
    expect(find('hilos-status-overview')).toBeNull()
    expect(vi.getTimerCount()).toBe(baseline)
  })

  it('opens, submits and closes on the answer through the StrictMode replay generation', async () => {
    const { sent, reply } = page(overview(), true)
    fireEvent.click(get('hilos-status-publish-now-11'))
    expect(text('modal')).toContain('Service · #11')
    fireEvent.submit(get('hilos-status-incident-form'))
    expect(sent).toEqual([
      {
        action: 'hilos_status_publish_now',
        data: { incidentId: 11 },
        requestId: expect.any(String),
      },
    ])
    await act(async () => {
      reply(true)
      await vi.advanceTimersByTimeAsync(0)
    })
    expect(find('modal')).toBeNull()
    expect(hilosToasts.toasts.get().map((toast) => toast.message)).toEqual([
      'Outage published.',
    ])
  })

  it('lets a late answer after unmount neither revive the window nor toast, and a remount opens a fresh one', async () => {
    const h = page(overview())
    fireEvent.click(get('hilos-status-publish-now-11'))
    fireEvent.submit(get('hilos-status-incident-form'))
    expect(h.sent).toHaveLength(1)
    h.unmount()
    await act(async () => {
      h.reply(true)
      await vi.advanceTimersByTimeAsync(0)
    })
    expect(find('modal')).toBeNull()
    expect(hilosToasts.toasts.get()).toEqual([])

    const again = h.mount(<HilosStatusPage context={h.context} />, true)
    cleanups.push(again.unmount)
    fireEvent.click(get('hilos-status-cancel-publication-11'))
    expect(text('modal')).toContain('Do not publish')
    expect(
      (get('hilos-status-incident-confirm') as HTMLButtonElement).disabled,
    ).toBe(false)
  })
})
