import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  createHilosAccountMerge,
  createHilosMergeCandidates,
  createHilosUserDetail,
  createHilosUserCardStepUp,
  createHilosUserLifecycle,
  createHilosUsersTable,
  createHilosUserStanding,
  hilosStandingBadge,
  hilosUserFrozenRow,
  HILOS_USER_CARD_STEP_UP_OPERATIONS,
  hilosUserLifecycleSections,
  resolveHilosMergeCandidateRow,
  type HilosUserCardWindow,
  type HilosUserDetailRow,
  type HilosUsersContext,
} from '../../../src/admin/users/hilosUsers.js'
import { hilosLegalLapsedHref } from '../../../src/admin/legal/hilosLegal.js'
import { type ProjectSignal } from '../../../src/protocol/parseSignal.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { type PageRouteMatch } from '../../../src/routing/PageRouter.js'
import { type HilosAccountStanding } from '../../../src/session/sessionScope.js'
import { createSignal } from '../../../src/state/signal.js'
import {
  ActionError,
  type ActionHandle,
} from '../../../src/connection/actionLifecycle.js'
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

/** One day, in ms. */
const DAY_MS = 86_400_000

/**
 * A standing as the wire carries it.
 *
 * @param facts The facts that differ from a plain account.
 */
function wireStanding(
  facts: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    shown: 'none',
    blocked: false,
    frozen: false,
    deletionEffectiveAt: null,
    lapsed: [],
    window: [],
    ...facts,
  }
}

/**
 * A standing as the card reads it.
 *
 * @param facts The facts that differ from a plain account.
 */
function readStanding(
  facts: Partial<HilosAccountStanding> = {},
): HilosAccountStanding {
  return {
    shown: 'none',
    blocked: false,
    frozen: false,
    deletionEffectiveAt: null,
    lapsed: [],
    window: [],
    ...facts,
  }
}

/** A connection double that replays project frames to its listeners. */
function frameConnection(): {
  connection: HilosConnection
  emit(type: string, data: unknown): void
} {
  const listeners: Array<(signal: ProjectSignal) => void> = []

  return {
    connection: {
      on(event: string, listener: (signal: ProjectSignal) => void) {
        if (event === 'projectSignal') {
          listeners.push(listener)
        }

        return () => {
          listeners.splice(listeners.indexOf(listener), 1)
        }
      },
    } as unknown as HilosConnection,
    emit(type, data) {
      for (const listener of [...listeners]) {
        listener({ kind: 'project', type, data } as unknown as ProjectSignal)
      }
    },
  }
}

describe('createHilosUserStanding (HIL-945)', () => {
  it('reads the page data first and every live frame about the person after it', () => {
    vi.useFakeTimers()
    vi.setSystemTime(100_000)
    applyServerTime(120_000)
    const { scopes } = userStore()
    const page = scopes.page()!
    page.tables.upsert('userDetail', 7, { users: { type: 'user', id: 7 } })
    const { connection, emit } = frameConnection()
    const card = createHilosUserStanding({ scopes, connection })

    card.start()
    expect(card.standing.get()).toBeNull()

    page.data.set(
      'accountStanding',
      wireStanding({
        shown: 'deletion_scheduled',
        deletionEffectiveAt: 20_000 + DAY_MS,
      }),
    )
    expect(card.standing.get()).toEqual(
      readStanding({
        shown: 'deletion_scheduled',
        deletionEffectiveAt: DAY_MS,
      }),
    )

    emit('hilos_account_standing_state', {
      userId: 7,
      accountStanding: wireStanding({ shown: 'blocked', blocked: true }),
    })
    expect(card.standing.get()).toEqual(
      readStanding({ shown: 'blocked', blocked: true }),
    )

    // A late frame of a card left behind is not this card's.
    emit('hilos_account_standing_state', {
      userId: 8,
      accountStanding: wireStanding(),
    })
    expect(card.standing.get()?.shown).toBe('blocked')

    // A viewer of the admin view mode is sent hidden marks: no standing to read.
    emit('hilos_account_standing_state', {
      userId: 7,
      accountStanding: { _hidden: true },
    })
    expect(card.standing.get()).toBeNull()

    card.dispose()
    emit('hilos_account_standing_state', {
      userId: 7,
      accountStanding: wireStanding({ shown: 'frozen', frozen: true }),
    })
    expect(card.standing.get()).toBeNull()
  })
})

