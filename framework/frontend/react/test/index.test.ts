// Pins the public surface: everything a React consumer may import from
// '@hilos/react' is exported here. Behavior is covered by the module tests.
import { expect, it } from 'vitest'
import {
  HilosLink,
  HilosRouterContext,
  HilosView,
  useConnectionState,
} from '../src/index.js'

it('exports the @hilos/react public surface', () => {
  expect(useConnectionState).toBeTypeOf('function')
  expect(HilosLink).toBeTypeOf('function')
  expect(HilosView).toBeTypeOf('function')
  expect(HilosRouterContext).toBeTypeOf('object')
})
