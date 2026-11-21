import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  createHilosAccountMergeWindow,
  HILOS_ACCOUNT_MERGE_WINDOW_COPY,
  type HilosAccountMergeWindow,
  type HilosAccountMergeWindowView,
  type HilosAccountMergeRequest,
} from '../../../src/admin/users/index.js'
import {
  createHilosAccountMerge,
  createHilosUserStanding,
  HILOS_USER_MERGE_SECTION,
  SIGNAL_ACCOUNT_STANDING_STATE,
  type HilosAccountMergeProgress,
} from '../../../src/admin/users/hilosUsers.js'
import { ActionLifecycle } from '../../../src/connection/actionLifecycle.js'
import {
  HilosConnection,
  type WebSocketLike,
} from '../../../src/connection/HilosConnection.js'
import { ACCOUNT_STANDING_SIGNAL_SCHEMAS } from '../../../src/protocol/accountStandingSignals.js'
import {
  SIGNAL_TYPE_ACTION_ERROR,
  SIGNAL_TYPE_ACTION_SUCCESS,
  SIGNAL_TYPE_HANDSHAKE,
} from '../../../src/protocol/constants.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import {
  bindAdminAccess,
  type HilosAdminAccess,
} from '../../../src/session/adminAccess.js'
import {
  bindSessionScope,
  SESSION_SIGNAL_SCHEMAS,
  SIGNAL_HANDSHAKE_RESPONSE,
} from '../../../src/session/sessionScope.js'
import { USER_ENTITY_TYPE, userFromFields } from '../../../src/state/entity.js'
import { entityCollection } from '../../../src/state/EntityCollection.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { computedSignal, subscribeSignal } from '../../../src/state/signal.js'

function progress(
  overrides: Partial<HilosAccountMergeProgress> = {},
): HilosAccountMergeProgress {
  return {
    operationId: 41,
    survivorUserId: 7,
    loserUserId: 8,
    state: 'preparing',
    stage: 'preparing',
    outcome: null,
    given: false,
    received: false,
    choice: {
      passwordFate: 'loser',
      secondFactorFate: 'both',
      expectedSurvivorHasSecondFactor: true,
      expectedLoserHasSecondFactor: true,
    },
    refusal: null,
    parts: [{ participant: 'give', owner: 'user', userId: 8, done: false }],
    waits: [{ participant: 'sessions', userId: 8 }],
    ...overrides,
  }
}

/** A server-withheld frame, taken through the same reader as administrator frames. */
function hiddenProgress() {
  return {
    ...progress(),
    operationId: HIDDEN_VALUE,
    survivorUserId: HIDDEN_VALUE,
    loserUserId: HIDDEN_VALUE,
    stage: HIDDEN_VALUE,
    outcome: HIDDEN_VALUE,
    choice: HIDDEN_VALUE,
    refusal: HIDDEN_VALUE,
    parts: [
      { participant: 'give', owner: 'user', userId: HIDDEN_VALUE, done: false },
    ],
    waits: [{ participant: 'sessions', userId: HIDDEN_VALUE }],
  }
}

const releases: (() => void)[] = []
afterEach(() => {
  for (const release of releases.splice(0).reverse()) release()
})