describe('hilosUserLifecycleSections with a standing (HIL-945)', () => {
  const detail: HilosUserDetailRow = {
    id: 5,
    name: 'Maria',
    lastActivity: null,
    presence: 'online',
    onlineSessionCount: 1,
    hasPassword: true,
    admin: false,
    block: false,
    deletionEffectiveAt: null,
  }

  it('reads the block and the deletion from the verdict rather than the row', () => {
    const now = 1_000_000
    const sections = hilosUserLifecycleSections(
      detail,
      99,
      30,
      now,
      readStanding({
        shown: 'blocked',
        blocked: true,
        deletionEffectiveAt: now + 3 * DAY_MS,
      }),
    )
    const access = sections.find((section) => section.key === 'access')

    expect(access?.rows.map((row) => [row.key, row.state, row.choice])).toEqual(
      [
        ['block', true, 'unblock'],
        ['deletion', true, 'cancelDeletion'],
      ],
    )
    expect(access?.rows[1]?.hint).toContain('3 days left')
  })

  it('falls back to the row while the card has no verdict', () => {
    const sections = hilosUserLifecycleSections(
      { ...detail, block: true },
      99,
      30,
      0,
      null,
    )

    expect(
      sections.find((section) => section.key === 'access')?.rows[0]?.state,
    ).toBe(true)
  })
})

describe('hilosUserFrozenRow (HIL-945)', () => {
  const lapsed = [
    { document: 'terms', deadline: '2026-09-01' },
    { document: 'privacy', deadline: '2026-09-15' },
  ] as const

  it('is absent while the card has no verdict', () => {
    expect(hilosUserFrozenRow(null)).toBeNull()
  })

  it('names every lapsed document and its day while frozen', () => {
    expect(
      hilosUserFrozenRow(
        readStanding({ shown: 'frozen', frozen: true, lapsed: [...lapsed] }),
      ),
    ).toEqual({
      key: 'frozen',
      title: 'Frozen',
      state: true,
      hint: null,
      lapsed: [
        'Terms of use — deadline passed 1 September 2026',
        'Privacy policy — deadline passed 15 September 2026',
      ],
    })
  })

  it('says "reminders only" past a deadline under the reminding setting', () => {
    expect(
      hilosUserFrozenRow(readStanding({ lapsed: [lapsed[0]] })),
    ).toMatchObject({
      state: false,
      hint: 'Past deadline — reminders only',
      lapsed: ['Terms of use — deadline passed 1 September 2026'],
    })
  })

  it('says "Not frozen" with nothing lapsed', () => {
    expect(hilosUserFrozenRow(readStanding())).toMatchObject({
      state: false,
      hint: 'Not frozen',
      lapsed: [],
    })
  })
})

describe('hilosStandingBadge (HIL-945)', () => {
  it('shows one badge for the standing shown, in the shell colors', () => {
    expect(hilosStandingBadge('none')).toBeNull()
    expect(hilosStandingBadge('blocked')).toEqual({
      label: 'Blocked',
      tone: 'danger',
      icon: 'bi-slash-circle',
    })
    expect(hilosStandingBadge('frozen')).toEqual({
      label: 'Frozen',
      tone: 'info',
      icon: 'bi-snow',
    })
    expect(hilosStandingBadge('deletion_scheduled')).toEqual({
      label: 'Deletion scheduled',
      tone: 'warning',
      icon: 'bi-trash',
    })
  })
})

