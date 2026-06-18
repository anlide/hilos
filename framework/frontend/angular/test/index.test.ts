// Pins the public surface: everything an Angular consumer may import from
// '@hilos/angular' is exported here. Behavior is covered by the module tests.
import { expect, it } from 'vitest'
import {
  HILOS_ROUTER,
  HilosLayout,
  HilosLink,
  HilosView,
  LoadingButton,
  connectionStateSignal,
} from '../src/index.js'

it('exports the @hilos/angular public surface', () => {
  expect(connectionStateSignal).toBeTypeOf('function')
  expect(HILOS_ROUTER).toBeTypeOf('object')
  expect(HilosLink).toBeTypeOf('function')
  expect(HilosView).toBeTypeOf('function')
  expect(HilosLayout).toBeTypeOf('function')
  expect(LoadingButton).toBeTypeOf('function')
})