/** Real connection parsing, standing reader and page/session Scope signals. */
function setup(raw: unknown = progress()) {
  const listeners = new Map<string, ((event: { data?: unknown }) => void)[]>()
  const socket = {
    send: vi.fn(),
    close: vi.fn(),
    addEventListener(type, listener) {
      const list = listeners.get(type) ?? []
      list.push(listener)
      listeners.set(type, list)
    },
  } satisfies WebSocketLike
  function message(type: string, data: unknown, requestId?: string): void {
    for (const listener of listeners.get('message') ?? []) {
      listener({ data: JSON.stringify({ type, data, requestId }) })
    }
  }
  const connection = new HilosConnection({
    url: 'ws://merge-window.test/ws',
    webSocketFactory: () => socket,
    projectSchemas: {
      ...SESSION_SIGNAL_SCHEMAS,
      ...ACCOUNT_STANDING_SIGNAL_SCHEMAS,
    },
  })
  const scopes = new ScopeManager()
  bindSessionScope(connection, scopes)
  const offAccess = bindAdminAccess(scopes)
  function access(next: HilosAdminAccess): void {
    message(SIGNAL_HANDSHAKE_RESPONSE, {
      entities: {
        currentUser: { id: 1, name: 'Administrator', admin: next === 'full' },
      },
      data: { adminViewMode: next !== 'none', accessLossReason: null },
    })
  }
  connection.connect()
  for (const listener of listeners.get('open') ?? []) listener({})
  message(SIGNAL_TYPE_HANDSHAKE, { build: 'merge-window-test' })
  access('full')
  const page = scopes.openPage(HilosPages.USER)
  page.tables.upsert('userDetail', 7, {
    users: { type: USER_ENTITY_TYPE, id: 7 },
  })
  page.data.set(HILOS_USER_MERGE_SECTION, raw)
  const standing = createHilosUserStanding({ scopes, connection })
  standing.start()
  const rows = scopes.pageTableSignal('userDetail')
  const readShown = vi.fn(() => {
    const row = rows.get()[0]
    return row === undefined ? undefined : Number(row.rowKey)
  })
  const shownUserId = computedSignal(readShown)
  const readMerge = vi.fn(() => standing.merge.get())
  const window = createHilosAccountMergeWindow({ get: readMerge }, shownUserId)
  const mergeAction = createHilosAccountMerge({
    scopes,
    connection,
    actions: new ActionLifecycle(connection),
    users: entityCollection(scopes, USER_ENTITY_TYPE, userFromFields),
  })
  releases.push(() => {
    window.dispose()
    standing.dispose()
    offAccess()
    connection.close()
  })
  socket.send.mockClear()
  return {
    window,
    standing,
    scopes,
    page,
    socket,
    connection,
    access,
    readMerge,
    start(request: HilosAccountMergeRequest = mergeRequest()) {
      const handle = mergeAction.merge(
        request.survivorUserId,
        request.loserUserId,
        request.passwordFate ?? undefined,
        request.secondFactorFate ?? undefined,
        request.expectedSurvivorHasSecondFactor,
        request.expectedLoserHasSecondFactor,
      )
      return { handle, done: window.track(handle, request) }
    },
    reply(requestId: string, reply?: unknown) {
      message(
        SIGNAL_TYPE_ACTION_SUCCESS,
        { action: 'hilos_user_merge', reply },
        requestId,
      )
    },
    refuse(requestId: string) {
      message(
        SIGNAL_TYPE_ACTION_ERROR,
        { action: 'hilos_user_merge', reason: 'The source account changed.' },
        requestId,
      )
    },
    update(next: unknown) {
      page.data.set(HILOS_USER_MERGE_SECTION, next)
    },
    frame(next: unknown, userId = 7) {
      message(SIGNAL_ACCOUNT_STANDING_STATE, {
        userId,
        accountStanding: HIDDEN_VALUE,
        accountMerge: next,
      })
    },
  }
}

function mergeRequest(
  overrides: Partial<HilosAccountMergeRequest> = {},
): HilosAccountMergeRequest {
  return {
    survivorUserId: 7,
    loserUserId: 8,
    passwordFate: 'loser',
    secondFactorFate: 'both',
    expectedSurvivorHasSecondFactor: true,
    expectedLoserHasSecondFactor: true,
    ...overrides,
  }
}

function current(window: HilosAccountMergeWindow) {
  const view = window.view.get()
  expect(view?.kind).toBe('progress')
  if (view?.kind !== 'progress') throw new Error('Expected current progress')
  return view
}

