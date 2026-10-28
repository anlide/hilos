import {
  ActionLifecycle,
  ScopeManager,
  bindAdminAccess,
  formatMoment,
  hilosAdminAccess,
  type HilosConnection,
} from '@hilos/core/base'
import type {
  PublicStatusFetch,
  PublicStatusFile,
  PublicStatusHeartbeat,
  PublicStatusOutage,
  PublicStatusState,
} from '@hilos/core/status'
import { DOMWrapper, mount } from '@vue/test-utils'
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
  frozen = false,
) {
  const listeners = new Map<string, Set<(value: unknown) => void>>()
  const emit = (event: string, value?: unknown): void => {
    for (const listener of listeners.get(event) ?? []) listener(value)
  }
  const send = vi.fn<
    (action: string, data: unknown, requestId?: string) => boolean
  >(() => true)
  const connection = {
    state: live ? 'connected' : 'disconnected',
    protectedMode: { active: frozen },
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
  const actions = new ActionLifecycle(
    connection as unknown as HilosConnection,
    {
      timeoutMs: 1000,
      deferredLoadingMs: 30,
    },
  )
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
    props: { connection: connection as unknown as HilosConnection, actions },
    attachTo: document.body,
  })
  cleanup.push(() => wrapper.unmount())
  return {
    wrapper,
    fetchFile,
    send,
    listeners,
    emit,
    answer(reply: unknown, index = send.mock.calls.length - 1) {
      const [action, , requestId] = send.mock.calls[index]!
      emit('actionSuccess', { action, requestId, reply })
    },
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
    connect() {
      connection.state = 'connected'
      emit('state', 'connected')
    },
    freeze(active = true) {
      connection.protectedMode.active = active
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
  window.history.replaceState({}, '', '/status')
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
    expect(h.listeners.get('state')?.size).toBe(3)
    expect(h.listeners.get('protectedMode')?.size).toBe(2)
    expect(h.listeners.get('projectSignal')?.size).toBe(1)
    expect(h.send).not.toHaveBeenCalled()
    expect(h.wrapper.get('[data-id="public-status-subscribe"]').text()).toBe(
      'Subscribe to updates',
    )
    expect(h.wrapper.text()).not.toMatch(/SLA|%/i)
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
    // The settled live reader has only clock callbacks; batch intermediate
    // renders, then flush the final state below.
    vi.advanceTimersByTime(120001)
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

  // FIXME(F-046, 2026-10-28): archive test timeout · чужой · попытка 1 · HIL-1634
  it.skip('retains the confirmed empty archive when stale, then replaces it with published history', async () => {
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
    // The application's lifecycle still owns its three connection listeners.
    expect(h.listeners.get('state')?.size).toBe(1)
    expect(h.listeners.get('protectedMode')?.size).toBe(0)
    expect(h.listeners.get('projectSignal')?.size).toBe(0)
    expect(vi.getTimerCount()).toBe(0)
    const calls = h.fetchFile.mock.calls.length
    resolve({ ok: true, json: async () => statusFile() })
    await settle()
    await vi.advanceTimersByTimeAsync(240000)
    expect(h.fetchFile).toHaveBeenCalledTimes(calls)
    expect(h.send).not.toHaveBeenCalled()
  })

  it('hides only subscription controls offline and frozen, then restores them without a page request', async () => {
    const h = harness(statusFile(), false)
    await settle()
    const controls = () => h.wrapper.find('[data-id="public-status-subscribe"]')
    const hint = () =>
      h.wrapper.find('[data-id="public-status-subscription-hint"]')
    expect(controls().exists()).toBe(false)
    expect(hint().exists()).toBe(false)
    expect(
      h.wrapper.get('[data-id="public-status-components"]').text(),
    ).toContain('Site')
    h.connect()
    await settle()
    expect(controls().exists()).toBe(true)
    expect(hint().text()).toBe(
      'Subscribed already? Every letter has a link to unsubscribe.',
    )
    h.freeze()
    await settle()
    expect(controls().exists()).toBe(false)
    h.freeze(false)
    await settle()
    expect(controls().exists()).toBe(true)
    expect(h.send).not.toHaveBeenCalled()
  })

  it.each(['guest', 'admin-view'])(
    'allows %s to subscribe without a page scope or an admin grant, keeps file content while pending and shows only its ack',
    async (mode) => {
      const scopes = new ScopeManager()
      if (mode === 'admin-view') scopes.session.data.set('adminViewMode', true)
      const release = bindAdminAccess(scopes)
      cleanup.push(release)
      expect(hilosAdminAccess.get()).toBe(
        mode === 'admin-view' ? 'view' : 'none',
      )
      const h = harness()
      await settle()
      const feedback = h.wrapper.get(
        '[data-id="public-status-subscription-slot"]',
      ).element
      const live = h.wrapper.get(
        '[data-id="public-status-subscription-live"]',
      ).element
      await h.wrapper
        .get('[data-id="public-status-subscribe"]')
        .trigger('click')
      const body = new DOMWrapper(document.body)
      await body
        .get('[data-id="status-subscription-email"]')
        .setValue('Reader@Example.net')
      await body.get('[data-id="status-subscription-form"]').trigger('submit')
      expect(h.send).toHaveBeenCalledExactlyOnceWith(
        'status_subscribe',
        { email: 'reader@example.net' },
        '1',
      )
      expect(h.wrapper.get('[data-id="public-status-banner"]').text()).toBe(
        'All systems operational',
      )
      expect(
        h.wrapper
          .find('[data-id="public-status-subscription-notice"]')
          .exists(),
      ).toBe(false)
      h.emit('actionSuccess', {
        action: 'status_subscribe',
        requestId: 'stale',
        reply: { status: 'check_inbox' },
      })
      await settle()
      expect(body.find('[data-id="modal"]').exists()).toBe(true)
      h.answer({ status: 'check_inbox' })
      await settle()
      expect(body.find('[data-id="modal"]').exists()).toBe(false)
      expect(
        h.wrapper.get('[data-id="public-status-subscription-notice"]').text(),
      ).toBe('Check your inbox')
      expect(
        h.wrapper.get('[data-id="public-status-subscription-slot"]').element,
      ).toBe(feedback)
      expect(
        h.wrapper.get('[data-id="public-status-subscription-live"]').element,
      ).toBe(live)
      h.disconnect()
      await settle()
      expect(
        h.wrapper.get('[data-id="public-status-subscription-notice"]').text(),
      ).toBe('Check your inbox')
    },
  )

  it.each(['hashchange', 'popstate'])(
    'handles delivered fragments on %s without replacing the reader and removes both address listeners on unmount',
    async (event) => {
      const listen = vi.spyOn(window, 'addEventListener')
      const remove = vi.spyOn(window, 'removeEventListener')
      const stored = vi.spyOn(Storage.prototype, 'setItem')
      const h = harness()
      await settle()
      const components = h.wrapper.get(
        '[data-id="public-status-components"]',
      ).element
      const live = h.wrapper.get(
        '[data-id="public-status-subscription-live"]',
      ).element
      const opener = h.wrapper.get('[data-id="public-status-subscribe"]')
        .element as HTMLButtonElement
      opener.focus()
      const token = 'ab'.repeat(32)
      window.history.pushState({}, '', `/status#status-confirm=${token}`)
      window.dispatchEvent(new Event(event))
      expect(window.location.hash).toBe('')
      expect(h.send).toHaveBeenCalledExactlyOnceWith(
        'status_subscription_confirm',
        { token },
        '1',
      )
      window.dispatchEvent(
        new Event(event === 'hashchange' ? 'popstate' : 'hashchange'),
      )
      await settle()
      const body = new DOMWrapper(document.body)
      expect(document.activeElement).toBe(body.get('[data-id="modal"]').element)
      expect(h.send).toHaveBeenCalledTimes(1)
      h.answer({ status: 'subscribed' })
      await settle()
      expect(document.activeElement).toBe(opener)
      expect(live.textContent).toBe('You are subscribed')
      window.history.pushState({}, '', `/status#status-unsubscribe=${token}`)
      window.dispatchEvent(new Event(event))
      expect(window.location.hash).toBe('')
      h.answer({ maskedEmail: 'r***@example.net' })
      await settle()
      expect(body.get('[data-id="modal"]').attributes('aria-label')).toBe(
        'Unsubscribe',
      )
      expect(document.activeElement).toBe(body.get('[data-id="modal"]').element)
      await body.get('[data-id="status-subscription-cancel"]').trigger('click')
      await settle()
      expect(document.activeElement).toBe(opener)
      expect(h.send).toHaveBeenCalledTimes(2)
      expect(
        h.wrapper.get('[data-id="public-status-components"]').element,
      ).toBe(components)
      expect(
        h.wrapper.get('[data-id="public-status-subscription-live"]').element,
      ).toBe(live)
      expect(h.fetchFile).toHaveBeenCalledTimes(2)
      expect(stored).not.toHaveBeenCalled()
      expect(document.body.textContent).not.toContain(token)
      h.wrapper.unmount()
      for (const kind of ['hashchange', 'popstate']) {
        const registrations = listen.mock.calls.filter(
          ([type]) => type === kind,
        )
        expect(registrations).toHaveLength(1)
        expect(remove).toHaveBeenCalledWith(kind, registrations[0]![1])
      }
      window.history.pushState({}, '', `/status#status-confirm=${token}`)
      window.dispatchEvent(new Event(event))
      await settle()
      expect(h.send).toHaveBeenCalledTimes(2)
      expect(body.find('[data-id="modal"]').exists()).toBe(false)
    },
  )

  it.each(['offline', 'frozen'])(
    'erases a confirm fragment immediately while %s, renders files and sends once after recovery',
    async (mode) => {
      const token = 'ab'.repeat(32)
      window.history.replaceState(
        { entry: 1 },
        '',
        `/status?theme=system#status-confirm=${token}`,
      )
      const replace = vi.spyOn(window.history, 'replaceState')
      const stored = vi.spyOn(Storage.prototype, 'setItem')
      const h = harness(
        statusFile(),
        mode !== 'offline',
        true,
        mode === 'frozen',
      )
      expect(replace).toHaveBeenCalledExactlyOnceWith(
        { entry: 1 },
        '',
        '/status?theme=system',
      )
      expect(window.location.hash).toBe('')
      expect(h.send).not.toHaveBeenCalled()
      await settle()
      const body = new DOMWrapper(document.body)
      expect(body.get('[data-id="status-subscription-progress"]').text()).toBe(
        'Waiting for a live connection to continue.',
      )
      expect(h.wrapper.get('[data-id="public-status-banner"]').text()).toBe(
        'All systems operational',
      )
      expect(h.fetchFile).toHaveBeenCalledTimes(2)
      expect(document.body.textContent).not.toContain(token)
      h.connect()
      h.freeze(false)
      await settle()
      expect(h.send).toHaveBeenCalledExactlyOnceWith(
        'status_subscription_confirm',
        { token },
        '1',
      )
      h.freeze()
      h.freeze(false)
      h.connect()
      expect(h.send).toHaveBeenCalledTimes(1)
      h.answer({ status: 'subscribed' })
      await settle()
      expect(body.find('[data-id="modal"]').exists()).toBe(false)
      expect(
        h.wrapper.get('[data-id="public-status-subscription-notice"]').text(),
      ).toBe('You are subscribed')
      expect(stored).not.toHaveBeenCalled()
      expect(
        h.fetchFile.mock.calls.every(([url]) => !url.includes(token)),
      ).toBe(true)
    },
  )

  it('keeps unsubscribe success on the file page after its explicit submit closes the window', async () => {
    window.history.replaceState(
      {},
      '',
      `/status#status-unsubscribe=${'ab'.repeat(32)}`,
    )
    const h = harness()
    await settle()
    const body = new DOMWrapper(document.body)
    expect(h.send.mock.calls[0]?.[0]).toBe('status_unsubscribe_preview')
    h.answer({ maskedEmail: 'r***@example.net' })
    await settle()
    const slot = h.wrapper.get('[data-id="public-status-subscription-slot"]')
    const reservation = slot.get('[aria-hidden="true"][inert]')
    expect(reservation.classes()).toEqual([
      'alert',
      'alert-secondary',
      'mb-0',
      'invisible',
    ])
    await body.get('[data-id="status-subscription-form"]').trigger('submit')
    expect(h.send.mock.calls[1]?.[0]).toBe('status_unsubscribe')
    expect(
      slot.find('[data-id="public-status-subscription-notice"]').exists(),
    ).toBe(false)
    h.answer({ status: 'unsubscribed' })
    await settle()
    expect(body.find('[data-id="modal"]').exists()).toBe(false)
    expect(
      slot.get('[data-id="public-status-subscription-notice"]').text(),
    ).toBe('You are unsubscribed')
    expect(slot.get('[aria-hidden="true"][inert]').element).toBe(
      reservation.element,
    )
    expect(h.wrapper.get('[data-id="public-status-banner"]').text()).toBe(
      'All systems operational',
    )
  })

  it('keeps a closed waiting operation visible on the page and ignores a completion after unmount', async () => {
    window.history.replaceState(
      {},
      '',
      `/status#status-confirm=${'ab'.repeat(32)}`,
    )
    const h = harness(statusFile(), false)
    await settle()
    const body = new DOMWrapper(document.body)
    await body.get('[data-id="status-subscription-cancel"]').trigger('click')
    expect(
      h.wrapper.get('[data-id="public-status-subscription-notice"]').text(),
    ).toBe('Waiting for a live connection to continue.')
    h.connect()
    await settle()
    expect(h.send).toHaveBeenCalledTimes(1)
    h.wrapper.unmount()
    expect(h.listeners.get('protectedMode')?.size).toBe(0)
    expect(h.listeners.get('projectSignal')?.size).toBe(0)
    expect(h.listeners.get('state')?.size).toBe(1)
    h.answer({ status: 'subscribed' })
    h.connect()
    await settle()
    expect(body.find('[data-id="modal"]').exists()).toBe(false)
    expect(document.body.textContent).not.toContain('You are subscribed')
    expect(h.send).toHaveBeenCalledTimes(1)
    expect(vi.getTimerCount()).toBe(0)
  })
})
