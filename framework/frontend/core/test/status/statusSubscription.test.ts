import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  createStatusSubscription,
  STATUS_SUBSCRIPTION_COPY,
} from '../../src/status/index.js'
import { hilosToasts } from '../../src/state/toasts.js'
import { subscribeSignal } from '../../src/state/signal.js'
import { harness } from '../admin/status/fixtures.js'

const token = 'ab'.repeat(32)
const fragment = (kind: string) => `#status-${kind}=${token}`
function setup(hash = '') {
  const h = harness()
  const location = { hash, pathname: '/status', search: '?theme=system' }
  const history = {
    state: { entry: 1 },
    replaceState: vi.fn(
      (_state: unknown, _unused: string, url?: string | URL | null) => {
        location.hash = new URL(String(url), 'https://example.net').hash
      },
    ),
  }
  const addressListeners = new Set<() => void>()
  const offAddress = vi.fn(() => addressListeners.clear())
  const onChange = vi.fn((listener: () => void) => {
    addressListeners.add(listener)
    return offAddress
  })
  const controller = createStatusSubscription(h.context, {
    address: { location, history, onChange },
  })
  return {
    ...h,
    controller,
    history,
    location,
    onChange,
    offAddress,
    navigate(hash: string) {
      location.hash = hash
      for (const listener of addressListeners) listener()
    },
    answer(reply: unknown, index = h.sent.length - 1) {
      const sent = h.sent[index]!
      h.emit('actionSuccess', {
        action: sent.action,
        requestId: sent.requestId,
        reply,
      })
    },
    async flush() {
      await Promise.resolve()
      await Promise.resolve()
    },
    dispose() {
      controller.dispose()
      h.releaseAccess()
    },
  }
}
beforeEach(() => vi.useFakeTimers())
afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

