import { afterEach, expect, it, vi } from 'vitest'
import type { Expect, Page } from '@playwright/test'

const { send } = vi.hoisted(() => ({ send: vi.fn() }))
vi.mock('../../scripts/standCommand.mjs', async (original) => ({
  ...(await original<typeof import('../../scripts/standCommand.mjs')>()),
  createStandCommandChannel: () => send,
}))
import { cleanupFixtures, type TestCleanup } from '../cleanupFixtures.js'
import {
  borrowSetting,
  borrowOAuthProvider,
  emitOwnedNotification,
  ownNotificationSubject,
} from '../ownedTestState.js'
import { StandCommandEndpointError } from '../../scripts/standCommand.mjs'

// The caller supplies its runner; this deterministic stand-in never advances real time.
const pollExpect = {
  poll: (read: () => Promise<unknown>) => ({
    toBe: async (expected: unknown) => {
      for (let attempt = 0; attempt < 3; attempt++) {
        if ((await read()) === expected) return
      }
      throw new Error('Subject remained absent or nonterminal')
    },
  }),
} as unknown as Expect

const page = {} as Page
const original = {
  key: 'example_integer',
  exists: true,
  type: 'integer',
  rawValue: '0007',
  valueSource: 'override' as const,
}
const emitted = { notificationId: 7, queuedChannels: ['email'] }
const absent = { notificationId: null, queuedChannels: [], deliveries: [] }
const terminal = {
  ...emitted,
  deliveries: [{ deliveryId: 8, channel: 'email', status: 'sent' }],
}

function traceCleanup(cleanup: TestCleanup, events: string[]): TestCleanup {
  return {
    defer(operation) {
      events.push('defer')
      cleanup.defer(operation)
    },
  }
}

afterEach(() => send.mockReset())

it('captures then defers restoration before mutation, including a failed setup and a lost snapshot', async () => {
  const events: string[] = []
  const primary = new Error('setup failed after mutation')
  send.mockImplementation(async (command: string) => {
    events.push(command)
    if (command === 'test:settings:snapshot') return { snapshot: original }
    return { restored: true }
  })
  await expect(
    cleanupFixtures({ page }, async (cleanup) => {
      await borrowSetting(traceCleanup(cleanup, events), original.key)
      events.push('mutation')
      throw primary
    }),
  ).rejects.toBe(primary)
  expect(events).toEqual([
    'test:settings:snapshot',
    'defer',
    'mutation',
    'test:settings:restore',
  ])
  expect(send).toHaveBeenLastCalledWith('test:settings:restore', {
    snapshot: original,
  })

  send.mockReset().mockRejectedValueOnce(primary)
  const defer = vi.fn()
  await expect(borrowSetting({ defer }, original.key)).rejects.toBe(primary)
  expect(defer).not.toHaveBeenCalled()
  expect(send).toHaveBeenCalledTimes(1)
})

it('reserves UI subjects and unique command titles before sending and waits for their own terminal deliveries', async () => {
  const events: string[] = []
  const subjects = new Map<string, number>()
  let pendingReads = 0
  send.mockImplementation(
    async (command: string, payload: { title: string }) => {
      events.push(command)
      if (command === 'test:notification:emit') {
        subjects.set(payload.title, emitted.notificationId)
        return emitted
      }
      if (command === 'test:notification:inspect') {
        expect(subjects.has(payload.title)).toBe(true)
        pendingReads++
        return {
          ...terminal,
          deliveries: [
            {
              ...terminal.deliveries[0],
              status: pendingReads < 3 ? 'pending' : 'sent',
            },
          ],
        }
      }
      expect(command).toBe('test:notification:cleanup')
      return { deleted: subjects.delete(payload.title) }
    },
  )
  let ownedTitle = ''
  await cleanupFixtures({ page }, async (cleanup) => {
    const traced = traceCleanup(cleanup, events)
    ownNotificationSubject(traced, 3, 'Test notification: Email', pollExpect)
    events.push('UI send')
    subjects.set('Test notification: Email', emitted.notificationId)
    const result = await emitOwnedNotification(
      traced,
      3,
      {
        type: 'test.owned',
        title: 'Owned',
      },
      pollExpect,
    )
    expect(result).toMatchObject(emitted)
    expect(result.title).toMatch(/^Owned [0-9a-f]{16}$/)
    ownedTitle = result.title
    expect(send).toHaveBeenCalledWith('test:notification:emit', {
      userId: 3,
      type: 'test.owned',
      title: ownedTitle,
    })
  })
  expect(events.slice(0, 4)).toEqual([
    'defer',
    'UI send',
    'defer',
    'test:notification:emit',
  ])
  expect(subjects.size).toBe(0)
  expect(
    send.mock.calls
      .filter(([command]) => command === 'test:notification:cleanup')
      .map(([, payload]) => payload),
  ).toEqual([
    { userId: 3, title: ownedTitle },
    { userId: 3, title: 'Test notification: Email' },
  ])
})