describe('createHilosAccountMergeWindow', () => {
  describe('track', () => {
    beforeEach(() => vi.useFakeTimers())
    afterEach(() => vi.useRealTimers())

    it('waits for the accepted id before projecting progress and later changes', async () => {
      const { window, start, reply, frame } = setup()
      const { handle, done } = start()
      reply(handle.requestId, { operationId: 42 })
      await expect(done).resolves.toBe('accepted')
      expect(window.opened.get()).toBe(true)
      const pending = {
        kind: 'pending',
        status: 'Preparing merge',
        success: false,
      }
      expect(window.view.get()).toEqual(pending)
      for (const old of [progress(), null, hiddenProgress()]) {
        frame(old)
        expect(window.view.get()).toEqual(pending)
      }
      frame(progress({ operationId: 42, state: 'wait', stage: 'go' }))
      expect(current(window)).toMatchObject({
        progress: { operationId: 42 },
        status: 'Merge in progress',
        success: false,
      })
      frame(null)
      expect(window.view.get()?.kind).toBe('unavailable')
      frame(hiddenProgress())
      expect(current(window).progress.operationId).toBeNull()
      frame(progress({ operationId: 43 }))
      expect(window.view.get()?.kind).toBe('changed')
    })

    it('uses the first operation frame when it arrives before acceptance', async () => {
      const { window, start, reply, frame } = setup()
      const { handle, done } = start()
      frame(progress({ operationId: 42 }))
      reply(handle.requestId, { operationId: 42 })
      await expect(done).resolves.toBe('accepted')
      expect(current(window).progress.operationId).toBe(42)
      expect(window.startNotice.get()).toBeNull()
    })

    it('rebinds an existing window without exposing a later operation', async () => {
      const { window, start, reply, frame } = setup()
      window.open()
      const seen: (HilosAccountMergeWindowView | null)[] = []
      releases.push(subscribeSignal(window.view, (view) => seen.push(view)))
      const { handle, done } = start()
      frame(progress({ operationId: 42 }))
      reply(handle.requestId, { operationId: 42 })
      await done
      expect(current(window).progress.operationId).toBe(42)
      seen.length = 0
      frame(progress({ operationId: 43 }))
      expect(seen.map((view) => view?.kind)).toEqual(['changed'])
    })

    it.each([false, true])(
      'leaves an opened=%s window alone on refusal',
      async (opened) => {
        const { window, start, refuse } = setup()
        if (opened) window.open()
        const previous = window.view.get()
        const { handle, done } = start()
        refuse(handle.requestId)
        await expect(done).resolves.toBe('refused')
        expect(window.opened.get()).toBe(opened)
        expect(window.view.get()).toBe(previous)
        expect(window.startNotice.get()).toBeNull()
      },
    )

    it.each(['timeout', 'disconnected', 'invalid-reply'])(
      'notices %s and adopts the exact new operation',
      async (outcome) => {
        const { window, start, reply, connection, update } = setup()
        const { handle, done } = start()
        if (outcome === 'timeout') await vi.advanceTimersByTimeAsync(30000)
        else if (outcome === 'disconnected') connection.close()
        else reply(handle.requestId, { operationId: 0 })
        await expect(done).resolves.toBe('unanswered')
        expect(window.opened.get()).toBe(false)
        expect(window.startNotice.get()).toBe(
          'The merge request got no answer. If the merge started, it appears here.',
        )
        update(progress({ operationId: 42 }))
        expect(window.startNotice.get()).toBeNull()
        expect(window.opened.get()).toBe(true)
        expect(current(window).progress.operationId).toBe(42)
      },
    )

    it('finds an exact new operation already visible when the answer is lost', async () => {
      const { window, start, frame } = setup()
      const { done } = start()
      frame(progress({ operationId: 42 }))
      await vi.advanceTimersByTimeAsync(30000)
      await expect(done).resolves.toBe('unanswered')
      expect(window.startNotice.get()).toBeNull()
      expect(current(window).progress.operationId).toBe(42)
    })

    it.each<[string, unknown]>([
      ['previous id', progress()],
      ['other survivor', progress({ operationId: 42, survivorUserId: 9 })],
      ['other loser', progress({ operationId: 42, loserUserId: 9 })],
      ['hidden operation', { ...progress(), operationId: HIDDEN_VALUE }],
      [
        'hidden survivor',
        { ...progress({ operationId: 42 }), survivorUserId: HIDDEN_VALUE },
      ],
      [
        'hidden loser',
        { ...progress({ operationId: 42 }), loserUserId: HIDDEN_VALUE },
      ],
      [
        'hidden choice',
        { ...progress({ operationId: 42 }), choice: HIDDEN_VALUE },
      ],
      ...Object.entries({
        passwordFate: 'survivor',
        secondFactorFate: 'survivor',
        expectedSurvivorHasSecondFactor: false,
        expectedLoserHasSecondFactor: false,
      }).flatMap<[string, unknown]>(([field, value]) => [
        [
          `different ${field}`,
          {
            ...progress({ operationId: 42 }),
            choice: { ...progress().choice, [field]: value },
          },
        ],
        [
          `hidden ${field}`,
          {
            ...progress({ operationId: 42 }),
            choice: { ...progress().choice, [field]: HIDDEN_VALUE },
          },
        ],
      ]),
    ])(
      'ignores %s while waiting for an unanswered request',
      async (_name, other) => {
        const { window, start, frame } = setup()
        const { done } = start()
        await vi.advanceTimersByTimeAsync(30000)
        await done
        frame(other)
        expect(window.opened.get()).toBe(false)
        expect(window.startNotice.get()).toBe(
          HILOS_ACCOUNT_MERGE_WINDOW_COPY.unanswered,
        )
        frame(progress({ operationId: 42 }))
        expect(current(window).progress.operationId).toBe(42)
      },
    )

    it('matches absent optional choices without accepting hidden expectations', async () => {
      const { window, start, frame } = setup(null)
      const { done } = start(
        mergeRequest({
          passwordFate: null,
          secondFactorFate: null,
          expectedSurvivorHasSecondFactor: false,
          expectedLoserHasSecondFactor: false,
        }),
      )
      await vi.advanceTimersByTimeAsync(30000)
      await done
      const choice = {
        passwordFate: null,
        secondFactorFate: null,
        expectedSurvivorHasSecondFactor: false,
        expectedLoserHasSecondFactor: false,
      }
      frame({
        ...progress(),
        choice: { ...choice, expectedLoserHasSecondFactor: HIDDEN_VALUE },
      })
      expect(window.opened.get()).toBe(false)
      frame(progress({ choice }))
      expect(current(window).progress.operationId).toBe(41)
    })

    it('ignores a late reply after timeout and still follows the live exact operation', async () => {
      const { window, start, reply, frame } = setup()
      const { handle, done } = start()
      await vi.advanceTimersByTimeAsync(30000)
      await done
      reply(handle.requestId, { operationId: 99 })
      await Promise.resolve()
      expect(window.opened.get()).toBe(false)
      expect(window.startNotice.get()).toBe(
        HILOS_ACCOUNT_MERGE_WINDOW_COPY.unanswered,
      )
      frame(progress({ operationId: 42 }))
      expect(current(window).progress.operationId).toBe(42)
      reply(handle.requestId, { operationId: 99 })
      await Promise.resolve()
      expect(current(window).progress.operationId).toBe(42)
    })

    it('releases tracking when a notice observer closes the window', async () => {
      const { window, start, frame, readMerge } = setup()
      releases.push(
        subscribeSignal(window.startNotice, (notice) => {
          if (notice !== null) window.close()
        }),
      )
      const { done } = start()
      await vi.advanceTimersByTimeAsync(30000)
      await done
      readMerge.mockClear()
      frame(progress({ operationId: 42 }))
      expect(readMerge).not.toHaveBeenCalled()
      expect(window.opened.get()).toBe(false)
      expect(window.startNotice.get()).toBeNull()
    })

    it('replaces unanswered tracking and ignores an earlier in-flight action', async () => {
      const { window, start, reply, frame } = setup()
      const first = start()
      await vi.advanceTimersByTimeAsync(30000)
      await first.done
      const second = start(mergeRequest({ loserUserId: 9 }))
      expect(window.startNotice.get()).toBeNull()
      frame(progress({ operationId: 42 }))
      expect(window.opened.get()).toBe(false)
      const third = start()
      reply(second.handle.requestId, { operationId: 43 })
      await second.done
      expect(window.opened.get()).toBe(false)
      reply(third.handle.requestId, { operationId: 44 })
      await third.done
      expect(window.view.get()?.kind).toBe('pending')
      frame(progress({ operationId: 44 }))
      expect(current(window).progress.operationId).toBe(44)
    })

    it.each(['close', 'dispose', 'card', 'row loss', 'view', 'none'])(
      'releases unanswered observers and notice on %s',
      async (change) => {
        const { window, start, scopes, access, frame, readMerge } = setup()
        const { done } = start()
        await vi.advanceTimersByTimeAsync(30000)
        await done
        const off = subscribeSignal(window.view, () => {})
        releases.push(off)
        if (change === 'close') window.close()
        else if (change === 'dispose') window.dispose()
        else if (change === 'row loss') scopes.dropPage()
        else if (change === 'card') {
          const next = scopes.openPage(HilosPages.USER)
          next.tables.upsert('userDetail', 9, {
            users: { type: USER_ENTITY_TYPE, id: 9 },
          })
        } else access(change === 'view' ? 'view' : 'none')
        expect(window.startNotice.get()).toBeNull()
        readMerge.mockClear()
        frame(progress({ operationId: 42 }))
        access('full')
        expect(readMerge).not.toHaveBeenCalled()
        expect(window.opened.get()).toBe(false)
        expect(window.view.get()).toBeNull()
      },
    )

    it.each(['close', 'dispose', 'card', 'view'])(
      'does not open on an answer after %s canceled tracking',
      async (change) => {
        const { window, start, reply, access, scopes } = setup()
        const { handle, done } = start()
        if (change === 'close') window.close()
        else if (change === 'dispose') window.dispose()
        else if (change === 'card') scopes.dropPage()
        else access('view')
        reply(handle.requestId, { operationId: 42 })
        await done
        expect(window.opened.get()).toBe(false)
        expect(window.startNotice.get()).toBeNull()
      },
    )
  })

  describe('state, outcome and refusal priority', () => {
    it('exports the window copy from the lazy users entry', () => {
      expect(HILOS_ACCOUNT_MERGE_WINDOW_COPY).toEqual({
        view: 'View merge',
        close: 'Close',
        unavailable: 'Merge progress is unavailable.',
        unanswered:
          'The merge request got no answer. If the merge started, it appears here.',
        changed:
          'The merge operation has changed. Close this window and open it again.',
        survivor: 'Survivor',
        loser: 'Other account',
      })
    })

    it.each<{
      state: HilosAccountMergeProgress['state']
      stage: HilosAccountMergeProgress['stage']
      outcome: HilosAccountMergeProgress['outcome']
      status: string
      success: boolean
    }>([
      {
        state: 'preparing',
        stage: 'preparing',
        outcome: null,
        status: 'Preparing merge',
        success: false,
      },
      {
        state: 'wait',
        stage: 'go',
        outcome: null,
        status: 'Merge in progress',
        success: false,
      },
      {
        state: 'refusal',
        stage: 'cancelling',
        outcome: null,
        status: 'Merge refused',
        success: false,
      },
      {
        state: 'end',
        stage: 'go',
        outcome: 'completed',
        status: 'Merge completed',
        success: true,
      },
      {
        state: 'end',
        stage: 'cancelling',
        outcome: 'not_started',
        status: 'Merge not started',
        success: false,
      },
      {
        state: 'refusal',
        stage: 'cancelling',
        outcome: 'not_started',
        status: 'Merge not started',
        success: false,
      },
      {
        state: 'end',
        stage: null,
        outcome: null,
        status: 'Merge finished',
        success: false,
      },
    ])(
      'presents $state / $stage / $outcome',
      ({ status, success, ...fields }) => {
        const reason = 'The source account changed. Please try again.'
        const { window } = setup(
          progress({
            ...fields,
            refusal: { code: 'protection_changed', reason },
          }),
        )
        expect(window.open()).toBe(true)
        expect(current(window)).toMatchObject({
          status,
          success,
          reason:
            fields.state === 'refusal' || fields.outcome === 'not_started'
              ? reason
              : null,
        })
      },
    )

    it('keeps hidden terminal outcome and refusal neutral', () => {
      const { window } = setup({
        ...progress({ state: 'end', given: true, received: true }),
        stage: HIDDEN_VALUE,
        outcome: HIDDEN_VALUE,
        refusal: HIDDEN_VALUE,
      })
      window.open()
      expect(current(window)).toMatchObject({
        status: 'Merge finished',
        success: false,
        reason: null,
      })
    })
  })

  describe('success requires an explicit completed end', () => {
    it.each<HilosAccountMergeProgress['state']>([
      'preparing',
      'wait',
      'refusal',
    ])(
      'does not infer success from given, received or all done in %s',
      (state) => {
        const { window } = setup(
          progress({
            state,
            given: true,
            received: true,
            outcome: 'completed',
            parts: [
              { participant: 'give', owner: 'user', userId: 8, done: true },
            ],
            waits: [],
          }),
        )
        window.open()
        expect(current(window).success).toBe(false)
      },
    )

    it('does not turn a tombstone or missing progress into success', () => {
      const { window, update, page } = setup()
      window.open()
      page.entities.upsert({ type: USER_ENTITY_TYPE, id: 7 }, { merged: true })
      update(null)
      expect(window.opened.get()).toBe(true)
      expect(window.view.get()).toEqual({
        kind: 'unavailable',
        message: 'Merge progress is unavailable.',
      })
    })
  })

  describe('opening, recovery and operation identity', () => {
    it('needs both a card row and readable current progress', () => {
      const { window, update, scopes } = setup(null)
      expect(window.open()).toBe(false)
      expect(window.view.get()).toBeNull()
      update(progress())
      scopes.dropPage()
      expect(window.open()).toBe(false)
      expect(window.opened.get()).toBe(false)
    })

    it('forgets private content while unreadable and recovers the same id', () => {
      const { window, frame } = setup()
      window.open()
      for (const unreadable of [null, HIDDEN_VALUE, { state: 'wait' }]) {
        frame(unreadable)
        expect(window.opened.get()).toBe(true)
        expect(window.view.get()).toEqual({
          kind: 'unavailable',
          message: 'Merge progress is unavailable.',
        })
        frame(progress({ state: 'wait', choice: null, waits: [] }))
        expect(current(window).progress.choice).toBeNull()
        expect(current(window).status).toBe('Merge in progress')
      }
    })

    it('latches a different id until close and a new open', () => {
      const { window, frame } = setup()
      window.open()
      frame(null)
      frame(progress({ operationId: 42 }))
      expect(window.view.get()).toEqual({
        kind: 'changed',
        message:
          'The merge operation has changed. Close this window and open it again.',
      })
      frame(progress())
      window.open()
      expect(window.view.get()?.kind).toBe('changed')
      frame(hiddenProgress())
      expect(window.view.get()?.kind).toBe('changed')
      frame(progress({ operationId: 42 }))
      window.close()
      expect(window.open()).toBe(true)
      expect(current(window).progress.operationId).toBe(42)
    })

    it('uses hidden current facts without publishing the previously bound id', () => {
      const { window, frame } = setup()
      window.open()
      frame(hiddenProgress())
      expect(current(window).progress).toMatchObject({
        operationId: null,
        survivorUserId: null,
        loserUserId: null,
        choice: null,
      })
      expect(current(window).pendingParts).toEqual([
        {
          key: '["give","user",null]',
          participant: 'Transfer account data',
          account: null,
        },
      ])
      frame(progress())
      expect(window.view.get()?.kind).toBe('progress')
      frame(progress({ operationId: 42 }))
      expect(window.view.get()?.kind).toBe('changed')
    })

    it('binds the first available id after opening with hidden identity', () => {
      const { window, frame } = setup(hiddenProgress())
      window.open()
      frame(progress({ operationId: 42 }))
      expect(current(window).progress.operationId).toBe(42)
      frame(progress())
      expect(window.view.get()?.kind).toBe('changed')
    })
  })

  describe('recorded choices stay readonly facts', () => {
    it('keeps the saved expectations when current account data changes', () => {
      const { window, page, frame } = setup()
      window.open()
      page.entities.upsert(
        { type: USER_ENTITY_TYPE, id: 7 },
        {
          hasSecondFactor: false,
          hasPassword: false,
        },
      )
      page.entities.upsert(
        { type: USER_ENTITY_TYPE, id: 8 },
        {
          hasSecondFactor: false,
          hasPassword: false,
        },
      )
      expect(current(window).progress.choice).toEqual({
        passwordFate: 'loser',
        secondFactorFate: 'both',
        expectedSurvivorHasSecondFactor: true,
        expectedLoserHasSecondFactor: true,
      })
      frame(
        progress({
          choice: {
            passwordFate: 'none',
            secondFactorFate: 'survivor',
            expectedSurvivorHasSecondFactor: true,
            expectedLoserHasSecondFactor: false,
          },
        }),
      )
      expect(current(window).progress.choice).toEqual({
        passwordFate: 'none',
        secondFactorFate: 'survivor',
        expectedSurvivorHasSecondFactor: true,
        expectedLoserHasSecondFactor: false,
      })
    })

    it('does not turn hidden or absent fates and expectations into none or false', () => {
      const { window } = setup({
        ...progress(),
        choice: {
          passwordFate: HIDDEN_VALUE,
          secondFactorFate: null,
          expectedSurvivorHasSecondFactor: HIDDEN_VALUE,
          expectedLoserHasSecondFactor: null,
        },
      })
      window.open()
      expect(current(window).progress.choice).toEqual({
        passwordFate: null,
        secondFactorFate: null,
        expectedSurvivorHasSecondFactor: null,
        expectedLoserHasSecondFactor: null,
      })
    })
  })

  describe('live admin access and frame ordering', () => {
    it.each(['session first', 'page first'])(
      'releases private data on downgrade with %s',
      (order) => {
        const { window, frame, access } = setup()
        window.open()
        const seen: (HilosAccountMergeWindowView | null)[] = []
        const off = subscribeSignal(window.view, (view) => seen.push(view))
        releases.push(off)
        if (order === 'page first') frame(hiddenProgress())
        seen.length = 0
        access('view')
        if (order === 'session first') {
          expect(window.view.get()?.kind).toBe('unavailable')
          frame(hiddenProgress())
        }
        expect(current(window).progress).toMatchObject({
          operationId: null,
          survivorUserId: null,
          loserUserId: null,
          choice: null,
          refusal: null,
          stage: null,
          outcome: null,
        })
        expect(current(window).waits).toEqual([
          {
            key: '["sessions",null]',
            participant: 'Close source sessions',
            account: null,
          },
        ])
        for (const view of seen) {
          if (view?.kind === 'progress') {
            expect(view.progress.choice).toBeNull()
            expect(view.progress.survivorUserId).toBeNull()
            expect(view.reason).toBeNull()
          }
        }
        frame(progress())
        expect(window.view.get()?.kind).toBe('unavailable')
        frame(hiddenProgress())
        expect(window.view.get()?.kind).toBe('progress')
      },
    )

    it('does not restore a stale private snapshot when full access returns', () => {
      const { window, access, frame } = setup(
        progress({
          state: 'refusal',
          refusal: { code: 'occupied', reason: 'Please wait.' },
        }),
      )
      window.open()
      access('view')
      expect(window.view.get()?.kind).toBe('unavailable')
      access('full')
      expect(window.view.get()?.kind).toBe('unavailable')
      frame(progress({ state: 'wait', choice: null, refusal: null }))
      expect(current(window)).toMatchObject({
        reason: null,
        progress: { choice: null, refusal: null },
      })
    })

    it('opens directly on server-withheld viewer progress', () => {
      const { window, access } = setup(hiddenProgress())
      access('view')
      expect(window.open()).toBe(true)
      expect(current(window).progress.operationId).toBeNull()
    })

    it('closes on none and cannot be revived by later access or page frames', () => {
      const { window, access, frame } = setup()
      window.open()
      access('none')
      expect(window.opened.get()).toBe(false)
      expect(window.view.get()).toBeNull()
      expect(window.open()).toBe(false)
      frame(progress())
      access('full')
      expect(window.view.get()).toBeNull()
      expect(window.opened.get()).toBe(false)
    })
  })

  describe('independent parts, waits and cancellation', () => {
    it('keeps unfinished parts when no wait was sent and waits when no part remains', () => {
      const { window, frame } = setup(progress({ waits: [] }))
      window.open()
      expect(current(window).pendingParts).toEqual([
        {
          key: '["give","user",8]',
          participant: 'Transfer account data',
          account: 8,
        },
      ])
      expect(current(window).waits).toEqual([])
      expect(current(window).success).toBe(false)
      frame(progress({ parts: [] }))
      expect(current(window).pendingParts).toEqual([])
      expect(current(window).waits).toEqual([
        {
          key: '["sessions",8]',
          participant: 'Close source sessions',
          account: 8,
        },
      ])
      expect(current(window).success).toBe(false)
    })

    it('projects only release duties while cancelling without changing the source', () => {
      const source = progress({
        state: 'wait',
        stage: 'cancelling',
        parts: [
          {
            participant: 'prepare_loser',
            owner: 'user',
            userId: 8,
            done: false,
          },
          { participant: 'give', owner: 'user', userId: 8, done: false },
          {
            participant: 'release_loser',
            owner: 'user',
            userId: 8,
            done: false,
          },
          {
            participant: 'release_survivor',
            owner: 'user',
            userId: 7,
            done: false,
          },
        ],
        waits: [{ participant: 'release_loser', userId: 8 }],
      })
      const { window, standing } = setup(source)
      window.open()
      expect(current(window).status).toBe('Releasing merge reservations')
      expect(current(window).pendingParts).toEqual([
        {
          key: '["release_loser","user",8]',
          participant: 'Release source reservation',
          account: 8,
        },
        {
          key: '["release_survivor","user",7]',
          participant: 'Release receiving reservation',
          account: null,
        },
      ])
      expect(current(window).progress).toEqual(source)
      expect(standing.merge.get()).toEqual(source)
    })

    it.each<HilosAccountMergeProgress['state']>(['refusal', 'end'])(
      'keeps a closed not_started reason without eternal release waits in %s',
      (state) => {
        const { window } = setup(
          progress({
            state,
            stage: 'cancelling',
            outcome: 'not_started',
            refusal: {
              code: 'occupied',
              reason: 'The other operation holds this account.',
            },
            parts: [
              {
                participant: 'release_loser',
                owner: 'user',
                userId: 8,
                done: false,
              },
            ],
            waits: [{ participant: 'release_loser', userId: 8 }],
          }),
        )
        window.open()
        expect(current(window)).toMatchObject({
          status: 'Merge not started',
          reason: 'The other operation holds this account.',
          pendingParts: [],
          waits: [],
          success: false,
        })
      },
    )

    it('uses only recorded facts when stage and outcome are hidden', () => {
      const { window } = setup(hiddenProgress())
      window.open()
      expect(current(window)).toMatchObject({
        status: 'Preparing merge',
        success: false,
      })
      expect(current(window).pendingParts[0]?.participant).toBe(
        'Transfer account data',
      )
      expect(current(window).waits[0]?.participant).toBe(
        'Close source sessions',
      )
    })
  })

  describe('local open and close', () => {
    it('sends no action or viewport and creates no transport subscription', () => {
      const { window, connection, socket, update } = setup()
      const subscriptions = vi.spyOn(connection, 'on')
      const sendAction = vi.spyOn(connection, 'sendAction')
      window.open()
      window.close()
      update(progress({ operationId: 42, choice: null }))
      expect(window.open()).toBe(true)
      expect(current(window).progress.operationId).toBe(42)
      expect(current(window).progress.choice).toBeNull()
      window.dispose()
      expect(socket.send).not.toHaveBeenCalled()
      expect(sendAction).not.toHaveBeenCalled()
      expect(subscriptions).not.toHaveBeenCalled()
    })

    it('releases local subscriptions on close and observes fresh data on reopen', () => {
      const { window, frame, readMerge } = setup()
      window.open()
      const off = subscribeSignal(window.view, () => {})
      releases.push(off)
      window.close()
      readMerge.mockClear()
      frame(progress({ operationId: 42 }))
      expect(readMerge).not.toHaveBeenCalled()
      expect(window.view.get()).toBeNull()
      window.open()
      expect(current(window).progress.operationId).toBe(42)
    })
  })

  describe('navigation, row loss and disposal', () => {
    it('closes when a different card replaces the bound row', () => {
      const { window, scopes, frame } = setup()
      window.open()
      const next = scopes.openPage(HilosPages.USER)
      next.tables.upsert('userDetail', 8, {
        users: { type: USER_ENTITY_TYPE, id: 8 },
      })
      next.data.set(HILOS_USER_MERGE_SECTION, progress({ operationId: 42 }))
      frame(progress(), 7)
      expect(window.opened.get()).toBe(false)
      expect(window.view.get()).toBeNull()
    })

    it('closes and releases subscriptions when the shown row disappears', () => {
      const { window, scopes, frame, readMerge } = setup()
      window.open()
      scopes.dropPage()
      expect(window.opened.get()).toBe(false)
      readMerge.mockClear()
      frame(progress())
      expect(readMerge).not.toHaveBeenCalled()
      expect(window.view.get()).toBeNull()
    })

    it('disposes idempotently and ignores late source and access changes', () => {
      const { window, frame, access, readMerge } = setup()
      window.open()
      const seen = vi.fn()
      const off = subscribeSignal(window.view, seen)
      releases.push(off)
      window.dispose()
      window.dispose()
      seen.mockClear()
      readMerge.mockClear()
      frame(progress({ operationId: 42 }))
      access('view')
      frame(hiddenProgress())
      access('full')
      expect(seen).not.toHaveBeenCalled()
      expect(readMerge).not.toHaveBeenCalled()
      expect(window.opened.get()).toBe(false)
      expect(window.view.get()).toBeNull()
      expect(window.open()).toBe(false)
    })
  })
})
