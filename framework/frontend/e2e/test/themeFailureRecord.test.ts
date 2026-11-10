import { EventEmitter } from 'node:events'
import type { Page } from '@playwright/test'
import { expect, it, vi } from 'vitest'
import { watchDeliveryFrames } from '../deliveryFrames.js'
import {
  themeFailureActions,
  themeFailureFrames,
} from '../themeFailureRecord.js'

function openDelivery() {
  const page = new EventEmitter()
  const log = watchDeliveryFrames(page as unknown as Page)
  const open = () => {
    const socket = new EventEmitter()
    page.emit('websocket', socket)
    return socket
  }
  return { log, open }
}

function receive(socket: EventEmitter, payload: unknown) {
  socket.emit('framereceived', {
    payload: typeof payload === 'string' ? payload : JSON.stringify(payload),
  })
}

function send(socket: EventEmitter, payload: unknown) {
  socket.emit('framesent', { payload: JSON.stringify(payload) })
}

function runningClock() {
  let clock = 0
  return vi.spyOn(performance, 'now').mockImplementation(() => {
    clock += 5
    return clock
  })
}

it('keeps only handshake and theme settings frames, at the packet time', () => {
  const now = runningClock()
  const { log, open } = openDelivery()
  const socket = open()
  const handshake = {
    type: 'handshake_response',
    data: { entities: { currentUser: { id: 7, themePick: 'dark' } } },
  }
  const noise = { type: 'page_update', data: { n: 1 } }
  const settings = {
    type: 'hilos_theme_settings',
    data: { switchingEnabled: true, defaultTheme: 'light' },
  }
  const later = {
    type: 'handshake_response',
    data: { entities: { currentUser: null } },
  }
  try {
    receive(socket, {
      type: 'signal_batch',
      data: { frames: [handshake, noise, settings] },
    })
    receive(socket, later)
    receive(socket, 'not-json')
    const frames = themeFailureFrames(log)
    expect(frames).toEqual([
      {
        at: log.packets[0].at,
        socket: 1,
        message: 1,
        type: 'handshake_response',
        wire: handshake,
      },
      {
        at: log.packets[0].at,
        socket: 1,
        message: 1,
        type: 'hilos_theme_settings',
        wire: settings,
      },
      {
        at: log.packets[1].at,
        socket: 1,
        message: 2,
        type: 'handshake_response',
        wire: later,
      },
    ])
    expect(frames[0].at).toBe(frames[1].at)
    expect(frames[2].at).not.toBe(frames[0].at)
    expect(
      log.packets[0].frames.some(
        (frame) =>
          frame.wire !== null &&
          typeof frame.wire === 'object' &&
          'type' in frame.wire &&
          frame.wire.type === 'page_update',
      ),
    ).toBe(true)
  } finally {
    log.stop()
    now.mockRestore()
  }
})

it('pairs a theme action with the reply on its socket and request', () => {
  const now = runningClock()
  const { log, open } = openDelivery()
  const first = open()
  const second = open()
  const pick = {
    type: 'action',
    action: 'hilos_theme_pick',
    requestId: 'pick-1',
  }
  const right = {
    type: 'action_success',
    requestId: 'pick-1',
    data: { action: 'hilos_theme_pick', reply: { themePick: 'dark' } },
  }
  try {
    send(first, pick)
    receive(second, {
      type: 'action_success',
      requestId: 'pick-1',
      data: { action: 'hilos_theme_pick', reply: { themePick: 'light' } },
    })
    receive(first, {
      type: 'action_success',
      requestId: 'other',
      data: { action: 'hilos_theme_pick', reply: { themePick: 'system' } },
    })
    receive(first, {
      type: 'action_error',
      requestId: 'pick-1',
      data: { action: 'hilos_theme_adopt', reply: { themePick: 'dark' } },
    })
    receive(first, {
      type: 'signal_batch',
      data: { frames: [{ type: 'page_update', data: {} }, right] },
    })
    expect(themeFailureActions(log)).toEqual([
      {
        at: log.sent[0].at,
        socket: 1,
        action: 'hilos_theme_pick',
        requestId: 'pick-1',
        raw: JSON.stringify(pick),
        reply: {
          at: log.packets[3].at,
          type: 'action_success',
          data: right.data,
        },
      },
    ])
    expect(log.sent[0].at).not.toBe(log.packets[3].at)
  } finally {
    log.stop()
    now.mockRestore()
  }
})

it('keeps an unanswered theme action with a null reply', () => {
  const now = runningClock()
  const { log, open } = openDelivery()
  const socket = open()
  const adopt = {
    type: 'action',
    action: 'hilos_theme_adopt',
    requestId: 'adopt-1',
  }
  try {
    send(socket, adopt)
    send(socket, {
      type: 'action',
      action: 'status_subscribe',
      requestId: 'other',
    })
    receive(socket, {
      type: 'action_error',
      requestId: 'adopt-2',
      data: { action: 'hilos_theme_adopt' },
    })
    expect(themeFailureActions(log)).toEqual([
      {
        at: log.sent[0].at,
        socket: 1,
        action: 'hilos_theme_adopt',
        requestId: 'adopt-1',
        raw: JSON.stringify(adopt),
        reply: null,
      },
    ])
  } finally {
    log.stop()
    now.mockRestore()
  }
})
