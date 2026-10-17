// The React peer of vue/src/admin/security/HilosSecurity2faPage.test.ts:
// the two-step verification setting's edit modal on the shared row-edit helper
// (HIL-1134): it holds the setting row in focus, reloads a pristine edit when
// the value changes elsewhere and says so, shows the conflict chrome without
// Merge on a changed one (the live value in words) and answers Keep mine / Take
// theirs, and locks save as "Deleted" when the row goes. The two first rows are
// switches (HIL-1303): the first, while on, opens the window that turns the
// factor off instead of moving, and off it dims and locks the rows below it.
import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  ActionLifecycle,
  HilosPages,
  HilosSecondFactorSettingKey,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
} from '@hilos/core'
import type {
  HilosConnection,
  HilosRouter,
  HilosTwoFactorContext,
  PageRouteMatch,
  ProjectSignal,
} from '@hilos/core'

import { HilosSecurity2faPage } from '../src/admin/security/HilosSecurity2faPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

const SETTINGS_TABLE = 'hilosSecurityTwoFactor'
const ENABLED = HilosSecondFactorSettingKey.enabled
const FORGET = HilosSecondFactorSettingKey.forgetTrustsOnDisable
const REQUIRED = HilosSecondFactorSettingKey.required
const TRUST_DAYS = HilosSecondFactorSettingKey.trustDays

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.SECURITY_2FA,
      params: {},
      admin: true,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: () => undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

/** A setting row's slot, with the given value. */
function settingSlot(rowKey: string, value: string): Record<string, unknown> {
  return {
    rowKey,
    value,
    defaultValue:
      rowKey === REQUIRED
        ? 'none'
        : rowKey === ENABLED || rowKey === FORGET
          ? 'true'
          : '30',
  }
}

function seededContext(
  hiddenRequired = false,
  enabled = 'true',
): {
  context: HilosTwoFactorContext
  pushUpdate: (rowKey: string, value: string) => void
  pushRemove: (rowKey: string) => void
  answer: (outcome: 'success' | 'fail', reply?: unknown) => void
  sent: Array<{ action: string; payload: Record<string, unknown> }>
  focus: string[]
} {
  let settings = new Map<string, Record<string, unknown>>([
    [ENABLED, settingSlot(ENABLED, enabled)],
    [FORGET, settingSlot(FORGET, 'true')],
    [
      REQUIRED,
      hiddenRequired
        ? { rowKey: REQUIRED, value: { _hidden: true }, defaultValue: 'none' }
        : settingSlot(REQUIRED, 'admins'),
    ],
    [TRUST_DAYS, settingSlot(TRUST_DAYS, '30')],
  ])
  const focus: string[] = []
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.SECURITY_2FA)
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const deltaListeners = new Set<(signal: { data: unknown }) => void>()
  const serveWindow = (tableKey: string): void => {
    const rows =
      tableKey === SETTINGS_TABLE
        ? [...settings].map(([rowKey, setting]) => ({
            rowKey,
            slots: { setting },
          }))
        : []
    const data = {
      page: HilosPages.SECURITY_2FA,
      tableKey,
      rows,
      totalCount: rows.length,
      totalExact: true,
      firstAnchor: null,
      lastAnchor: null,
      offset: 0,
      limit: 10,
    }
    for (const listener of windowListeners) {
      listener({ data })
    }
  }
  const pushDelta = (data: Record<string, unknown>): void => {
    for (const listener of deltaListeners) {
      listener({
        data: {
          page: HilosPages.SECURITY_2FA,
          tableKey: SETTINGS_TABLE,
          ...data,
        },
      })
    }
  }

  const connection = {
    registerTableWindow(tableKey: string): void {
      serveWindow(tableKey)
    },
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(_page: string, tableKey: string): boolean {
      serveWindow(tableKey)

      return true
    },
    sendTableRendered(): boolean {
      return true
    },
    sendTableRowFocus(
      _page: string,
      _tableKey: string,
      rowKey: string,
    ): boolean {
      focus.push(rowKey)

      return true
    },
    on(
      event: string,
      listener: (signal: { data: unknown }) => void,
    ): () => void {
      if (event === 'tableWindow') {
        windowListeners.add(listener)

        return () => windowListeners.delete(listener)
      }
      if (event === 'tableViewportDelta') {
        deltaListeners.add(listener)

        return () => deltaListeners.delete(listener)
      }

      return () => {}
    },
  }
  const sent: Array<{
    action: string
    payload: Record<string, unknown>
    requestId?: string
  }> = []
  const replyListeners = new Map<
    string,
    Set<(signal: Record<string, unknown>) => void>
  >()
  const source = {
    sendAction: (
      action: string,
      payload: Record<string, unknown>,
      requestId?: string,
    ) => {
      sent.push({ action, payload, requestId })

      return true
    },
    on: (event: string, listener: (state: never) => void) => {
      if (event === 'state') {
        drops.push(() => (listener as (state: string) => void)('disconnected'))

        return () => {}
      }
      const listeners = replyListeners.get(event) ?? new Set()
      listeners.add(listener as (signal: Record<string, unknown>) => void)
      replyListeners.set(event, listeners)

      return () => {}
    },
  }
  const actions = new ActionLifecycle(
    source as unknown as ConstructorParameters<typeof ActionLifecycle>[0],
  )

  return {
    context: {
      connection: connection as unknown as HilosTwoFactorContext['connection'],
      scopes,
      actions,
    },
    pushUpdate(rowKey: string, value: string): void {
      const setting = settingSlot(rowKey, value)
      settings = new Map(settings).set(rowKey, setting)
      pushDelta({
        kind: 'row_updated',
        rowKey,
        row: { rowKey, slots: { setting } },
      })
    },
    pushRemove(rowKey: string): void {
      settings = new Map(settings)
      settings.delete(rowKey)
      pushDelta({ kind: 'row_removed', rowKey, reason: 'deleted' })
    },
    answer(outcome: 'success' | 'fail', reply?: unknown): void {
      const last = sent[sent.length - 1]
      const event = outcome === 'success' ? 'actionSuccess' : 'actionError'
      for (const listener of replyListeners.get(event) ?? []) {
        listener({
          kind: event,
          action: last?.action,
          requestId: last?.requestId,
          reason: 'The setting refused the write.',
          ...(reply !== undefined ? { reply } : {}),
        })
      }
    },
    sent,
    focus,
  }
}

