import { describe, expect, it } from 'vitest'
import type { Expect, Page } from '@playwright/test'

import { createSocketFrameReader } from '../socket.js'
import {
  expectTimeZoneEnvironment,
  timeZoneContextOptions,
} from '../timeZone.js'

const checks = expect as unknown as Expect
const welcome = (projectTimeZone: unknown) => ({
  type: 'handshake',
  data: { build: 'test', sessionCookieName: 'hilos', projectTimeZone },
})
const pageReply = {
  type: 'page_response',
  data: { page: 'hilos_profile', payload: {} },
}
const read = (...frames: unknown[]) =>
  createSocketFrameReader(1)(
    JSON.stringify({ type: 'signal_batch', data: { frames } }),
  )
const browser = (timeZone: string) =>
  ({ evaluate: async () => timeZone }) as unknown as Page
const environment = {
  browserTimeZone: 'America/New_York',
  projectTimeZone: 'Europe/Warsaw',
}

describe('timeZoneContextOptions', () => {
  it('sets the explicit zone and the light palette, keeping the transport and locale of the given options untouched', () => {
    const options = {
      baseURL: 'https://tasks.hilos.test',
      ignoreHTTPSErrors: true,
      locale: 'pl-PL',
      viewport: { width: 1280, height: 720 },
      timezoneId: 'Europe/Warsaw',
      colorScheme: 'dark' as const,
    }
    expect(timeZoneContextOptions(options, 'Asia/Tokyo')).toMatchObject({
      baseURL: 'https://tasks.hilos.test',
      ignoreHTTPSErrors: true,
      locale: 'pl-PL',
      viewport: { width: 1280, height: 720 },
      timezoneId: 'Asia/Tokyo',
      colorScheme: 'light',
    })
    // The project's options are the spec's to reuse for its next context.
    expect(options).toEqual({
      baseURL: 'https://tasks.hilos.test',
      ignoreHTTPSErrors: true,
      locale: 'pl-PL',
      viewport: { width: 1280, height: 720 },
      timezoneId: 'Europe/Warsaw',
      colorScheme: 'dark',
    })
  })
})

describe('expectTimeZoneEnvironment', () => {
  it('accepts a decoded welcome naming the expected project zone beside the given browser zone', async () => {
    const frames = read(welcome('Europe/Warsaw'), pageReply)
    await expect(
      expectTimeZoneEnvironment(
        checks,
        browser('America/New_York'),
        frames,
        environment,
      ),
    ).resolves.toBeUndefined()
  })

  it('refuses identical browser and project zones', async () => {
    await expect(
      expectTimeZoneEnvironment(
        checks,
        browser('Europe/Warsaw'),
        read(welcome('Europe/Warsaw')),
        {
          browserTimeZone: 'Europe/Warsaw',
          projectTimeZone: 'Europe/Warsaw',
        },
      ),
    ).rejects.toThrow()
  })

  it('refuses browser and project aliases of the same canonical zone', async () => {
    await expect(
      expectTimeZoneEnvironment(
        checks,
        browser('America/New_York'),
        read(welcome('US/Eastern')),
        {
          browserTimeZone: 'America/New_York',
          projectTimeZone: 'US/Eastern',
        },
      ),
    ).rejects.toThrow()
  })

  it('refuses UTC and its aliases beside a different project zone', async () => {
    for (const browserTimeZone of ['UTC', 'Etc/UTC', 'Etc/GMT']) {
      await expect(
        expectTimeZoneEnvironment(
          checks,
          browser(browserTimeZone),
          read(welcome('Europe/Warsaw')),
          { browserTimeZone, projectTimeZone: 'Europe/Warsaw' },
        ),
      ).rejects.toThrow()
    }
  })

  it('refuses a welcome whose project zone is not the one the spec expects', async () => {
    const frames = read(welcome('UTC'), pageReply)
    await expect(
      expectTimeZoneEnvironment(
        checks,
        browser('America/New_York'),
        frames,
        environment,
      ),
    ).rejects.toThrow()
  })

  it('refuses a stream with a frame the decoder failed on, even when its zone matches', async () => {
    const frames = read(
      {
        type: 'handshake',
        data: { build: 42, projectTimeZone: 'Europe/Warsaw' },
      },
      pageReply,
    )
    expect(frames[0]!.failure).toBeDefined()
    await expect(
      expectTimeZoneEnvironment(
        checks,
        browser('America/New_York'),
        frames,
        environment,
      ),
    ).rejects.toThrow()
  })

  it('refuses a browser whose own zone differs from the one its context was given', async () => {
    const frames = read(welcome('Europe/Warsaw'))
    await expect(
      expectTimeZoneEnvironment(
        checks,
        browser('Europe/Warsaw'),
        frames,
        environment,
      ),
    ).rejects.toThrow()
  })

  it('does not take a stream without any welcome for an environment', async () => {
    await expect(
      expectTimeZoneEnvironment(
        checks,
        browser('America/New_York'),
        read(pageReply),
        environment,
      ),
    ).rejects.toThrow()
  })
})
