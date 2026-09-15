import { describe, expect, it } from 'vitest'

import {
  protectedModeVerdict,
  reAskProtectedMode,
} from './protectedModeReAsk.mjs'

const OPERATION = 'unit-restore'

const ACTIVE_SNAPSHOT = {
  rtMounted: true,
  phase: 'active',
  operation: OPERATION,
}

it('reads a matching operation in a taken phase as taken', () => {
  expect(
    protectedModeVerdict(ACTIVE_SNAPSHOT, {
      operation: OPERATION,
      takenPhases: ['active'],
    }),
  ).toBe('taken')
})

it("compares the operation instead of accepting somebody else's freeze", () => {
  expect(
    protectedModeVerdict(
      { ...ACTIVE_SNAPSHOT, operation: 'somebody-else' },
      { operation: OPERATION, takenPhases: ['active'] },
    ),
  ).toBe('notTaken')
})

it('reads an installation without protected-mode runtime as not taken', () => {
  expect(
    protectedModeVerdict(
      { ...ACTIVE_SNAPSHOT, rtMounted: false },
      { operation: OPERATION, takenPhases: ['active'] },
    ),
  ).toBe('notTaken')
})

it('reads an activating freeze as under way', () => {
  expect(
    protectedModeVerdict(
      { ...ACTIVE_SNAPSHOT, phase: 'activating' },
      { operation: OPERATION, takenPhases: ['active'] },
    ),
  ).toBe('underWay')
})

describe.each([
  ['enter', ['active', 'verifying', 'deactivating'], 'inactive'],
  ['leave', ['verifying'], 'active'],
  ['open', ['inactive'], 'active'],
  ['close', ['active'], 'verifying'],
] as const)('%s drive', (_command, takenPhases, otherPhase) => {
  it("owns its taken phases and no other drive's phase", () => {
    for (const takenPhase of takenPhases) {
      expect(
        protectedModeVerdict(
          { ...ACTIVE_SNAPSHOT, phase: takenPhase },
          { operation: OPERATION, takenPhases: [...takenPhases] },
        ),
      ).toBe('taken')
    }
    expect(
      protectedModeVerdict(
        { ...ACTIVE_SNAPSHOT, phase: otherPhase },
        { operation: OPERATION, takenPhases: [...takenPhases] },
      ),
    ).toBe('notTaken')
  })
})

it('turns a failed inspect into unknown instead of throwing', async () => {
  await expect(
    reAskProtectedMode(
      async () => {
        throw new Error('the master did not answer')
      },
      { operation: OPERATION, takenPhases: ['active'] },
    ),
  ).resolves.toMatchObject({ verdict: 'unknown', snapshot: {} })
})

it('calls a successful inspect exactly once', async () => {
  let calls = 0
  const outcome = await reAskProtectedMode(
    async () => {
      calls += 1
      return ACTIVE_SNAPSHOT
    },
    { operation: OPERATION, takenPhases: ['active'] },
  )

  expect(calls).toBe(1)
  expect(outcome).toMatchObject({ verdict: 'taken', snapshot: ACTIVE_SNAPSHOT })
})
