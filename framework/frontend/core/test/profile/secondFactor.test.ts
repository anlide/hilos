// Covers the profile's second-factor section (HIL-494): the section read off
// the wire with its moments on the local scale, the store that takes it from the
// page and from the person's group, the administrator's policy frame laid over
// an older section and not over a newer one, and the profile actions with the
// replies they read — including the empty reply a PHP array with nothing in it
// becomes.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  createHilosSecondFactorActions,
  createHilosSecondFactorStore,
  readHilosSecondFactorState,
  SIGNAL_SECOND_FACTOR_STATE,
} from '../../src/profile/secondFactor.js'
import { type ActionLifecycle } from '../../src/connection/actionLifecycle.js'
import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import { type ProjectSignal } from '../../src/protocol/parseSignal.js'
import { applyServerTime } from '../../src/session/serverClock.js'
import {
  bindSessionScope,
  SIGNAL_SECOND_FACTOR_POLICY,
} from '../../src/session/sessionScope.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'

/** A browser clock parked at a known moment. */
const LOCAL_NOW = 1_700_000_000_000

/** How far ahead of this browser the server's clock runs, in ms. */
const SERVER_DRIFT_MS = 45_000

/** A section as the backend sends it, its moments on the server's scale. */
function wireSection(overrides: Record<string, unknown> = {}) {
  return {
    authenticators: [
      {
        id: 7,
        label: 'Work phone',
        createdAt: LOCAL_NOW + SERVER_DRIFT_MS,
        lastUsedAt: null,
      },
    ],
    backupCodesLeft: 9,
    backupCodesTotal: 10,
    required: false,
    resetWait: {
      days: 8,
      pendingDays: 2,
      pendingFrom: LOCAL_NOW + SERVER_DRIFT_MS + 1_000,
      defaultDays: 8,
      minDays: 1,
      maxDays: 30,
    },
    reset: {
      requestedAt: LOCAL_NOW + SERVER_DRIFT_MS,
      effectiveAt: LOCAL_NOW + SERVER_DRIFT_MS + 5_000,
    },
    ...overrides,
  }
}

/** A connection double that replays project signals to its listeners. */
function fakeConnection() {
  const listeners: Array<(signal: ProjectSignal) => void> = []

  return {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event !== 'projectSignal') {
        return () => {}
      }
      const typed = listener as (signal: ProjectSignal) => void
      listeners.push(typed)

      return () => {
        listeners.splice(listeners.indexOf(typed), 1)
      }
    },
    emit(type: string, data: unknown): void {
      const signal = { kind: 'project', type, data } as unknown as ProjectSignal
      for (const listener of [...listeners]) {
        listener(signal)
      }
    },
    listenerCount(): number {
      return listeners.length
    },
  }
}

/** A store over a fake connection whose session scope takes the policy frame. */
function storeSetup() {
  const connection = fakeConnection()
  const scopes = new ScopeManager()
  bindSessionScope(connection as unknown as HilosConnection, scopes)
  const store = createHilosSecondFactorStore({
    connection: connection as unknown as HilosConnection,
    scopes,
    actions: {} as ActionLifecycle,
  })

  return { connection, store }
}

const POLICY = {
  required: 'none',
  trustDays: 30,
  backupCodes: 10,
  resetWaitDefaultDays: 8,
  resetWaitMinDays: 1,
  resetWaitMaxDays: 30,
}

beforeEach(() => {
  applyServerTime(Date.now() + SERVER_DRIFT_MS)
})

afterEach(() => {
  applyServerTime(Date.now())
})

