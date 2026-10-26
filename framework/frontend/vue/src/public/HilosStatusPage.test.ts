import { formatMoment, type HilosConnection } from '@hilos/core/base'
import type {
  PublicStatusFetch,
  PublicStatusFile,
  PublicStatusHeartbeat,
  PublicStatusOutage,
  PublicStatusState,
} from '@hilos/core/status'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { readerTimeZone } from '../../test/helpers/readerTimeZone.js'
import HilosStatusPage from './HilosStatusPage.vue'

const NOW = Date.parse('2026-10-25T12:00:00Z')
const cleanup: Array<() => void> = []

function statusFile(
  overrides: Partial<PublicStatusFile> = {},
): PublicStatusFile {
  return {
    schemaVersion: 1,
    revision: 'public-revision',
    generatedAt: NOW - 5000,
    config: { pollIntervalSeconds: 30, staleAfterSeconds: 120 },
    components: [
      {
        componentKey: 'site',
        name: 'Site',
        description: 'Pages and live updates.',
        state: 'operational',
      },
    ],
    candidates: [],
    outages: [],
    scheduledWorks: [],
    maintenancePeriods: [],
    ...overrides,
  }
}

function outage(
  overrides: Partial<PublicStatusOutage> = {},
): PublicStatusOutage {
  return {
    id: 'published-outage',
    componentKey: 'site',
    severity: 'major_outage',
    detectedAt: NOW - 120000,
    publishedAt: NOW - 60000,
    resolvedAt: null,
    updates: [],
    ...overrides,
  }
}

function harness(
  initial: unknown = statusFile(),
  live = true,
  available = true,
) {
  const listeners = new Map<string, Set<(value: unknown) => void>>()
  const emit = (event: string, value?: unknown): void => {
    for (const listener of listeners.get(event) ?? []) listener(value)
  }
  const send = vi.fn()
  const connection = {
    state: live ? 'connected' : 'disconnected',
    protectedMode: { active: false },
    on(event: string, listener: (value: unknown) => void) {
      const bucket = listeners.get(event) ?? new Set()
      listeners.set(event, bucket)
      bucket.add(listener)
      return () => bucket.delete(listener)
    },
    sendAction: send,
    sendPageSubscribe: send,
    sendTableViewport: send,
  }
  let status: unknown = initial
  let beat: PublicStatusHeartbeat = {
    schemaVersion: 1,
    revision: 'public-revision',
    aliveAt: NOW,
  }
  let ok = available
  const fetchFile = vi.fn<PublicStatusFetch>(async (url) => ({
    ok,
    json: async () => (url.endsWith('/status.json') ? status : beat),
  }))
  vi.stubGlobal('fetch', fetchFile)
  const wrapper = mount(HilosStatusPage, {
    props: { connection: connection as unknown as HilosConnection },
    attachTo: document.body,
  })
  cleanup.push(() => wrapper.unmount())
  return {
    wrapper,
    fetchFile,
    send,
    listeners,
    publish(next: unknown, aliveAt = NOW) {
      status = next
      beat = { ...beat, aliveAt }
      emit('projectSignal', { type: 'status_files_changed', data: {} })
    },
    fail() {
      ok = false
    },
    recover() {
      ok = true
    },
    disconnect() {
      connection.state = 'disconnected'
      emit('state', 'disconnected')
    },
    freeze() {
      connection.protectedMode.active = true
      emit('protectedMode', connection.protectedMode)
    },
  }
}