describe('public Status subscription', () => {
  it('starts without browser globals or an address dependency', () => {
    const h = harness()
    const controller = createStatusSubscription(h.context)
    controller.start()
    expect(controller.usable.get()).toBe(true)
    expect(controller.step.get()).toBe('idle')
    expect(h.sent).toEqual([])
    controller.dispose()
    h.releaseAccess()
  })

  it('consumes a new confirm fragment after Check your inbox without restarting the mount', async () => {
    const h = setup()
    h.controller.start()
    h.controller.open()
    h.controller.setDraft({ email: 'reader@example.net' })
    const subscribe = h.controller.submit()
    h.answer({ status: 'check_inbox' })
    expect(await subscribe).toBe(true)
    h.navigate(fragment('confirm'))
    expect(h.location.hash).toBe('')
    expect(h.controller.dialog.get()).toBe(false)
    expect(h.controller.success.get()).toBeNull()
    expect(h.sent[1]).toEqual({
      action: 'status_subscription_confirm',
      data: { token },
      requestId: '2',
    })
    h.onChange.mock.calls[0]![0]() // The paired popstate/hashchange sees the scrubbed address.
    h.controller.start()
    expect(h.onChange).toHaveBeenCalledOnce()
    expect(h.sent).toHaveLength(2)
    h.answer({ status: 'subscribed' })
    await h.flush()
    expect(h.controller.success.get()).toBe(STATUS_SUBSCRIPTION_COPY.subscribed)
    expect(h.registered.size).toBe(0)
    expect(h.viewports).toEqual([])
    h.dispose()
    expect(h.offAddress).toHaveBeenCalledOnce()
  })

  it.each(['offline', 'frozen'])(
    'immediately scrubs a newly delivered fragment while %s and retains only the first waiting link',
    async (mode) => {
      const h = setup()
      h.controller.start()
      if (mode === 'offline') h.connect('disconnected')
      else h.freeze(true)
      h.navigate(fragment('confirm'))
      expect(h.location.hash).toBe('')
      expect(h.controller.step.get()).toBe('waiting')
      expect(h.sent).toEqual([])
      h.navigate(`#status-unsubscribe=${'cd'.repeat(32)}`)
      expect(h.location.hash).toBe('')
      expect(h.controller.operation.get()).toBe('confirm')
      h.connect('connected')
      h.freeze(false)
      expect(h.sent).toEqual([
        {
          action: 'status_subscription_confirm',
          data: { token },
          requestId: '1',
        },
      ])
      h.answer({ status: 'subscribed' })
      await h.flush()
      h.connect('connected')
      expect(h.sent).toHaveLength(1)
      h.dispose()
    },
  )

  it('previews new unsubscribe arrivals in one mount, keeps on cancel and deletes only on explicit submit', async () => {
    const h = setup()
    h.controller.start()
    for (const keep of [true, false]) {
      h.navigate(fragment('unsubscribe'))
      expect(h.location.hash).toBe('')
      expect(h.controller.dialog.get()).toBe(true)
      expect(h.sent.at(-1)?.action).toBe('status_unsubscribe_preview')
      expect(await h.controller.submit()).toBe(false)
      h.answer({ maskedEmail: 'r***@example.net' })
      await h.flush()
      if (keep) {
        h.controller.close()
        expect(h.controller.dialog.get()).toBe(false)
        expect(h.sent).toHaveLength(1)
      }
    }
    const submit = h.controller.submit()
    expect(h.sent).toEqual([
      { action: 'status_unsubscribe_preview', data: { token }, requestId: '1' },
      { action: 'status_unsubscribe_preview', data: { token }, requestId: '2' },
      { action: 'status_unsubscribe', data: { token }, requestId: '3' },
    ])
    h.answer({ status: 'unsubscribed' })
    expect(await submit).toBe(true)
    expect(h.controller.success.get()).toBe(
      STATUS_SUBSCRIPTION_COPY.unsubscribed,
    )
    h.dispose()
  })

  it('scrubs overlapping capabilities without replacing an in-flight request or a previewed token', async () => {
    const h = setup()
    h.controller.start()
    h.controller.open()
    h.controller.setDraft({ email: 'reader@example.net' })
    const subscribe = h.controller.submit()
    h.navigate(fragment('confirm'))
    expect(h.location.hash).toBe('')
    expect(h.controller.operation.get()).toBe('subscribe')
    expect(h.controller.requestId.get()).toBe('1')
    expect(h.controller.busy.get()).toBe(true)
    expect(h.sent).toHaveLength(1)
    h.answer({ status: 'check_inbox' })
    expect(await subscribe).toBe(true)
    h.navigate(fragment('unsubscribe'))
    h.answer({ maskedEmail: 'r***@example.net' })
    await h.flush()
    h.navigate(`#status-unsubscribe=${'cd'.repeat(32)}`)
    expect(h.location.hash).toBe('')
    expect(h.controller.maskedEmail.get()).toBe('r***@example.net')
    expect(h.controller.requestId.get()).toBe('2')
    const submit = h.controller.submit()
    h.navigate(fragment('confirm'))
    h.controller.close()
    expect(await h.controller.submit()).toBe(false)
    expect(h.controller.requestId.get()).toBe('3')
    expect(h.sent).toHaveLength(3)
    expect(h.sent[2]?.data).toEqual({ token })
    h.answer({ status: 'unsubscribed' })
    expect(await submit).toBe(true)
    h.dispose()
  })

  it('ignores unrelated anchors and addresses outside the mounted pathname without resetting the draft', () => {
    const h = setup()
    h.controller.start()
    h.controller.open()
    h.controller.setDraft({ email: 'reader@example.net' })
    for (const hash of ['#hilos-main-content', '#history']) {
      h.navigate(hash)
      expect(h.controller.draft.get()).toEqual({ email: 'reader@example.net' })
      expect(h.controller.step.get()).toBe('ready')
    }
    h.location.pathname = '/other'
    h.navigate(fragment('confirm'))
    expect(h.history.replaceState).not.toHaveBeenCalled()
    expect(h.sent).toEqual([])
    h.dispose()
  })

  it('unsubscribes address changes terminally, including a callback already captured before disposal', () => {
    const h = setup()
    h.controller.start()
    const captured = h.onChange.mock.calls[0]![0]
    h.dispose()
    h.navigate(fragment('confirm'))
    captured()
    h.controller.start()
    expect(h.onChange).toHaveBeenCalledOnce()
    expect(h.offAddress).toHaveBeenCalledOnce()
    expect(h.history.replaceState).not.toHaveBeenCalled()
    expect(h.sent).toEqual([])
    expect(h.controller.step.get()).toBe('idle')
  })

  it('rejects a malformed live arrival, then accepts a new link without adopting a timed-out reply', async () => {
    const h = setup()
    h.controller.start()
    h.navigate('#status-confirm=short')
    expect(h.location.hash).toBe('')
    expect(h.controller.error.get()).toBe(STATUS_SUBSCRIPTION_COPY.invalidLink)
    expect(h.sent).toEqual([])
    h.navigate(fragment('confirm'))
    vi.advanceTimersByTime(1000)
    await h.flush()
    expect(h.controller.error.get()).toBe(STATUS_SUBSCRIPTION_COPY.unconfirmed)
    h.navigate(`#status-confirm=${'cd'.repeat(32)}`)
    expect(h.controller.error.get()).toBeNull()
    expect(h.controller.requestId.get()).toBe('2')
    h.answer({ status: 'subscribed' }, 0)
    await h.flush()
    expect(h.controller.busy.get()).toBe(true)
    expect(h.controller.success.get()).toBeNull()
    h.answer({ status: 'subscribed' })
    await h.flush()
    expect(h.controller.success.get()).toBe(STATUS_SUBSCRIPTION_COPY.subscribed)
    expect(h.sent).toHaveLength(2)
    h.dispose()
  })

  it.each(['offline', 'frozen'])(
    'scrubs the fragment immediately while %s and sends confirm once on first usable connection',
    async (mode) => {
      const h = setup(fragment('confirm'))
      if (mode === 'offline') h.connect('disconnected')
      else h.freeze(true)
      h.controller.start()
      h.controller.start()
      expect(h.history.replaceState).toHaveBeenCalledExactlyOnceWith(
        { entry: 1 },
        '',
        '/status?theme=system',
      )
      expect(h.controller.step.get()).toBe('waiting')
      expect(h.sent).toEqual([])
      expect(h.registered.size).toBe(0)
      expect(h.viewports).toEqual([])
      h.connect('connected')
      h.freeze(false)
      expect(h.sent).toEqual([
        {
          action: 'status_subscription_confirm',
          data: { token },
          requestId: '1',
        },
      ])
      h.freeze(true)
      h.freeze(false)
      h.connect('connected')
      expect(h.sent).toHaveLength(1)
      expect(h.controller.success.get()).toBeNull()
      h.answer({ status: 'subscribed' })
      await h.flush()
      expect(h.controller.success.get()).toBe(
        STATUS_SUBSCRIPTION_COPY.subscribed,
      )
      expect(h.controller.dialog.get()).toBe(false)
      h.connect('reconnecting')
      h.connect('connected')
      expect(h.sent).toHaveLength(1)
      h.dispose()
    },
  )

  it.each([
    '#status-confirm=short',
    '#status-unsubscribe',
    `${fragment('confirm')}&status-confirm=${token}`,
    `${fragment('confirm')}&status-unsubscribe=${token}`,
    '#status-confirm=%ZZ',
  ])(
    'rejects malformed or ambiguous own fragments without publishing a token: %s',
    (hash) => {
      const h = setup(hash)
      const toast = vi.spyOn(hilosToasts, 'push')
      h.controller.start()
      expect(h.history.replaceState).toHaveBeenCalledOnce()
      expect(h.sent).toEqual([])
      expect(h.controller.step.get()).toBe('error')
      expect(h.controller.error.get()).toBe(
        STATUS_SUBSCRIPTION_COPY.invalidLink,
      )
      expect(h.controller.dialog.get()).toBe(false)
      expect(h.controller.success.get()).toBeNull()
      expect(h.controller.canSubmit.get()).toBe(false)
      expect(toast).not.toHaveBeenCalled()
      h.dispose()
    },
  )

  it('leaves unrelated fragments alone and retains unrelated fragment entries when scrubbing', () => {
    const h = setup('#history')
    h.controller.start()
    expect(h.history.replaceState).not.toHaveBeenCalled()
    expect(h.controller.operation.get()).toBeNull()
    h.dispose()
    const own = setup(`${fragment('unsubscribe')}&section=history`)
    own.connect('disconnected')
    own.controller.start()
    expect(own.history.replaceState).toHaveBeenCalledWith(
      { entry: 1 },
      '',
      '/status?theme=system#section=history',
    )
    own.dispose()
  })

  it('preview and Keep never delete; only explicit unsubscribe sends the token', async () => {
    const h = setup(fragment('unsubscribe'))
    h.controller.start()
    expect(h.sent[0]?.action).toBe('status_unsubscribe_preview')
    expect(await h.controller.submit()).toBe(false)
    h.answer({ maskedEmail: 'r***@example.net' })
    await h.flush()
    expect(h.controller.maskedEmail.get()).toBe('r***@example.net')
    expect(h.controller.step.get()).toBe('ready')
    h.controller.close()
    expect(h.controller.dialog.get()).toBe(false)
    expect(h.sent).toHaveLength(1)
    expect(await h.controller.submit()).toBe(false)
    h.dispose()

    const explicit = setup(fragment('unsubscribe'))
    explicit.controller.start()
    explicit.answer({ maskedEmail: 'r***@example.net' })
    await explicit.flush()
    const submit = explicit.controller.submit()
    expect(explicit.sent[1]).toEqual({
      action: 'status_unsubscribe',
      data: { token },
      requestId: '2',
    })
    expect(explicit.controller.success.get()).toBeNull()
    explicit.answer({ status: 'unsubscribed' })
    expect(await submit).toBe(true)
    expect(explicit.controller.success.get()).toBe(
      STATUS_SUBSCRIPTION_COPY.unsubscribed,
    )
    expect(explicit.controller.dialog.get()).toBe(false)
    explicit.dispose()
  })

  it('holds an unsubscribe token across freeze and disconnect until its first preview, without replaying notifications', async () => {
    const h = setup(fragment('unsubscribe'))
    h.freeze(true)
    h.controller.start()
    h.connect('disconnected')
    h.freeze(false)
    expect(h.sent).toEqual([])
    expect(h.controller.step.get()).toBe('waiting')
    h.connect('connected')
    expect(h.sent).toEqual([
      { action: 'status_unsubscribe_preview', data: { token }, requestId: '1' },
    ])
    h.freeze(true)
    h.freeze(false)
    h.answer({ maskedEmail: 'r***@example.net' })
    await h.flush()
    h.connect('connected')
    expect(h.sent).toHaveLength(1)
    expect(h.controller.maskedEmail.get()).toBe('r***@example.net')
    h.dispose()
  })

  it('confirms without a window, waits on the page and never opens one on completion', async () => {
    const h = setup(fragment('confirm'))
    h.connect('disconnected')
    h.controller.start()
    expect(h.controller.step.get()).toBe('waiting')
    expect(h.controller.dialog.get()).toBe(false)
    h.connect('connected')
    expect(h.controller.dialog.get()).toBe(false)
    expect(h.sent).toEqual([
      {
        action: 'status_subscription_confirm',
        data: { token },
        requestId: '1',
      },
    ])
    h.answer({ status: 'subscribed' })
    await h.flush()
    expect(h.controller.success.get()).toBe(STATUS_SUBSCRIPTION_COPY.subscribed)
    expect(h.controller.dialog.get()).toBe(false)
    h.dispose()
  })

  it.each([
    ['confirm', undefined],
    ['confirm', { status: 'check_inbox' }],
    ['confirm', { status: 'subscribed', token }],
    ['unsubscribe', {}],
    ['unsubscribe', { maskedEmail: null }],
    [
      'unsubscribe',
      { maskedEmail: 'r***@example.net', email: 'reader@example.net' },
    ],
  ])(
    'rejects a nonexact automatic %s reply and never starts a new auto chain',
    async (operation, reply) => {
      const h = setup(fragment(operation as string))
      h.controller.start()
      h.answer(reply)
      await h.flush()
      expect(h.controller.success.get()).toBeNull()
      expect(h.controller.maskedEmail.get()).toBeNull()
      expect(h.controller.error.get()).toBe(
        STATUS_SUBSCRIPTION_COPY.unconfirmed,
      )
      h.connect('connected')
      h.freeze(true)
      h.freeze(false)
      expect(h.sent).toHaveLength(1)
      h.dispose()
    },
  )

  it('keeps an unsubscribe dialog after a malformed delete reply and ignores the stale reply during an explicit retry', async () => {
    const h = setup(fragment('unsubscribe'))
    h.controller.start()
    h.answer({ maskedEmail: 'r***@example.net' })
    await h.flush()
    const first = h.controller.submit()
    h.answer({ status: 'subscribed' })
    expect(await first).toBe(false)
    expect(h.controller.dialog.get()).toBe(true)
    expect(h.controller.success.get()).toBeNull()
    const retry = h.controller.submit()
    h.answer({ status: 'unsubscribed' }, 1)
    await h.flush()
    expect(h.controller.busy.get()).toBe(true)
    expect(h.controller.success.get()).toBeNull()
    h.answer({ status: 'unsubscribed' })
    expect(await retry).toBe(true)
    h.dispose()
  })

  it('normalizes the submitted email, locks the busy dialog and waits for its correlated exact reply', async () => {
    const h = setup()
    const toast = vi.spyOn(hilosToasts, 'push')
    h.controller.start()
    h.controller.open()
    expect(await h.controller.submit()).toBe(false)
    h.controller.setDraft({ email: ' Reader@Example.NET ' })
    const submit = h.controller.submit()
    expect(h.sent).toEqual([
      {
        action: 'status_subscribe',
        data: { email: 'reader@example.net' },
        requestId: '1',
      },
    ])
    h.controller.setDraft({ email: 'other@example.net' })
    h.controller.close()
    h.controller.open()
    expect(await h.controller.submit()).toBe(false)
    expect(h.controller.draft.get()).toEqual({ email: ' Reader@Example.NET ' })
    expect(h.controller.busy.get()).toBe(true)
    expect(h.controller.loading.get()).toBe(false)
    vi.advanceTimersByTime(30)
    expect(h.controller.loading.get()).toBe(true)
    h.emit('actionSuccess', {
      action: 'status_subscribe',
      requestId: 'wrong',
      reply: { status: 'check_inbox' },
    })
    expect(h.controller.busy.get()).toBe(true)
    h.answer({ status: 'check_inbox' })
    expect(await submit).toBe(true)
    expect(h.controller.success.get()).toBe(STATUS_SUBSCRIPTION_COPY.checkInbox)
    expect(h.controller.dialog.get()).toBe(false)
    expect(h.controller.loading.get()).toBe(false)
    expect(h.controller.draft.get()).toEqual({ email: '' })
    expect(toast).not.toHaveBeenCalled()
    h.dispose()
  })

  it.each([
    undefined,
    {},
    { status: 'subscribed' },
    { status: 'check_inbox', email: 'private@example.net' },
  ])(
    'rejects a missing, wrong or nonexact reply without exposing parser details',
    async (reply) => {
      const h = setup()
      h.controller.start()
      h.controller.open()
      h.controller.setDraft({ email: 'reader@example.net' })
      const submit = h.controller.submit()
      h.answer(reply)
      expect(await submit).toBe(false)
      expect(h.controller.unconfirmed.get()).toBe(true)
      expect(h.controller.success.get()).toBeNull()
      expect(h.controller.error.get()).toBe(
        STATUS_SUBSCRIPTION_COPY.unconfirmed,
      )
      h.dispose()
    },
  )

  it.each(['timeout', 'disconnect', 'refusal'])(
    'keeps %s local, does not retry a token or turn a late reply into success',
    async (outcome) => {
      const h = setup(fragment('confirm'))
      h.controller.start()
      if (outcome === 'timeout') vi.advanceTimersByTime(1000)
      else if (outcome === 'disconnect') h.connect('disconnected')
      else
        h.emit('actionError', {
          action: 'status_subscription_confirm',
          requestId: '1',
          reason: 'This confirmation link has expired.',
          errorDetail: token,
        })
      await h.flush()
      expect(h.controller.error.get()).toBe(
        outcome === 'refusal'
          ? 'This confirmation link has expired.'
          : STATUS_SUBSCRIPTION_COPY.unconfirmed,
      )
      expect(h.controller.busy.get()).toBe(false)
      h.connect('connected')
      h.freeze(true)
      h.freeze(false)
      h.answer({ status: 'subscribed' })
      await h.flush()
      expect(h.sent).toHaveLength(1)
      expect(h.controller.success.get()).toBeNull()
      expect(
        JSON.stringify({
          error: h.controller.error.get(),
          success: h.controller.success.get(),
        }),
      ).not.toContain(token)
      h.dispose()
    },
  )

  it('blocks new sends during disconnection and freeze without losing a ready unsubscribe', async () => {
    const h = setup(fragment('unsubscribe'))
    h.controller.start()
    h.answer({ maskedEmail: 'r***@example.net' })
    await h.flush()
    h.freeze(true)
    expect(h.controller.canSubmit.get()).toBe(false)
    expect(await h.controller.submit()).toBe(false)
    h.freeze(false)
    h.connect('disconnected')
    expect(await h.controller.submit()).toBe(false)
    h.connect('connected')
    const submit = h.controller.submit()
    expect(h.sent[1]?.data).toEqual({ token })
    h.answer({ status: 'unsubscribed' })
    expect(await submit).toBe(true)
    h.dispose()
  })

  it.each(['before-start', 'waiting', 'inflight', 'previewed'])(
    'disposes terminally %s and ignores every later completion',
    async (phase) => {
      const h = setup(fragment('unsubscribe'))
      if (phase === 'waiting') h.connect('disconnected')
      if (phase !== 'before-start') h.controller.start()
      if (phase === 'previewed') {
        h.answer({ maskedEmail: 'r***@example.net' })
        await h.flush()
      }
      h.controller.dispose()
      h.controller.dispose()
      const changed = vi.fn()
      const off = subscribeSignal(h.controller.step, changed)
      h.connect('connected')
      h.freeze(false)
      h.controller.start()
      h.controller.open()
      if (h.sent.length > 0) h.answer({ maskedEmail: 'r***@example.net' })
      await h.flush()
      expect(changed).not.toHaveBeenCalled()
      expect(h.controller.dialog.get()).toBe(false)
      expect(h.controller.maskedEmail.get()).toBeNull()
      expect(h.controller.canSubmit.get()).toBe(false)
      expect(h.listeners.get('protectedMode')?.size ?? 0).toBe(0)
      expect(h.listeners.get('state')?.size).toBe(1)
      off()
      h.dispose()
    },
  )
})
