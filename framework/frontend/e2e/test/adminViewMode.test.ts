import { EventEmitter } from 'node:events'
import type { Page } from '@playwright/test'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { armViewerSubmit } from '../adminViewMode.js'

afterEach(() => {
  vi.useRealTimers()
})

function browser() {
  const events = new EventEmitter()
  const evaluate = vi.fn<(script: unknown, frame: string) => Promise<void>>(
    async () => {},
  )
  const addInitScript = vi.fn(async () => {})
  const page = Object.assign(events, {
    evaluate,
    addInitScript,
  }) as unknown as Page
  const connect = () => {
    const socket = new EventEmitter()
    events.emit('websocket', socket)
    return socket
  }
  return { page, events, evaluate, addInitScript, connect }
}

function reply(socket: EventEmitter, requestId: string) {
  socket.emit('framereceived', {
    payload: JSON.stringify({
      type: 'action_error',
      data: {
        requestId,
        action: 'hilos_daemon_agent_restart',
        errorCode: 'view_mode',
        reason: 'View mode: you can look around, but not change anything.',
      },
    }),
  })
}

describe('armViewerSubmit', () => {
  it('correlates an early reply but waits for the send to complete too', async () => {
    const f = browser()
    const viewer = await armViewerSubmit(f.page)
    expect(f.addInitScript).toHaveBeenCalledOnce()
    const socket = f.connect()
    let sent!: () => void
    f.evaluate.mockImplementation(() => {
      reply(socket, 'wanted')
      return new Promise<void>((resolve) => {
        sent = resolve
      })
    })

    let settled = false
    const submission = viewer.submit('forged frame', 'wanted').then((frame) => {
      settled = true
      return frame
    })
    await Promise.resolve()
    expect(settled).toBe(false)
    sent()
    expect((await submission).failure).toBeUndefined()
    expect(f.evaluate.mock.calls[0]?.[1]).toBe('forged frame')
    viewer.dispose()
  })

  it('ignores another request or an old socket and removes all listeners', async () => {
    const f = browser()
    const viewer = await armViewerSubmit(f.page)
    const oldSocket = f.connect()
    const currentSocket = f.connect()
    const submission = viewer.submit('forged frame', 'wanted')
    let settled = false
    void submission.then(() => {
      settled = true
    })

    reply(oldSocket, 'wanted')
    reply(currentSocket, 'other')
    await Promise.resolve()
    expect(settled).toBe(false)
    reply(currentSocket, 'wanted')
    expect((await submission).socket).toBe(2)
    viewer.dispose()
    expect(f.events.eventNames()).toEqual([])
    expect(oldSocket.eventNames()).toEqual([])
    expect(currentSocket.eventNames()).toEqual([])
  })

  it('bounds a missing reply at five seconds', async () => {
    vi.useFakeTimers()
    const f = browser()
    const viewer = await armViewerSubmit(f.page)
    f.connect()
    const submission = viewer.submit('forged frame', 'wanted')
    const refused = expect(submission).rejects.toThrow(
      'The server did not answer the forged submit',
    )

    await vi.advanceTimersByTimeAsync(5_000)
    await refused
    viewer.dispose()
    expect(vi.getTimerCount()).toBe(0)
  })

  it('rejects on socket close and cleans up when the page closes', async () => {
    const f = browser()
    const viewer = await armViewerSubmit(f.page)
    const socket = f.connect()
    const submission = viewer.submit('forged frame', 'wanted')
    socket.emit('close')

    await expect(submission).rejects.toThrow(
      'The viewer socket closed before the forged reply',
    )
    f.events.emit('close')
    expect(f.events.eventNames()).toEqual([])
    expect(socket.eventNames()).toEqual([])
    await expect(viewer.submit('forged frame', 'again')).rejects.toThrow(
      'The forged submit observer was disposed',
    )
  })

  it('rejects a pending submit when disposed and propagates a failed send', async () => {
    const f = browser()
    const viewer = await armViewerSubmit(f.page)
    f.connect()
    f.evaluate.mockRejectedValueOnce(new Error('Send failed'))
    await expect(viewer.submit('forged frame', 'first')).rejects.toThrow(
      'Send failed',
    )

    const submission = viewer.submit('forged frame', 'second')
    viewer.dispose()
    await expect(submission).rejects.toThrow(
      'The forged submit observer was disposed',
    )
    expect(f.events.eventNames()).toEqual([])
  })
})
