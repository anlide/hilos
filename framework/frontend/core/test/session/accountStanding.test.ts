import { hilosTimeZone } from '../../src/timeZone/timeZoneState.js'
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
    deletionGraceDays: null,
    lapsed: [],
    window: [],
    mergedInto: null,
    mergedIntoName: null,
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
    scopes,
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
      deletionGraceDays: null,
      lapsed: [{ document: 'terms', deadline: '2026-09-01' }],
      window: [],
      mergedInto: null,
      mergedIntoName: null,
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

    expect(hilosDeletionStrip.get()).toEqual({
      effectiveAt: NOW + 12 * DAY_MS,
      deletionGraceDays: null,
    })
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
    'under a takeover of a %s person colors the strip and the mark %s, and projects their deletion',
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

      expect(hilosImpersonation.get()).toEqual({
        userName: 'Bob',
        tone,
        viewOnly: false,
      })
      expect(hilosSessionAvatarMark.get()).toEqual({
        tone,
        icon: 'bi-people-fill',
      })
      expect(hilosDeletionStrip.get()).toEqual(
        shown === 'deletion_scheduled'
          ? { effectiveAt: NOW + DAY_MS, deletionGraceDays: null }
          : null,
      )
    },
  )

  it.each([false, true])(
    'draws no deletion strip without an erasure moment (takeover %s)',
    (impersonatedBy) => {
      const booted = bind()
      unbind = booted.unbind
      booted.connection.emitHandshakeResponse(
        handshake(standing('deletion_scheduled'), impersonatedBy),
      )

      expect(hilosDeletionStrip.get()).toBeNull()
    },
  )

  it('follows the current identity through takeover, return and sign-out', () => {
    const booted = bind()
    unbind = booted.unbind
    booted.connection.emitHandshakeResponse(
      handshake(
        standing('deletion_scheduled', { deletionEffectiveAt: NOW + DAY_MS }),
        true,
      ),
    )
    expect(hilosDeletionStrip.get()).toEqual({
      effectiveAt: NOW + DAY_MS,
      deletionGraceDays: null,
    })

    booted.connection.emitHandshakeResponse({
      ...handshake(
        standing('deletion_scheduled', {
          deletionEffectiveAt: NOW + 12 * DAY_MS,
        }),
      ),
      entities: {
        currentUser: { id: 1, name: 'Ada' },
        impersonatedBy: null,
      },
    })
    expect(hilosDeletionStrip.get()).toEqual({
      effectiveAt: NOW + 12 * DAY_MS,
      deletionGraceDays: null,
    })
    expect(hilosSessionAvatarMark.get()).toEqual({
      tone: 'warning',
      icon: 'bi-trash',
    })

    booted.connection.emitHandshakeResponse(handshake(standing('none'), true))
    expect(hilosDeletionStrip.get()).toBeNull()

    booted.connection.emitHandshakeResponse(
      handshake(
        standing('deletion_scheduled', { deletionEffectiveAt: NOW + DAY_MS }),
        true,
      ),
    )
    expect(hilosDeletionStrip.get()).toEqual({
      effectiveAt: NOW + DAY_MS,
      deletionGraceDays: null,
    })
    booted.connection.emitHandshakeResponse({
      data: { accountStanding: null },
      entities: { currentUser: null, impersonatedBy: null },
    })
    expect(hilosAccountStanding.get()).toBeNull()
    expect(hilosDeletionStrip.get()).toBeNull()
    expect(hilosSessionAvatarMark.get()).toBeNull()
  })

  it('forgets a deletion when the session standing is reset', () => {
    const booted = bind()
    unbind = booted.unbind
    booted.connection.emitHandshakeResponse(
      handshake(
        standing('deletion_scheduled', { deletionEffectiveAt: NOW + DAY_MS }),
        true,
      ),
    )
    expect(hilosDeletionStrip.get()).toEqual({
      effectiveAt: NOW + DAY_MS,
      deletionGraceDays: null,
    })

    booted.scopes.session.data.set('accountStanding', undefined)
    expect(hilosAccountStanding.get()).toBeNull()
    expect(hilosDeletionStrip.get()).toBeNull()
  })

  it('releases the old binding without letting a stale release clear the new one', () => {
    const previous = bind()
    unbind = previous.unbind
    previous.connection.emitHandshakeResponse(
      handshake(
        standing('deletion_scheduled', { deletionEffectiveAt: NOW + DAY_MS }),
        true,
      ),
    )
    expect(hilosDeletionStrip.get()).toEqual({
      effectiveAt: NOW + DAY_MS,
      deletionGraceDays: null,
    })

    const current = bind()
    unbind = current.unbind
    expect(hilosDeletionStrip.get()).toBeNull()
    current.connection.emitHandshakeResponse(
      handshake(
        standing('deletion_scheduled', {
          deletionEffectiveAt: NOW + 12 * DAY_MS,
        }),
        true,
      ),
    )

    previous.unbind()
    previous.connection.emitHandshakeResponse(handshake(standing('none')))
    expect(hilosDeletionStrip.get()).toEqual({
      effectiveAt: NOW + 12 * DAY_MS,
      deletionGraceDays: null,
    })
    current.unbind()
    current.connection.emitHandshakeResponse(handshake(standing('none')))
    expect(hilosDeletionStrip.get()).toBeNull()
  })

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
  it('is gray for a merge, red for a block, blue for a freeze and yellow otherwise', () => {
    expect(hilosStandingTone('merged')).toBe('secondary')
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

    expect(
      formatHilosDeletionStrip({ effectiveAt, deletionGraceDays: null }, NOW),
    ).toBe(`Your account will be deleted on ${date} — 12 days left`)
    expect(
      formatHilosDeletionStrip(
        { effectiveAt, deletionGraceDays: null },
        effectiveAt + DAY_MS,
      ),
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

it.each([-300_000, 300_000])(
  'displays absolute moments in the reader zone with clock drift %i',
  (drift) => {
    vi.useFakeTimers()
    vi.setSystemTime(1_700_000_000_000)
    const zone = vi
      .spyOn(hilosTimeZone, 'get')
      .mockReturnValue('America/Los_Angeles')
    applyServerTime(Date.now() + drift)
    try {
      const now = Date.parse('2025-12-30T00:30:05Z') - drift
      const effectiveAt = Date.parse('2026-01-01T00:30:05Z')
      expect(
        formatHilosDeletionStrip({ effectiveAt, deletionGraceDays: null }, now),
      ).toBe('Your account will be deleted on December 31, 2025 — 2 days left')
    } finally {
      zone.mockRestore()
      applyServerTime(Date.now())
      vi.useRealTimers()
    }
  },
)

describe('the deletion strip historical duration', () => {
  afterEach(() => {
    applyServerTime(Date.now())
    vi.useRealTimers()
  })

  it('caps initial, live and reconnected requests and clears cancellation', () => {
    vi.useFakeTimers()
    vi.setSystemTime(NOW)
    const booted = bind()
    const initial = standing('deletion_scheduled', {
      deletionEffectiveAt: NOW + 30 * DAY_MS + 1,
      deletionGraceDays: 30,
    })
    try {
      booted.connection.emitHandshakeResponse(handshake(initial))
      expect(hilosDeletionStrip.get()).toEqual({
        effectiveAt: NOW + 30 * DAY_MS + 1,
        deletionGraceDays: 30,
      })
      expect(
        formatHilosDeletionStrip(hilosDeletionStrip.get()!, NOW),
      ).toContain('30 days left')
      expect(
        formatHilosDeletionStrip(hilosDeletionStrip.get()!, NOW + DAY_MS + 1),
      ).toContain('29 days left')
      expect(
        formatHilosDeletionStrip(hilosDeletionStrip.get()!, NOW + 31 * DAY_MS),
      ).toContain('1 day left')

      booted.scopes.session.data.set('accountStanding', standing('none'))
      expect(hilosDeletionStrip.get()).toBeNull()
      const next = standing('deletion_scheduled', {
        deletionEffectiveAt: NOW + 7 * DAY_MS + 1,
        deletionGraceDays: 7,
      })
      booted.scopes.session.data.set('accountStanding', next)
      expect(
        formatHilosDeletionStrip(hilosDeletionStrip.get()!, NOW),
      ).toContain('7 days left')
      booted.connection.emitHandshakeResponse(handshake(next))
      expect(hilosDeletionStrip.get()?.deletionGraceDays).toBe(7)
      expect(
        formatHilosDeletionStrip(hilosDeletionStrip.get()!, NOW),
      ).toContain('7 days left')
    } finally {
      booted.unbind()
    }
  })
})
