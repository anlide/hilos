import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  HIDDEN_VALUE,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  applyServerTime,
  bindTimeZoneState,
} from '@hilos/core/base'

import HilosStatusPage from './HilosStatusPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
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
})
afterEach(() => {
  for (const cleanup of cleanups.splice(0).reverse()) cleanup()
  vi.useRealTimers()
})
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
