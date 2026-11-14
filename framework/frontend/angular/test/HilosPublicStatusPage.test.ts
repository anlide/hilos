import { formatMoment, HilosPages } from '@hilos/core/base'
import {
  STATUS_SUBSCRIPTION_COPY,
  type PublicStatusState,
} from '@hilos/core/status'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { HilosStatusPage } from '../src/public/HilosStatusPage.js'
import {
  el,
  query,
  text,
  mountPublicStatus,
  publicStatusFile,
  statusHarness,
} from './helpers/hilosStatus.js'

const mount = (
  initial: unknown = publicStatusFile(),
  live = true,
  frozen = false,
) => {
  window.history.replaceState({}, '', '/status')
  return mountPublicStatus(HilosStatusPage, initial, live, frozen)
}
const outage = () => ({
  id: 'outage',
  componentKey: 'site',
  severity: 'major_outage' as const,
  detectedAt: Date.now() - 120000,
  publishedAt: Date.now() - 60000,
  resolvedAt: null,
  updates: [
    {
      at: Date.now() - 1000,
      text: '<b>Team update</b>',
      source: 'team' as const,
    },
  ],
})
afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

describe('public Angular Status over the real core reader', () => {
  it.each<[PublicStatusState, string, string]>([
    ['operational', 'All systems operational', 'Operational'],
    ['checking', 'Checking a possible problem', 'Checking'],
    ['partial_outage', 'Partial outage', 'Partial outage'],
    ['major_outage', 'Major outage', 'Major outage'],
    ['maintenance', 'Maintenance in progress', 'Maintenance'],
    ['unknown', 'Status unknown', 'Unknown'],
  ])(
    'renders %s from files without sending a page request',
    async (state, banner, word) => {
      const file = publicStatusFile()
      file.components[0]!.state = state
      const h = mount(file)
      expect(
        el('public-status-banner').querySelector('h2')?.textContent?.trim(),
      ).toBe('Status unknown')
      await h.settle()
      expect(
        el('public-status-banner').querySelector('h2')?.textContent?.trim(),
      ).toBe(banner)
      expect(text('public-status-component-state')).toBe(word)
      expect(document.querySelector('h1')?.textContent).toContain(
        'System status',
      )
      expect(document.querySelectorAll('h1')).toHaveLength(1)
      expect(el('public-status-live').getAttribute('aria-live')).toBe('polite')
      expect(h.fetchFile).toHaveBeenCalledTimes(2)
      expect(h.sent).toEqual([])
    },
  )

  it('keeps cold missing or malformed files unknown', async () => {
    const h = mount({ schemaVersion: 999 }, false)
    await h.settle()
    expect(
      el('public-status-banner').querySelector('h2')?.textContent?.trim(),
    ).toBe('Status unknown')
    expect(query('public-status-components')).toBeNull()
    expect(query('public-status-subscribe')).toBeNull()
    expect(text('public-status-last-update')).toContain('Unknown')
    expect(h.sent).toEqual([])
  })

  it('retains accepted facts on disconnect and a bad pair, then ages current components independently of the socket', async () => {
    const file = publicStatusFile({ outages: [outage()] })
    const h = mount(file)
    await h.settle()
    expect(text('public-status-update')).toContain('<b>Team update</b>')
    expect(el('public-status-update').querySelector('b')).toBeNull()
    h.act(() => h.connect('disconnected'))
    expect(text('public-status-connection')).toContain('system is up')
    h.publish(publicStatusFile({ components: [] }))
    h.beat({
      schemaVersion: 4,
      revision: 'another-revision',
      aliveAt: Date.now(),
    })
    await h.settle()
    expect(text('public-status-component-state')).toBe('Operational')
    h.fail()
    h.act(() => h.connect('connected'))
    await h.settle()
    await vi.advanceTimersByTimeAsync(120001)
    h.detect()
    expect(text('public-status-banner')).toContain('not responding since')
    expect(text('public-status-component-state')).toBe('Unknown')
    expect(text('public-status-outages')).toContain('Team update')
    expect(query('public-status-connection')).toBeNull()
  })

  it('reads hints, polls offline and refreshes immediately on recovery without using actions', async () => {
    const h = mount()
    await h.settle()
    h.publish(publicStatusFile({ components: [] }))
    await h.settle()
    expect(query('public-status-components')).toBeNull()
    expect(h.fetchFile).toHaveBeenCalledTimes(4)
    h.act(() => h.connect('disconnected'))
    await vi.advanceTimersByTimeAsync(30000)
    h.detect()
    expect(h.fetchFile).toHaveBeenCalledTimes(6)
    h.act(() => h.connect('connected'))
    await h.settle()
    expect(h.fetchFile).toHaveBeenCalledTimes(8)
    expect(h.sent).toEqual([])
  })

  it('gives Checking a separate card and keeps accessible calendar details through keyboard focus and activation', async () => {
    const h = mount(
      publicStatusFile({
        candidates: [{ componentKey: 'site', detectedAt: Date.now() - 1000 }],
        outages: [outage()],
      }),
    )
    await h.settle()
    expect(text('public-status-candidates')).toContain(
      'Checking a possible problem with Site',
    )
    const wide = el('public-status-days-90').querySelectorAll('button')
    const narrow = el('public-status-days-30').querySelectorAll('button')
    expect(wide).toHaveLength(90)
    expect(narrow).toHaveLength(30)
    const details = el('public-status-day-detail')
    expect(details.textContent?.trim()).toBe(
      wide[89]!.getAttribute('aria-label'),
    )
    h.act(() => wide[0]!.focus())
    expect(details.textContent?.trim()).toBe(
      wide[0]!.getAttribute('aria-label'),
    )
    expect(wide[0]!.getAttribute('aria-describedby')).toBe(details.id)
    h.act(() => narrow[0]!.click())
    expect(details.textContent?.trim()).toBe(
      narrow[0]!.getAttribute('aria-label'),
    )
    expect(narrow[0]!.getAttribute('aria-label')).toMatch(
      / · (Operational|Unknown|Major outage)/,
    )
  })

  it.each([
    ['major_outage', 'Major outage'],
    ['partial_outage', 'Partial outage'],
    ['maintenance', 'Maintenance'],
  ] as const)(
    'names No data and recorded %s, while silence alone has no Operational claim',
    async (event, word) => {
      vi.setSystemTime(Date.parse('2026-10-25T12:00:00Z'))
      const startedAt = Date.parse('2026-10-24T06:00:00Z')
      const endedAt = Date.parse('2026-10-24T07:00:00Z')
      const h = mount(
        publicStatusFile({
          silencePeriods: [
            {
              id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
              startedAt: Date.parse('2026-10-24T08:00:00Z'),
              endedAt: Date.parse('2026-10-24T09:00:00Z'),
            },
            {
              id: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
              startedAt: Date.parse('2026-10-25T08:00:00Z'),
              endedAt: Date.parse('2026-10-25T09:00:00Z'),
            },
          ],
          outages:
            event === 'maintenance'
              ? []
              : [
                  {
                    ...outage(),
                    severity: event,
                    detectedAt: startedAt,
                    publishedAt: startedAt,
                    resolvedAt: endedAt,
                  },
                ],
          maintenancePeriods:
            event === 'maintenance'
              ? [
                  {
                    id: 'historical-maintenance',
                    workId: null,
                    announcementKnown: false,
                    title: 'Historical maintenance',
                    componentKeys: ['site'],
                    startedAt,
                    lastObservedAt: endedAt,
                    endedAt,
                    completionObservedAt: endedAt,
                  },
                ]
              : [],
        }),
      )
      await h.settle()
      const mixedLabel = `${formatMoment(startedAt, { kind: 'date' })} · No data · ${word} recorded`
      const silenceLabel = `${formatMoment(Date.now(), { kind: 'date' })} · No data`
      const detail = el('public-status-day-detail')
      expect(detail.textContent?.trim()).toBe(silenceLabel)

      for (const count of [90, 30]) {
        const strip = el(`public-status-days-${count}`)
        const mixed = strip.querySelector<HTMLButtonElement>(
          `button[aria-label="${mixedLabel}"]`,
        )
        const silence = strip.querySelector<HTMLButtonElement>(
          `button[aria-label="${silenceLabel}"]`,
        )
        expect(mixed).not.toBeNull()
        expect(silence).not.toBeNull()
        expect(mixed!.querySelector('[aria-hidden="true"]')).not.toBeNull()
        expect(silence!.querySelector('[aria-hidden="true"]')).toBeNull()

        h.act(() => mixed!.click())
        expect(detail.textContent?.trim()).toBe(mixedLabel)
        expect(mixed!.getAttribute('aria-describedby')).toBe(detail.id)
        h.act(() => silence!.click())
        expect(detail.textContent?.trim()).toBe(silenceLabel)
        expect(detail.textContent).not.toContain('Operational')
      }
      expect(h.sent).toEqual([])
    },
  )

  it('names a day before observation Not monitored yet, without a mark, and selects it by keyboard focus', async () => {
    vi.setSystemTime(Date.parse('2026-10-25T12:00:00Z'))
    const observedSince = Date.parse('2026-10-20T10:00:00Z')
    // A gap with an outage in it would mark that day, were the day not before the moment.
    const file = publicStatusFile({
      silencePeriods: [
        {
          id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
          startedAt: Date.parse('2026-10-18T09:00:00Z'),
          endedAt: Date.parse('2026-10-18T11:00:00Z'),
        },
      ],
      outages: [
        {
          ...outage(),
          detectedAt: Date.parse('2026-10-18T09:30:00Z'),
          publishedAt: Date.parse('2026-10-18T09:30:00Z'),
          resolvedAt: Date.parse('2026-10-18T10:30:00Z'),
        },
      ],
    })
    file.components[0]!.observedSince = observedSince
    const h = mount(file)
    await h.settle()
    // Two days earlier is a whole reader day before the moment in any zone.
    const emptyLabel = `${formatMoment(Date.parse('2026-10-18T10:00:00Z'), { kind: 'date' })} · Not monitored yet`
    const startedLabel = `${formatMoment(observedSince, { kind: 'date' })} · Operational`
    const detail = el('public-status-day-detail')
    for (const count of [90, 30]) {
      const strip = el(`public-status-days-${count}`)
      const empty = strip.querySelector<HTMLButtonElement>(
        `button[aria-label="${emptyLabel}"]`,
      )
      expect(empty).not.toBeNull()
      expect(
        strip.querySelector(`button[aria-label="${startedLabel}"]`),
      ).not.toBeNull()
      expect(empty!.querySelector('[aria-hidden="true"]')).toBeNull()
      h.act(() => empty!.focus())
      expect(document.activeElement).toBe(empty)
      expect(detail.textContent?.trim()).toBe(emptyLabel)
      expect(empty!.getAttribute('aria-describedby')).toBe(detail.id)
    }
    expect(h.sent).toEqual([])
  })

  it.each([90, 30])(
    'keeps the keyboard-focused date in the %s-day strip through heartbeat aging, midnight and recovery',
    async (count) => {
      const aliveAt = Date.parse('2026-10-25T22:59:00Z')
      vi.setSystemTime(aliveAt)
      const facts = publicStatusFile({
        outages: [
          {
            ...outage(),
            detectedAt: aliveAt - 60000,
            publishedAt: aliveAt - 30000,
            resolvedAt: aliveAt,
          },
        ],
      })
      const h = mount(facts)
      h.chooseZone('Europe/Warsaw')
      h.publish(facts, aliveAt)
      await h.settle()

      const date = formatMoment(aliveAt, { kind: 'date' })
      const strip = el(`public-status-days-${count}`)
      const button = strip.querySelector<HTMLButtonElement>(
        `button[aria-label="${date} · Major outage"]`,
      )
      expect(button).not.toBeNull()
      h.act(() => button!.focus())
      const detail = el('public-status-day-detail')
      expect(document.activeElement).toBe(button)
      expect(detail.textContent?.trim()).toBe(`${date} · Major outage`)
      expect(button!.getAttribute('aria-describedby')).toBe(detail.id)

      await vi.advanceTimersByTimeAsync(120001)
      h.detect()
      const mixedLabel = `${date} · No data · Major outage recorded`
      const agedButton = strip.querySelector<HTMLButtonElement>(
        `button[aria-label="${mixedLabel}"]`,
      )
      expect(agedButton).toBe(button)
      expect(document.activeElement).toBe(button)
      expect(detail.textContent?.trim()).toBe(mixedLabel)
      const todayLabel = `${formatMoment(Date.now(), { kind: 'date' })} · No data`
      expect(
        strip.querySelector(`button[aria-label="${todayLabel}"]`),
      ).not.toBeNull()
      expect(text('public-status-banner')).toContain('not responding since')

      h.publish(
        publicStatusFile({
          outages: facts.outages,
          silencePeriods: [
            {
              id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
              startedAt: aliveAt,
              endedAt: Date.now(),
            },
          ],
        }),
        Date.now(),
      )
      await h.settle()
      expect(strip.querySelector(`button[aria-label="${mixedLabel}"]`)).toBe(
        button,
      )
      expect(document.activeElement).toBe(button)
      expect(detail.textContent?.trim()).toBe(mixedLabel)
      expect(button!.getAttribute('aria-describedby')).toBe(detail.id)
      expect(text('public-status-banner')).toBe('All systems operational')
      expect(h.sent).toEqual([])
    },
  )

  it('renders scheduled work, actual maintenance and observed completion without inventing an exact end', async () => {
    const now = Date.now()
    const period = {
      id: 'maintenance',
      workId: null,
      announcementKnown: false,
      title: 'Historical maintenance',
      componentKeys: ['site'],
      startedAt: now - 172800000,
      lastObservedAt: now - 172799000,
      endedAt: null,
      completionObservedAt: now - 1000,
    }
    const h = mount(
      publicStatusFile({
        scheduledWorks: [
          {
            id: 'work',
            title: '<b>Upgrade</b>',
            description: 'Planned work',
            componentKeys: ['site'],
            startsAt: now + 60000,
            endsAt: now + 3600000,
          },
        ],
        maintenancePeriods: [
          period,
          {
            ...period,
            id: 'active',
            title: 'Actual maintenance',
            startedAt: now - 60000,
            lastObservedAt: now,
            completionObservedAt: null,
          },
        ],
      }),
    )
    await h.settle()
    expect(text('public-status-works')).toContain('<b>Upgrade</b>')
    expect(el('public-status-works').querySelector('b')).toBeNull()
    expect(text('public-status-maintenance')).toContain('Actual maintenance')
    expect(text('public-status-history')).toContain('Completion observed at')
    expect(text('public-status-history')).toContain('Exact end unknown.')
    expect(text('public-status-history')).not.toContain('Completed at')
    h.chooseZone('Europe/Warsaw')
    const before = text('public-status-works')
    h.chooseZone('Asia/Tokyo')
    expect(text('public-status-works')).not.toBe(before)
    expect(text('public-status-works')).toContain(formatMoment(now + 60000))
    expect(h.fetchFile).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(120001)
    h.detect()
    expect(text('public-status-maintenance-retained')).toContain(
      'Current maintenance status is unknown',
    )
  })

  it('reads files under freeze while withholding subscription controls until the connection is usable', async () => {
    const h = mount(publicStatusFile(), false, true)
    await h.settle()
    expect(text('public-status-banner')).toBe('All systems operational')
    expect(query('public-status-subscribe')).toBeNull()
    h.act(() => h.connect('connected'))
    expect(query('public-status-subscribe')).toBeNull()
    h.act(() => h.freeze(false))
    await h.settle()
    expect(query('public-status-subscribe')).not.toBeNull()
    expect(h.sent).toEqual([])
  })

  it('scrubs a confirmation fragment while waiting and never replays it after a late reply on a replaced input', async () => {
    const fragment = `#status-confirm=${'ab'.repeat(32)}`
    window.history.replaceState({}, '', `/status${fragment}`)
    const h = mountPublicStatus(HilosStatusPage, publicStatusFile(), false)
    expect(window.location.hash).toBe(fragment)
    expect(h.sent).toEqual([])
    await h.settle()
    expect(window.location.hash).toBe('')
    expect(query('modal')).toBeNull()
    expect(text('public-status-subscription-notice')).toBe(
      STATUS_SUBSCRIPTION_COPY.waiting,
    )
    h.act(() => h.connect('connected'))
    expect(h.sent[0]?.action).toBe('status_subscription_confirm')
    const second = statusHarness(HilosPages.PUBLIC_STATUS)
    try {
      h.fixture.componentRef.setInput('connection', second.context.connection)
      h.fixture.componentRef.setInput('actions', second.context.actions)
      h.detect()
      h.reply(true, { reply: { status: 'subscribed' } })
      await h.settle()
      expect(query('public-status-subscription-notice')).toBeNull()
      expect(query('modal')).toBeNull()
      expect(second.sent).toEqual([])
    } finally {
      second.dispose()
    }
  })

  it('aborts a pending read on input replacement and on departure, dropping every old listener and late result', async () => {
    const h = mount(publicStatusFile(), false)
    await h.settle()
    type Reply = { ok: boolean; json(): Promise<unknown> }
    const pending: Array<{ url: string; accept: (reply: Reply) => void }> = []
    h.fetchFile.mockImplementation(
      (url) =>
        new Promise((accept) => {
          pending.push({ url, accept })
        }),
    )
    const late = publicStatusFile()
    late.components[0]!.name = 'Late old site'
    const release = (requests: typeof pending) => {
      for (const { url, accept } of requests)
        accept({
          ok: true,
          json: async () =>
            url.endsWith('/status.json')
              ? late
              : {
                  schemaVersion: 4,
                  revision: late.revision,
                  aliveAt: Date.now(),
                },
        })
    }
    h.publish(publicStatusFile())
    const oldAbort = h.fetchFile.mock.calls.at(-1)![1].signal!
    const second = statusHarness(HilosPages.PUBLIC_STATUS)
    try {
      h.fixture.componentRef.setInput('connection', second.context.connection)
      h.fixture.componentRef.setInput('actions', second.context.actions)
      h.detect()
      expect(oldAbort.aborted).toBe(true)
      expect(h.listenerCount()).toBe(h.baselineListenerCount)
      release(pending.splice(0, 2))
      await h.settle()
      expect(query('public-status-components')).toBeNull()
      expect(text('public-status-banner')).not.toContain('Late old site')
      const latestAbort = h.fetchFile.mock.calls.at(-1)![1].signal!
      h.fixture.destroy()
      h.fixture.nativeElement.remove()
      expect(latestAbort.aborted).toBe(true)
      const calls = h.fetchFile.mock.calls.length
      release(pending.splice(0))
      await vi.advanceTimersByTimeAsync(240000)
      expect(h.fetchFile).toHaveBeenCalledTimes(calls)
      expect(vi.getTimerCount()).toBe(0)
    } finally {
      second.dispose()
    }
  })

  it('scrubs a malformed capability and reports it without opening a dialog or blocking files', async () => {
    window.history.replaceState({}, '', '/status#status-confirm=broken')
    const h = mountPublicStatus(HilosStatusPage)
    await h.settle()
    expect(window.location.hash).toBe('')
    expect(text('public-status-subscription-error')).toContain(
      STATUS_SUBSCRIPTION_COPY.invalidLink,
    )
    expect(query('modal')).toBeNull()
    expect(text('public-status-component-state')).toBe('Operational')
    expect(h.sent).toEqual([])
  })
})
