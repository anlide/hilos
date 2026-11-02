// The public /status page in React: the real page, the real core file reader and
// subscription controller, the real window, form error and static frame; only
// the wire and the two nginx files are fakes. Scenarios mirror
// framework/frontend/vue/src/public/HilosStatusPage.test.ts; the window's own
// scenarios stay in HilosStatusSubscriptionModal.test.tsx.
import { act, fireEvent } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  ScopeManager,
  bindAdminAccess,
  formatMoment,
  hilosAdminAccess,
} from '@hilos/core/base'
import {
  STATUS_SUBSCRIPTION_COPY,
  type PublicStatusFile,
  type PublicStatusMaintenancePeriod,
  type PublicStatusOutage,
  type PublicStatusState,
  type PublicStatusWork,
} from '@hilos/core/status'

import { HilosStatusPage } from '../src/public/HilosStatusPage.js'
import { readerTimeZone } from './readerTimeZone.js'
import {
  PUBLIC_STATUS_REVISION,
  publicStatusHarness,
} from './statusFixtures.js'

const NOW = Date.parse('2026-10-25T12:00:00Z')
const TOKEN = 'ab'.repeat(32)
const cleanups: (() => void)[] = []

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(NOW)
  window.history.replaceState({}, '', '/status')
  const zone = readerTimeZone()
  act(() => zone.choose('Europe/Warsaw'))
  cleanups.push(zone.release)
})
afterEach(() => {
  for (const cleanup of cleanups.splice(0).reverse()) act(cleanup)
  vi.useRealTimers()
  vi.restoreAllMocks()
})

function statusFile(
  overrides: Partial<PublicStatusFile> = {},
): PublicStatusFile {
  return {
    schemaVersion: 2,
    revision: PUBLIC_STATUS_REVISION,
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

function work(overrides: Partial<PublicStatusWork> = {}): PublicStatusWork {
  return {
    id: 'public-work',
    title: 'Database upgrade',
    description: null,
    componentKeys: ['site'],
    startsAt: NOW + 60000,
    endsAt: NOW + 3600000,
    ...overrides,
  }
}

function maintenance(
  overrides: Partial<PublicStatusMaintenancePeriod> = {},
): PublicStatusMaintenancePeriod {
  return {
    id: 'public-maintenance',
    workId: 'public-work',
    announcementKnown: true,
    title: 'Database upgrade',
    componentKeys: ['site'],
    startedAt: NOW - 120000,
    lastObservedAt: NOW,
    endedAt: null,
    completionObservedAt: null,
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

function all(id: string, root: ParentNode = document.body): HTMLElement[] {
  return [...root.querySelectorAll<HTMLElement>(`[data-id="${id}"]`)]
}

/**
 * The words an element shows, with whitespace collapsed.
 *
 * @param element The element to read.
 */
function words(element: Element): string {
  return (element.textContent ?? '').replace(/\s+/g, ' ').trim()
}

function text(id: string): string {
  return words(get(id))
}

/**
 * Every piece of text under an element, one per text node, so neighbouring
 * elements never run together into a word neither of them shows.
 *
 * @param root The element to read.
 */
function textNodes(root: Element): string[] {
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT)
  const texts: string[] = []
  for (let node = walker.nextNode(); node !== null; node = walker.nextNode())
    texts.push(node.textContent ?? '')
  return texts
}

/** Let the pending file reads and their renders finish. */
async function settle() {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(0)
  })
}

/**
 * Move the clock forward, firing the reader's timers and their renders.
 *
 * @param ms How far to move it.
 */
async function advance(ms: number) {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(ms)
  })
}

/**
 * Move the clock while only the reader's clock callbacks are due, batching
 * their renders into one: a live reader whose files stopped answering has no
 * read to wait for.
 *
 * @param ms How far to move it.
 */
function elapse(ms: number) {
  act(() => {
    vi.advanceTimersByTime(ms)
  })
}

/**
 * Change the reader's time zone, as a session answer does.
 *
 * @param timeZone The new IANA zone.
 */
function chooseZone(timeZone: string) {
  const zone = readerTimeZone()
  cleanups.push(zone.release)
  act(() => zone.choose(timeZone))
}

/**
 * Mount the public page over the stubbed files and the harness wire.
 *
 * @param initial The status file the first reads find.
 * @param options The connection and file conditions at mount, and StrictMode.
 * @param options.live Whether the connection is up.
 * @param options.available Whether the files answer.
 * @param options.frozen Whether the node's freeze holds.
 * @param options.strict Whether to mount in StrictMode.
 */
