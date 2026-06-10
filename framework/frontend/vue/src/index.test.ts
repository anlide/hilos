// Pins the public surface: everything a Vue consumer may import from
// '@hilos/vue' is exported here. Behavior is covered by the module tests.
import { expect, it } from 'vitest'
import { useConnectionState } from './index.js'

it('exports the @hilos/vue public surface', () => {
  expect(useConnectionState).toBeTypeOf('function')
})
