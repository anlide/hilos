// Pins the public surface: everything an Angular consumer may import from
// '@hilos/angular' is exported here. Behavior is covered by the module tests.
import { expect, it } from 'vitest'
import { connectionStateSignal } from './index.js'

it('exports the @hilos/angular public surface', () => {
  expect(connectionStateSignal).toBeTypeOf('function')
})