// Drops every connection a case left an action in flight on, so the lifecycle
// fails the action and stops its deferred-loading timer before the page goes.
const drops: Array<() => void> = []

afterEach(() => {
  act(() => {
    for (const drop of drops.splice(0)) {
      drop()
    }
  })
  cleanup()
  document.body.classList.remove('modal-open')
})

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function valueInput(): HTMLInputElement | HTMLSelectElement {
  return byId('hilos-2fa-input') as HTMLInputElement | HTMLSelectElement
}

function saveButton(): HTMLButtonElement {
  return byId('hilos-2fa-save') as HTMLButtonElement
}

function notice(): HTMLElement | null {
  return byId('hilos-2fa-edit-notice')
}

function typeDraft(text: string): void {
  fireEvent.change(valueInput(), { target: { value: text } })
}

/**
 * Mount the page and open the modal of one setting. The row's controls stand
 * in the document twice — the table and the narrow-screen card — so the button
 * is looked up through the table.
 */
function openModal(context: HilosTwoFactorContext, rowKey: string): void {
  render(
    <HilosRouterContext.Provider value={router()}>
      <HilosSecurity2faPage context={context} />
    </HilosRouterContext.Provider>,
  )
  fireEvent.click(
    document.querySelector(
      `table [data-id="hilos-2fa-edit-${rowKey}"]`,
    ) as Element,
  )
}

