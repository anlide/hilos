import { describe, expect, it } from 'vitest'
import {
  LETTER_FRAME_DATA,
  LETTER_FRAME_FIELD,
  createHilosChannelFrame,
  letterFrameSchema,
} from '../../../src/admin/communications/index.js'
import { HilosConnection } from '../../../src/connection/HilosConnection.js'
import { pageResponseSchema } from '../../../src/protocol/scopePayload.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { subscribeSignal } from '../../../src/state/signal.js'
import { bindPageScope } from '../../../src/subscription/bindPageScope.js'

const frame = {
  channel: 'email',
  html: '<html lang="[[:lang]]"><body>[[:content]]</body></html>',
  source: 'default',
  previewHtml: '<html lang="en"><body>482913</body></html>',
}

describe('letterFrameSchema', () => {
  it.each(['settings', 'project', 'default'])(
    'preserves the complete %s frame',
    (source) => {
      expect(LETTER_FRAME_DATA).toBe('letterFrame')
      expect(LETTER_FRAME_FIELD).toBe('frame')
      expect(letterFrameSchema.parse({ ...frame, source })).toEqual({
        ...frame,
        source,
      })
    },
  )

  it('leaves HTML validation to the backend without coercion or trimming', () => {
    const value = { ...frame, html: '', previewHtml: ' \n ' }
    expect(letterFrameSchema.parse(value)).toEqual(value)
  })

  it.each(['html', 'previewHtml'] as const)(
    'canonicalizes a hidden %s independently',
    (field) => {
      const parsed = letterFrameSchema.parse({
        ...frame,
        [field]: { _hidden: true },
      })
      expect(parsed[field]).toBe(HIDDEN_VALUE)
      expect(parsed[field === 'html' ? 'previewHtml' : 'html']).toBe(
        frame[field === 'html' ? 'previewHtml' : 'html'],
      )
    },
  )

  it('accepts both HTML fields masked, with public metadata intact', () => {
    const parsed = letterFrameSchema.parse({
      ...frame,
      html: { _hidden: true },
      previewHtml: { _hidden: true },
    })
    expect(parsed.html).toBe(HIDDEN_VALUE)
    expect(parsed.previewHtml).toBe(HIDDEN_VALUE)
    expect(parsed.channel).toBe('email')
    expect(parsed.source).toBe('default')
  })

  it.each(['channel', 'html', 'source', 'previewHtml'])(
    'requires %s',
    (key) => {
      const value: Record<string, unknown> = { ...frame }
      delete value[key]
      expect(letterFrameSchema.safeParse(value).success).toBe(false)
    },
  )

  it.each([
    { channel: 'sms' },
    { channel: 'EMAIL' },
    { channel: 1 },
    { channel: { _hidden: true } },
    { source: 'env' },
    { source: '' },
    { source: null },
    { source: { _hidden: true } },
    { extra: true },
  ])('rejects unexpected metadata or extra fields: %j', (patch) => {
    expect(letterFrameSchema.safeParse({ ...frame, ...patch }).success).toBe(
      false,
    )
  })

  describe.each(['html', 'previewHtml'])('%s', (field) => {
    it.each([
      null,
      undefined,
      true,
      1,
      [],
      {},
      { _hidden: false },
      { _hidden: 'true' },
      { _hidden: true, value: 'html' },
    ])('rejects mistyped HTML or hidden marks: %j', (value) => {
      expect(
        letterFrameSchema.safeParse({ ...frame, [field]: value }).success,
      ).toBe(false)
    })
  })
})

describe('createHilosChannelFrame', () => {
  it('reads the first page answer and server preview updates without other scopes', () => {
    const scopes = new ScopeManager()
    const pages = bindPageScope(
      new HilosConnection({ url: 'ws://test/ws' }),
      scopes,
    )
    const current = createHilosChannelFrame({ scopes })
    scopes.session.data.set('letterFrame', frame)
    scopes.user.data.set('letterFrame', frame)
    scopes.openGroup('sample').data.set('letterFrame', frame)
    expect(current.get()).toBeUndefined()

    const observed: unknown[] = []
    const off = subscribeSignal(current, (value) => observed.push(value))
    const page = HilosPages.COMMUNICATIONS_CHANNEL
    pages.subscribe(page, { channelId: 'email' })
    expect(current.get()).toBeUndefined()
    const first = pageResponseSchema.parse({
      page,
      payload: { data: { letterFrame: frame, pageLabel: 'Email' } },
    })
    pages.ingestPageResponse(first.page, first.payload ?? {})
    expect(current.get()).toEqual(frame)

    const updated = {
      ...frame,
      source: 'settings',
      previewHtml: { _hidden: true },
    }
    const live = pageResponseSchema.parse({
      page,
      payload: { data: { letterFrame: updated } },
    })
    pages.ingestPageResponse(live.page, live.payload ?? {})
    expect(current.get()?.previewHtml).toBe(HIDDEN_VALUE)
    expect(current.get()).toEqual({ ...updated, previewHtml: HIDDEN_VALUE })
    expect(scopes.page()?.data.signal('pageLabel').get()).toBe('Email')
    expect(observed).toEqual([frame, { ...updated, previewHtml: HIDDEN_VALUE }])
    pages.unsubscribe()
    expect(current.get()).toBeUndefined()
    off()
  })

  it.each([undefined, null, {}, [], 'html', { ...frame, channel: 'sms' }])(
    'drops a valid frame when the payload becomes missing or malformed: %j',
    (value) => {
      const scopes = new ScopeManager()
      const scope = scopes.openPage(HilosPages.COMMUNICATIONS_CHANNEL)
      const current = createHilosChannelFrame({ scopes })
      scope.data.set('letterFrame', frame)
      expect(current.get()).toEqual(frame)
      scope.data.set('letterFrame', value)
      expect(current.get()).toBeUndefined()
    },
  )

  it('forgets old scopes across clearing and replacement of the same page', () => {
    const scopes = new ScopeManager()
    const current = createHilosChannelFrame({ scopes })
    const old = scopes.openPage(HilosPages.COMMUNICATIONS_CHANNEL)
    old.data.set('letterFrame', frame)
    expect(current.get()).toEqual(frame)

    scopes.dropPage()
    expect(current.get()).toBeUndefined()
    const fresh = scopes.openPage(HilosPages.COMMUNICATIONS_CHANNEL)
    expect(current.get()).toBeUndefined()
    old.data.set('letterFrame', { ...frame, source: 'project' })
    expect(current.get()).toBeUndefined()
    fresh.data.set('letterFrame', { ...frame, html: 'fresh' })
    expect(current.get()?.html).toBe('fresh')

    scopes.openPage(HilosPages.COMMUNICATIONS_CHANNEL)
    expect(current.get()).toBeUndefined()
    fresh.data.set('letterFrame', frame)
    expect(current.get()).toBeUndefined()
  })
})
