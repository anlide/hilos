import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  type ActionLifecycle,
} from '../../src/connection/actionLifecycle.js'
import {
  bindLegalReconsent,
  closeLegalReconsent,
  createHilosLegalReconsentPreview,
  createHilosLegalReconsentStore,
  describeHilosLegalReconsentAccepted,
  describeHilosLegalReconsentChange,
  describeHilosLegalReconsentPlate,
  describeHilosLegalReconsentRefusal,
  formatHilosLegalReconsentBadge,
  hilosFrozenScreen,
  hilosLegalReconsent,
  hilosLegalReconsentDue,
  hilosLegalReconsentOpen,
  hilosLegalReconsentPerson,
  hilosLegalReconsentSections,
  legalReconsentContentSchema,
  legalReconsentPreviewSchema,
  openLegalReconsent,
  type HilosLegalReconsentContent,
} from '../../src/legal/legalReconsent.js'
import { bindSessionScope } from '../../src/session/sessionScope.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { type HilosConnection, type ProjectSignal } from '../../src/index.js'
import { change, clause, current, first } from './fixtures.js'

/** One day, in ms. */
const DAY_MS = 86_400_000

/** The midnight 1 November 2026 starts at, UTC. */
const NOVEMBER_FIRST = Date.UTC(2026, 10, 1)

/** A connection double replaying handshake responses into the session scope. */
function fakeConnection() {
  const listeners: Array<(signal: ProjectSignal) => void> = []

  return {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal') {
        listeners.push(listener as (signal: ProjectSignal) => void)
      }

      return () => {}
    },
    handshake(payload: Record<string, unknown>): void {
      const signal = {
        kind: 'project',
        type: 'handshake_response',
        data: payload,
        envelope: {},
      } as unknown as ProjectSignal
      for (const listener of listeners) {
        listener(signal)
      }
    },
  }
}

/**
 * A standing as the wire carries it.
 *
 * @param facts The facts that differ from a plain account.
 */