describe('HilosSecurity2faPage setting modal', () => {
  it('opens on the live value with save locked, the message line empty, and the row in focus', () => {
    const { context, focus } = seededContext()
    openModal(context, TRUST_DAYS)

    expect(valueInput().value).toBe('30')
    expect(saveButton().disabled).toBe(true)
    expect(notice()).toBeNull()
    expect(focus).toEqual([TRUST_DAYS])
  })

  it('sends nothing on Enter while a conflict stands', () => {
    const { context, pushUpdate, sent } = seededContext()
    openModal(context, REQUIRED)
    typeDraft('everyone')
    act(() => {
      pushUpdate(REQUIRED, 'none')
    })

    fireEvent.submit(valueInput().form as HTMLFormElement)

    expect(sent).toHaveLength(0)
    expect(byId('conflict-badge')).not.toBeNull()
  })

  it('reloads a pristine edit when the value changes elsewhere and says so', () => {
    const { context, pushUpdate } = seededContext()
    openModal(context, TRUST_DAYS)

    act(() => {
      pushUpdate(TRUST_DAYS, '14')
    })

    expect(valueInput().value).toBe('14')
    expect(notice()?.textContent).toContain('Updated just now')
    expect(saveButton().disabled).toBe(true)
  })

  it('surfaces a conflict in words, without Merge, and Keep mine sends mine', () => {
    const { context, pushUpdate, sent } = seededContext()
    openModal(context, REQUIRED)
    typeDraft('everyone')
    expect(saveButton().disabled).toBe(false)

    act(() => {
      pushUpdate(REQUIRED, 'none')
    })

    expect(byId('conflict-badge')).not.toBeNull()
    expect(notice()?.textContent).toContain('Changed elsewhere to "Nobody"')
    expect(byId('conflict-merge')).toBeNull()
    expect(saveButton().disabled).toBe(true)

    fireEvent.click(byId('conflict-accept-mine') as Element)
    fireEvent.click(saveButton())
    expect(sent).toHaveLength(1)
    expect(sent[0]?.action).toBe('security_2fa_setting_set')
    expect(sent[0]?.payload).toMatchObject({ key: REQUIRED, value: 'everyone' })
  })

  it('Take theirs puts the live value in, says so, and locks save', () => {
    const { context, pushUpdate } = seededContext()
    openModal(context, REQUIRED)
    typeDraft('everyone')
    act(() => {
      pushUpdate(REQUIRED, 'none')
    })

    fireEvent.click(byId('conflict-accept-theirs') as Element)

    expect(valueInput().value).toBe('none')
    expect(notice()?.textContent).toContain('Updated just now')
    expect(saveButton().disabled).toBe(true)
  })

  it('locks save as Deleted and keeps the draft when the row goes, and lets the row go on close', () => {
    const { context, pushRemove, focus } = seededContext()
    openModal(context, REQUIRED)
    typeDraft('everyone')
    act(() => {
      pushRemove(REQUIRED)
    })

    expect(notice()?.textContent).toContain('Deleted elsewhere')
    expect(saveButton().textContent?.trim()).toBe('Deleted')
    expect(saveButton().disabled).toBe(true)
    expect(valueInput().value).toBe('everyone')

    fireEvent.click(byId('modal-close') as Element)
    expect(focus).toEqual([REQUIRED, ''])
  })

  it('asks before discarding a changed draft, and closes a pristine one on Save without a request', () => {
    const { context, sent } = seededContext()
    openModal(context, TRUST_DAYS)
    typeDraft('7')

    fireEvent.click(byId('modal-close') as Element)
    expect(byId('modal-confirm-discard')).not.toBeNull()
    fireEvent.click(byId('modal-confirm-discard') as Element)
    expect(byId('modal')).toBeNull()

    fireEvent.click(
      document.querySelector(
        `table [data-id="hilos-2fa-edit-${TRUST_DAYS}"]`,
      ) as Element,
    )
    fireEvent.submit(valueInput().form as HTMLFormElement)
    expect(sent).toHaveLength(0)
    expect(byId('modal')).toBeNull()
  })
})

describe('HilosSecurity2faPage', () => {
  it('no longer carries the operations that ask for confirmation (HIL-1204)', () => {
    const { context } = seededContext()
    render(
      <HilosRouterContext.Provider value={router()}>
        <HilosSecurity2faPage context={context} />
      </HilosRouterContext.Provider>,
    )

    expect(byId('hilos-step-up-table')).toBeNull()
  })
})

/** Mount the page over a context. */
function mountPage(context: HilosTwoFactorContext): void {
  render(
    <HilosRouterContext.Provider value={router()}>
      <HilosSecurity2faPage context={context} />
    </HilosRouterContext.Provider>,
  )
}

/** A row's control as the table draws it (the card draws it once more). */
function inTable<T extends HTMLElement>(id: string): T {
  return document.querySelector(`table [data-id="${id}"]`) as T
}

/** Answer the action last sent and let its promise chain reach the view. */
async function answered(
  world: ReturnType<typeof seededContext>,
  outcome: 'success' | 'fail',
  reply?: unknown,
): Promise<void> {
  await act(async () => {
    world.answer(outcome, reply)
    await Promise.resolve()
  })
}

/** Click the first switch and answer the opening of its step. */
async function openDisable(
  world: ReturnType<typeof seededContext>,
  required: boolean,
): Promise<void> {
  fireEvent.click(inTable(`hilos-2fa-switch-${ENABLED}`))
  await answered(world, 'success', {
    required,
    purpose: 'turn the second factor off',
    method: 'password',
  })
}

