import { describe, expect, it } from 'vitest'

import {
  CHANNEL_SUMMARY_DATA,
  channelSummarySchema,
  createHilosChannelSummary,
} from '../../../src/admin/communications/index.js'
import { HilosConnection } from '../../../src/connection/HilosConnection.js'
import { pageResponseSchema } from '../../../src/protocol/scopePayload.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { subscribeSignal } from '../../../src/state/signal.js'
import { bindPageScope } from '../../../src/subscription/bindPageScope.js'

/** The complete wire summary, containing no configuration values. */
const summary = {
  channel: 'email',
  label: 'Email',
  enabled: true,
  configured: false,
  driver: 'smtp',
  missingFields: 2,
  fieldCount: 6,
  secretFieldCount: 1,
  overriddenFieldCount: 3,
}

describe('channelSummarySchema', () => {
  it('preserves every wire field', () => {
    expect(CHANNEL_SUMMARY_DATA).toBe('channelSummary')
    expect(channelSummarySchema.parse(summary)).toEqual(summary)
  })

  it('accepts a channel without a driver, an empty label and zero counts', () => {
    const empty = {
      ...summary,
      label: '',
      enabled: false,
      configured: true,
      driver: null,
      missingFields: 0,
      fieldCount: 0,
      secretFieldCount: 0,
      overriddenFieldCount: 0,
    }

    expect(channelSummarySchema.parse(empty)).toEqual(empty)
  })

  it('canonicalizes hidden enablement without turning it into false', () => {
    const parsed = channelSummarySchema.parse({
      ...summary,
      enabled: { _hidden: true },
    })

    expect(parsed.enabled).toBe(HIDDEN_VALUE)
    expect(parsed.configured).toBe(false)
  })

  it.each([
    'channel',
    'label',
    'enabled',
    'configured',
    'driver',
    'missingFields',
    'fieldCount',
    'secretFieldCount',
    'overriddenFieldCount',
  ])('requires %s even when it can be null or zero', (key) => {
    const value: Record<string, unknown> = { ...summary }
    delete value[key]

    expect(channelSummarySchema.safeParse(value).success).toBe(false)
  })

  describe.each([
    'missingFields',
    'fieldCount',
    'secretFieldCount',
    'overriddenFieldCount',
  ])('%s', (field) => {
    it.each([-1, 0.5, '1', null, NaN, Infinity])(
      'rejects a non-integer or negative count: %j',
      (value) => {
        expect(
          channelSummarySchema.safeParse({ ...summary, [field]: value })
            .success,
        ).toBe(false)
      },
    )
  })

  it.each([
    { channel: 1 },
    { label: null },
    { enabled: 'false' },
    { enabled: 0 },
    { enabled: null },
    { enabled: { _hidden: false } },
    { enabled: { _hidden: true, value: false } },
    { configured: { _hidden: true } },
    { configured: 'true' },
    { driver: 1 },
    { extra: true },
  ])('rejects malformed or unexpected fields: %j', (fields) => {
    expect(
      channelSummarySchema.safeParse({ ...summary, ...fields }).success,
    ).toBe(false)
  })
})

describe('createHilosChannelSummary', () => {
  it.each([
    HilosPages.COMMUNICATIONS_CHANNEL,
    'hilos_communications_channel_configuration',
    HilosPages.COMMUNICATIONS_DELIVERIES,
  ])('reads the first answer and live data-only updates on %s', (page) => {
    const scopes = new ScopeManager()
    const pages = bindPageScope(
      new HilosConnection({ url: 'ws://test/ws' }),
      scopes,
    )
    const current = createHilosChannelSummary({ scopes })
    const observed: unknown[] = []
    const off = subscribeSignal(current, (value) => observed.push(value))

    expect(current.get()).toBeNull()
    pages.subscribe(page, { channelId: 'email' })
    expect(current.get()).toBeNull()

    const first = pageResponseSchema.parse({
      page,
      payload: {
        data: { channelSummary: summary, pageLabel: 'Channel' },
        windows: {
          hilosCommunicationsChannelFields: {
            rows: [],
            sort: [],
            limit: 10,
            totalCount: 0,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
          },
        },
      },
    })
    pages.ingestPageResponse(first.page, first.payload ?? {})
    expect(current.get()).toEqual(summary)

    const updated = {
      ...summary,
      label: 'Mail',
      enabled: { _hidden: true },
      configured: true,
      missingFields: 0,
      overriddenFieldCount: 4,
    }
    const live = pageResponseSchema.parse({
      page,
      payload: { data: { channelSummary: updated } },
    })
    pages.ingestPageResponse(live.page, live.payload ?? {})
    expect(current.get()).toEqual({ ...updated, enabled: HIDDEN_VALUE })
    expect(current.get()?.enabled).toBe(HIDDEN_VALUE)
    expect(scopes.page()?.data.signal('pageLabel').get()).toBe('Channel')
    expect(observed).toEqual([summary, { ...updated, enabled: HIDDEN_VALUE }])
    off()
  })

  it.each([undefined, null, {}, [], 'email', { ...summary, fieldCount: -1 }])(
    'clears a previously valid summary when the slot becomes invalid: %j',
    (value) => {
      const scopes = new ScopeManager()
      const scope = scopes.openPage(HilosPages.COMMUNICATIONS_CHANNEL)
      const current = createHilosChannelSummary({ scopes })
      scope.data.set('channelSummary', summary)
      expect(current.get()).toEqual(summary)

      scope.data.set('channelSummary', value)
      expect(current.get()).toBeNull()
    },
  )

  it('drops the previous card across channels, page changes and scope clearing', () => {
    const scopes = new ScopeManager()
    const pages = bindPageScope(
      new HilosConnection({ url: 'ws://test/ws' }),
      scopes,
    )
    const current = createHilosChannelSummary({ scopes })
    const overview = HilosPages.COMMUNICATIONS_CHANNEL
    const configuration = 'hilos_communications_channel_configuration'

    const oldScope = pages.subscribe(overview, { channelId: 'email' })
    pages.ingestPageResponse(overview, { data: { channelSummary: summary } })
    expect(current.get()).toEqual(summary)

    pages.subscribe(overview, { channelId: 'sms' })
    expect(current.get()).toBeNull()
    const sms = { ...summary, channel: 'sms', label: 'SMS', driver: null }
    pages.ingestPageResponse(overview, { data: { channelSummary: sms } })
    expect(current.get()).toEqual(sms)

    pages.subscribe(configuration, { channelId: 'sms' })
    expect(current.get()).toBeNull()
    expect(
      pages.ingestPageResponse(overview, { data: { channelSummary: summary } }),
    ).toBe(false)
    expect(current.get()).toBeNull()
    pages.ingestPageResponse(configuration, { data: { channelSummary: sms } })
    expect(current.get()).toEqual(sms)

    pages.unsubscribe()
    expect(current.get()).toBeNull()
    oldScope.data.set('channelSummary', { ...summary, label: 'Old channel' })
    expect(current.get()).toBeNull()
    pages.subscribe(overview, { channelId: 'push' })
    pages.ingestPageResponse(overview, { data: { pageLabel: 'Channel' } })
    expect(current.get()).toBeNull()
  })
})
