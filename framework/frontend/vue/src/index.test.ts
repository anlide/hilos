// Harness smoke: proves the vitest project wiring runs against this package's
// source. Real tests replace it as the Vue view layer fills in.
import { expect, it } from 'vitest'
import * as entry from './index'

it('loads the @hilos/vue entry module', () => {
  expect(entry).toBeTypeOf('object')
})