async function settle(): Promise<void> {
  await vi.advanceTimersByTimeAsync(0)
  await nextTick()
}

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(NOW)
  const zone = readerTimeZone()
  zone.choose('Europe/Warsaw')
  cleanup.push(zone.release)
})
afterEach(() => {
  for (const release of cleanup.splice(0).reverse()) release()
  vi.useRealTimers()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('public HilosStatusPage', () => {
  it.each<[PublicStatusState, string, string]>([
    ['operational', 'All systems operational', 'Operational'],
    ['checking', 'Checking a possible problem', 'Checking'],
    ['partial_outage', 'Partial outage', 'Partial outage'],
    ['major_outage', 'Major outage', 'Major outage'],
    ['maintenance', 'Maintenance in progress', 'Maintenance'],
    ['unknown', 'Status unknown', 'Unknown'],
  ])('renders %s from one mounted core reader', async (state, title, word) => {
    const file = statusFile()
    file.components[0]!.state = state
    const h = harness(file)
    const region = h.wrapper.get('[data-id="public-status-live"]').element
    expect(h.wrapper.get('[data-id="public-status-banner"]').text()).toContain(
      'Status unknown',
    )
    await settle()
    expect(h.wrapper.get('[data-id="public-status-banner"]').text()).toBe(title)
    expect(
      h.wrapper.get('[data-id="public-status-component-state"]').text(),
    ).toBe(word)
    expect(
      h.wrapper.get('[data-id="public-status-components"]').text(),
    ).toContain('Pages and live updates.')
    expect(h.wrapper.findAll('h1')).toHaveLength(1)
    expect(h.wrapper.get('h1').text()).toBe('System status')
    expect(h.wrapper.get('[data-id="public-status-live"]').element).toBe(region)
    expect(region.getAttribute('role')).toBe('status')
    expect(region.getAttribute('aria-live')).toBe('polite')
    expect(
      h.wrapper.get('[data-id="public-status-banner"]').attributes('role'),
    ).toBeUndefined()
    expect(
      h.wrapper
        .findAll('i')
        .every(
          (icon) =>
            icon.attributes('aria-hidden') === 'true' ||
            icon.element.parentElement?.getAttribute('aria-hidden') === 'true',
        ),
    ).toBe(true)
    expect(h.fetchFile).toHaveBeenCalledTimes(2)
    expect([...h.listeners.values()].map((bucket) => bucket.size)).toEqual([
      1, 1, 1,
    ])
    expect(h.send).not.toHaveBeenCalled()
    expect(h.wrapper.text()).not.toMatch(/subscribe|SLA|%/i)
    expect(h.wrapper.find('[data-id="public-status-works"]').exists()).toBe(
      false,
    )
    expect(
      h.wrapper.find('[data-id="public-status-maintenance"]').exists(),
    ).toBe(false)
  })

  it.each(['outage', 'maintenance'] as const)(
    'retains %s with the exact fresh lost-connection line, including freeze',
    async (kind) => {
      const file = statusFile()
      file.components[0]!.state =
        kind === 'maintenance' ? 'maintenance' : 'major_outage'
      if (kind === 'outage') file.outages = [outage()]
      const h = harness(file)
      await settle()
      h.freeze()
      await nextTick()
      expect(h.wrapper.get('[data-id="public-status-connection"]').text()).toBe(
        'Your live connection to the site is lost — the system is up',
      )
      expect(h.wrapper.get('[data-id="public-status-banner"]').text()).toBe(
        kind === 'maintenance' ? 'Maintenance in progress' : 'Major outage',
      )
      if (kind === 'outage')
        expect(
          h.wrapper.get('[data-id="public-status-outages"]').text(),
        ).toContain(formatMoment(file.outages[0]!.detectedAt))
      h.disconnect()
      await nextTick()
      expect(h.send).not.toHaveBeenCalled()
    },
  )

  it('stales without a socket event, keeps published history, and recovers on a coherent pair', async () => {
    const ended = outage({
      detectedAt: NOW - 3600000,
      resolvedAt: NOW - 3000000,
    })
    const h = harness(statusFile({ outages: [ended] }))
    await settle()
    const history = h.wrapper.get('[data-id="public-status-history"]').text()
    h.fail()
    h.publish({ malformed: true }, NOW + 120000)
    await settle()
    await vi.advanceTimersByTimeAsync(120001)
    await nextTick()
    expect(h.wrapper.get('[data-id="public-status-banner"]').text()).toBe(
      `The system is not responding since ${formatMoment(NOW)}`,
    )
    expect(
      h.wrapper.get('[data-id="public-status-component-state"]').text(),
    ).toBe('Unknown')
    expect(h.wrapper.get('[data-id="public-status-history"]').text()).toBe(
      history,
    )
    expect(h.wrapper.get('[data-id="public-status-last-update"]').text()).toBe(
      `Last update from the system: ${formatMoment(NOW)}`,
    )
    expect(
      h.wrapper.find('[data-id="public-status-connection"]').exists(),
    ).toBe(false)
    h.recover()
    h.publish(statusFile({ outages: [ended] }), Date.now())
    await settle()
    expect(h.wrapper.get('[data-id="public-status-banner"]').text()).toBe(
      'All systems operational',
    )
    expect(h.wrapper.get('[data-id="public-status-history"]').text()).toBe(
      history,
    )
  })

  it.each(['missing', 'malformed'] as const)(
    'keeps the cold %s pair honestly Unknown, then recovers in the same mount',
    async (failure) => {
      const h = harness(
        failure === 'malformed' ? { schemaVersion: 2 } : statusFile(),
        false,
        failure !== 'missing',
      )
      await settle()
      expect(
        h.wrapper.get('[data-id="public-status-banner"]').text(),
      ).toContain('Status unknown')
      expect(
        h.wrapper.get('[data-id="public-status-last-update"]').text(),
      ).toBe('Last update from the system: Unknown')
      expect(
        h.wrapper.find('[data-id="public-status-components"]').exists(),
      ).toBe(false)
      expect(h.wrapper.find('[data-id="public-status-history"]').exists()).toBe(
        false,
      )
      expect(h.wrapper.text()).not.toContain('No past published incidents')
      expect(h.wrapper.text()).not.toContain('since')
      h.fetchFile.mockImplementation(async (url) => ({
        ok: true,
        json: async () =>
          url.endsWith('/status.json')
            ? statusFile()
            : {
                schemaVersion: 1,
                revision: 'public-revision',
                aliveAt: Date.now(),
              },
      }))
      await vi.advanceTimersByTimeAsync(30000)
      await nextTick()
      expect(h.wrapper.get('[data-id="public-status-banner"]').text()).toBe(
        'All systems operational',
      )
      expect(
        h.wrapper.get('[data-id="public-status-history"]').get('p').text(),
      ).toBe('No past published incidents')
    },
  )

  it('retains the confirmed empty archive when stale, then replaces it with published history', async () => {
    const h = harness()
    expect(h.wrapper.find('[data-id="public-status-history"]').exists()).toBe(
      false,
    )
    await settle()
    const history = h.wrapper.get('[data-id="public-status-history"]')
    expect(history.get('h2').text()).toBe('Past incidents')
    expect(history.get('p').text()).toBe('No past published incidents')
    expect(history.find('ul').exists()).toBe(false)
    h.fail()
    h.publish({ malformed: true }, NOW + 120000)
    await settle()
    await vi.advanceTimersByTimeAsync(120001)
    await nextTick()
    expect(h.wrapper.get('[data-id="public-status-banner"]').text()).toBe(
      `The system is not responding since ${formatMoment(NOW)}`,
    )
    expect(
      h.wrapper.get('[data-id="public-status-component-state"]').text(),
    ).toBe('Unknown')
    expect(history.get('p').text()).toBe('No past published incidents')
    expect(history.find('ul').exists()).toBe(false)
    const ended = outage({
      detectedAt: NOW - 3600000,
      resolvedAt: NOW - 3000000,
    })
    h.recover()
    h.publish(statusFile({ outages: [ended] }), Date.now())
    await settle()
    expect(history.findAll('li')).toHaveLength(1)
    expect(history.text()).not.toContain('No past published incidents')
    expect(history.get('li').text()).toContain('Site')
    expect(history.get('li').text()).toContain('Major outage')
    expect(history.get('li').text()).toContain(formatMoment(ended.detectedAt))
    expect(history.get('li').text()).toContain('10m 00s')
    expect(history.get('li').text()).toContain(
      `Resolved at ${formatMoment(ended.resolvedAt!)}`,
    )
  })

  it('shows Checking facts without archiving candidates or painting days', async () => {
    const file = statusFile({
      candidates: [{ componentKey: 'site', detectedAt: NOW - 7000 }],
    })
    file.components[0]!.state = 'checking'
    const h = harness(file)
    await settle()
    expect(
      h.wrapper.get('[data-id="public-status-candidates"]').text(),
    ).toContain(`Detected at ${formatMoment(NOW - 7000)}`)
    const history = h.wrapper.get('[data-id="public-status-history"]')
    expect(history.get('p').text()).toBe('No past published incidents')
    expect(history.findAll('li')).toHaveLength(0)
    expect(history.text()).not.toContain(formatMoment(NOW - 7000))
    for (const button of h.wrapper
      .get('[data-id="public-status-days-90"]')
      .findAll('button')) {
      expect(button.attributes('aria-label')).toMatch(/Operational$/)
      expect(button.classes()).toContain('btn-success')
    }
  })

  it('renders descending plain-text timelines and the accepted heartbeat rather than fetch or generation time', async () => {
    const h = harness(
      statusFile({
        outages: [
          outage({
            updates: [
              {
                at: NOW - 110000,
                source: 'automatic',
                text: 'Detected automatically.',
              },
              {
                at: NOW - 1000,
                source: 'team',
                text: '<img src=x onerror=alert(1)> & investigating',
              },
            ],
          }),
        ],
      }),
    )
    await settle()
    const updates = h.wrapper.findAll('[data-id="public-status-update"]')
    expect(updates[0]!.text()).toContain(
      '<img src=x onerror=alert(1)> & investigating',
    )
    expect(updates[0]!.text()).toContain('From the team')
    expect(updates[1]!.text()).toContain('Automatic')
    expect(h.wrapper.find('img').exists()).toBe(false)
    expect(h.wrapper.get('[data-id="public-status-last-update"]').text()).toBe(
      `Last update from the system: ${formatMoment(NOW)}`,
    )
  })

  it('reformats every moment on a reader-zone change and exposes 90/30 calendar day details to keyboard focus', async () => {
    const zone = readerTimeZone()
    cleanup.push(zone.release)
    zone.choose('Europe/Warsaw')
    const ended = outage({
      id: 'ended',
      detectedAt: NOW - 3600000 - 7000,
      resolvedAt: NOW - 3000000,
    })
    const h = harness(
      statusFile({
        candidates: [{ componentKey: 'site', detectedAt: NOW - 3000 }],
        outages: [
          ended,
          outage({
            updates: [
              { at: NOW - 1000, source: 'team', text: 'Working on it.' },
            ],
          }),
        ],
      }),
    )
    await settle()
    const wide = h.wrapper.get('[data-id="public-status-days-90"]')
    const narrow = h.wrapper.get('[data-id="public-status-days-30"]')
    expect(wide.classes()).toEqual(['d-none', 'd-md-block'])
    expect(narrow.classes()).toEqual(['d-md-none'])
    expect(wide.findAll('button')).toHaveLength(90)
    expect(narrow.findAll('button')).toHaveLength(30)
    expect(
      narrow.findAll('button').map((button) => button.attributes('aria-label')),
    ).toEqual(
      wide
        .findAll('button')
        .slice(-30)
        .map((button) => button.attributes('aria-label')),
    )
    const button = wide.findAll('button').at(-1)!
    ;(button.element as HTMLButtonElement).focus()
    await nextTick()
    const detail = h.wrapper.get('[data-id="public-status-day-detail"]')
    expect(document.activeElement).toBe(button.element)
    expect(detail.text()).toBe(button.attributes('aria-label'))
    expect(button.attributes('aria-describedby')).toBe(detail.attributes('id'))
    expect(detail.text()).toContain('Major outage')
    expect(h.wrapper.get('[data-id="public-status-history"]').text()).toContain(
      '10m 07s',
    )
    const previous = h.wrapper.text()
    zone.choose('Pacific/Kiritimati')
    await nextTick()
    expect(h.wrapper.text()).not.toBe(previous)
    for (const [id, at] of [
      ['public-status-candidates', NOW - 3000],
      ['public-status-outages', NOW - 120000],
      ['public-status-update', NOW - 1000],
      ['public-status-history', ended.detectedAt],
      ['public-status-last-update', NOW],
    ] as const)
      expect(h.wrapper.get(`[data-id="${id}"]`).text()).toContain(
        formatMoment(at),
      )
    expect(detail.text()).toBe(
      wide.findAll('button').at(-1)!.attributes('aria-label'),
    )
    const first = narrow.findAll('button')[0]!
    await first.trigger('click')
    expect(detail.text()).toBe(first.attributes('aria-label'))
    expect(h.send).not.toHaveBeenCalled()
  })

  it('renders real scheduled and maintenance data only when supplied', async () => {
    const h = harness(
      statusFile({
        scheduledWorks: [
          {
            id: 'work',
            title: 'Database upgrade',
            description: 'Upgrade window.',
            componentKeys: ['site'],
            startsAt: NOW + 60000,
            endsAt: NOW + 3600000,
          },
        ],
        maintenancePeriods: [
          {
            id: 'period',
            workId: null,
            title: 'Unannounced maintenance',
            componentKeys: ['site'],
            startedAt: NOW - 60000,
            endedAt: null,
          },
        ],
      }),
    )
    await settle()
    expect(h.wrapper.get('[data-id="public-status-works"]').text()).toContain(
      'Database upgrade',
    )
    expect(
      h.wrapper.get('[data-id="public-status-maintenance"]').text(),
    ).toContain('Unannounced maintenance')
    expect(h.wrapper.get('[data-id="public-status-banner"]').text()).toBe(
      'Maintenance in progress',
    )
  })

  it('disposes the single reader on unmount, aborts requests and ignores late responses', async () => {
    const h = harness(statusFile(), false)
    let resolve!: (value: { ok: boolean; json: () => Promise<unknown> }) => void
    const late = new Promise<{ ok: boolean; json: () => Promise<unknown> }>(
      (accept) => {
        resolve = accept
      },
    )
    h.fetchFile.mockReturnValue(late)
    await settle()
    await vi.advanceTimersByTimeAsync(30000)
    const signal = h.fetchFile.mock.calls.at(-1)![1].signal!
    h.wrapper.unmount()
    expect(signal.aborted).toBe(true)
    expect([...h.listeners.values()].every((bucket) => bucket.size === 0)).toBe(
      true,
    )
    expect(vi.getTimerCount()).toBe(0)
    const calls = h.fetchFile.mock.calls.length
    resolve({ ok: true, json: async () => statusFile() })
    await settle()
    await vi.advanceTimersByTimeAsync(240000)
    expect(h.fetchFile).toHaveBeenCalledTimes(calls)
    expect(h.send).not.toHaveBeenCalled()
  })
})
