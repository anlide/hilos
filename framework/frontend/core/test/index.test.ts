// Pins the public surface: everything an adapter or demo may import from
// '@hilos/core' is exported here. Behavior is covered by the module tests.
import { expect, it } from 'vitest'
import {
  HilosConnection,
  parseSignal,
  assertNever,
  computeBackoffDelay,
  createPageRouter,
  HILOS_PAGE_ROUTES,
  SIGNAL_TYPE_PAGE_RESPONSE,
} from '../src/index.js'

it('exports the @hilos/core public surface', () => {
  expect(HilosConnection).toBeTypeOf('function')
  expect(parseSignal).toBeTypeOf('function')
  expect(assertNever).toBeTypeOf('function')
  expect(computeBackoffDelay).toBeTypeOf('function')
  expect(createPageRouter).toBeTypeOf('function')
  expect(HILOS_PAGE_ROUTES).toBeTypeOf('object')
  expect(SIGNAL_TYPE_PAGE_RESPONSE).toBe('page_response')
})
