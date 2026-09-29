import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionLifecycle,
  type ActionLifecycleSource,
} from '../../src/connection/actionLifecycle.js'
import { HILOS_ACCOUNT_DELETION_CANCEL_ACTION } from '../../src/profile/accountDeletion.js'
import { HilosPages } from '../../src/routing/hilosPages.js'
import {
  ACCOUNT_FROZEN_ERROR_CODE,
  ACCOUNT_STANDING_STRIP_COPY,
  bindAccountStanding,
  formatHilosDeletionStrip,
  hilosAccountStanding,
  hilosDeletionStrip,
  HILOS_FROZEN_OPEN_PAGES,
  hilosSessionAvatarMark,
  hilosStandingTone,
  keepMyAccount,
} from '../../src/session/accountStanding.js'
import {
  bindImpersonation,
  hilosImpersonation,
} from '../../src/session/impersonation.js'
import { applyServerTime } from '../../src/session/serverClock.js'
import { bindSessionScope } from '../../src/session/sessionScope.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { type HilosConnection, type ProjectSignal } from '../../src/index.js'

/** One day, in ms. */
const DAY_MS = 86_400_000

/** A browser clock parked at a known moment. */
const NOW = 1_800_000_000_000

/** A connection double replaying handshake responses into the session scope. */
function fakeConnection() {
  const projectListeners: Array<(signal: ProjectSignal) => void> = []

  return {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal') {
        projectListeners.push(listener as (signal: ProjectSignal) => void)
      }

      return () => {}
    },
    emitHandshakeResponse(payload: Record<string, unknown>): void {
      const signal = {
        kind: 'project',
        type: 'handshake_response',
        data: payload,
        envelope: {},
      } as unknown as ProjectSignal
      for (const listener of projectListeners) {
        listener(signal)
      }
    },
  }
}

/** A lifecycle source that records what was sent and never answers. */
function recordingSource(): ActionLifecycleSource & {
  readonly sent: { action: string; data: unknown; requestId?: string }[]
} {
  const sent: { action: string; data: unknown; requestId?: string }[] = []

  return {
    sent,
    sendAction(action: string, data: unknown, requestId?: string): boolean {
      sent.push({ action, data, requestId })

      return true
    },
    on(): () => void {
      return () => {}
    },
  }
}

/**
 * A standing as the wire carries it.
 *
 * @param shown The standing shown.
 * @param facts The facts beside it.
 */
function standing(
  shown: string,
  facts: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    shown,
    blocked: false,
    frozen: false,
    deletionEffectiveAt: null,
    lapsed: [],
    ...facts,
  }
}

/**
 * A handshake of Bob's session with the standing given.
 *
 * @param accountStanding The standing the handshake stamps.
 * @param impersonatedBy Whether Ada stands behind the session.
 */
function handshake(
  accountStanding: Record<string, unknown> | null,
  impersonatedBy = false,
): Record<string, unknown> {
  return {
    data: { serverTimeMs: NOW, accountStanding },
    entities: {
      currentUser: { id: 2, name: 'Bob' },
      impersonatedBy: impersonatedBy ? { id: 1, name: 'Ada' } : null,
    },
  }
}

/** Boot the pieces the shell reads: a session scope and a bound lifecycle. */
function bind() {
  const connection = fakeConnection()
  const scopes = new ScopeManager()
  bindSessionScope(connection as unknown as HilosConnection, scopes)
  const source = recordingSource()
  const actions = new ActionLifecycle(source)
  const unbindStanding = bindAccountStanding(scopes, actions)
  const unbindStrip = bindImpersonation(scopes, actions)

  return {
    connection,
    source,
    unbind(): void {
      unbindStanding()
      unbindStrip()
    },
  }
}

