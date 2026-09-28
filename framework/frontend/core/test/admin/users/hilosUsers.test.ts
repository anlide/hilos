import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  createHilosAccountMerge,
  createHilosMergeCandidates,
  createHilosUserDetail,
  createHilosUserLifecycle,
  resolveHilosMergeCandidateRow,
  type HilosUsersContext,
} from '../../../src/admin/users/hilosUsers.js'
import { type ActionHandle } from '../../../src/connection/actionLifecycle.js'
import {
  type HilosConnection,
  type TableViewportDescriptor,
} from '../../../src/connection/HilosConnection.js'
import { entityCollection } from '../../../src/state/EntityCollection.js'
import { USER_ENTITY_TYPE, userFromFields } from '../../../src/state/entity.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { applyServerTime } from '../../../src/session/serverClock.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

/** Build a page scope and the typed user collection the admin module resolves through. */
function userStore(): {
  scopes: ScopeManager
  users: HilosUsersContext['users']
} {
  const scopes = new ScopeManager()
  scopes.openPage('hilos_user')
  const users = entityCollection(scopes, USER_ENTITY_TYPE, userFromFields)

  return { scopes, users }
}

describe('resolveHilosMergeCandidateRow', () => {
  it('folds the user entity and safe identity slot into a candidate', () => {
    const { scopes, users } = userStore()
    scopes
      .page()
      ?.entities.upsert(
        { type: 'user', id: 7 },
        { id: 7, name: 'Loser', lastActivity: '2026-09-24 10:00' },
      )
    const row: TableRow = {
      rowKey: '7',
      slots: {
        users: { type: 'user', id: 7 },
        merge: {
          identities: [
            {
              type: 'email',
              identifier: 'loser@example.test',
              provider: null,
              verified: true,
            },
          ],
          hasPassword: true,
        },
      },
    }

    expect(resolveHilosMergeCandidateRow(row, users)).toEqual({
      id: 7,
      name: 'Loser',
      lastActivity: '2026-09-24 10:00',
      identities: [
        {
          type: 'email',
          identifier: 'loser@example.test',
          provider: null,
          verified: true,
        },
      ],
      hasPassword: true,
    })
  })
})

afterEach(() => {
  applyServerTime(Date.now())
  vi.useRealTimers()
})

describe('createHilosUserDetail', () => {
  it('follows live rights, block and deletion changes on the local clock', () => {
    vi.useFakeTimers()
    vi.setSystemTime(100_000)
    applyServerTime(120_000)
    const { scopes, users } = userStore()
    const page = scopes.page()!
    const ref = { type: 'user', id: 1 }
    page.entities.upsert(ref, {
      id: 1,
      name: 'Candidate',
      admin: true,
      block: false,
    })
    page.tables.upsert('userDetail', 1, { users: ref })
    const detail = createHilosUserDetail({ scopes, users } as HilosUsersContext)

    expect(detail.get()).toMatchObject({
      admin: true,
      block: false,
      deletionEffectiveAt: null,
    })
    page.entities.upsert(ref, { admin: false, block: true })
    page.tables.upsert('userDetail', 1, {
      users: ref,
      accountDeletions: { userId: 1, deletionEffectiveAt: 320_000 },
    })
    expect(detail.get()).toMatchObject({
      admin: false,
      block: true,
      deletionEffectiveAt: 300_000,
    })
    page.tables.upsert('userDetail', 1, {
      users: ref,
      accountDeletions: { userId: 1, deletionEffectiveAt: null },
    })
    expect(detail.get()?.deletionEffectiveAt).toBeNull()
  })

  it('reads password presence from the identities slot and defaults it to false', () => {
    const { scopes, users } = userStore()
    const page = scopes.page()
    page?.entities.upsert(
      { type: 'user', id: 1 },
      { id: 1, name: 'Survivor', lastActivity: null },
    )
    page?.tables.upsert('userDetail', 1, {
      users: { type: 'user', id: 1 },
      identities: { hasPassword: true },
    })
    const detail = createHilosUserDetail({ scopes, users } as HilosUsersContext)

    expect(detail.get()?.hasPassword).toBe(true)
    page?.tables.upsert('userDetail', 1, {
      users: { type: 'user', id: 1 },
    })
    expect(detail.get()?.hasPassword).toBe(false)
  })
})

