import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  HIDDEN_VALUE,
  HilosPages,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  applyServerTime,
  bindTimeZoneState,
  formatMoment,
} from '@hilos/core/base'
import type { HilosStatusPublicWork } from '@hilos/core/admin/status'

import HilosStatusPage from './HilosStatusPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { readerTimeZone } from '../../../test/helpers/readerTimeZone.js'
import {
  statusHarness,
  incident,
  overview,
} from '../../../test/statusFixtures.js'

const cleanups: (() => void)[] = []
beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(1800000000000)
  applyServerTime(Date.now())
  const zone = readerTimeZone()
  zone.choose('Europe/Warsaw')
  cleanups.push(zone.release)
})
afterEach(() => {
  for (const cleanup of cleanups.splice(0).reverse()) cleanup()
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

async function page(data?: unknown) {
  const h = statusHarness()
  cleanups.push(h.dispose)
  if (data !== undefined) h.setOverview(data)
  const wrapper = mount(HilosStatusPage, {
    props: { context: markRaw(h.context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: h.router } },
  })
  cleanups.push(() => wrapper.unmount())
  await nextTick()
  return { ...h, wrapper }
}

describe('HilosStatusPage', () => {
  it('uses the real shared head with one h1, three route links and only the active tab current', async () => {
    const { wrapper, sent } = await page(overview())
    expect(wrapper.findAll('h1').map((h) => h.text())).toEqual(['Status'])
    const tabs = wrapper.get('nav[aria-label="Status pages"]')
    expect(
      tabs
        .findAll('a')
        .map((a) => [
          a.text(),
          a.attributes('href'),
          a.attributes('aria-current'),
        ]),
    ).toEqual([
      ['Overview', '/hilos/status', 'page'],
      ['Components', '/hilos/status/components', undefined],
      ['Subscribers', '/hilos/status/subscribers', undefined],
    ])
    expect(wrapper.get('[data-id="hilos-status-components-link"]').text()).toBe(
      '0 / 2 operational · View component health',
    )
    expect(
      wrapper.get('[data-id="hilos-status-settings-link"]').attributes('href'),
    ).toBe('/hilos/settings')
    expect(sent).toEqual([])
  })
  it('links to Subscribers and renders authoritative counts, zero and unavailable in the same tile frame', async () => {
    const { wrapper, setOverview, sent, viewports } = await page(
      overview({ subscribers: null }),
    )
    const tile = wrapper.get('[data-id="hilos-status-subscribers-tile"]')
    const frame = tile.element
    const confirmed = () =>
      tile.get('[data-id="hilos-status-subscribers-tile-confirmed"]').text()
    const pending = () =>
      tile.get('[data-id="hilos-status-subscribers-tile-pending"]').text()
    expect([confirmed(), pending()]).toEqual(['—', '—'])
    expect(
      tile.get('[data-id="hilos-status-subscribers-tile-unavailable"]').text(),
    ).toBe('Subscriber data is unavailable.')
    expect(
      tile.get('[data-id="hilos-status-subscribers-link"]').attributes('href'),
    ).toBe('/hilos/status/subscribers')
    for (const counts of [
      { confirmedCount: 0, pendingCount: 0 },
      { confirmedCount: 214, pendingCount: 3 },
    ]) {
      setOverview(overview({ subscribers: counts }))
      await nextTick()
      expect([confirmed(), pending()]).toEqual([
        String(counts.confirmedCount),
        String(counts.pendingCount),
      ])
      expect(
        tile
          .find('[data-id="hilos-status-subscribers-tile-unavailable"]')
          .exists(),
      ).toBe(false)
      expect(
        wrapper.get('[data-id="hilos-status-subscribers-tile"]').element,
      ).toBe(frame)
    }
    setOverview(overview({ subscribers: null }))
    await nextTick()
    expect([confirmed(), pending()]).toEqual(['—', '—'])
    expect(
      tile.find('.hilos-stack > .invisible[aria-hidden="true"]').exists(),
    ).toBe(true)
    expect(sent).toEqual([])
    expect(viewports).toEqual([])
  })
  it('reads work counts independently of a table window and recovers from unavailable through zero and named next work', async () => {
    const { wrapper, setOverview, sent, viewports, viewer } =
      await page(overview())
    const tile = wrapper.get('[data-id="hilos-status-works-tile"]')
    const frame = tile.element
    const reservation = tile.get(
      '.invisible[aria-hidden="true"][inert]',
    ).element
    expect(tile.get('h2').text()).toBe('Scheduled maintenance')
    expect(tile.get('[data-id="hilos-status-works-count"]').text()).toBe('—')
    expect(tile.get('[data-id="hilos-status-works-unavailable"]').text()).toBe(
      'Scheduled maintenance data is unavailable.',
    )
    expect(tile.find('[data-id="hilos-status-works-next"]').exists()).toBe(
      false,
    )
    expect(tile.find('[data-id="hilos-status-works-link"]').exists()).toBe(
      false,
    )
    const nextWork = work({ title: '<b>Database & upgrade</b>' })
    for (const scheduledWorks of [
      { count: 0, nextWork: null },
      { count: 7, nextWork },
      { count: 2, nextWork: work({ title: 'New server title' }) },
    ]) {
      setOverview(overview({ scheduledWorks }))
      await nextTick()
      expect(tile.get('[data-id="hilos-status-works-count"]').text()).toBe(
        String(scheduledWorks.count),
      )
      expect(
        tile.find('[data-id="hilos-status-works-unavailable"]').exists(),
      ).toBe(false)
      if (scheduledWorks.nextWork === null) {
        expect(tile.get('[data-id="hilos-status-works-empty"]').text()).toBe(
          'No maintenance is scheduled.',
        )
        expect(tile.find('[data-id="hilos-status-works-next"]').exists()).toBe(
          false,
        )
      } else {
        const next = tile.get('[data-id="hilos-status-works-next"]')
        expect(next.text()).toContain(scheduledWorks.nextWork.title)
        expect(next.find('b').exists()).toBe(false)
        expect(next.text()).toContain(
          formatMoment(scheduledWorks.nextWork.startsAt),
        )
        expect(next.text()).toContain(
          formatMoment(scheduledWorks.nextWork.endsAt),
        )
        expect(tile.find('[data-id="hilos-status-works-empty"]').exists()).toBe(
          false,
        )
      }
      expect(wrapper.get('[data-id="hilos-status-works-tile"]').element).toBe(
        frame,
      )
      expect(tile.get('.invisible').element).toBe(reservation)
    }
    viewer()
    setOverview(overview({ scheduledWorks: null }))
    await nextTick()
    expect(tile.get('[data-id="hilos-status-works-count"]').text()).toBe('—')
    expect(
      tile.find('[data-id="hilos-status-works-unavailable"]').exists(),
    ).toBe(true)
    expect(tile.find('[data-id="hilos-status-works-next"]').exists()).toBe(
      false,
    )
    expect(tile.find('button').exists()).toBe(false)
    expect(sent).toEqual([])
    expect(viewports).toEqual([])
  })
  it('uses a named native Works link when that route is served, preserving the shared head and its single tab row', async () => {
    const { wrapper, router, setOverview, sent } = await page(overview())
    const resolve = router.resolvePath
    vi.spyOn(router, 'resolvePath').mockImplementation((key, params) =>
      key === HilosPages.STATUS_WORKS
        ? '/hilos/status/works'
        : resolve(key, params),
    )
    const navigate = vi.spyOn(router, 'navigate')
    setOverview(overview({ scheduledWorks: { count: 1, nextWork: work() } }))
    await nextTick()
    const link = wrapper.get('a[data-id="hilos-status-works-link"]')
    expect(link.text()).toBe('Scheduled maintenance')
    expect(link.attributes('href')).toBe('/hilos/status/works')
    expect(link.attributes('aria-current')).toBeUndefined()
    await link.trigger('click', { button: 0 })
    expect(navigate).toHaveBeenCalledExactlyOnceWith('/hilos/status/works')
    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.findAll('nav[aria-label="Status pages"]')).toHaveLength(1)
    expect(wrapper.find('[role="tablist"]').exists()).toBe(false)
    expect(sent).toEqual([])
  })
  it('projects each announcement verdict live beside the existing administrative maintenance facts', async () => {
    const h = await page(overview())
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
      await nextTick()
      const card = h.wrapper.get('[data-id="hilos-status-maintenance"]')
      const words = card.get(
        '[data-id="hilos-status-maintenance-announcement"]',
      )
      const visible = words.get('.hilos-stack > :not([aria-hidden])')
      expect(visible.text()).toContain(label)
      if (announcement?.work == null) expect(visible.text()).toBe(label)
      expect(words.find('b').exists()).toBe(false)
      if (announcement?.work) {
        expect(visible.text()).toContain(formatMoment(planned.startsAt))
        expect(visible.text()).toContain(formatMoment(planned.endsAt))
      } else {
        expect(visible.text()).not.toContain(formatMoment(planned.endsAt))
      }
      expect(card.text()).toContain('verify')
      expect(card.text()).toContain('restore')
      expect(card.text()).toContain(formatMoment(fact.startedAt))
      expect(card.get('h2').text()).toBe('Maintenance in progress')
      expect(card.get('a').attributes('href')).toBe('/hilos/maintenance')
      expect(card.find('[role="alert"], [aria-live]').exists()).toBe(false)
    }
    h.viewer()
    await nextTick()
    expect(
      h.wrapper.get('[data-id="hilos-status-maintenance-announcement"]').text(),
    ).toContain(planned.title)
    expect(h.sent).toEqual([])
    expect(h.viewports).toEqual([])
  })
  it('updates both planned intervals when the reader changes zone without another page request', async () => {
    const planned = work()
    const { wrapper, sent } = await page(
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
    const before = wrapper.get('[data-id="hilos-status-works-next"]').text()
    const zone = readerTimeZone()
    cleanups.push(zone.release)
    zone.choose('Asia/Tokyo')
    await nextTick()
    expect(wrapper.get('[data-id="hilos-status-works-next"]').text()).not.toBe(
      before,
    )
    for (const id of [
      'hilos-status-works-next',
      'hilos-status-maintenance-announcement',
    ]) {
      const interval = wrapper.get(`[data-id="${id}"]`).text()
      expect(interval).toContain(formatMoment(planned.startsAt))
      expect(interval).toContain(formatMoment(planned.endsAt))
    }
    expect(sent).toEqual([])
  })
  it('waits for data and paints green only for fully known operational health', async () => {
    const { wrapper, setOverview } = await page()
    expect(wrapper.find('[data-id="hilos-status-overview"]').exists()).toBe(
      false,
    )
    setOverview(
      overview({
        incidents: [],
        components: { operationalCount: 2, totalCount: 2 },
      }),
    )
    await nextTick()
    const banner = wrapper.get('[data-id="hilos-status-operational"]')
    expect(
      banner.get('[data-id="hilos-status-operational-label"]').text(),
    ).toBe('All systems operational')
    const publicLink = banner.get('a[data-id="hilos-status-public-link"]')
    expect(publicLink.attributes('href')).toBe('/status')
    expect(publicLink.text()).toBe('Public status page')
    expect(publicLink.attributes('aria-hidden')).toBeUndefined()
    expect(publicLink.attributes('tabindex')).toBeUndefined()
    setOverview(
      overview({
        healthKnown: false,
        incidents: null,
        publishDelaySeconds: null,
      }),
    )
    await nextTick()
    expect(wrapper.find('[data-id="hilos-status-operational"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-status-public-link"]').exists()).toBe(
      false,
    )
    expect(wrapper.get('[data-id="hilos-status-unknown"]').text()).toBe(
      'Status is being checked',
    )
    expect(
      wrapper.get('[data-id="hilos-status-incidents-unavailable"]').text(),
    ).toBe('Incident data is unavailable.')
    expect(wrapper.get('[data-id="hilos-status-delay"]').text()).toBe(
      'Publication delay is unavailable.',
    )
    expect(
      wrapper.get('[data-id="hilos-status-components-link"]').text(),
    ).toContain('0 / 2')
  })
  it('retains multiple facts under unknown health and suppression removes only its timer and actions', async () => {
    const { wrapper } = await page(
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
    expect(wrapper.find('[data-id="hilos-status-unknown"]').exists()).toBe(true)
    const suppressed = wrapper.get('[data-id="hilos-status-incident-11"]')
    expect(suppressed.text()).toContain('Checking')
    expect(suppressed.text()).toContain('Publication canceled manually')
    expect(suppressed.find('button').exists()).toBe(false)
    expect(
      suppressed.find('[data-id="hilos-status-countdown-11"]').exists(),
    ).toBe(false)
    const published = wrapper.get('[data-id="hilos-status-incident-12"]')
    expect(published.text()).toContain('Partial outage')
    expect(published.get('[data-id="hilos-hidden"]').text()).toContain('Hidden')
    expect(
      published.find('[data-id="hilos-status-add-update-12"]').exists(),
    ).toBe(true)
  })
  it('shows maintenance before incident cards and keeps the available summary', async () => {
    const { wrapper } = await page(
      overview({
        maintenance: {
          phase: 'verify',
          operation: 'restore',
          startedAt: 1800000000000,
          announcement: null,
        },
      }),
    )
    expect(
      wrapper
        .findAll('section[data-id^="hilos-status-"]')
        .slice(0, 2)
        .map((s) => s.attributes('data-id')),
    ).toEqual(['hilos-status-maintenance', 'hilos-status-incident-11'])
    expect(
      wrapper.get('[data-id="hilos-status-maintenance"]').text(),
    ).toContain('restore')
    expect(
      wrapper
        .get('[data-id="hilos-status-maintenance-link"]')
        .attributes('href'),
    ).toBe('/hilos/maintenance')
    expect(wrapper.find('[data-id="hilos-status-operational"]').exists()).toBe(
      false,
    )
    expect(
      wrapper.find('[data-id="hilos-status-components-link"]').exists(),
    ).toBe(true)
  })
  it('keeps an expired candidate Checking without announcing every countdown tick', async () => {
    const { wrapper } = await page(overview())
    const timer = wrapper.get('[data-id="hilos-status-countdown-11"]')
    expect(timer.attributes('aria-live')).toBeUndefined()
    expect(timer.attributes('role')).toBeUndefined()
    await vi.advanceTimersByTimeAsync(61000)
    expect(
      wrapper.get('[data-id="hilos-status-awaiting-11"]').text(),
    ).toContain('Waiting for the server')
    expect(
      wrapper.find('[data-id="hilos-status-publish-now-11"]').exists(),
    ).toBe(true)
    expect(
      wrapper.get('[data-id="hilos-status-incident-11"]').text(),
    ).toContain('Checking')
  })
  it('updates moments when the reader changes time zone, preserving hidden settings', async () => {
    const { wrapper, context, emit } = await page(
      overview({ publishDelaySeconds: HIDDEN_VALUE }),
    )
    cleanups.push(bindTimeZoneState(context.connection))
    const before = wrapper.get('[data-id="hilos-status-incident-11"]').text()
    emit('projectSignal', {
      type: 'handshake_response',
      data: { entities: { currentUser: { id: 1, timeZone: 'Asia/Tokyo' } } },
    })
    await nextTick()
    expect(wrapper.get('[data-id="hilos-status-incident-11"]').text()).not.toBe(
      before,
    )
    expect(wrapper.get('[data-id="hilos-status-delay"]').text()).toBe('Hidden')
  })
  it('uses real disabled mutation controls described by the viewer banner', async () => {
    const { wrapper, viewer, sent } = await page(overview())
    viewer()
    await nextTick()
    for (const id of ['cancel-publication', 'publish-now']) {
      const button = wrapper.get(`[data-id="hilos-status-${id}-11"]`)
      expect((button.element as HTMLButtonElement).disabled).toBe(true)
      expect(button.attributes('aria-describedby')).toBe(
        HILOS_VIEW_MODE_STRIP_TEXT_ID,
      )
      await button.trigger('click')
    }
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(sent).toEqual([])
  })
  it('escapes team updates as plain text in the server timeline order', async () => {
    const { wrapper } = await page(
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
    const timeline = wrapper.get('[data-id="hilos-status-timeline-11"]')
    expect(timeline.findAll('li').map((li) => li.text())).toEqual([
      expect.stringContaining('<b>New team update</b>'),
      expect.stringContaining('Original publication'),
    ])
    expect(timeline.find('b').exists()).toBe(false)
  })
})