describe('readHilosSecondFactorState', () => {
  it('puts every moment of the section on the local scale', () => {
    vi.useFakeTimers()
    vi.setSystemTime(LOCAL_NOW)
    try {
      applyServerTime(LOCAL_NOW + SERVER_DRIFT_MS)
      const state = readHilosSecondFactorState(wireSection())

      expect(state?.authenticators).toStrictEqual([
        { id: 7, label: 'Work phone', createdAt: LOCAL_NOW, lastUsedAt: null },
      ])
      expect(state?.resetWait.pendingFrom).toBe(LOCAL_NOW + 1_000)
      expect(state?.reset).toStrictEqual({
        requestedAt: LOCAL_NOW,
        effectiveAt: LOCAL_NOW + 5_000,
      })
    } finally {
      vi.useRealTimers()
    }
  })

  it('reads a section with no factor and no removal', () => {
    const state = readHilosSecondFactorState(
      wireSection({
        authenticators: [],
        backupCodesLeft: 0,
        backupCodesTotal: 0,
        reset: null,
      }),
    )

    expect(state?.authenticators).toStrictEqual([])
    expect(state?.reset).toBeNull()
  })

  it('refuses half a section rather than draw a factor that is not there', () => {
    expect(readHilosSecondFactorState(null)).toBeNull()
    expect(
      readHilosSecondFactorState(wireSection({ resetWait: undefined })),
    ).toBeNull()
    expect(
      readHilosSecondFactorState(
        wireSection({ authenticators: [{ id: 'seven' }] }),
      ),
    ).toBeNull()
  })
})

describe('the section store', () => {
  it('takes the section from the page and then from the frames of the group', () => {
    const { connection, store } = storeSetup()
    expect(store.state.get()).toBeNull()

    store.applyState(wireSection())
    expect(store.state.get()?.backupCodesLeft).toBe(9)

    store.start()
    connection.emit(
      SIGNAL_SECOND_FACTOR_STATE,
      wireSection({ backupCodesLeft: 8 }),
    )
    expect(store.state.get()?.backupCodesLeft).toBe(8)

    store.dispose()
    expect(store.state.get()).toBeNull()
    connection.emit(SIGNAL_SECOND_FACTOR_STATE, wireSection())
    expect(store.state.get()).toBeNull()
  })

  it('takes the section the page subscription put in the page data', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const store = createHilosSecondFactorStore({
      connection: connection as unknown as HilosConnection,
      scopes,
      actions: {} as ActionLifecycle,
    })
    scopes
      .openPage('hilos_profile_security')
      .data.set('secondFactor', wireSection({ backupCodesLeft: 4 }))
    store.start()

    expect(store.state.get()?.backupCodesLeft).toBe(4)

    scopes.page()?.data.set('secondFactor', wireSection({ backupCodesLeft: 3 }))
    expect(store.state.get()?.backupCodesLeft).toBe(3)
    store.dispose()
  })

  it('keeps one listener however often it is started', () => {
    const { connection, store } = storeSetup()
    const before = connection.listenerCount()
    store.start()
    store.start()

    expect(connection.listenerCount()).toBe(before + 1)
    store.dispose()
    expect(connection.listenerCount()).toBe(before)
  })

  it('ignores a copy that is not a section', () => {
    const { store } = storeSetup()
    store.applyState(wireSection())
    store.applyState({ broken: true })

    expect(store.state.get()?.backupCodesLeft).toBe(9)
  })

  it('lays a newer policy frame over the section, and not the one it was sent under', () => {
    const { connection, store } = storeSetup()
    connection.emit(SIGNAL_SECOND_FACTOR_POLICY, POLICY)
    store.applyState(wireSection())
    // The frame that stood when the section came is what it was computed under.
    expect(store.state.get()?.resetWait.minDays).toBe(1)

    connection.emit(SIGNAL_SECOND_FACTOR_POLICY, {
      ...POLICY,
      required: 'everyone',
      resetWaitMinDays: 3,
      resetWaitMaxDays: 20,
    })
    expect(store.state.get()).toMatchObject({
      required: true,
      resetWait: { days: 8, minDays: 3, maxDays: 20 },
    })

    // A new copy of the section is the server's word again.
    store.applyState(wireSection())
    expect(store.state.get()).toMatchObject({
      required: false,
      resetWait: { minDays: 1 },
    })
  })

  it('leaves "required" to the next section when the policy turns on who the administrators are', () => {
    const { connection, store } = storeSetup()
    store.applyState(wireSection({ required: true }))

    connection.emit(SIGNAL_SECOND_FACTOR_POLICY, {
      ...POLICY,
      required: 'admins',
    })
    expect(store.state.get()?.required).toBe(true)

    connection.emit(SIGNAL_SECOND_FACTOR_POLICY, { ...POLICY })
    expect(store.state.get()?.required).toBe(false)
  })
})

