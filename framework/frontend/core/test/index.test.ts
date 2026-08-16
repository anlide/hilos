// Pins the public surface: everything an adapter or demo may import from
// '@hilos/core' is exported here. Behavior is covered by the module tests.
import { expect, it } from 'vitest'
import {
  HilosConnection,
  parseSignal,
  assertNever,
  computeBackoffDelay,
  createPageRouter,
  createAppPageRouter,
  createHilosRouter,
  browserNavigationEnvironment,
  bindPageScope,
  PAGE_SIGNAL_SCHEMAS,
  createHilosConnection,
  bindSessionScope,
  sessionUserName,
  SESSION_SIGNAL_SCHEMAS,
  applyServerTime,
  offsetMs,
  toLocal,
  bootHilos,
  createAuthGate,
  createAuthSurface,
  PASSWORD_AUTH_METHOD,
  HILOS_PAGE_ROUTES,
  SIGNAL_TYPE_PAGE_RESPONSE,
} from '../src/index.js'

it('exports the @hilos/core public surface', () => {
  expect(HilosConnection).toBeTypeOf('function')
  expect(parseSignal).toBeTypeOf('function')
  expect(assertNever).toBeTypeOf('function')
  expect(computeBackoffDelay).toBeTypeOf('function')
  expect(createPageRouter).toBeTypeOf('function')
  expect(createAppPageRouter).toBeTypeOf('function')
  expect(createHilosRouter).toBeTypeOf('function')
  expect(browserNavigationEnvironment).toBeTypeOf('function')
  expect(bindPageScope).toBeTypeOf('function')
  expect(PAGE_SIGNAL_SCHEMAS[SIGNAL_TYPE_PAGE_RESPONSE]).toBeDefined()
  expect(createHilosConnection).toBeTypeOf('function')
  expect(bindSessionScope).toBeTypeOf('function')
  expect(sessionUserName).toBeTypeOf('function')
  expect(SESSION_SIGNAL_SCHEMAS['handshake_response']).toBeDefined()
  expect(applyServerTime).toBeTypeOf('function')
  expect(offsetMs).toBeTypeOf('function')
  expect(toLocal).toBeTypeOf('function')
  expect(bootHilos).toBeTypeOf('function')
  expect(createAuthGate).toBeTypeOf('function')
  expect(createAuthSurface).toBeTypeOf('function')
  expect(PASSWORD_AUTH_METHOD.modes).toContain('login')
  expect(HILOS_PAGE_ROUTES).toBeTypeOf('object')
  expect(SIGNAL_TYPE_PAGE_RESPONSE).toBe('page_response')
})
