import { describe, expect, it } from 'vitest'

import {
  createHilosAccountMerge,
  createHilosMergeCandidates,
  createHilosUserDetail,
  resolveHilosMergeCandidateRow,
  type HilosUserProfile,
  type HilosUsersContext,
} from '../../../src/admin/users/hilosUsers.js'
import { type ActionHandle } from '../../../src/connection/actionLifecycle.js'
import {
  type HilosConnection,
  type TableViewportDescriptor,
} from '../../../src/connection/HilosConnection.js'
import { entityCollection } from '../../../src/state/EntityCollection.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

/** Build a page scope and the typed user collection the admin module resolves through. */
function userStore(): {
  scopes: ScopeManager
  users: HilosUsersContext['users']
} {
  const scopes = new ScopeManager()
  scopes.openPage('hilos_user')
  const users = entityCollection<HilosUserProfile>(
    scopes,
    'user',
    (fields) => ({
      id: Number(fields.id),
      name: String(fields.name ?? ''),
      lastActivity: (fields.lastActivity as string | null) ?? null,
    }),
  )

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

describe('createHilosUserDetail', () => {
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