describe('createHilosMergeCandidates', () => {
  it('keeps the survivor preset in every server-side search window', () => {
    const { scopes, users } = userStore()
    const sent: Array<{
      page: string
      tableKey: string
      descriptor: TableViewportDescriptor
    }> = []
    const connection = {
      on: () => () => {},
      registerTableWindow: () => {},
      unregisterTableWindow: () => {},
      sendTableRendered: () => true,
      sendTableViewport: (
        page: string,
        tableKey: string,
        descriptor: TableViewportDescriptor,
      ) => sent.push({ page, tableKey, descriptor }) > 0,
    } as unknown as HilosConnection
    const candidates = createHilosMergeCandidates({
      scopes,
      users,
      connection,
    } as HilosUsersContext)

    candidates.start(12)
    candidates.controller.setSearch('loser@example.test')

    expect(sent).toHaveLength(2)
    expect(sent.at(-1)).toMatchObject({
      page: 'hilos_user',
      tableKey: 'mergeCandidates',
      descriptor: {
        filter: { survivor: 12, search: 'loser@example.test' },
      },
    })
    expect(candidates.controller.frame.declaration?.search?.placeholder).toBe(
      'Search by name, address or ID',
    )
    expect(candidates.controller.frame.declaration?.empty?.title).toBe(
      'No accounts match.',
    )
    candidates.controller.resetFilters()
    expect(sent.at(-1)).toMatchObject({
      descriptor: {
        filter: { survivor: 12 },
      },
    })
    candidates.dispose()
  })
})

describe('createHilosAccountMerge', () => {
  /** Record tracked-action payloads without constructing a live connection. */
  function recordingContext(): {
    context: HilosUsersContext
    calls: Array<{ action: string; payload: Record<string, unknown> }>
  } {
    const calls: Array<{ action: string; payload: Record<string, unknown> }> =
      []
    const context = {
      actions: {
        dispatch(
          action: string,
          payload: Record<string, unknown>,
        ): ActionHandle {
          calls.push({ action, payload })

          return {} as ActionHandle
        },
      },
    } as unknown as HilosUsersContext

    return { context, calls }
  }

  it('omits passwordFate when the accounts do not need a choice', () => {
    const { context, calls } = recordingContext()
    createHilosAccountMerge(context).merge(12, 57)

    expect(calls).toEqual([
      {
        action: 'hilos_user_merge',
        payload: { survivorUserId: 12, loserUserId: 57 },
      },
    ])
  })

  it('sends the named password fate when both accounts have a password', () => {
    const { context, calls } = recordingContext()
    createHilosAccountMerge(context).merge(12, 57, 'loser')

    expect(calls).toEqual([
      {
        action: 'hilos_user_merge',
        payload: {
          survivorUserId: 12,
          loserUserId: 57,
          passwordFate: 'loser',
        },
      },
    ])
  })
})

describe('createHilosUserLifecycle', () => {
  it('sends all six choices as tracked actions without changing committed state', () => {
    const { scopes, users } = userStore()
    const dispatch = vi.fn().mockReturnValue({ done: Promise.resolve() })
    const context = {
      scopes,
      users,
      actions: { dispatch },
    } as unknown as HilosUsersContext
    const lifecycle = createHilosUserLifecycle(context)
    for (const requested of [true, false]) {
      expect(lifecycle.setAdmin(12, requested)).toBe(
        dispatch.mock.results.at(-1)?.value,
      )
      lifecycle.setBlock(12, requested)
      lifecycle.setDeletion(12, requested)
    }
    expect(dispatch.mock.calls).toEqual([
      ['hilos_user_admin_set', { userId: 12, admin: true }],
      ['hilos_user_block_set', { userId: 12, block: true }],
      ['hilos_user_deletion_set', { userId: 12, scheduled: true }],
      ['hilos_user_admin_set', { userId: 12, admin: false }],
      ['hilos_user_block_set', { userId: 12, block: false }],
      ['hilos_user_deletion_set', { userId: 12, scheduled: false }],
    ])
    expect(scopes.page()?.tables.signal('userDetail').get()).toEqual([])
  })

  it('reads the grace period from page data, including later subscriptions', () => {
    const { scopes, users } = userStore()
    const lifecycle = createHilosUserLifecycle({
      scopes,
      users,
    } as HilosUsersContext)
    expect(lifecycle.graceDays.get()).toBeNull()
    for (const invalid of [0, -1, 1.5, '30', null]) {
      scopes.page()?.data.set('accountDeletionGraceDays', invalid)
      expect(lifecycle.graceDays.get()).toBeNull()
    }
    scopes.page()?.data.set('accountDeletionGraceDays', 30)
    expect(lifecycle.graceDays.get()).toBe(30)
    scopes.openPage('hilos_user').data.set('accountDeletionGraceDays', 1)
    expect(lifecycle.graceDays.get()).toBe(1)
  })
})
