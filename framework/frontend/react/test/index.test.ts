// Pins the public surface: everything a React consumer may import from
// '@hilos/react' is exported here. Behavior is covered by the module tests.
import { expect, it } from 'vitest'
import {
  HilosAuthGateContext,
  HilosAuthSurface,
  HilosDropdown,
  HilosLayout,
  HilosLink,
  HilosMagicLinkPage,
  HilosOAuthCallbackPage,
  HilosPageHeadingIdContext,
  HilosRouterContext,
  HilosSkeleton,
  HilosSwitch,
  HilosView,
  useConnectionState,
  useReconnectDragging,
} from '../src/index.js'

it('exports the @hilos/react public surface', () => {
  expect(useConnectionState).toBeTypeOf('function')
  expect(useReconnectDragging).toBeTypeOf('function')
  expect(HilosLink).toBeTypeOf('function')
  expect(HilosView).toBeTypeOf('function')
  expect(HilosSkeleton).toBeTypeOf('function')
  expect(HilosSwitch).toBeTypeOf('function')
  expect(HilosLayout).toBeTypeOf('function')
  expect(HilosDropdown).toBeTypeOf('function')
  expect(HilosRouterContext).toBeTypeOf('object')
  expect(HilosPageHeadingIdContext).toBeTypeOf('object')
  expect(HilosAuthGateContext).toBeTypeOf('object')
  expect(HilosAuthSurface).toBeTypeOf('function')
  expect(HilosMagicLinkPage).toBeTypeOf('function')
  expect(HilosOAuthCallbackPage).toBeTypeOf('function')
})