function harness(
  initial: unknown = statusFile(),
  {
    live = true,
    available = true,
    frozen = false,
    strict = false,
  }: {
    live?: boolean
    available?: boolean
    frozen?: boolean
    strict?: boolean
  } = {},
) {
  const h = publicStatusHarness(initial, NOW)
  cleanups.push(h.dispose)
  if (!available) h.fail()
  if (!live) h.connect('disconnected')
  if (frozen) h.freeze(true)
  const page = () => (
    <HilosStatusPage
      connection={h.context.connection}
      actions={h.context.actions}
    />
  )
  let view = h.mount(page(), strict)
  let mounted = true
  const unmount = () => {
    if (!mounted) return
    mounted = false
    view.unmount()
  }
  cleanups.push(() => unmount())
  return {
    ...h,
    unmount,
    /**
     * Enter the page again after leaving it.
     *
     * @param again Whether to mount the new page in StrictMode.
     */
    remount(again = strict) {
      unmount()
      view = h.mount(page(), again)
      mounted = true
    },
  }
}

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
    const region = get('public-status-live')
    expect(text('public-status-banner')).toContain('Status unknown')
    await settle()
    expect(text('public-status-banner')).toBe(title)
    expect(text('public-status-component-state')).toBe(word)
    expect(text('public-status-components')).toContain(
      'Pages and live updates.',
    )
    const headings = document.body.querySelectorAll('h1')
    expect(headings).toHaveLength(1)
    expect(words(headings[0]!)).toBe('System status')
    expect(get('public-status-live')).toBe(region)
    expect(region.getAttribute('role')).toBe('status')
    expect(region.getAttribute('aria-live')).toBe('polite')
    expect(get('public-status-banner').getAttribute('role')).toBeNull()
    expect(
      [...document.body.querySelectorAll('i')].every(
        (icon) =>
          icon.getAttribute('aria-hidden') === 'true' ||
          icon.parentElement?.getAttribute('aria-hidden') === 'true',
      ),
    ).toBe(true)
    expect(h.fetchFile).toHaveBeenCalledTimes(2)
    expect(h.listening('state')).toBe(3)
    expect(h.listening('protectedMode')).toBe(2)
    expect(h.listening('projectSignal')).toBe(1)
    expect(h.sent).toEqual([])
    expect(text('public-status-subscribe')).toBe('Subscribe to updates')
    expect(
      textNodes(document.body).filter((piece) => /SLA|%/i.test(piece)),
    ).toEqual([])
    expect(find('public-status-works')).toBeNull()
    expect(find('public-status-maintenance')).toBeNull()
  })

  it('renders upcoming plain words, affected names and reader-zone bounds from the core, then follows file and zone changes', async () => {
    const planned = work({
      title: '<b>Database & upgrade</b>',
      description: '<img src=x onerror=alert(1)> A plain description.',
    })
    const h = harness(statusFile({ scheduledWorks: [planned] }))
    await settle()
    const section = get('public-status-works')
    expect(words(section.querySelector('h2')!)).toBe('Scheduled maintenance')
    expect(words(section.querySelector('h3')!)).toBe(planned.title)
    expect(section.querySelector('b')).toBeNull()
    expect(section.querySelector('img')).toBeNull()
    expect(words(section.querySelector('article p')!)).toBe(planned.description)
    expect(words(section)).toContain('Affects: Site')
    expect(words(section)).not.toContain('Affects: site')
    expect(words(section)).toContain(formatMoment(planned.startsAt))
    expect(words(section)).toContain(formatMoment(planned.endsAt))
    const previous = words(section)
    chooseZone('Asia/Tokyo')
    expect(words(section)).not.toBe(previous)
    expect(words(section)).toContain(formatMoment(planned.startsAt))
    expect(words(section)).toContain(formatMoment(planned.endsAt))
    h.publish(statusFile({ scheduledWorks: [work({ title: 'Updated work' })] }))
    await settle()
    expect(words(section.querySelector('h3')!)).toBe('Updated work')
    expect(words(section)).not.toContain(planned.title)
    expect(section.querySelectorAll('article p')).toHaveLength(1)
    expect(h.fetchFile).toHaveBeenCalledTimes(4)
    expect(h.sent).toEqual([])
    expect(document.body.querySelectorAll('h1')).toHaveLength(1)
  })

  it('keeps a begun unused announcement until its window ends and never returns consumed work to upcoming', async () => {
    const begun = work({ startsAt: NOW - 60000, endsAt: NOW + 1000 })
    const consumed = work({ id: 'consumed', title: 'Already used' })
    const expired = work({
      id: 'expired',
      title: 'Expired',
      startsAt: NOW - 120000,
      endsAt: NOW,
    })
    const h = harness(
      statusFile({
        scheduledWorks: [consumed, expired, begun],
        maintenancePeriods: [maintenance({ workId: consumed.id })],
      }),
    )
    await settle()
    expect(
      [...get('public-status-works').querySelectorAll('h3')].map(words),
    ).toEqual(['Database upgrade'])
    await advance(1000)
    expect(find('public-status-works')).toBeNull()
    expect(text('public-status-maintenance')).toContain(
      'Maintenance in progress',
    )
    h.publish(
      statusFile({
        scheduledWorks: [consumed],
        maintenancePeriods: [
          maintenance({
            workId: consumed.id,
            lastObservedAt: Date.now(),
            endedAt: Date.now(),
            completionObservedAt: Date.now(),
          }),
        ],
      }),
      Date.now(),
    )
    await settle()
    expect(find('public-status-works')).toBeNull()
    expect(find('public-status-maintenance')).toBeNull()
    expect(h.sent).toEqual([])
  })

  it.each([
    ['announced', 'public-work', true, 'Announced in advance'],
    ['unannounced', null, true, 'Not announced in advance'],
    ['unknown', null, false, 'Advance announcement unknown'],
  ] as const)(
    'renders %s actual maintenance after the planned end and keeps full-freeze component health independent of affected words',
    async (_kind, workId, announcementKnown, label) => {
      const planned = work({ startsAt: NOW - 3600000, endsAt: NOW - 60000 })
      const file = statusFile({
        scheduledWorks: [planned],
        maintenancePeriods: [maintenance({ workId, announcementKnown })],
      })
      file.components[0]!.state = 'maintenance'
      file.components.push({
        componentKey: 'mail',
        name: 'Notifications',
        description: null,
        state: 'maintenance',
      })
      const h = harness(file)
      await settle()
      const card = get('public-status-maintenance')
      expect(words(card.querySelector('h2')!)).toBe('Maintenance')
      expect(words(card.querySelector('h3')!)).toBe('Database upgrade')
      expect(words(card)).toContain(label)
      expect(words(card)).toContain('Maintenance in progress')
      expect(words(card)).toContain(formatMoment(NOW - 120000))
      expect(words(card)).toContain('Site')
      expect(words(card)).not.toContain('Notifications')
      expect(words(card).includes('Planned window')).toBe(workId !== null)
      if (workId !== null) {
        expect(words(card)).toContain(formatMoment(planned.endsAt))
        expect(words(card)).toContain(
          'The scheduled window does not determine the actual end.',
        )
        expect(text('public-status-banner')).toBe(
          'Maintenance in progress: Database upgrade',
        )
      } else {
        expect(words(card)).not.toContain(formatMoment(planned.startsAt))
        expect(text('public-status-banner')).toBe('Maintenance in progress')
      }
      expect(all('public-status-component-state').map(words)).toEqual([
        'Maintenance',
        'Maintenance',
      ])
      h.freeze(true)
      h.connect('disconnected')
      await settle()
      expect(words(card)).toContain(label)
      expect(find('public-status-subscribe')).toBeNull()
      expect(h.sent).toEqual([])
      expect(
        h.fetchFile.mock.calls.every(
          ([url]) =>
            url.endsWith('/status.json') || url.endsWith('/heartbeat.json'),
        ),
      ).toBe(true)
    },
  )

  it('preserves the saved announced title without borrowing a same-title work window', async () => {
    harness(
      statusFile({
        scheduledWorks: [work({ id: 'another-public-id' })],
        maintenancePeriods: [maintenance()],
      }),
    )
    await settle()
    const card = get('public-status-maintenance')
    expect(words(card.querySelector('h3')!)).toBe('Database upgrade')
    expect(words(card)).toContain('Announced in advance')
    expect(words(card)).not.toContain('Planned window')
    expect(words(card)).not.toContain(formatMoment(NOW + 3600000))
    expect(text('public-status-banner')).toBe(
      'Maintenance in progress: Database upgrade',
    )
    expect(get('public-status-works').querySelectorAll('article')).toHaveLength(
      1,
    )
  })

  it('marks a stale actual as a retained observation in reserved room, then confirms it again from files', async () => {
    const file = statusFile({ maintenancePeriods: [maintenance()] })
    const h = harness(file)
    await settle()
    const card = get('public-status-maintenance')
    const reservation = card.querySelector('[aria-hidden="true"][inert]')!
    expect(find('public-status-maintenance-retained', card)).toBeNull()
    h.fail()
    elapse(120001)
    expect(words(card)).toContain('Last observed maintenance')
    expect(text('public-status-maintenance-retained')).toBe(
      'This is a retained observation. Current maintenance status is unknown.',
    )
    expect(words(card)).not.toContain('Maintenance in progress')
    expect(text('public-status-banner')).toContain(
      'The system is not responding since',
    )
    expect(card.querySelector('[aria-hidden="true"][inert]')).toBe(reservation)
    expect(card.querySelector('[role="alert"], [aria-live]')).toBeNull()
    h.recover()
    h.publish(file, Date.now())
    await settle()
    expect(words(card)).toContain('Maintenance in progress')
    expect(find('public-status-maintenance-retained', card)).toBeNull()
    expect(card.querySelector('[aria-hidden="true"][inert]')).toBe(reservation)
    expect(h.sent).toEqual([])
  })

  it('shows completed exact intervals and observed-only completion without a false end or duration', async () => {
    const h = harness(
      statusFile({
        maintenancePeriods: [
          maintenance({
            id: 'exact',
            title: 'Exact work',
            startedAt: NOW - 1200000,
            lastObservedAt: NOW - 600000,
            endedAt: NOW - 600000,
            completionObservedAt: NOW - 600000,
          }),
          maintenance({
            id: 'observed',
            title: 'Observed work',
            workId: null,
            announcementKnown: false,
            startedAt: NOW - 3600000,
            lastObservedAt: NOW - 3000000,
            completionObservedAt: NOW - 1200000,
          }),
        ],
        outages: [outage({ resolvedAt: NOW - 60000 })],
      }),
    )
    await settle()
    const rows = [...get('public-status-history').querySelectorAll('li')]
    expect(rows).toHaveLength(3)
    const exact = words(rows.find((row) => words(row).includes('Exact work'))!)
    expect(exact).toContain('Completed')
    expect(exact).toContain('Announced in advance')
    expect(exact).toContain('Affects: Site')
    expect(exact).toContain(`Completed at ${formatMoment(NOW - 600000)}`)
    expect(exact).toContain('10m 00s')
    expect(exact).not.toContain('Completion observed')
    const observed = words(
      rows.find((row) => words(row).includes('Observed work'))!,
    )
    expect(observed).toContain('Completed')
    expect(observed).toContain('Advance announcement unknown')
    expect(observed).toContain(
      `Completion observed at ${formatMoment(NOW - 1200000)}. Exact end unknown.`,
    )
    expect(observed).not.toContain('Completed at')
    expect(observed).not.toMatch(/\d+[hms]\b/)
    expect(find('public-status-maintenance')).toBeNull()
    expect(h.sent).toEqual([])
  })

  it.each(['offline', 'frozen'] as const)(
    'reads maintenance cards on a cold %s mount and follows later files by the existing poll',
    async (mode) => {
      const file = statusFile({
        scheduledWorks: [work()],
        maintenancePeriods: [
          maintenance({ workId: null, title: 'Emergency maintenance' }),
        ],
      })
      const h = harness(file, {
        live: mode !== 'offline',
        frozen: mode === 'frozen',
      })
      await settle()
      const heading = (id: string) => words(get(id).querySelector('h3')!)
      expect(heading('public-status-works')).toBe('Database upgrade')
      expect(heading('public-status-maintenance')).toBe('Emergency maintenance')
      expect(find('public-status-subscribe')).toBeNull()
      h.write(
        statusFile({
          scheduledWorks: [work({ title: 'Revised announcement' })],
          maintenancePeriods: file.maintenancePeriods,
        }),
      )
      await advance(29999)
      expect(heading('public-status-works')).toBe('Database upgrade')
      expect(h.fetchFile).toHaveBeenCalledTimes(2)
      await advance(1)
      expect(heading('public-status-works')).toBe('Revised announcement')
      expect(heading('public-status-maintenance')).toBe('Emergency maintenance')
      expect(h.fetchFile).toHaveBeenCalledTimes(4)
      expect(h.sent).toEqual([])
    },
  )

  it.each(['outage', 'maintenance'] as const)(
    'retains %s with the exact fresh lost-connection line, including freeze',
    async (kind) => {
      const file = statusFile()
      file.components[0]!.state =
        kind === 'maintenance' ? 'maintenance' : 'major_outage'
      if (kind === 'outage') file.outages = [outage()]
      const h = harness(file)
      await settle()
      expect(find('public-status-connection')).toBeNull()
      h.freeze(true)
      expect(text('public-status-connection')).toBe(
        'Your live connection to the site is lost — the system is up',
      )
      expect(text('public-status-live')).toContain(
        'Your live connection to the site is lost — the system is up',
      )
      expect(text('public-status-banner')).toBe(
        kind === 'maintenance' ? 'Maintenance in progress' : 'Major outage',
      )
      if (kind === 'outage')
        expect(text('public-status-outages')).toContain(
          formatMoment(file.outages[0]!.detectedAt),
        )
      h.connect('disconnected')
      expect(h.sent).toEqual([])
    },
  )

  it('stales without a socket event, keeps published history, and recovers on a coherent pair', async () => {
    const ended = outage({
      detectedAt: NOW - 3600000,
      resolvedAt: NOW - 3000000,
    })
    const h = harness(statusFile({ outages: [ended] }))
    await settle()
    const history = text('public-status-history')
    h.fail()
    h.publish({ malformed: true }, NOW + 120000)
    await settle()
    elapse(120001)
    expect(text('public-status-banner')).toBe(
      `The system is not responding since ${formatMoment(NOW)}`,
    )
    expect(text('public-status-component-state')).toBe('Unknown')
    expect(text('public-status-history')).toBe(history)
    expect(text('public-status-last-update')).toBe(
      `Last update from the system: ${formatMoment(NOW)}`,
    )
    expect(find('public-status-connection')).toBeNull()
    h.recover()
    h.publish(statusFile({ outages: [ended] }), Date.now())
    await settle()
    expect(text('public-status-banner')).toBe('All systems operational')
    expect(text('public-status-history')).toBe(history)
  })

  it.each(['missing', 'malformed'] as const)(
    'keeps the cold %s pair honestly Unknown, then recovers in the same mount',
    async (failure) => {
      const h = harness(
        failure === 'malformed' ? { schemaVersion: 2 } : statusFile(),
        { live: false, available: failure !== 'missing' },
      )
      await settle()
      expect(text('public-status-banner')).toContain('Status unknown')
      expect(text('public-status-banner')).toContain(
        'There is no trustworthy status picture yet.',
      )
      expect(text('public-status-last-update')).toBe(
        'Last update from the system: Unknown',
      )
      expect(find('public-status-components')).toBeNull()
      expect(find('public-status-history')).toBeNull()
      expect(words(document.body)).not.toContain('No past published incidents')
      expect(words(document.body)).not.toContain('since')
      h.fetchFile.mockImplementation(async (url) => ({
        ok: true,
        json: async () =>
          url.endsWith('/status.json')
            ? statusFile()
            : {
                schemaVersion: 2,
                revision: PUBLIC_STATUS_REVISION,
                aliveAt: Date.now(),
              },
      }))
      await advance(30000)
      expect(text('public-status-banner')).toBe('All systems operational')
      expect(words(get('public-status-history').querySelector('p')!)).toBe(
        'No past published incidents',
      )
    },
  )

  it('retains the confirmed empty archive when stale, then replaces it with published history', async () => {
    const h = harness()
    expect(find('public-status-history')).toBeNull()
    await settle()
    const history = get('public-status-history')
    expect(words(history.querySelector('h2')!)).toBe('Past incidents')
    expect(words(history.querySelector('p')!)).toBe(
      'No past published incidents',
    )
    expect(history.querySelector('ul')).toBeNull()
    h.fail()
    h.publish({ malformed: true }, NOW + 120000)
    await settle()
    elapse(120001)
    expect(text('public-status-banner')).toBe(
      `The system is not responding since ${formatMoment(NOW)}`,
    )
    expect(text('public-status-component-state')).toBe('Unknown')
    expect(words(history.querySelector('p')!)).toBe(
      'No past published incidents',
    )
    expect(history.querySelector('ul')).toBeNull()
    const ended = outage({
      detectedAt: NOW - 3600000,
      resolvedAt: NOW - 3000000,
    })
    h.recover()
    h.publish(statusFile({ outages: [ended] }), Date.now())
    await settle()
    const rows = history.querySelectorAll('li')
    expect(rows).toHaveLength(1)
    expect(words(history)).not.toContain('No past published incidents')
    expect(words(rows[0]!)).toContain('Site')
    expect(words(rows[0]!)).toContain('Major outage')
    expect(words(rows[0]!)).toContain(formatMoment(ended.detectedAt))
    expect(words(rows[0]!)).toContain('10m 00s')
    expect(words(rows[0]!)).toContain(
      `Resolved at ${formatMoment(ended.resolvedAt!)}`,
    )
  })

  it('shows Checking facts without archiving candidates or painting days', async () => {
    const file = statusFile({
      candidates: [{ componentKey: 'site', detectedAt: NOW - 7000 }],
    })
    file.components[0]!.state = 'checking'
    harness(file)
    await settle()
    expect(words(get('public-status-candidates').querySelector('h2')!)).toBe(
      'Checking',
    )
    expect(text('public-status-candidates')).toContain(
      'Checking a possible problem with Site',
    )
    expect(text('public-status-candidates')).toContain(
      `Detected at ${formatMoment(NOW - 7000)}`,
    )
    const history = get('public-status-history')
    expect(words(history.querySelector('p')!)).toBe(
      'No past published incidents',
    )
    expect(history.querySelectorAll('li')).toHaveLength(0)
    expect(words(history)).not.toContain(formatMoment(NOW - 7000))
    for (const button of get('public-status-days-90').querySelectorAll(
      'button',
    ))
      expect(button.getAttribute('aria-label')).toMatch(/ · Operational$/)
  })

  it('shows Checking apart from an older outage verdict and hides it once the heartbeat is stale', async () => {
    const file = statusFile({
      candidates: [{ componentKey: 'mail', detectedAt: NOW - 7000 }],
      outages: [outage()],
    })
    file.components[0]!.state = 'major_outage'
    file.components.push({
      componentKey: 'mail',
      name: 'Notifications',
      description: null,
      state: 'checking',
    })
    const h = harness(file)
    await settle()
    expect(text('public-status-banner')).toBe('Major outage')
    expect(text('public-status-candidates')).toContain(
      'Checking a possible problem with Notifications',
    )
    expect(all('public-status-component-state').map(words)).toEqual([
      'Major outage',
      'Checking',
    ])
    h.fail()
    elapse(120001)
    expect(find('public-status-candidates')).toBeNull()
    expect(text('public-status-outages')).toContain('Site')
  })

  it('renders descending plain-text timelines and the accepted heartbeat rather than fetch or generation time', async () => {
    harness(
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
    const updates = all('public-status-update').map(words)
    expect(updates[0]).toContain('<img src=x onerror=alert(1)> & investigating')
    expect(updates[0]).toContain('From the team')
    expect(updates[1]).toContain('Automatic')
    expect(document.body.querySelector('img')).toBeNull()
    expect(text('public-status-last-update')).toBe(
      `Last update from the system: ${formatMoment(NOW)}`,
    )
  })

  it('reformats every moment on a reader-zone change and exposes 90/30 calendar day details to keyboard focus', async () => {
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
    const wide = get('public-status-days-90')
    const narrow = get('public-status-days-30')
    expect(
      wide.querySelector('[role="group"]')!.getAttribute('aria-label'),
    ).toBe('Site: last 90 days')
    expect(
      narrow.querySelector('[role="group"]')!.getAttribute('aria-label'),
    ).toBe('Site: last 30 days')
    const wideDays = () => [...wide.querySelectorAll('button')]
    const narrowDays = () => [...narrow.querySelectorAll('button')]
    expect(wideDays()).toHaveLength(90)
    expect(narrowDays()).toHaveLength(30)
    expect(narrowDays().map((day) => day.getAttribute('aria-label'))).toEqual(
      wideDays()
        .slice(-30)
        .map((day) => day.getAttribute('aria-label')),
    )
    const detail = get('public-status-day-detail')
    const today = wideDays().at(-1)!
    act(() => today.focus())
    expect(document.activeElement).toBe(today)
    expect(words(detail)).toBe(today.getAttribute('aria-label'))
    expect(today.getAttribute('aria-describedby')).toBe(detail.id)
    expect(words(detail)).toContain('Major outage')
    expect(text('public-status-history')).toContain('10m 07s')
    const previous = words(document.body)
    chooseZone('Pacific/Kiritimati')
    expect(words(document.body)).not.toBe(previous)
    for (const [id, at] of [
      ['public-status-candidates', NOW - 3000],
      ['public-status-outages', NOW - 120000],
      ['public-status-update', NOW - 1000],
      ['public-status-history', ended.detectedAt],
      ['public-status-last-update', NOW],
    ] as const)
      expect(text(id)).toContain(formatMoment(at))
    expect(words(detail)).toBe(wideDays().at(-1)!.getAttribute('aria-label'))
    const first = narrowDays()[0]!
    fireEvent.click(first)
    expect(words(detail)).toBe(first.getAttribute('aria-label'))
    expect(words(detail)).toContain('Operational')
    expect(h.sent).toEqual([])
  })

  it('renders real scheduled and maintenance data only when supplied', async () => {
    harness(
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
            announcementKnown: true,
            title: 'Unannounced maintenance',
            componentKeys: ['site'],
            startedAt: NOW - 60000,
            lastObservedAt: NOW - 60000,
            endedAt: null,
            completionObservedAt: null,
          },
        ],
      }),
    )
    await settle()
    expect(text('public-status-works')).toContain('Database upgrade')
    expect(text('public-status-maintenance')).toContain(
      'Unannounced maintenance',
    )
    expect(text('public-status-banner')).toBe('Maintenance in progress')
  })

  it('shows observed completion with an unknown end and no invented duration or active maintenance', async () => {
    harness(
      statusFile({
        maintenancePeriods: [
          {
            id: 'missed-end',
            workId: null,
            announcementKnown: false,
            title: 'Historical maintenance',
            componentKeys: ['site'],
            startedAt: NOW - 172800000,
            lastObservedAt: NOW - 172799000,
            endedAt: null,
            completionObservedAt: NOW - 1000,
          },
        ],
      }),
    )
    await settle()
    expect(find('public-status-maintenance')).toBeNull()
    expect(text('public-status-banner')).toBe('All systems operational')
    const history = text('public-status-history')
    expect(history).toContain('Historical maintenance')
    expect(history).toContain('Completion observed at')
    expect(history).toContain('Exact end unknown.')
    expect(history).not.toContain('Completed at')
    expect(history).not.toContain('0s')
  })

  it('hides only subscription controls offline and frozen, then restores them without a page request', async () => {
    const h = harness(statusFile(), { live: false })
    await settle()
    expect(find('public-status-subscribe')).toBeNull()
    expect(find('public-status-subscription-hint')).toBeNull()
    expect(text('public-status-components')).toContain('Site')
    h.connect('connected')
    await settle()
    expect(find('public-status-subscribe')).not.toBeNull()
    expect(text('public-status-subscription-hint')).toBe(
      'Subscribed already? Every letter has a link to unsubscribe.',
    )
    h.freeze(true)
    expect(find('public-status-subscribe')).toBeNull()
    h.freeze(false)
    expect(find('public-status-subscribe')).not.toBeNull()
    expect(h.sent).toEqual([])
  })

  it.each(['guest', 'admin-view'] as const)(
    'allows %s to subscribe without a page scope or an admin grant, keeps file content while pending and shows only its ack',
    async (mode) => {
      const h = harness()
      const scopes = new ScopeManager()
      if (mode === 'admin-view') scopes.session.data.set('adminViewMode', true)
      let release = () => {}
      act(() => {
        release = bindAdminAccess(scopes)
      })
      cleanups.push(() => release())
      expect(hilosAdminAccess.get()).toBe(
        mode === 'admin-view' ? 'view' : 'none',
      )
      await settle()
      const slot = get('public-status-subscription-slot')
      const live = get('public-status-subscription-live')
      get('public-status-subscribe').focus()
      fireEvent.click(get('public-status-subscribe'))
      fireEvent.change(get('status-subscription-email'), {
        target: { value: 'Reader@Example.net' },
      })
      fireEvent.submit(get('status-subscription-form'))
      expect(h.sent).toEqual([
        {
          action: 'status_subscribe',
          data: { email: 'reader@example.net' },
          requestId: expect.any(String),
        },
      ])
      expect(text('public-status-banner')).toBe('All systems operational')
      expect(find('public-status-subscription-notice')).toBeNull()
      act(() =>
        h.emit('actionSuccess', {
          action: 'status_subscribe',
          requestId: 'stale',
          reply: { status: 'check_inbox' },
        }),
      )
      await settle()
      expect(find('modal')).not.toBeNull()
      h.answer({ status: 'check_inbox' })
      await settle()
      expect(find('modal')).toBeNull()
      expect(text('public-status-subscription-notice')).toBe(
        STATUS_SUBSCRIPTION_COPY.checkInbox,
      )
      expect(words(live)).toBe(STATUS_SUBSCRIPTION_COPY.checkInbox)
      expect(get('public-status-subscription-slot')).toBe(slot)
      expect(get('public-status-subscription-live')).toBe(live)
      h.connect('disconnected')
      await settle()
      expect(text('public-status-subscription-notice')).toBe(
        STATUS_SUBSCRIPTION_COPY.checkInbox,
      )
    },
  )

  it.each(['hashchange', 'popstate'] as const)(
    'handles delivered fragments on %s without replacing the reader and removes both address listeners on unmount',
    async (event) => {
      const listen = vi.spyOn(window, 'addEventListener')
      const remove = vi.spyOn(window, 'removeEventListener')
      const stored = vi.spyOn(Storage.prototype, 'setItem')
      const h = harness()
      await settle()
      const components = get('public-status-components')
      const live = get('public-status-subscription-live')
      const opener = get('public-status-subscribe')
      opener.focus()
      window.history.pushState({}, '', `/status#status-confirm=${TOKEN}`)
      act(() => {
        window.dispatchEvent(new Event(event))
      })
      expect(window.location.hash).toBe('')
      expect(h.sent).toEqual([
        {
          action: 'status_subscription_confirm',
          data: { token: TOKEN },
          requestId: expect.any(String),
        },
      ])
      act(() => {
        window.dispatchEvent(
          new Event(event === 'hashchange' ? 'popstate' : 'hashchange'),
        )
      })
      await settle()
      expect(find('modal')).toBeNull()
      expect(document.activeElement).toBe(opener)
      expect(text('public-status-subscription-notice')).toBe(
        'Confirming your subscription…',
      )
      expect(h.sent).toHaveLength(1)
      h.answer({ status: 'subscribed' })
      await settle()
      expect(document.activeElement).toBe(opener)
      expect(words(live)).toBe(STATUS_SUBSCRIPTION_COPY.subscribed)
      window.history.pushState({}, '', `/status#status-unsubscribe=${TOKEN}`)
      act(() => {
        window.dispatchEvent(new Event(event))
      })
      expect(window.location.hash).toBe('')
      h.answer({ maskedEmail: 'r***@example.net' })
      await settle()
      expect(get('modal').getAttribute('aria-label')).toBe('Unsubscribe')
      expect(document.activeElement).toBe(get('modal'))
      fireEvent.click(get('status-subscription-cancel'))
      await settle()
      expect(document.activeElement).toBe(opener)
      expect(h.sent).toHaveLength(2)
      expect(get('public-status-components')).toBe(components)
      expect(get('public-status-subscription-live')).toBe(live)
      expect(h.fetchFile).toHaveBeenCalledTimes(2)
      expect(stored).not.toHaveBeenCalled()
      expect(document.body.textContent).not.toContain(TOKEN)
      h.unmount()
      for (const kind of ['hashchange', 'popstate']) {
        const registrations = listen.mock.calls.filter(
          ([type]) => type === kind,
        )
        expect(registrations).toHaveLength(1)
        expect(remove).toHaveBeenCalledWith(kind, registrations[0]![1])
      }
      window.history.pushState({}, '', `/status#status-confirm=${TOKEN}`)
      act(() => {
        window.dispatchEvent(new Event(event))
      })
      await settle()
      expect(h.sent).toHaveLength(2)
      expect(find('modal')).toBeNull()
    },
  )

  it.each(['offline', 'frozen'] as const)(
    'erases a confirm fragment immediately while %s, renders files and sends once after recovery',
    async (mode) => {
      window.history.replaceState(
        { entry: 1 },
        '',
        `/status?theme=system#status-confirm=${TOKEN}`,
      )
      const replace = vi.spyOn(window.history, 'replaceState')
      const stored = vi.spyOn(Storage.prototype, 'setItem')
      const h = harness(statusFile(), {
        live: mode !== 'offline',
        frozen: mode === 'frozen',
      })
      expect(replace).toHaveBeenCalledExactlyOnceWith(
        { entry: 1 },
        '',
        '/status?theme=system',
      )
      expect(window.location.hash).toBe('')
      expect(h.sent).toEqual([])
      await settle()
      expect(find('modal')).toBeNull()
      expect(text('public-status-subscription-notice')).toBe(
        STATUS_SUBSCRIPTION_COPY.waiting,
      )
      expect(text('public-status-banner')).toBe('All systems operational')
      expect(h.fetchFile).toHaveBeenCalledTimes(2)
      expect(document.body.textContent).not.toContain(TOKEN)
      h.connect('connected')
      h.freeze(false)
      await settle()
      expect(h.sent).toEqual([
        {
          action: 'status_subscription_confirm',
          data: { token: TOKEN },
          requestId: expect.any(String),
        },
      ])
      h.freeze(true)
      h.freeze(false)
      h.connect('connected')
      expect(h.sent).toHaveLength(1)
      h.answer({ status: 'subscribed' })
      await settle()
      expect(find('modal')).toBeNull()
      expect(text('public-status-subscription-notice')).toBe(
        STATUS_SUBSCRIPTION_COPY.subscribed,
      )
      expect(stored).not.toHaveBeenCalled()
      expect(
        h.fetchFile.mock.calls.every(([url]) => !url.includes(TOKEN)),
      ).toBe(true)
    },
  )

  it('answers a broken link in the page slot without a window or a send', async () => {
    window.history.replaceState({}, '', '/status#status-confirm=broken')
    const h = harness()
    await settle()
    expect(window.location.hash).toBe('')
    expect(find('modal')).toBeNull()
    expect(text('public-status-subscription-error')).toContain(
      STATUS_SUBSCRIPTION_COPY.invalidLink,
    )
    expect(find('public-status-subscription-notice')).toBeNull()
    expect(text('public-status-banner')).toBe('All systems operational')
    expect(h.sent).toEqual([])
  })

  it('keeps unsubscribe success on the file page after its explicit submit closes the window', async () => {
    window.history.replaceState({}, '', `/status#status-unsubscribe=${TOKEN}`)
    const h = harness()
    await settle()
    expect(h.sent[0]?.action).toBe('status_unsubscribe_preview')
    h.answer({ maskedEmail: 'r***@example.net' })
    await settle()
    const slot = get('public-status-subscription-slot')
    const reservation = slot.querySelector('[aria-hidden="true"][inert]')!
    expect(words(reservation)).toBe(STATUS_SUBSCRIPTION_COPY.subscribed)
    fireEvent.submit(get('status-subscription-form'))
    expect(h.sent[1]?.action).toBe('status_unsubscribe')
    expect(find('public-status-subscription-notice', slot)).toBeNull()
    h.answer({ status: 'unsubscribed' })
    await settle()
    expect(find('modal')).toBeNull()
    expect(words(get('public-status-subscription-notice', slot))).toBe(
      STATUS_SUBSCRIPTION_COPY.unsubscribed,
    )
    expect(slot.querySelector('[aria-hidden="true"][inert]')).toBe(reservation)
    expect(text('public-status-banner')).toBe('All systems operational')
  })

  it('keeps a waiting confirmation visible on the page without a window and ignores a completion after unmount', async () => {
    window.history.replaceState({}, '', `/status#status-confirm=${TOKEN}`)
    const h = harness(statusFile(), { live: false })
    await settle()
    expect(find('modal')).toBeNull()
    expect(text('public-status-subscription-notice')).toBe(
      'Waiting for a live connection to continue.',
    )
    h.connect('connected')
    await settle()
    expect(h.sent).toHaveLength(1)
    h.unmount()
    expect(h.listening('protectedMode')).toBe(0)
    expect(h.listening('projectSignal')).toBe(0)
    expect(h.listening('state')).toBe(1)
    h.answer({ status: 'subscribed' })
    h.connect('connected')
    await settle()
    expect(find('modal')).toBeNull()
    expect(document.body.textContent).not.toContain(
      STATUS_SUBSCRIPTION_COPY.subscribed,
    )
    expect(h.sent).toHaveLength(1)
    expect(vi.getTimerCount()).toBe(0)
  })
})

