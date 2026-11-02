import { By } from '@angular/platform-browser'
import {
  HIDDEN_VALUE,
  HilosPages,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  formatMoment,
} from '@hilos/core/base'
import { type HilosStatusPublicWork } from '@hilos/core/admin/status'
import { describe, expect, it, vi } from 'vitest'

import { HilosAdminPage } from '../src/HilosAdminPage.js'
import { HilosStatusPage } from '../src/admin/status/HilosStatusPage.js'
import {
  el,
  incident,
  mountStatus,
  overview,
  query,
  statusHarness,
  text,
} from './helpers/hilosStatus.js'

function work(): HilosStatusPublicWork {
  return {
    id: 'public-work',
    title: '<b>Database & upgrade</b>',
    description: null,
    componentKeys: ['service'],
    startsAt: 1_800_000_060_000,
    endsAt: 1_800_003_600_000,
  }
}
const page = (data?: unknown) =>
  mountStatus(HilosStatusPage, HilosPages.STATUS, data)

describe('HilosStatusPage', () => {
  it('hands the Status section identity and tab name to the shared head without requesting data', () => {
    const h = page(overview())
    const head = h.fixture.debugElement.query(By.directive(HilosAdminPage))
      .componentInstance as HilosAdminPage
    expect([head.page(), head.headKind(), head.tabsLabel()]).toEqual([
      HilosPages.STATUS,
      'section',
      'Status pages',
    ])
    expect(el('hilos-status-components-link').getAttribute('href')).toBe(
      '/hilos/status/components',
    )
    expect(el('hilos-status-settings-link').getAttribute('href')).toBe(
      '/hilos/settings',
    )
    expect(h.sent).toEqual([])
    expect(h.viewports).toEqual([])
  })

  it('waits for the page answer and calls health operational only when every component is known operational', () => {
    const h = page()
    expect(query('hilos-status-overview')).toBeNull()
    h.act(() =>
      h.setOverview(
        overview({
          incidents: [],
          components: { operationalCount: 2, totalCount: 2 },
        }),
      ),
    )
    expect(text('hilos-status-operational-label')).toBe(
      'All systems operational',
    )
    expect(el('hilos-status-public-link').getAttribute('href')).toBe('/status')
    h.act(() =>
      h.setOverview(
        overview({
          incidents: [],
          components: { operationalCount: 1, totalCount: 2 },
        }),
      ),
    )
    expect(query('hilos-status-operational')).toBeNull()
    expect(text('hilos-status-checking')).toBe('Checking')
    h.act(() =>
      h.setOverview(
        overview({
          healthKnown: false,
          incidents: null,
          publishDelaySeconds: null,
        }),
      ),
    )
    expect(text('hilos-status-unknown')).toBe('Status is being checked')
    expect(text('hilos-status-incidents-unavailable')).toBe(
      'Incident data is unavailable.',
    )
    expect(text('hilos-status-delay')).toBe('Publication delay is unavailable.')
    expect(text('hilos-status-components-link')).toContain('0 / 2')
    expect(query('hilos-status-public-link')).toBeNull()
  })

  it('keeps suppressed facts Checking without countdown or publication actions alongside a published outage', () => {
    page(
      overview({
        healthKnown: false,
        incidents: [
          incident({ suppressedAt: Date.now() }),
          incident({
            incidentId: 12,
            componentName: 'Mail',
            publishedAt: Date.now(),
            severity: 'partial_outage',
          }),
        ],
      }),
    )
    expect(query('hilos-status-unknown')).not.toBeNull()
    expect(text('hilos-status-incident-11')).toContain('Checking')
    expect(text('hilos-status-suppressed-11')).toBe(
      'Publication canceled manually',
    )
    expect(query('hilos-status-countdown-11')).toBeNull()
    expect(query('hilos-status-cancel-publication-11')).toBeNull()
    expect(query('hilos-status-publish-now-11')).toBeNull()
    expect(text('hilos-status-incident-12')).toContain('Partial outage')
    expect(query('hilos-status-add-update-12')).not.toBeNull()
  })

  it('keeps an expired candidate Checking until the server publishes it', async () => {
    const h = page(overview())
    expect(el('hilos-status-countdown-11').getAttribute('aria-live')).toBeNull()
    await vi.advanceTimersByTimeAsync(61_000)
    h.detect()
    expect(text('hilos-status-awaiting-11')).toContain('Waiting for the server')
    expect(text('hilos-status-incident-11')).toContain('Checking')
    expect(query('hilos-status-publish-now-11')).not.toBeNull()
  })

  it('renders timeline text and Hidden in server order without interpreting HTML', () => {
    page(
      overview({
        publishDelaySeconds: HIDDEN_VALUE,
        incidents: [
          incident({
            publishedAt: Date.now(),
            updates: [
              {
                id: 2,
                kind: 'team',
                severity: 'major_outage',
                text: '<b>Plain team update</b>',
                at: Date.now() + 2000,
              },
              {
                id: 1,
                kind: 'published',
                severity: 'major_outage',
                text: HIDDEN_VALUE,
                at: Date.now() + 1000,
              },
            ],
          }),
        ],
      }),
    )
    const entries = [...el('hilos-status-timeline-11').querySelectorAll('li')]
    expect(entries.map((node) => node.textContent)).toEqual([
      expect.stringContaining('<b>Plain team update</b>'),
      expect.stringContaining('Hidden'),
    ])
    expect(el('hilos-status-timeline-11').querySelector('b')).toBeNull()
    expect(text('hilos-status-delay')).toBe('Hidden')
  })

  it('reads independent subscriber counts as unavailable, known zero or exact totals', () => {
    const h = page(overview({ subscribers: null }))
    const tile = el('hilos-status-subscribers-tile')
    expect([
      text('hilos-status-subscribers-tile-confirmed'),
      text('hilos-status-subscribers-tile-pending'),
    ]).toEqual(['—', '—'])
    expect(query('hilos-status-subscribers-tile-unavailable')).not.toBeNull()
    for (const counts of [
      { confirmedCount: 0, pendingCount: 0 },
      { confirmedCount: 214, pendingCount: 3 },
    ]) {
      h.act(() => h.setOverview(overview({ subscribers: counts })))
      expect([
        text('hilos-status-subscribers-tile-confirmed'),
        text('hilos-status-subscribers-tile-pending'),
      ]).toEqual([String(counts.confirmedCount), String(counts.pendingCount)])
      expect(query('hilos-status-subscribers-tile-unavailable')).toBeNull()
      expect(el('hilos-status-subscribers-tile')).toBe(tile)
    }
    expect(el('hilos-status-subscribers-link').getAttribute('href')).toBe(
      '/hilos/status/subscribers',
    )
    expect(h.sent).toEqual([])
    expect(h.viewports).toEqual([])
  })

  it('uses authoritative history totals including zero and unavailable without creating a timer', () => {
    const h = page(overview({ incidents: [], historyCount: null }))
    const timers = vi.getTimerCount()
    for (const count of [0, 713, 1, null]) {
      h.act(() =>
        h.setOverview(overview({ incidents: [], historyCount: count })),
      )
      expect(text('hilos-status-history-count')).toBe(
        count === null ? '—' : String(count),
      )
      expect(query('hilos-status-history-unavailable') !== null).toBe(
        count === null,
      )
    }
    expect(el('hilos-status-history-link').getAttribute('href')).toBe(
      '/hilos/status/history',
    )
    expect(vi.getTimerCount()).toBe(timers)
    expect(h.sent).toEqual([])
    expect(h.viewports).toEqual([])
  })

  it('follows known empty, named next work and unavailable scheduled summaries without table traffic', () => {
    const h = page(overview())
    expect(text('hilos-status-works-count')).toBe('—')
    expect(query('hilos-status-works-unavailable')).not.toBeNull()
    h.act(() =>
      h.setOverview(overview({ scheduledWorks: { count: 0, nextWork: null } })),
    )
    expect(text('hilos-status-works-count')).toBe('0')
    expect(text('hilos-status-works-empty')).toBe(
      'No maintenance is scheduled.',
    )
    h.act(() =>
      h.setOverview(
        overview({ scheduledWorks: { count: 7, nextWork: work() } }),
      ),
    )
    expect(text('hilos-status-works-count')).toBe('7')
    expect(text('hilos-status-works-next')).toContain(work().title)
    expect(el('hilos-status-works-next').querySelector('b')).toBeNull()
    const navigate = vi.spyOn(h.router, 'navigate')
    el('hilos-status-works-link').click()
    expect(navigate).toHaveBeenCalledExactlyOnceWith('/hilos/status/works')
    h.act(() => h.setOverview(overview({ scheduledWorks: null })))
    expect(text('hilos-status-works-count')).toBe('—')
    expect(query('hilos-status-works-next')).toBeNull()
    expect(h.sent).toEqual([])
    expect(h.viewports).toEqual([])
  })

  it('distinguishes all maintenance announcement verdicts while retaining the current incident', () => {
    const h = page(overview())
    for (const [announcement, label] of [
      [null, 'Announcement details are unavailable.'],
      [{ known: false, work: null }, 'Advance announcement unknown'],
      [{ known: true, work: null }, 'Not announced in advance'],
      [{ known: true, work: work() }, work().title],
    ] as const) {
      h.act(() =>
        h.setOverview(
          overview({
            maintenance: {
              phase: 'verify',
              operation: 'restore',
              startedAt: Date.now(),
              announcement,
            },
          }),
        ),
      )
      expect(text('hilos-status-maintenance-announcement')).toContain(label)
      expect(text('hilos-status-maintenance')).toContain('restore')
      expect(
        el('hilos-status-maintenance-announcement').querySelector('b'),
      ).toBeNull()
      expect(el('hilos-status-maintenance-link').getAttribute('href')).toBe(
        '/hilos/maintenance',
      )
      expect(query('hilos-status-incident-11')).not.toBeNull()
      expect(query('hilos-status-operational')).toBeNull()
    }
  })

  it('redraws incident and both work intervals in the reader zone without another request', () => {
    const h = page(
      overview({
        scheduledWorks: { count: 1, nextWork: work() },
        maintenance: {
          phase: 'verify',
          operation: 'restore',
          startedAt: Date.now(),
          announcement: { known: true, work: work() },
        },
      }),
    )
    h.chooseZone('Europe/Warsaw')
    const before = text('hilos-status-incident-11')
    const intervalBefore = text('hilos-status-works-next')
    h.chooseZone('Asia/Tokyo')
    expect(text('hilos-status-incident-11')).not.toBe(before)
    expect(text('hilos-status-works-next')).not.toBe(intervalBefore)
    for (const id of [
      'hilos-status-works-next',
      'hilos-status-maintenance-announcement',
    ]) {
      expect(text(id)).toContain(formatMoment(work().startsAt))
      expect(text(id)).toContain(formatMoment(work().endsAt))
    }
    expect(h.sent).toEqual([])
    expect(h.viewports).toEqual([])
  })

  it('describes disabled viewer controls through the common banner and sends no writes', () => {
    const h = page(overview())
    h.act(h.viewer)
    for (const action of ['cancel-publication', 'publish-now']) {
      const button = el<HTMLButtonElement>(`hilos-status-${action}-11`)
      expect(button.disabled).toBe(true)
      expect(button.getAttribute('aria-describedby')).toBe(
        HILOS_VIEW_MODE_STRIP_TEXT_ID,
      )
      button.click()
    }
    h.detect()
    expect(query('modal')).toBeNull()
    expect(h.sent).toEqual([])
  })

  it('disposes the previous context generation and its incident window, then releases the final timer on destroy', () => {
    const startTimer = vi.spyOn(globalThis, 'setInterval')
    const stopTimer = vi.spyOn(globalThis, 'clearInterval')
    const h = page(overview())
    const firstTimer = startTimer.mock.results[0]!.value
    const second = statusHarness()
    try {
      second.setOverview(
        overview({
          incidents: [
            incident({ incidentId: 23, componentName: 'Replacement' }),
          ],
        }),
      )
      h.act(() => el('hilos-status-publish-now-11').click())
      h.fixture.componentRef.setInput('context', second.context)
      h.detect()
      expect(query('modal')).toBeNull()
      expect(query('hilos-status-incident-11')).toBeNull()
      expect(text('hilos-status-incident-23')).toContain('Replacement')
      expect(stopTimer).toHaveBeenCalledWith(firstTimer)
      const currentTimer = startTimer.mock.results.at(-1)!.value
      h.act(() => h.setOverview(overview({ incidents: [] })))
      expect(query('hilos-status-incident-23')).not.toBeNull()
      h.fixture.destroy()
      expect(stopTimer).toHaveBeenCalledWith(currentTimer)
    } finally {
      second.dispose()
    }
  })
})
