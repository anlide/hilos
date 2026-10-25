// Pins the public surface: everything a Vue consumer may import from
// '@hilos/vue' is exported here. Behavior is covered by the module tests.
import { expect, it } from 'vitest'
import {
  HilosLayout,
  HilosLink,
  HilosPageHead,
  HilosSkeleton,
  HilosSwitch,
  HilosTableText,
  HilosView,
  hilosRouterKey,
  useConnectionState,
  useReconnectDragging,
  HilosModerationPage,
} from '../src/index.js'
import * as base from '../src/base/index.js'
import * as moderation from '../src/admin/moderation/index.js'

it('exports the @hilos/vue public surface', () => {
  expect(useConnectionState).toBeTypeOf('function')
  expect(useReconnectDragging).toBeTypeOf('function')
  expect(HilosLayout).toBeTypeOf('object')
  expect(HilosLink).toBeTypeOf('object')
  expect(HilosPageHead).toBeTypeOf('object')
  expect(HilosView).toBeTypeOf('object')
  expect(HilosSkeleton).toBeTypeOf('object')
  expect(HilosSwitch).toBeTypeOf('object')
  expect(hilosRouterKey).toBeTypeOf('symbol')
})

it('exports the shared page head once, from the base entry the root re-exports', () => {
  expect(base.HilosPageHead).toBe(HilosPageHead)
})

it('exports only the public text primitive with the same root/base identity', () => {
  expect(HilosTableText).toBeTypeOf('object')
  expect(base.HilosTableText).toBe(HilosTableText)
  for (const name of [
    'HilosTableValue',
    'hilosTableTextKey',
    'useTableTextReading',
    'useTableTextOverflow',
  ])
    expect(base).not.toHaveProperty(name)
})

it('exports HilosModerationPage from the root and section entry with the same identity', () => {
  expect(HilosModerationPage).toBeTypeOf('object')
  expect(moderation.HilosModerationPage).toBe(HilosModerationPage)
})