describe('public HilosStatusPage lifecycle', () => {
  it('disposes the single reader on unmount, aborts requests and ignores late responses', async () => {
    const h = harness(statusFile(), { live: false })
    let resolve!: (value: { ok: boolean; json: () => Promise<unknown> }) => void
    const late = new Promise<{ ok: boolean; json: () => Promise<unknown> }>(
      (accept) => {
        resolve = accept
      },
    )
    h.fetchFile.mockReturnValue(late)
    await settle()
    await advance(30000)
    const signal = h.fetchFile.mock.calls.at(-1)![1].signal!
    h.unmount()
    expect(signal.aborted).toBe(true)
    // The application's lifecycle still owns its connection listener.
    expect(h.listening('state')).toBe(1)
    expect(h.listening('protectedMode')).toBe(0)
    expect(h.listening('projectSignal')).toBe(0)
    expect(vi.getTimerCount()).toBe(0)
    const calls = h.fetchFile.mock.calls.length
    resolve({ ok: true, json: async () => statusFile() })
    await settle()
    await advance(240000)
    expect(h.fetchFile).toHaveBeenCalledTimes(calls)
    expect(h.sent).toEqual([])
  })

  it('gives the StrictMode replay one live reader and subscription and leaves nothing after unmount', async () => {
    const h = harness(statusFile(), { strict: true })
    // The replay discarded the first generation and aborted its two reads.
    expect(h.fetchFile).toHaveBeenCalledTimes(4)
    expect(
      h.fetchFile.mock.calls.map(([, init]) => init.signal!.aborted),
    ).toEqual([true, true, false, false])
    expect(h.listening('state')).toBe(3)
    expect(h.listening('protectedMode')).toBe(2)
    expect(h.listening('projectSignal')).toBe(1)
    await settle()
    expect(text('public-status-banner')).toBe('All systems operational')
    h.publish(statusFile({ scheduledWorks: [work()] }))
    await settle()
    expect(h.fetchFile).toHaveBeenCalledTimes(6)
    expect(text('public-status-works')).toContain('Database upgrade')
    fireEvent.click(get('public-status-subscribe'))
    fireEvent.change(get('status-subscription-email'), {
      target: { value: 'reader@example.net' },
    })
    fireEvent.submit(get('status-subscription-form'))
    expect(h.sent.map((sent) => sent.action)).toEqual(['status_subscribe'])
    h.answer({ status: 'check_inbox' })
    await settle()
    expect(find('modal')).toBeNull()
    expect(text('public-status-subscription-notice')).toBe(
      STATUS_SUBSCRIPTION_COPY.checkInbox,
    )
    h.unmount()
    expect(find('public-status-banner')).toBeNull()
    expect(h.listening('state')).toBe(1)
    expect(h.listening('protectedMode')).toBe(0)
    expect(h.listening('projectSignal')).toBe(0)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('reads the files again on re-entry with a fresh subscription, and a late answer revives nothing', async () => {
    const h = harness()
    await settle()
    fireEvent.click(get('public-status-subscribe'))
    fireEvent.change(get('status-subscription-email'), {
      target: { value: 'reader@example.net' },
    })
    fireEvent.submit(get('status-subscription-form'))
    expect(h.sent).toHaveLength(1)
    h.unmount()
    h.answer({ status: 'check_inbox' })
    await settle()
    expect(find('modal')).toBeNull()
    expect(document.body.textContent).not.toContain(
      STATUS_SUBSCRIPTION_COPY.checkInbox,
    )

    h.remount(true)
    expect(h.listening('state')).toBe(3)
    expect(h.listening('protectedMode')).toBe(2)
    expect(h.listening('projectSignal')).toBe(1)
    await settle()
    expect(text('public-status-banner')).toBe('All systems operational')
    expect(find('public-status-subscription-notice')).toBeNull()
    expect(find('modal')).toBeNull()
    fireEvent.click(get('public-status-subscribe'))
    expect(get('modal').getAttribute('aria-label')).toBe(
      STATUS_SUBSCRIPTION_COPY.subscribe,
    )
    expect((get('status-subscription-email') as HTMLInputElement).value).toBe(
      '',
    )
  })
})