describe('HilosSecurity2faPage switches', () => {
  it('draws the two first rows as switches without a pencil, the rest with one', () => {
    mountPage(seededContext().context)

    const first = inTable<HTMLInputElement>(`hilos-2fa-switch-${ENABLED}`)
    expect(first.checked).toBe(true)
    expect(first.getAttribute('aria-label')).toBe('Two-factor enabled')
    expect(
      inTable<HTMLInputElement>(`hilos-2fa-switch-${FORGET}`).checked,
    ).toBe(true)
    expect(inTable(`hilos-2fa-edit-${ENABLED}`)).toBeNull()
    expect(inTable(`hilos-2fa-edit-${FORGET}`)).toBeNull()
    expect(inTable(`hilos-2fa-value-${ENABLED}`)).toBeNull()
    expect(inTable(`hilos-2fa-edit-${REQUIRED}`)).not.toBeNull()
  })

  it('sets the second switch by one write and moves it only with the row', async () => {
    const world = seededContext()
    mountPage(world.context)

    fireEvent.click(inTable(`hilos-2fa-switch-${FORGET}`))
    expect(world.sent).toEqual([
      expect.objectContaining({
        action: 'security_2fa_switch_set',
        payload: { key: FORGET, enabled: false },
      }),
    ])
    expect(
      inTable(`hilos-2fa-switch-${FORGET}`).getAttribute('aria-busy'),
    ).toBe('true')

    await answered(world, 'success')
    expect(
      inTable<HTMLInputElement>(`hilos-2fa-switch-${FORGET}`).checked,
    ).toBe(true)
    act(() => world.pushUpdate(FORGET, 'false'))
    expect(
      inTable<HTMLInputElement>(`hilos-2fa-switch-${FORGET}`).checked,
    ).toBe(false)
  })

  it('turns the factor back on by one write, with no window', () => {
    const world = seededContext(false, 'false')
    mountPage(world.context)

    fireEvent.click(inTable(`hilos-2fa-switch-${ENABLED}`))

    expect(world.sent).toEqual([
      expect.objectContaining({
        action: 'security_2fa_switch_set',
        payload: { key: ENABLED, enabled: true },
      }),
    ])
    expect(byId('hilos-2fa-disable-window')).toBeNull()
  })

  it('dims the rows below the first and locks their pencils and the second switch while off', () => {
    const world = seededContext(false, 'false')
    mountPage(world.context)

    expect(
      inTable<HTMLInputElement>(`hilos-2fa-switch-${ENABLED}`).disabled,
    ).toBe(false)
    expect(
      inTable<HTMLInputElement>(`hilos-2fa-switch-${FORGET}`).disabled,
    ).toBe(true)
    expect(
      inTable<HTMLButtonElement>(`hilos-2fa-edit-${REQUIRED}`).disabled,
    ).toBe(true)
    expect(
      inTable(`hilos-2fa-value-${TRUST_DAYS}`).classList.contains('opacity-50'),
    ).toBe(true)

    act(() => world.pushUpdate(ENABLED, 'true'))
    expect(
      inTable<HTMLInputElement>(`hilos-2fa-switch-${FORGET}`).disabled,
    ).toBe(false)
    expect(
      inTable<HTMLButtonElement>(`hilos-2fa-edit-${REQUIRED}`).disabled,
    ).toBe(false)
    expect(
      inTable(`hilos-2fa-value-${TRUST_DAYS}`).classList.contains('opacity-50'),
    ).toBe(false)
  })
})

