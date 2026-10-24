import { EventEmitter } from 'node:events'
import type { Expect, Page } from '@playwright/test'
import { describe, expect, it } from 'vitest'
import { expectSessionRotation } from '../connection.js'

const checks = expect as unknown as Expect
const welcome = JSON.stringify({ type: 'handshake', data: { build: 'test' } })

function browser() {
  const events = new EventEmitter()
  const connect = () => {
    const socket = new EventEmitter()
    events.emit('websocket', socket)
    return socket
  }
  return { page: events as unknown as Page, events, connect }
}

describe('session rotation readiness', () => {
  it('requires the latest new socket to receive a valid welcome', async () => {
    const browserPage = browser()
    const old = browserPage.connect()
    let settled = false
    const pending = expectSessionRotation(
      checks,
      browserPage.page,
      async () => {},
    ).then(() => {
      settled = true
    })
    old.emit('framereceived', { payload: welcome })
    const first = browserPage.connect()
    first.emit('framereceived', { payload: welcome })
    const next = browserPage.connect()
    next.emit('framereceived', { payload: '{"type":"handshake",' })
    next.emit('framereceived', {
      payload: JSON.stringify({
        type: 'page_response',
        data: { page: 'main', payload: {} },
      }),
    })
    await Promise.resolve()
    expect(settled).toBe(false)
    next.emit('framereceived', {
      payload: JSON.stringify({
        type: 'signal_batch',
        data: { frames: [JSON.parse(welcome)] },
      }),
    })
    await pending
    expect(settled).toBe(true)
    expect(browserPage.events.listenerCount('websocket')).toBe(0)
    expect(first.listenerCount('framereceived')).toBe(0)
    expect(next.listenerCount('framereceived')).toBe(0)
  })

  it('observes a fast rotation but still waits for the action to settle', async () => {
    const b = browser()
    let finish!: () => void
    let settled = false
    const pending = expectSessionRotation(checks, b.page, () => {
      b.connect().emit('framereceived', { payload: welcome })
      return new Promise<void>((resolve) => {
        finish = resolve
      })
    }).then(() => {
      settled = true
    })
    await Promise.resolve()
    expect(settled).toBe(false)
    finish()
    await pending
    expect(settled).toBe(true)
  })

  it('removes listeners when the triggering action fails', async () => {
    const b = browser()
    const failed = new Error('Action refused')
    await expect(
      expectSessionRotation(checks, b.page, async () => {
        b.connect()
        throw failed
      }),
    ).rejects.toBe(failed)
    expect(b.events.listenerCount('websocket')).toBe(0)
  })
})