describe('the session standing (HIL-945)', () => {
  let unbind: (() => void) | undefined

  afterEach(() => {
    unbind?.()
    unbind = undefined
    applyServerTime(Date.now())
    vi.useRealTimers()
  })

  it('is null before any bind and for an anonymous session', () => {
    expect(hilosAccountStanding.get()).toBeNull()
    expect(hilosDeletionStrip.get()).toBeNull()
    expect(hilosSessionAvatarMark.get()).toBeNull()

    const booted = bind()
    unbind = booted.unbind
    booted.connection.emitHandshakeResponse({
      data: { accountStanding: null },
      entities: { currentUser: null },
    })

    expect(hilosAccountStanding.get()).toBeNull()
    expect(hilosDeletionStrip.get()).toBeNull()
    expect(hilosSessionAvatarMark.get()).toBeNull()
  })

  it('reads the standing whole and draws nothing for a plain account', () => {
    const booted = bind()
    unbind = booted.unbind

    booted.connection.emitHandshakeResponse(
      handshake(
        standing('none', {
          lapsed: [{ document: 'terms', deadline: '2026-09-01' }],
        }),
      ),
    )

    expect(hilosAccountStanding.get()).toEqual({
      shown: 'none',
      blocked: false,
      frozen: false,
      deletionEffectiveAt: null,
      lapsed: [{ document: 'terms', deadline: '2026-09-01' }],
    })
    expect(hilosDeletionStrip.get()).toBeNull()
    expect(hilosSessionAvatarMark.get()).toBeNull()
  })

  it('puts up the deletion strip and the yellow trash mark for its own deletion', () => {
    vi.useFakeTimers()
    vi.setSystemTime(NOW)
    const booted = bind()
    unbind = booted.unbind

    booted.connection.emitHandshakeResponse(
      handshake(
        standing('deletion_scheduled', {
          deletionEffectiveAt: NOW + 12 * DAY_MS,
        }),
      ),
    )

    expect(hilosDeletionStrip.get()).toEqual({ effectiveAt: NOW + 12 * DAY_MS })
    expect(hilosSessionAvatarMark.get()).toEqual({
      tone: 'warning',
      icon: 'bi-trash',
    })

    // The next handshake without the deletion takes both down.
    booted.connection.emitHandshakeResponse(handshake(standing('none')))
    expect(hilosDeletionStrip.get()).toBeNull()
    expect(hilosSessionAvatarMark.get()).toBeNull()
  })

  it('draws no strip and no mark of their own for a block or a freeze', () => {
    const booted = bind()
    unbind = booted.unbind

    for (const shown of ['blocked', 'frozen']) {
      booted.connection.emitHandshakeResponse(
        handshake(
          standing(shown, {
            blocked: shown === 'blocked',
            frozen: shown === 'frozen',
            deletionEffectiveAt: NOW + DAY_MS,
          }),
        ),
      )

      expect(hilosDeletionStrip.get()).toBeNull()
      expect(hilosSessionAvatarMark.get()).toBeNull()
    }
  })

  it.each([
    ['none', 'warning'],
    ['deletion_scheduled', 'warning'],
    ['blocked', 'danger'],
    ['frozen', 'info'],
  ] as const)(
    'under a takeover of a %s person colors the strip and the mark %s, and drops the deletion strip',
    (shown, tone) => {
      const booted = bind()
      unbind = booted.unbind

      booted.connection.emitHandshakeResponse(
        handshake(
          standing(shown, {
            deletionEffectiveAt:
              shown === 'deletion_scheduled' ? NOW + DAY_MS : null,
          }),
          true,
        ),
      )

      expect(hilosImpersonation.get()).toEqual({ userName: 'Bob', tone })
      expect(hilosSessionAvatarMark.get()).toEqual({
        tone,
        icon: 'bi-people-fill',
      })
      expect(hilosDeletionStrip.get()).toBeNull()
    },
  )

  it('is null again once unbound', () => {
    const booted = bind()
    booted.connection.emitHandshakeResponse(
      handshake(
        standing('deletion_scheduled', { deletionEffectiveAt: NOW + DAY_MS }),
      ),
    )

    booted.unbind()

    expect(hilosAccountStanding.get()).toBeNull()
    expect(hilosDeletionStrip.get()).toBeNull()
    expect(hilosSessionAvatarMark.get()).toBeNull()
  })
})

describe('hilosStandingTone', () => {
  it('is red for a block, blue for a freeze and yellow otherwise', () => {
    expect(hilosStandingTone('blocked')).toBe('danger')
    expect(hilosStandingTone('frozen')).toBe('info')
    expect(hilosStandingTone('deletion_scheduled')).toBe('warning')
    expect(hilosStandingTone('none')).toBe('warning')
  })
})

describe('formatHilosDeletionStrip', () => {
  it('names the date and the whole days left, never below one', () => {
    const effectiveAt = NOW + 11 * DAY_MS + 1
    const date = new Date(effectiveAt).toLocaleDateString(undefined, {
      dateStyle: 'long',
    })

    expect(formatHilosDeletionStrip({ effectiveAt }, NOW)).toBe(
      `Your account will be deleted on ${date} — 12 days left`,
    )
    expect(
      formatHilosDeletionStrip({ effectiveAt }, effectiveAt + DAY_MS),
    ).toBe(`Your account will be deleted on ${date} — 1 day left`)
    expect(ACCOUNT_STANDING_STRIP_COPY.keep).toBe('Keep my account')
  })
})

describe('keepMyAccount', () => {
  it('dispatches the deletion cancel with an empty payload and a request id on the bound lifecycle', () => {
    const booted = bind()
    try {
      const handle = keepMyAccount()
      // Never answered here; the driver's timeout must not surface as unhandled.
      handle.done.catch(() => {})

      expect(booted.source.sent).toEqual([
        {
          action: HILOS_ACCOUNT_DELETION_CANCEL_ACTION,
          data: {},
          requestId: handle.requestId,
        },
      ])
      expect(handle.requestId).toBeTruthy()
    } finally {
      booted.unbind()
    }
  })

  it('throws before any bind', () => {
    expect(() => keepMyAccount()).toThrow(/bindAccountStanding/)
  })
})

describe('what the freeze screen is handed (HIL-500)', () => {
  it('names the refusal code and the pages a freeze leaves open', () => {
    expect(ACCOUNT_FROZEN_ERROR_CODE).toBe('account_frozen')
    expect(HILOS_FROZEN_OPEN_PAGES).toEqual([
      HilosPages.PROFILE_DATA,
      HilosPages.PROFILE_AGREEMENTS,
      HilosPages.PROFILE_AGREEMENTS_HISTORY,
      HilosPages.ABOUT,
      HilosPages.TERMS,
      HilosPages.PRIVACY,
      HilosPages.LICENSE,
    ])
  })
})