function standing(facts: Record<string, unknown> = {}) {
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

/** Terms inside their window until 10 November. */
const IN_WINDOW = { window: [{ document: 'terms', deadline: '2026-11-10' }] }

/**
 * A handshake of Bob's session, or of an anonymous one.
 *
 * @param accountStanding The standing the handshake stamps, or null for nobody.
 * @param impersonatedBy Whether Ada stands behind the session.
 */
function handshake(
  accountStanding: Record<string, unknown> | null,
  impersonatedBy = false,
) {
  return {
    data: { accountStanding },
    entities: {
      currentUser: accountStanding === null ? null : { id: 2, name: 'Bob' },
      impersonatedBy: impersonatedBy ? { id: 1, name: 'Ada' } : null,
    },
  }
}

/** The content reply of a person holding the first terms. */
function content(
  refusal: 'freeze' | 'remind' = 'freeze',
): HilosLegalReconsentContent {
  return {
    refusal,
    documents: [
      {
        document: 'terms',
        standing: 'window',
        deadline: '2026-11-10',
        held: first,
        current,
        changes: [change],
        clauses: [clause],
      },
    ],
  }
}

/** Boot a session scope and the screen's binding over an answering lifecycle. */
function bind(
  dispatch = vi.fn(() => ({ done: Promise.resolve({ reply: content() }) })),
) {
  const connection = fakeConnection()
  const scopes = new ScopeManager()
  bindSessionScope(connection as unknown as HilosConnection, scopes)
  const unbind = bindLegalReconsent(scopes, {
    dispatch,
  } as unknown as ActionLifecycle)

  return { connection, dispatch, unbind }
}

describe('the re-consent window rises on a sign-in only (HIL-500)', () => {
  let unbind: (() => void) | undefined

  afterEach(() => {
    unbind?.()
    unbind = undefined
  })

  it('stays down on the first frame of a tab opened on a live sign-in', () => {
    const booted = bind()
    unbind = booted.unbind

    booted.connection.handshake(handshake(standing(IN_WINDOW)))

    expect(hilosLegalReconsentOpen.get()).toBe(false)
    expect(hilosLegalReconsentDue.get()).toEqual({
      nearestDeadline: '2026-11-10',
    })
    expect(hilosLegalReconsentPerson.get()).toEqual({
      name: 'Bob',
      impersonated: false,
    })
  })

  it('opens and reads its content when an anonymous tab signs in with something due', async () => {
    const booted = bind()
    unbind = booted.unbind
    booted.connection.handshake(handshake(null))

    booted.connection.handshake(handshake(standing(IN_WINDOW)))

    expect(hilosLegalReconsentOpen.get()).toBe(true)
    expect(booted.dispatch).toHaveBeenCalledWith(
      'hilos_legal_reconsent',
      {},
      { replySchema: legalReconsentContentSchema },
    )
    await vi.waitFor(() =>
      expect(hilosLegalReconsent.content.get()).not.toBeNull(),
    )
  })

  it('stays down on a sign-in with nothing due', () => {
    const booted = bind()
    unbind = booted.unbind
    booted.connection.handshake(handshake(null))

    booted.connection.handshake(handshake(standing()))

    expect(hilosLegalReconsentOpen.get()).toBe(false)
    expect(hilosLegalReconsentDue.get()).toBeNull()
  })

  it('treats a takeover starting as no entrance and hides the reminder under it', () => {
    const booted = bind()
    unbind = booted.unbind
    booted.connection.handshake(handshake(standing()))

    booted.connection.handshake(handshake(standing(IN_WINDOW), true))

    expect(hilosLegalReconsentOpen.get()).toBe(false)
    expect(hilosLegalReconsentDue.get()).toBeNull()
    expect(hilosLegalReconsentPerson.get()?.impersonated).toBe(true)
  })

  it('closes when a frame says nothing is left to decide', () => {
    const booted = bind()
    unbind = booted.unbind
    booted.connection.handshake(handshake(null))
    booted.connection.handshake(handshake(standing(IN_WINDOW)))
    expect(hilosLegalReconsentOpen.get()).toBe(true)

    booted.connection.handshake(handshake(standing()))

    expect(hilosLegalReconsentOpen.get()).toBe(false)
  })

  it('gives way to the freeze screen when the deadline passes under it', () => {
    const booted = bind()
    unbind = booted.unbind
    booted.connection.handshake(handshake(null))
    booted.connection.handshake(handshake(standing(IN_WINDOW)))

    const lapsed = [{ document: 'terms', deadline: '2026-11-10' }]
    booted.connection.handshake(
      handshake(standing({ shown: 'frozen', frozen: true, lapsed })),
    )

    expect(hilosLegalReconsentOpen.get()).toBe(false)
    expect(hilosFrozenScreen.get()).toBe(true)
    expect(hilosLegalReconsentDue.get()).toBeNull()
  })

  it('opens from the header reminder and closes on Later', () => {
    const booted = bind()
    unbind = booted.unbind
    booted.connection.handshake(handshake(standing(IN_WINDOW)))

    openLegalReconsent()
    expect(hilosLegalReconsentOpen.get()).toBe(true)
    closeLegalReconsent()

    expect(hilosLegalReconsentOpen.get()).toBe(false)
    expect(hilosLegalReconsentDue.get()).not.toBeNull()
  })
})

describe('the header reminder (HIL-500)', () => {
  let unbind: (() => void) | undefined

  afterEach(() => {
    unbind?.()
    unbind = undefined
  })

  it('stands for a lapsed document under remind, with no deadline to count', () => {
    const booted = bind()
    unbind = booted.unbind

    const lapsed = [{ document: 'terms', deadline: '2026-09-01' }]
    booted.connection.handshake(handshake(standing({ lapsed })))

    expect(hilosLegalReconsentDue.get()).toEqual({ nearestDeadline: null })
    expect(hilosFrozenScreen.get()).toBe(false)
  })

  it('stands down for a frozen and for a blocked person', () => {
    const booted = bind()
    unbind = booted.unbind
    const lapsed = [{ document: 'terms', deadline: '2026-09-01' }]

    booted.connection.handshake(
      handshake(standing({ ...IN_WINDOW, frozen: true, lapsed })),
    )
    expect(hilosLegalReconsentDue.get()).toBeNull()
    expect(hilosFrozenScreen.get()).toBe(true)

    booted.connection.handshake(
      handshake(
        standing({ ...IN_WINDOW, blocked: true, frozen: true, lapsed }),
      ),
    )
    expect(hilosLegalReconsentDue.get()).toBeNull()
    expect(hilosFrozenScreen.get()).toBe(false)
  })

  it('names the nearest deadline still ahead', () => {
    const booted = bind()
    unbind = booted.unbind

    booted.connection.handshake(
      handshake(
        standing({
          window: [
            { document: 'terms', deadline: '2026-12-01' },
            { document: 'privacy', deadline: '2026-11-10' },
          ],
        }),
      ),
    )

    expect(hilosLegalReconsentDue.get()).toEqual({
      nearestDeadline: '2026-11-10',
    })
  })

  it('counts whole days up to the deadline and never below one', () => {
    const due = { nearestDeadline: '2026-11-10' }
    expect(formatHilosLegalReconsentBadge(due, NOVEMBER_FIRST)).toBe(
      'The terms have changed — 9 days left to decide',
    )
    expect(
      formatHilosLegalReconsentBadge(due, NOVEMBER_FIRST + 9 * DAY_MS - 1),
    ).toBe('The terms have changed — 1 day left to decide')
    expect(
      formatHilosLegalReconsentBadge(due, NOVEMBER_FIRST + 20 * DAY_MS),
    ).toBe('The terms have changed — 1 day left to decide')
    expect(
      formatHilosLegalReconsentBadge({ nearestDeadline: null }, NOVEMBER_FIRST),
    ).toBe('The terms have changed — please review them')
  })
})

describe('the screen content (HIL-500)', () => {
  it('reads both reply shapes and refuses a standing it does not know', () => {
    expect(legalReconsentContentSchema.safeParse(content()).success).toBe(true)
    expect(
      legalReconsentContentSchema.safeParse({
        ...content(),
        documents: [{ ...content().documents[0], standing: 'covered' }],
      }).success,
    ).toBe(false)
    const preview = {
      document: 'privacy',
      standing: null,
      deadline: null,
      held: null,
      current: first,
      changes: [],
      clauses: [clause],
    }
    expect(legalReconsentPreviewSchema.safeParse(preview).success).toBe(true)
    expect(
      hilosLegalReconsentSections(legalReconsentPreviewSchema.parse(preview)),
    ).toHaveLength(1)
  })

  it('describes the plates of the window, the freeze and the preview', () => {
    const [section] = content().documents
    expect(
      describeHilosLegalReconsentPlate(section!, 'window', NOVEMBER_FIRST),
    ).toMatchObject({
      tone: 'warning',
      text: '9 days left',
      detail: 'until 10 November 2026',
    })
    const lapsed = { ...section!, standing: 'lapsed' as const }
    expect(
      describeHilosLegalReconsentPlate(lapsed, 'window', NOVEMBER_FIRST),
    ).toMatchObject({
      tone: 'secondary',
      text: 'deadline passed 10 November 2026',
    })
    expect(
      describeHilosLegalReconsentPlate(lapsed, 'frozen', NOVEMBER_FIRST),
    ).toMatchObject({
      tone: 'info',
      icon: 'bi-snow',
      text: 'account frozen · deadline passed 10 November 2026',
    })
    const substantial = { ...current, significance: 'substantial' as const }
    expect(
      describeHilosLegalReconsentPlate(
        { ...lapsed, current: substantial },
        'preview',
        NOVEMBER_FIRST,
      ),
    ).toMatchObject({ text: 'No window · in force since 27 September 2026' })
    expect(
      describeHilosLegalReconsentPlate(
        { ...section!, current: substantial },
        'preview',
        NOVEMBER_FIRST,
      ),
    ).toMatchObject({ text: '9 days left · until 10 November 2026' })
    expect(
      describeHilosLegalReconsentPlate(section!, 'preview', NOVEMBER_FIRST),
    ).toMatchObject({ text: 'Editorial · nobody is asked to accept it' })
    expect(
      describeHilosLegalReconsentPlate(
        { ...section!, held: null, standing: null },
        'preview',
        NOVEMBER_FIRST,
      ),
    ).toBeNull()
  })

  it('says what the person accepted and what changed since', () => {
    const [section] = content().documents
    expect(describeHilosLegalReconsentAccepted(section!)).toBe(
      'You accepted the revision of 17 September 2026. Since then 1 clause changed — the rest is as before.',
    )
    expect(
      describeHilosLegalReconsentAccepted({ ...section!, changes: [] }),
    ).toContain('Since then 0 clauses changed')
    expect(describeHilosLegalReconsentChange(change)).toEqual({
      clauseKey: 'standard.retention',
      icon: 'bi-clock-history',
      statement: 'Project retention',
      kind: 'Changed',
      before: 'Wording changed',
    })
    expect(
      describeHilosLegalReconsentChange({
        ...change,
        before: { ...clause, statement: 'Kept for a year' },
      }).before,
    ).toBe('Before: Kept for a year')
    expect(
      describeHilosLegalReconsentChange({
        ...change,
        kind: 'removed',
        after: null,
      }),
    ).toMatchObject({
      kind: 'Removed',
      statement: 'Project retention',
      before: null,
    })
  })

  it('words the refusal step by the setting', () => {
    expect(describeHilosLegalReconsentRefusal(content('freeze'))).toBe(
      'After 10 November 2026 you will not be able to use the product until you accept. Sign-in, your data and its export stay.',
    )
    expect(describeHilosLegalReconsentRefusal(content('remind'))).toBe(
      'Nothing changes: this reminder will keep coming back.',
    )
  })
})

describe('the acceptance (HIL-500)', () => {
  it('sends the revisions in force of every document shown and reports success', async () => {
    const onAccepted = vi.fn()
    const dispatch = vi.fn((action: string) => ({
      done: Promise.resolve(
        action === 'hilos_legal_reconsent' ? { reply: content() } : {},
      ),
    }))
    const store = createHilosLegalReconsentStore(
      { actions: { dispatch } as unknown as ActionLifecycle },
      { onAccepted },
    )
    await store.load()

    expect(await store.accept()).toBe(true)

    expect(dispatch).toHaveBeenLastCalledWith('hilos_legal_accept', {
      acceptedRevisions: { terms: current.revisionId },
    })
    expect(onAccepted).toHaveBeenCalledOnce()
    expect(store.busy.get()).toBe(false)
  })

  it('shows the refusal and reads the content again when the terms moved', async () => {
    const refusal = new ActionError(
      'hilos_legal_accept',
      'fail',
      'The terms were updated while you were reading. Review the differences and accept again.',
    )
    const dispatch = vi
      .fn()
      .mockReturnValueOnce({ done: Promise.resolve({ reply: content() }) })
      .mockReturnValueOnce({ done: Promise.reject(refusal) })
      .mockReturnValueOnce({ done: Promise.resolve({ reply: content() }) })
    const store = createHilosLegalReconsentStore({
      actions: { dispatch } as unknown as ActionLifecycle,
    })
    await store.load()
    store.show({ kind: 'refuse' })

    expect(await store.accept()).toBe(false)

    expect(store.error.get()).toBe(
      'The terms were updated while you were reading. Review the differences and accept again.',
    )
    expect(dispatch).toHaveBeenCalledTimes(3)
    expect(store.content.get()).not.toBeNull()
    expect(store.view.get()).toEqual({ kind: 'changes' })
  })

  it('refuses to accept before anything was read', async () => {
    const dispatch = vi.fn()
    const store = createHilosLegalReconsentStore({
      actions: { dispatch } as unknown as ActionLifecycle,
    })

    expect(await store.accept()).toBe(false)
    expect(dispatch).not.toHaveBeenCalled()
  })

  it('drops a read that answers after the screen was reset', async () => {
    let answer!: (value: { reply: HilosLegalReconsentContent }) => void
    const dispatch = vi.fn(() => ({
      done: new Promise((resolve) => {
        answer = resolve
      }),
    }))
    const store = createHilosLegalReconsentStore({
      actions: { dispatch } as unknown as ActionLifecycle,
    })
    const read = store.load()
    store.reset()
    answer({ reply: content() })
    await read

    expect(store.content.get()).toBeNull()
    expect(store.loading.get()).toBe(false)
  })

  it('reports a failed read with its own words', async () => {
    const dispatch = vi.fn(() => ({ done: Promise.reject(new Error('gone')) }))
    const store = createHilosLegalReconsentStore({
      actions: { dispatch } as unknown as ActionLifecycle,
    })

    await store.load()

    expect(store.error.get()).toBe('The terms could not be loaded.')
  })
})

describe('the acceptance guards (HIL-500)', () => {
  it('sends nothing when nothing is left to accept', async () => {
    const empty = { ...content(), documents: [] }
    const dispatch = vi.fn(() => ({ done: Promise.resolve({ reply: empty }) }))
    const store = createHilosLegalReconsentStore({
      actions: { dispatch } as unknown as ActionLifecycle,
    })
    await store.load()

    expect(await store.accept()).toBe(false)
    expect(dispatch).toHaveBeenCalledTimes(1)
  })

  it('leaves a screen closed during the acceptance closed when it is refused', async () => {
    let refuse!: (reason: unknown) => void
    const dispatch = vi
      .fn()
      .mockReturnValueOnce({ done: Promise.resolve({ reply: content() }) })
      .mockReturnValueOnce({
        done: new Promise((_, reject) => {
          refuse = reject
        }),
      })
    const store = createHilosLegalReconsentStore({
      actions: { dispatch } as unknown as ActionLifecycle,
    })
    await store.load()
    const accepting = store.accept()
    store.reset()
    refuse(new ActionError('hilos_legal_accept', 'fail', 'Refused'))

    expect(await accepting).toBe(false)
    expect(store.error.get()).toBeNull()
    expect(store.content.get()).toBeNull()
    expect(dispatch).toHaveBeenCalledTimes(2)
  })
})

describe('the admin preview (HIL-500)', () => {
  it('reads one document and forgets it on close', async () => {
    const reply = {
      document: 'terms',
      standing: 'lapsed',
      deadline: '2026-09-27',
      held: first,
      current,
      changes: [change],
      clauses: [clause],
    }
    const dispatch = vi.fn(() => ({ done: Promise.resolve({ reply }) }))
    const preview = createHilosLegalReconsentPreview({
      actions: { dispatch } as unknown as ActionLifecycle,
    })

    await preview.open('terms')

    expect(dispatch).toHaveBeenCalledWith(
      'hilos_legal_reconsent_preview',
      { document: 'terms' },
      { replySchema: legalReconsentPreviewSchema },
    )
    expect(preview.preview.get()?.document).toBe('terms')
    preview.close()
    expect(preview.opened.get()).toBe(false)
    expect(preview.preview.get()).toBeNull()
  })
})
