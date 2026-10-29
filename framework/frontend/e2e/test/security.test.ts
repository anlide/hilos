import { EventEmitter } from 'node:events'
import type { Expect, Page } from '@playwright/test'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { watchDeliveryFrames } from '../deliveryFrames.js'
import {
  expectSecurityOverviewData,
  securityPageReplies,
  SECURITY_PROVIDER_PAGE,
  type SecurityOverviewHiddenField,
} from '../security.js'

function peer() {
  let now = 0
  vi.spyOn(performance, 'now').mockImplementation(() => ++now)
  const page = new EventEmitter()
  const log = watchDeliveryFrames(page as unknown as Page)
  const open = () => {
    const socket = new EventEmitter()
    page.emit('websocket', socket)
    return {
      send: (wire: unknown) =>
        socket.emit('framesent', { payload: JSON.stringify(wire) }),
      receive: (wire: unknown) =>
        socket.emit('framereceived', { payload: JSON.stringify(wire) }),
    }
  }
  return { log, open }
}

const subscribe = (providerId: string) => ({
  type: 'page_subscribe',
  page: SECURITY_PROVIDER_PAGE,
  params: { providerId },
})
const reply = (oauthProviderHead: unknown) => ({
  type: 'page_response',
  data: {
    page: SECURITY_PROVIDER_PAGE,
    payload: { data: { oauthProviderHead } },
  },
})

afterEach(() => vi.restoreAllMocks())

describe('Security subscription evidence', () => {
  it('excludes an old opening, another socket and packets after the next route subscription', () => {
    const { log, open } = peer()
    const old = open()
    old.send(subscribe('oauth:github'))
    old.receive(reply({ label: 'Old GitHub' }))
    const opening = log.sent.length
    const current = open()
    current.send(subscribe('oauth:github'))
    old.receive(reply({ label: 'Stale socket' }))
    current.receive(reply({ label: 'Current GitHub' }))
    current.send(subscribe('oauth:google'))
    current.receive(reply({ label: 'Google' }))
    const replies = securityPageReplies(log, opening, SECURITY_PROVIDER_PAGE, {
      providerId: 'oauth:github',
    })
    expect(replies.map(({ data }) => data.oauthProviderHead)).toEqual([
      { label: 'Current GitHub' },
    ])
    expect(replies[0]!.request.socket).toBe(2)
    expect(replies[0]!.frame).toBe(replies[0]!.packet.frames[0])
    log.stop()
  })

  it('matches the exact provider params and retains every batch neighbor and the physical raw text', () => {
    const { log, open } = peer()
    const socket = open()
    socket.send(subscribe('oauth:github'))
    socket.receive(reply({ label: 'GitHub' }))
    socket.send(subscribe('oauth:google'))
    const batch = {
      type: 'signal_batch',
      data: {
        frames: [
          {
            type: 'page_response',
            data: { page: 'hilos_security', payload: {} },
          },
          reply({ label: 'Google' }),
        ],
      },
    }
    socket.receive(batch)
    const replies = securityPageReplies(log, 0, SECURITY_PROVIDER_PAGE, {
      providerId: 'oauth:google',
    })
    expect(replies).toHaveLength(1)
    expect(replies[0]!.packet.raw).toBe(JSON.stringify(batch))
    expect(replies[0]!.packet.frames).toHaveLength(2)
    expect(replies[0]!.frame).toBe(replies[0]!.packet.frames[1])
    expect(
      securityPageReplies(log, 0, SECURITY_PROVIDER_PAGE, {
        providerId: 'oauth:google',
        extra: 'foreign',
      }),
    ).toEqual([])
    log.stop()
  })

  it('keeps a malformed first response ahead of a later complete one without dropping its decoder failure', () => {
    const { log, open } = peer()
    const socket = open()
    socket.send(subscribe('oauth:github'))
    const malformed = {
      type: 'page_response',
      time: 'invalid',
      data: { page: SECURITY_PROVIDER_PAGE, payload: {} },
    }
    socket.receive(malformed)
    socket.receive(reply({ label: 'Later GitHub' }))
    const replies = securityPageReplies(log, 0, SECURITY_PROVIDER_PAGE, {
      providerId: 'oauth:github',
    })
    expect(replies).toHaveLength(2)
    expect(replies[0]!.data).toEqual({})
    expect(replies[0]!.frame.failure).toBeDefined()
    expect(replies[0]!.frame.raw).toBe(JSON.stringify(malformed))
    expect(replies[1]!.data.oauthProviderHead).toEqual({
      label: 'Later GitHub',
    })
    log.stop()
  })
})

describe('Security catalog visibility expectations', () => {
  function overview() {
    return {
      twoFactor: { enabled: true, required: 'none' },
      stepUp: { enabledCount: 12, totalCount: 15 },
      oauth: { configuredCount: 2, totalCount: 2, unconfigured: [] },
      signInMethods: { enabledCount: 6, totalCount: 6, unready: [] },
      impersonation: { allowed: true, scope: 'act' },
    }
  }

  function check(
    value: unknown,
    hiddenFields: readonly SecurityOverviewHiddenField[] = [],
  ) {
    const { log, open } = peer()
    const socket = open()
    socket.send({ type: 'page_subscribe', page: 'hilos_security', params: {} })
    socket.receive({
      type: 'page_response',
      data: {
        page: 'hilos_security',
        payload: { data: { securityOverview: value } },
      },
    })
    try {
      return expectSecurityOverviewData(
        expect as unknown as Expect,
        securityPageReplies(log, 0, 'hilos_security')[0]!,
        hiddenFields,
      )
    } finally {
      log.stop()
    }
  }

  it('requires real values for open sources and rejects an unexpected mask', () => {
    expect(check(overview())).toEqual(overview())
    const value = {
      ...overview(),
      twoFactor: { enabled: { _hidden: true }, required: 'none' },
    }
    expect(() => check(value)).toThrow()
  })

  it('requires the declared field mask without hiding its open neighbors', () => {
    const hiddenFields = ['twoFactor.required'] as const
    expect(() => check(overview(), hiddenFields)).toThrow()
    const value = {
      ...overview(),
      twoFactor: { enabled: true, required: { _hidden: true } },
    }
    expect(check(value, hiddenFields)).toEqual(value)
    expect(() =>
      check(
        {
          ...value,
          impersonation: { allowed: { _hidden: true }, scope: 'act' },
        },
        hiddenFields,
      ),
    ).toThrow()
  })

  it('keeps count bounds and warning shapes strict when another source is closed', () => {
    const value = {
      ...overview(),
      stepUp: { enabledCount: { _hidden: true }, totalCount: 15 },
    }
    const hiddenFields = ['stepUp.enabledCount'] as const
    expect(check(value, hiddenFields)).toEqual(value)
    for (const enabledCount of [-1, 1.5, 7])
      expect(() =>
        check(
          { ...value, signInMethods: { ...value.signInMethods, enabledCount } },
          hiddenFields,
        ),
      ).toThrow()
    expect(() =>
      check(
        {
          ...value,
          oauth: { configuredCount: 1, totalCount: 2, unconfigured: [] },
        },
        hiddenFields,
      ),
    ).toThrow()
    expect(() =>
      check(
        {
          ...value,
          signInMethods: {
            ...value.signInMethods,
            unready: [{ methodKey: 'sms' }],
          },
        },
        hiddenFields,
      ),
    ).toThrow()
    expect(() =>
      check(
        {
          ...value,
          impersonation: { ...value.impersonation, clientSecret: 'secret' },
        },
        hiddenFields,
      ),
    ).toThrow()
  })
})
