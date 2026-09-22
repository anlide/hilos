// Pins the public surface: everything a Vue consumer may import from
// '@hilos/vue' is exported here. Behavior is covered by the module tests.
import { expect, it } from 'vitest'
import {
  HilosLayout,
  HilosLink,
  HilosSkeleton,
  HilosSwitch,
  HilosView,
  hilosRouterKey,
  useConnectionState,
  useReconnectDragging,
} from '../src/index.js'

it('exports the @hilos/vue public surface', () => {
  expect(useConnectionState).toBeTypeOf('function')
  expect(useReconnectDragging).toBeTypeOf('function')
  expect(HilosLayout).toBeTypeOf('object')
  expect(HilosLink).toBeTypeOf('object')
  expect(HilosView).toBeTypeOf('object')
  expect(HilosSkeleton).toBeTypeOf('object')
  expect(HilosSwitch).toBeTypeOf('object')
  expect(hilosRouterKey).toBeTypeOf('symbol')
})