describe('the profile actions', () => {
  /**
   * A lifecycle recording every dispatch and answering each with the given
   * reply, validated by the schema the caller hands it.
   *
   * @param wire The reply as the backend sends it.
   */
  function recordingLifecycle(wire: unknown) {
    const sent: Array<{ name: string; payload: unknown }> = []
    const actions = {
      dispatch: (
        name: string,
        payload: unknown,
        options: { replySchema?: { parse(value: unknown): unknown } } = {},
      ) => {
        sent.push({ name, payload })

        return {
          done: Promise.resolve({
            reply:
              options.replySchema === undefined
                ? wire
                : options.replySchema.parse(wire),
          }),
        }
      },
    } as unknown as ActionLifecycle

    return { actions, sent }
  }

  it('sends each action with its proof under the keys the server reads', async () => {
    const { actions, sent } = recordingLifecycle([])
    const profile = createHilosSecondFactorActions({ actions })
    const proof = { code: '123456', backupCode: false }

    await profile.enrollConfirm(7, '654321', 'Work phone').done
    await profile.remove(7, proof).done
    await profile.setResetWait(5).done
    await profile.requestReset().done
    await profile.cancelReset().done

    expect(sent).toStrictEqual([
      {
        name: 'profile_second_factor_enroll_confirm',
        payload: { authenticatorId: 7, code: '654321', label: 'Work phone' },
      },
      {
        name: 'profile_second_factor_remove',
        payload: {
          authenticatorId: 7,
          proofCode: '123456',
          proofBackup: false,
        },
      },
      { name: 'profile_second_factor_reset_wait_set', payload: { days: 5 } },
      { name: 'profile_second_factor_reset_request', payload: {} },
      { name: 'profile_second_factor_reset_cancel', payload: {} },
    ])
  })

  it('starts the first enrolment with no proof, and a further one with it', async () => {
    const { actions, sent } = recordingLifecycle({
      authenticatorId: 8,
      secret: 'JBSWY3DP',
      otpauthUri: 'otpauth://totp/x',
    })
    const profile = createHilosSecondFactorActions({ actions })

    await expect(profile.enrollStart(null).done).resolves.toStrictEqual({
      reply: {
        authenticatorId: 8,
        secret: 'JBSWY3DP',
        otpauthUri: 'otpauth://totp/x',
      },
    })
    await profile.enrollStart({ code: 'abcde-fghjk', backupCode: true }).done

    expect(sent.map((entry) => entry.payload)).toStrictEqual([
      { proofCode: null, proofBackup: false },
      { proofCode: 'abcde-fghjk', proofBackup: true },
    ])
  })

  it('reads a further app that brings no codes: the empty reply', async () => {
    const { actions } = recordingLifecycle([])
    const profile = createHilosSecondFactorActions({ actions })

    await expect(
      profile.enrollConfirm(8, '123456', '').done,
    ).resolves.toStrictEqual({ reply: {} })
  })

  it('reads the listed codes and a new set', async () => {
    const listed = recordingLifecycle({
      codes: [
        { code: 'abcde-fghjk', used: true },
        { code: 'mnpqr-stuvw', used: false },
      ],
    })
    await expect(
      createHilosSecondFactorActions({ actions: listed.actions }).showCodes({
        code: '123456',
        backupCode: false,
      }).done,
    ).resolves.toMatchObject({
      reply: { codes: [{ used: true }, { used: false }] },
    })

    const renewed = recordingLifecycle({ backupCodes: ['xyz23-45678'] })
    await expect(
      createHilosSecondFactorActions({ actions: renewed.actions }).renewCodes({
        code: '123457',
        backupCode: false,
      }).done,
    ).resolves.toStrictEqual({ reply: { backupCodes: ['xyz23-45678'] } })
    expect(renewed.sent[0]?.name).toBe('profile_second_factor_codes_renew')
  })
})
