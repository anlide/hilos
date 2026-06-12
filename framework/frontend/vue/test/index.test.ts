// Pins the public surface: everything a Vue consumer may import from
// '@hilos/vue' is exported here. Behavior is covered by the module tests.
import { expect, it } from 'vitest'
import {
  HilosLayout,
  HilosLink,
  HilosView,
  hilosRouterKey,
  useConnectionState,
} from '../src/index.js'

it('exports the @hilos/vue public surface', () => {
  expect(useConnectionState).toBeTypeOf('function')
  expect(HilosLayout).toBeTypeOf('object')
  expect(HilosLink).toBeTypeOf('object')
  expect(HilosView).toBeTypeOf('object')
  expect(hilosRouterKey).toBeTypeOf('symbol')
})
