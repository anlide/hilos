// Harness smoke: proves the vitest project wiring runs against this package's
// source. Real tests replace it as rewrite step 7 fills the core.
import { expect, it } from 'vitest'
import * as entry from './index'

it('loads the @hilos/core entry module', () => {
  expect(entry).toBeTypeOf('object')
})
