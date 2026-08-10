// The adaptive-timeout rule, exercised on synthetic host readings: which load and
// memory numbers stretch the Playwright caps, by how much, and what the printed
// reason blames it on. The host is never read here — that is exactly why the rule
// takes its inputs as arguments.
import { expect, it } from 'vitest'

import { deriveTimeoutScale } from './timeout-scale.mjs'

/** An unstressed host: no override, load well under one core, memory to spare. */
const IDLE = { override: undefined, loadPerCpu: 0.2, availableGib: 8 }

it('leaves an unstressed host at 1.0', () => {
  expect(deriveTimeoutScale(IDLE)).toMatchObject({ factor: 1.0 })
})

it('leaves a host it cannot measure at 1.0', () => {
  const blind = { override: undefined, loadPerCpu: null, availableGib: null }

  expect(deriveTimeoutScale(blind)).toMatchObject({ factor: 1.0 })
})

it('ignores load until it passes three quarters of a core', () => {
  expect(deriveTimeoutScale({ ...IDLE, loadPerCpu: 0.75 })).toMatchObject({
    factor: 1.0,
  })
})

it('stretches by the load above three quarters of a core', () => {
  expect(deriveTimeoutScale({ ...IDLE, loadPerCpu: 2.25 })).toMatchObject({
    factor: 2.5,
  })
})

it('names the load that decided the factor', () => {
  const { reason } = deriveTimeoutScale({ ...IDLE, loadPerCpu: 2.25 })

  expect(reason).toContain('2.25')
})

it('caps a runaway load at 4.0 so a hang still ends', () => {
  expect(deriveTimeoutScale({ ...IDLE, loadPerCpu: 40 })).toMatchObject({
    factor: 4.0,
  })
})

it('doubles when memory drops under 2 GiB', () => {
  expect(deriveTimeoutScale({ ...IDLE, availableGib: 1.5 })).toMatchObject({
    factor: 2.0,
  })
})

it('triples when memory drops under 1 GiB', () => {
  expect(deriveTimeoutScale({ ...IDLE, availableGib: 0.5 })).toMatchObject({
    factor: 3.0,
  })
})

it('keeps the load factor when it already beats the memory floor', () => {
  const starved = { ...IDLE, loadPerCpu: 3.0, availableGib: 1.5 }

  expect(deriveTimeoutScale(starved)).toMatchObject({ factor: 3.25 })
})

it('raises a mild load factor to the memory floor', () => {
  const starved = { ...IDLE, loadPerCpu: 1.25, availableGib: 1.5 }

  expect(deriveTimeoutScale(starved)).toMatchObject({ factor: 2.0 })
})

it('takes an explicit override over what the host says', () => {
  const overridden = { ...IDLE, override: '2.5', loadPerCpu: 10 }

  expect(deriveTimeoutScale(overridden)).toMatchObject({
    factor: 2.5,
    reason: 'HILOS_E2E_TIMEOUT_SCALE=2.5',
  })
})

it('never lets an override shorten a timeout', () => {
  expect(deriveTimeoutScale({ ...IDLE, override: '0.5' })).toMatchObject({
    factor: 1.0,
  })
})

it('caps an override at 4.0 as well', () => {
  expect(deriveTimeoutScale({ ...IDLE, override: '99' })).toMatchObject({
    factor: 4.0,
  })
})

it('says so in the reason when the override is not a number', () => {
  const typo = { ...IDLE, override: 'yes' }

  expect(deriveTimeoutScale(typo)).toMatchObject({ factor: 1.0 })
  expect(deriveTimeoutScale(typo).reason).toContain('non-numeric')
})

it('treats a blank override as no override', () => {
  const blank = { ...IDLE, override: '  ', loadPerCpu: 2.25 }

  expect(deriveTimeoutScale(blank)).toMatchObject({ factor: 2.5 })
})