it('reconciles failed emits without a second emit, preserving primary and cleanup failures', async () => {
  for (const outcome of [
    'terminal',
    'late',
    'missing',
    'pending',
    'duplicate',
    'cleanup failure',
    'endpoint',
  ]) {
    send.mockReset()
    const primary =
      outcome === 'endpoint'
        ? new StandCommandEndpointError('No endpoint')
        : new Error('Emit reply lost or partial')
    let reads = 0
    send.mockImplementation(async (command: string) => {
      if (command === 'test:notification:emit') throw primary
      if (command === 'test:notification:inspect') {
        reads++
        if (outcome === 'duplicate') throw new Error('Duplicate subject')
        if (outcome === 'missing' || (outcome === 'late' && reads === 1))
          return absent
        if (outcome === 'pending')
          return {
            ...terminal,
            deliveries: [{ ...terminal.deliveries[0], status: 'pending' }],
          }
        return terminal
      }
      if (outcome === 'cleanup failure') throw new Error('Cleanup refused')
      return { deleted: true }
    })
    const run = cleanupFixtures({ page }, async (cleanup) => {
      await emitOwnedNotification(
        cleanup,
        3,
        {
          type: 'test.owned',
          title: 'Owned',
        },
        pollExpect,
      )
    })
    if (['terminal', 'late', 'endpoint'].includes(outcome)) {
      await expect(run).rejects.toBe(primary)
    } else {
      const failure = await run.catch((error: unknown) => error)
      expect(failure).toBeInstanceOf(AggregateError)
      const first = (failure as AggregateError).errors[0]
      expect(
        first === primary ||
          (first instanceof AggregateError && first.errors[0] === primary),
      ).toBe(true)
      expect((failure as AggregateError).errors.length).toBeGreaterThan(1)
    }
    expect(
      send.mock.calls.filter(
        ([command]) => command === 'test:notification:emit',
      ),
    ).toHaveLength(1)
    if (['missing', 'pending', 'duplicate', 'endpoint'].includes(outcome)) {
      expect(
        send.mock.calls.some(
          ([command]) => command === 'test:notification:cleanup',
        ),
      ).toBe(false)
    }
  }
})

it('checks successful emission identity and channels and still attempts settings restoration after cleanup fails', async () => {
  for (const mismatch of ['id', 'channels']) {
    send.mockReset()
    send.mockImplementation(async (command: string) => {
      if (command === 'test:notification:emit') return emitted
      if (command === 'test:notification:inspect')
        return mismatch === 'id'
          ? { ...terminal, notificationId: 9 }
          : { ...terminal, queuedChannels: [], deliveries: [] }
      return { deleted: true }
    })
    await expect(
      cleanupFixtures({ page }, async (cleanup) => {
        await emitOwnedNotification(
          cleanup,
          3,
          {
            type: 'test.owned',
            title: 'Owned',
          },
          pollExpect,
        )
      }),
    ).rejects.toThrow('cleanup failed')
    expect(
      send.mock.calls.some(
        ([command]) => command === 'test:notification:cleanup',
      ),
    ).toBe(true)
  }
  send.mockReset()
  const primary = new Error('UI setup failed')
  const cleanupFailure = new Error('Notification cleanup failed')
  send.mockImplementation(async (command: string) => {
    if (command === 'test:settings:snapshot') return { snapshot: original }
    if (command === 'test:settings:restore') return { restored: true }
    if (command === 'test:notification:inspect') return terminal
    throw cleanupFailure
  })
  const failure = await cleanupFixtures({ page }, async (cleanup) => {
    await borrowSetting(cleanup, original.key)
    ownNotificationSubject(cleanup, 3, 'Test notification: Email', pollExpect)
    throw primary
  }).catch((error: unknown) => error)
  expect(failure).toBeInstanceOf(AggregateError)
  expect((failure as AggregateError).errors).toEqual([primary, cleanupFailure])
  expect((failure as AggregateError).cause).toBe(primary)
  expect(send).toHaveBeenLastCalledWith('test:settings:restore', {
    snapshot: original,
  })
})

const providerSnapshot = {
  providerKey: 'oauth:github',
  row: {
    id: 11,
    clientId: '',
    clientSecret: 'synthetic-provider-secret',
    scope: null,
    createdAt: 123,
    updatedAt: 456,
  },
}

it('borrows provider state without returning secrets and defers cleanup before mutation', async () => {
  const events: string[] = []
  send.mockImplementation(async (command: string) => {
    events.push(command)
    return command === 'test:oauth-provider:snapshot'
      ? { snapshot: providerSnapshot }
      : { restored: true }
  })
  await cleanupFixtures({ page }, async (cleanup) => {
    const result = await borrowOAuthProvider(
      traceCleanup(cleanup, events),
      providerSnapshot.providerKey,
    )
    expect(result).toBeUndefined()
    events.push('mutation')
  })
  expect(events).toEqual([
    'test:oauth-provider:snapshot',
    'defer',
    'mutation',
    'test:oauth-provider:snapshot',
    'test:oauth-provider:restore',
    'test:oauth-provider:snapshot',
  ])
})

it('awaits provider cleanup after a scenario failure, using the fresh current primary id', async () => {
  const primary = new Error('UI failed')
  const absent = { providerKey: providerSnapshot.providerKey, row: null }
  let reads = 0
  let finishRestore: (() => void) | undefined
  let markRestoreStarted: () => void = () => {}
  const restoreStarted = new Promise<void>((resolve) => {
    markRestoreStarted = resolve
  })
  send.mockImplementation(async (command: string, payload) => {
    if (command === 'test:oauth-provider:snapshot') {
      return { snapshot: ++reads === 2 ? providerSnapshot : absent }
    }
    expect(payload.currentId).toBe(11)
    await new Promise<void>((resolve) => {
      finishRestore = resolve
      markRestoreStarted()
    })
    return { restored: true }
  })
  let settled = false
  const run = cleanupFixtures({ page }, async (cleanup) => {
    await borrowOAuthProvider(cleanup, providerSnapshot.providerKey)
    throw primary
  }).catch((failure: unknown) => {
    settled = true
    return failure
  })
  await restoreStarted
  expect(settled).toBe(false)
  finishRestore?.()
  expect(await run).toBe(primary)
  expect(reads).toBe(3)
})
