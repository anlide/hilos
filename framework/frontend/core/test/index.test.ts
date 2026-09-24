// Pins the public surface: everything an adapter or demo may import from
// '@hilos/core' is exported here. Behavior is covered by the module tests.
import { expect, it } from 'vitest'
import {
  HilosConnection,
  parseSignal,
  assertNever,
  computeBackoffDelay,
  isReconnectDragging,
  RECONNECT_DRAGGING_COPY,
  browserRefusesCookies,
  COOKIES_REFUSED_COPY,
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
  createAuthFlow,
  PASSWORD_FLOW_METHOD,
  HILOS_ROUTE_DECLARATIONS,
  HILOS_PAGE_ROUTES,
  SIGNAL_TYPE_PAGE_RESPONSE,
  createDeferredFlagState,
  DEFAULT_SKELETON_DELAY_MS,
  HILOS_SKELETON_LINES,
  type DeferredDelay,
  type DeferredFlagState,
} from '../src/index.js'

it('exports the @hilos/core public surface', () => {
  expect(HilosConnection).toBeTypeOf('function')
  expect(parseSignal).toBeTypeOf('function')
  expect(assertNever).toBeTypeOf('function')
  expect(computeBackoffDelay).toBeTypeOf('function')
  expect(isReconnectDragging).toBeTypeOf('function')
  expect(RECONNECT_DRAGGING_COPY.dragging).toBeTypeOf('string')
  expect(browserRefusesCookies).toBeTypeOf('function')
  expect(COOKIES_REFUSED_COPY.title).toBeTypeOf('string')
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
  expect(createAuthFlow).toBeTypeOf('function')
  expect(PASSWORD_FLOW_METHOD.kind).toBe('identifier')
  expect(HILOS_ROUTE_DECLARATIONS).toBeTypeOf('object')
  expect(HILOS_PAGE_ROUTES).toBeTypeOf('object')
  expect(SIGNAL_TYPE_PAGE_RESPONSE).toBe('page_response')
  expect(createDeferredFlagState).toBeTypeOf('function')
  expect(DEFAULT_SKELETON_DELAY_MS).toBeTypeOf('number')
  expect(HILOS_SKELETON_LINES).toEqual([9, 6, 10])
  const delay: DeferredDelay = DEFAULT_SKELETON_DELAY_MS
  const flag: DeferredFlagState = createDeferredFlagState(delay)
  expect(flag.shown.get()).toBe(false)
  flag.dispose()
})
