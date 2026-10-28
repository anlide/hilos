import { EventEmitter } from 'node:events'
import type { Page } from '@playwright/test'
import { expect, it } from 'vitest'
import { watchDeliveryFrames } from '../deliveryFrames.js'
import { trackedActionReplies } from '../actionReplies.js'

it('rejects foreign sockets, actions, ids and earlier attempts, including batches', () => {
  const page = new EventEmitter()
  const log = watchDeliveryFrames(page as unknown as Page)
  const first = new EventEmitter()
  const second = new EventEmitter()
  page.emit('websocket', first)
  page.emit('websocket', second)
  const request = {
    type: 'action',
    action: 'status_subscribe',
    requestId: 'mine',
  }
  first.emit('framesent', { payload: JSON.stringify(request) })
  const response = {
    type: 'action_success',
    requestId: 'mine',
    data: { action: 'status_subscribe', reply: { status: 'check_inbox' } },
  }
  second.emit('framereceived', { payload: JSON.stringify(response) })
  first.emit('framereceived', {
    payload: JSON.stringify({ ...response, requestId: 'other' }),
  })
  first.emit('framereceived', {
    payload: JSON.stringify({ ...response, data: { action: 'other' } }),
  })
  expect(trackedActionReplies(log, request.action, 0)).toEqual([])
  first.emit('framereceived', {
    payload: JSON.stringify({
      type: 'signal_batch',
      data: { frames: [response] },
    }),
  })
  expect(trackedActionReplies(log, request.action, 0)).toMatchObject([
    { frame: response },
  ])
  expect(trackedActionReplies(log, request.action, 1)).toEqual([])
  log.stop()
})