describe('createHilosUsersTable lapsed filter (HIL-945)', () => {
  /**
   * The users table over a recording connection and a fake address.
   *
   * @param params The route params the page opened on.
   */
  function usersTable(params: Record<string, string>) {
    const { scopes, users } = userStore()
    const sent: TableViewportDescriptor[] = []
    const connection = {
      on: () => () => {},
      registerTableWindow: () => {},
      unregisterTableWindow: () => {},
      sendTableRendered: () => true,
      sendTableViewport: (
        _page: string,
        _tableKey: string,
        descriptor: TableViewportDescriptor,
      ) => sent.push(descriptor) > 0,
    } as unknown as HilosConnection
    const route = createSignal<PageRouteMatch>({
      page: HilosPages.USERS,
      params,
      admin: true,
    })
    const replaced: string[] = []
    const table = createHilosUsersTable(
      { scopes, users, connection } as HilosUsersContext,
      {
        currentRoute: route,
        replacePath(pathname) {
          replaced.push(pathname)
          route.set({
            page: HilosPages.USERS,
            params: pathname.endsWith('/users')
              ? {}
              : { lapsed: pathname.split('/').at(-1) ?? '' },
            admin: true,
          })
        },
      },
    )

    return { table, sent, replaced }
  }

  it('opens on the document its address names and keeps the address in step', () => {
    const { table, sent, replaced } = usersTable({ lapsed: 'terms' })
    table.start()

    expect(table.controller.filter.get()).toEqual({ lapsed: 'terms' })
    table.controller.setSearch('maria')
    expect(sent.at(-1)?.filter).toEqual({ lapsed: 'terms', search: 'maria' })
    expect(replaced).toEqual([])

    table.controller.setFilter('lapsed', 'privacy')
    expect(replaced).toEqual(['/hilos/users/privacy'])

    // The reset brings back the whole list, not the filter the address opened on.
    table.controller.resetFilters()
    expect(table.controller.filter.get()).toEqual({})
    expect(sent.at(-1)?.filter).toEqual({})
    expect(replaced).toEqual(['/hilos/users/privacy', '/hilos/users'])
    table.dispose()
  })

  it('offers the filter in the bar and ignores a tail it does not know', () => {
    const { table, replaced } = usersTable({ lapsed: 'cookies' })
    table.start()

    expect(table.controller.filter.get()).toEqual({})
    const filter = table.controller.frame.declaration?.filters?.[0]
    expect(filter).toMatchObject({
      kind: 'select',
      key: 'lapsed',
      label: 'Past deadline on',
      anyLabel: 'All',
    })
    expect(filter?.kind === 'select' ? filter.options() : undefined).toEqual([
      { value: 'terms', label: 'Terms of use' },
      { value: 'privacy', label: 'Privacy policy' },
    ])

    table.controller.setFilter('lapsed', 'terms')
    expect(replaced).toEqual(['/hilos/users/terms'])
    table.dispose()
  })

  it('is where the legal root sends its third count', () => {
    expect(hilosLegalLapsedHref('terms')).toBe('/hilos/users/terms')
    expect(hilosLegalLapsedHref('privacy')).toBe('/hilos/users/privacy')
  })
})

describe('createHilosUserCardStepUp', () => {
  /** A context whose start action answers with the given word, recording what was sent. */
  function stepUpContext(answer: { required: boolean } | ActionError): {
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
          const done =
            answer instanceof ActionError
              ? Promise.reject(answer)
              : Promise.resolve({
                  reply: {
                    ...answer,
                    purpose: 'merge an account into this one',
                    method: 'password',
                  },
                })

          return { done } as unknown as ActionHandle
        },
      },
    } as unknown as HilosUsersContext

    return { context, calls }
  }

  it('names the operation of every window that takes something away', () => {
    expect(HILOS_USER_CARD_STEP_UP_OPERATIONS).toEqual({
      merge: 'merge_accounts',
      grant: 'grant_admin',
      revoke: 'revoke_admin',
      block: 'block_account',
      delete: 'delete_other_account',
    })
  })

  it('opens a window that gives back without asking the server', async () => {
    const { context, calls } = stepUpContext({ required: true })
    const stepUp = createHilosUserCardStepUp(context)

    expect(await stepUp.open('unblock')).toBe('skip')
    expect(await stepUp.open('cancelDeletion')).toBe('skip')
    expect(calls).toEqual([])
  })

  it('asks the server by the operation of every other window, whatever the list says', async () => {
    const { context, calls } = stepUpContext({ required: false })
    const stepUp = createHilosUserCardStepUp(context)
    const windows: (keyof typeof HILOS_USER_CARD_STEP_UP_OPERATIONS &
      HilosUserCardWindow)[] = ['merge', 'grant', 'revoke', 'block', 'delete']

    for (const window of windows) {
      expect(await stepUp.open(window)).toBe('skip')
    }
    expect(calls).toEqual(
      windows.map((window) => ({
        action: 'hilos_step_up_start',
        payload: { operation: HILOS_USER_CARD_STEP_UP_OPERATIONS[window] },
      })),
    )
  })

  it('draws the step when the server asks, and the refusal when it refuses', async () => {
    const asking = createHilosUserCardStepUp(
      stepUpContext({ required: true }).context,
    )
    expect(await asking.open('merge')).toBe('ask')
    expect(asking.step.opening.get()?.method).toBe('password')

    const refusing = createHilosUserCardStepUp(
      stepUpContext(
        new ActionError(
          'hilos_step_up_start',
          'fail',
          'Add a password, an email or a phone to your account to do this',
        ),
      ).context,
    )
    expect(await refusing.open('grant')).toBe('refused')
    expect(refusing.step.refusal.get()).toBe(
      'Add a password, an email or a phone to your account to do this',
    )
  })
})
