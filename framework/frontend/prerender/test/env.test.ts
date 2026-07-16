// resolveSiteOrigin reads HILOS_SITE_ORIGIN, falling back to the localhost
// default, and reads process.env when no environment is passed.
import process from 'node:process'
import { afterEach, expect, it } from 'vitest'

import { resolveSiteOrigin } from '../src/env.js'

const previous = process.env.HILOS_SITE_ORIGIN

afterEach(() => {
  if (previous === undefined) {
    delete process.env.HILOS_SITE_ORIGIN
  } else {
    process.env.HILOS_SITE_ORIGIN = previous
  }
})

it('reads HILOS_SITE_ORIGIN from the given environment', () => {
  expect(
    resolveSiteOrigin({ HILOS_SITE_ORIGIN: 'https://hilos.example' }),
  ).toBe('https://hilos.example')
})

it('defaults to localhost when unset', () => {
  expect(resolveSiteOrigin({})).toBe('https://localhost')
})

it('reads process.env by default', () => {
  process.env.HILOS_SITE_ORIGIN = 'https://from-process-env'

  expect(resolveSiteOrigin()).toBe('https://from-process-env')
})