describe('HilosSecurity2faPage turning the factor off', () => {
  it("opens the window instead of moving the switch: Confirm it's you, then what it does and Turn off", async () => {
    const world = seededContext()
    mountPage(world.context)
    await openDisable(world, true)

    expect(world.sent[0]).toMatchObject({
      action: 'hilos_step_up_start',
      payload: { operation: 'disable_second_factor' },
    })
    expect(
      inTable<HTMLInputElement>(`hilos-2fa-switch-${ENABLED}`).checked,
    ).toBe(true)
    expect(byId('modal')?.textContent).toContain("Confirm it's you")
    fireEvent.change(byId('step-up-password') as HTMLInputElement, {
      target: { value: 'secret' },
    })
    fireEvent.click(byId('hilos-2fa-disable-step-up-confirm') as HTMLElement)
    expect(world.sent[1]).toMatchObject({
      action: 'hilos_step_up_confirm',
      payload: { operation: 'disable_second_factor', password: 'secret' },
    })

    await answered(world, 'success')
    expect(byId('modal')?.textContent).toContain('Turn two-factor off')
    const lines = Array.from(
      document.querySelectorAll(
        '[data-id="hilos-2fa-disable-consequences"] li',
      ),
    ).map((item) => item.textContent?.trim())
    expect(lines).toHaveLength(6)
    expect(lines[2]).toBe(
      'Trusted browsers are forgotten: once it is back on, they ask for the code again.',
    )

    fireEvent.click(byId('hilos-2fa-disable-confirm') as HTMLElement)
    expect(world.sent[2]).toMatchObject({
      action: 'security_2fa_switch_set',
      payload: { key: ENABLED, enabled: false },
    })
    await answered(world, 'success')
    expect(byId('hilos-2fa-disable-window')).toBeNull()
  })

  it('keeps the window open with the refusal above the buttons', async () => {
    const world = seededContext()
    mountPage(world.context)
    await openDisable(world, false)

    expect(byId('hilos-2fa-disable-refusal')).toBeNull()
    fireEvent.click(byId('hilos-2fa-disable-confirm') as HTMLElement)
    await answered(world, 'fail')

    expect(byId('hilos-2fa-disable-refusal')?.textContent).toContain(
      'The setting refused the write.',
    )
    expect(byId('hilos-2fa-disable-confirm')).not.toBeNull()
  })

  it('says why the step could not open, with Cancel alone', async () => {
    const world = seededContext()
    mountPage(world.context)
    fireEvent.click(inTable(`hilos-2fa-switch-${ENABLED}`))
    await answered(world, 'fail')

    expect(byId('hilos-2fa-disable-refusal')?.textContent).toContain(
      'The setting refused the write.',
    )
    expect(byId('hilos-2fa-disable-confirm')).toBeNull()
    expect(byId('hilos-2fa-disable-step-up-confirm')).toBeNull()
    fireEvent.click(byId('hilos-2fa-disable-cancel') as HTMLElement)
    expect(byId('hilos-2fa-disable-window')).toBeNull()
  })

  it('closes by itself once the first row says off, whoever wrote it', async () => {
    const world = seededContext()
    mountPage(world.context)
    await openDisable(world, false)
    expect(byId('hilos-2fa-disable-window')).not.toBeNull()

    act(() => world.pushUpdate(ENABLED, 'false'))

    expect(byId('hilos-2fa-disable-window')).toBeNull()
  })
})

describe('HilosSecurity2faPage in the admin view mode', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
    for (const release of releases.splice(0)) release()
  })

  function bindSession(scopes: ScopeManager) {
    const listeners: ((signal: ProjectSignal) => void)[] = []
    const connection = {
      on(event: string, listener: (payload: never) => void): () => void {
        if (event === 'projectSignal') {
          listeners.push(listener as (signal: ProjectSignal) => void)
        }

        return () => {}
      },
    } as unknown as HilosConnection
    bindSessionScope(connection, scopes)
    releases.push(bindAdminAccess(scopes))

    return {
      handshake(
        user: { id: number; admin: boolean } | null,
        viewMode: boolean,
      ): void {
        const signal = {
          kind: 'project',
          type: 'handshake_response',
          data: {
            entities: {
              currentUser: user === null ? null : { ...user, name: 'Olena' },
            },
            data: { adminViewMode: viewMode },
          },
          envelope: {},
        } as unknown as ProjectSignal
        for (const listener of listeners) {
          listener(signal)
        }
      },
    }
  }

  it('a viewer opens a setting edit, sees the hidden mark instead of an input, and closes via Cancel', () => {
    const { context, sent } = seededContext(true)
    bindSession(context.scopes).handshake(null, true)
    openModal(context, REQUIRED)

    expect(byId('hilos-2fa-input')).toBeNull()
    expect(byId('hilos-hidden')).not.toBeNull()
    expect(saveButton().disabled).toBe(true)
    fireEvent.click(saveButton())
    expect(sent).toEqual([])

    const cancel = Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
    expect(cancel?.disabled).toBe(false)
    fireEvent.click(cancel as HTMLElement)

    expect(byId('modal-confirm-discard')).toBeNull()
    expect(byId('modal')).toBeNull()
  })

  it('a viewer finds both switches locked, and the window does not open', () => {
    const { context, sent } = seededContext()
    bindSession(context.scopes).handshake(null, true)
    mountPage(context)

    const first = inTable<HTMLInputElement>(`hilos-2fa-switch-${ENABLED}`)
    expect(first.disabled).toBe(true)
    expect(
      inTable<HTMLInputElement>(`hilos-2fa-switch-${FORGET}`).disabled,
    ).toBe(true)
    fireEvent.click(first)
    expect(sent).toEqual([])
    expect(byId('hilos-2fa-disable-window')).toBeNull()
  })

  it('an admin on a node in the mode has Save active after typing a draft', () => {
    const { context } = seededContext()
    bindSession(context.scopes).handshake({ id: 1, admin: true }, true)
    openModal(context, REQUIRED)

    typeDraft('everyone')
    expect(saveButton().disabled).toBe(false)
    expect(saveButton().getAttribute('aria-describedby')).toBeNull()
  })
})
