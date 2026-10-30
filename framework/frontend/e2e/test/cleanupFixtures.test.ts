import type { Page } from '@playwright/test'
import { expect, it, vi } from 'vitest'
import { cleanupFixtures } from '../cleanupFixtures.js'

const fixtures = { page: {} as Page }

it('awaits dependents before parents and preserves setup and every cleanup failure', async () => {
  const original = new Error('Setup failed after acquiring resources')
  const dispose = new Error('Document disposal failed')
  const restore = new Error('View mode restoration failed')
  const events: string[] = []
  const result = cleanupFixtures(fixtures, async (cleanup) => {
    cleanup.defer(() => events.push('parent closed'))
    cleanup.defer(() => {
      events.push('restore attempted')
      throw restore
    })
    cleanup.defer(async () => {
      events.push('document start')
      await Promise.resolve()
      events.push('document end')
      throw dispose
    })
    throw original
  })
  await expect(result).rejects.toMatchObject({
    cause: original,
    errors: [original, dispose, restore],
    message: expect.stringContaining('View mode restoration failed'),
  })
  expect(events).toEqual([
    'document start',
    'document end',
    'restore attempted',
    'parent closed',
  ])
})

it('reports teardown failure after the runner releases a failed or timed-out test', async () => {
  const failure = new Error('Model key cleanup failed')
  const close = vi.fn()
  let release!: () => void
  const finished = new Promise<void>((resolve) => {
    release = resolve
  })
  const result = cleanupFixtures(fixtures, async (cleanup) => {
    cleanup.defer(close)
    cleanup.defer(() => {
      throw failure
    })
    // Playwright records the test error, then resolves use to run teardown.
    await finished
  })
  expect(close).not.toHaveBeenCalled()
  release()
  await expect(result).rejects.toMatchObject({ errors: [failure] })
  expect(close).toHaveBeenCalledTimes(1)
})

it('keeps the original failure identity when cleanup succeeds', async () => {
  const failure = new Error('Assertion failed')
  const close = vi.fn()
  const result = cleanupFixtures(fixtures, async (cleanup) => {
    cleanup.defer(close)
    throw failure
  })
  await expect(result).rejects.toBe(failure)
  expect(close).toHaveBeenCalledTimes(1)
})
