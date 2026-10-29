import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  HIDDEN_VALUE,
  HILOS_MAINTENANCE_CIRCLE_COPY,
  HILOS_MAINTENANCE_DISABLE_COPY,
  HILOS_MAINTENANCE_ENABLE_COPY,
  HILOS_MAINTENANCE_PASS_COPY,
  HILOS_MAINTENANCE_STATE_COPY,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  MAINTENANCE_STATE_SIGNAL,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
  hilosToasts,
} from '@hilos/core'
import {
  formatMoment,
  hilosBrowserTimeZone,
  hilosTimeZone,
} from '@hilos/core/base'
import type {
  ActionHandle,
  ActionLifecycle,
  ActionResult,
  Hideable,
  HilosConnection,
  HilosMaintenanceContext,
  HilosMaintenanceState,
  HilosRouter,
  PageRouteMatch,
  ProjectSignal,
  WritableSignal,
} from '@hilos/core'

import { HilosMaintenancePage } from '../src/admin/maintenance/HilosMaintenancePage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import { readerTimeZone } from './readerTimeZone.js'

const CIRCLE_TABLE = 'hilosVerifierCircle'

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.MAINTENANCE,
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

interface CircleMember {
  memberId: number
  identityType: string
  identifier: Hideable<string>
  online: boolean
}

/** The wire row of one member: the id is the row key, the rest rides the slot. */
function wireRow(member: CircleMember): {
  rowKey: string
  slots: Record<string, unknown>
} {
  return {
    rowKey: String(member.memberId),
    slots: {
      verifierCircle: {
        identityType: member.identityType,
        identifier: member.identifier,
        online: member.online,
      },
    },
  }
}

interface Dispatched {
  action: string
  payload: Record<string, unknown>
  loading: WritableSignal<boolean>
  settle: (result: ActionResult) => void
  refuse: (error: ActionError) => void
}

/**
 * An action lifecycle that records what was dispatched and hands the answer back to
 * the test: the add dialog closes on the server's word, so a fake that settled by
 * itself would hide exactly the step under test.
 */
function makeActions(): {
  actions: ActionLifecycle
  dispatched: Dispatched[]
} {
  const dispatched: Dispatched[] = []
  const actions = {
    dispatch(action: string, payload: Record<string, unknown>): ActionHandle {
      let settle: (result: ActionResult) => void = () => {}
      let refuse: (error: ActionError) => void = () => {}
      const done = new Promise<ActionResult>((resolve, reject) => {
        settle = resolve
        refuse = reject
      })
      const loading = createSignal(false)
      dispatched.push({ action, payload, loading, settle, refuse })

      return {
        requestId: String(dispatched.length),
        loading,
        done,
      }
    },
  } as unknown as ActionLifecycle

  return { actions, dispatched }
}

function seededContext(
  initial: CircleMember[],
  actions: ActionLifecycle = makeActions().actions,
): {
  context: HilosMaintenanceContext
  focus: string[]
  pushState: (state: HilosMaintenanceState) => void
  pushUpdate: (next: CircleMember) => void
  pushRemove: (memberId: number, own?: boolean) => void
} {
  const rows = initial.slice()
  const focus: string[] = []
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.MAINTENANCE)
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const deltaListeners = new Set<(signal: { data: unknown }) => void>()
  const stateListeners = new Set<
    (signal: { type: string; data: unknown }) => void
  >()
  const serveWindow = (
    page: string = HilosPages.MAINTENANCE,
    tableKey: string = CIRCLE_TABLE,
  ): void => {
    const data = {
      page,
      tableKey,
      rows: rows.map(wireRow),
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

  const connection = {
    registerTableWindow(): void {
      serveWindow()
    },
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(page: string, tableKey: string): boolean {
      serveWindow(page, tableKey)

      return true
    },
    sendTableRowFocus(page: string, tableKey: string, rowKey: string): boolean {
      focus.push(rowKey)

      return true
    },
    sendTableRendered(): boolean {
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
      if (event === 'projectSignal') {
        stateListeners.add(
          listener as (signal: { type: string; data: unknown }) => void,
        )

        return () =>
          stateListeners.delete(
            listener as (signal: { type: string; data: unknown }) => void,
          )
      }

      return () => {}
    },
  }

  return {
    context: {
      connection:
        connection as unknown as HilosMaintenanceContext['connection'],
      scopes,
      actions,
    },
    focus,
    // The section's state frame, as the connection hands it to every listener.
    pushState(state: HilosMaintenanceState): void {
      for (const listener of stateListeners) {
        listener({ type: MAINTENANCE_STATE_SIGNAL, data: state })
      }
    },
    pushUpdate(next: CircleMember): void {
      for (const listener of deltaListeners) {
        listener({
          data: {
            page: HilosPages.MAINTENANCE,
            tableKey: CIRCLE_TABLE,
            kind: 'row_updated',
            rowKey: String(next.memberId),
            row: wireRow(next),
          },
        })
      }
    },
    // The membership was taken out: a removal without a body, tagged as this tab's own
    // when this tab is the one that took it out.
    pushRemove(memberId: number, own = false): void {
      for (const listener of deltaListeners) {
        listener({
          data: {
            page: HilosPages.MAINTENANCE,
            tableKey: CIRCLE_TABLE,
            kind: 'row_removed',
            rowKey: String(memberId),
            reason: 'deleted',
            own,
          },
        })
      }
    },
  }
}

afterEach(() => {
  cleanup()
  document.body.classList.remove('modal-open')
  hilosToasts.clear()
})

function mountPage(context: HilosMaintenanceContext) {
  return render(
    <HilosRouterContext.Provider value={router()}>
      <HilosMaintenancePage context={context} />
    </HilosRouterContext.Provider>,
  )
}

// A row stands in the document twice — once in the table and once in the card the
// same row becomes on a narrow screen — so a mark is looked up through the table.
function mark(identifier: string): HTMLElement | null {
  return document.querySelector(
    `table [data-id="hilos-maintenance-circle-online-${identifier}"]`,
  )
}

/** Flush the action's promise and the resulting React render together. */
async function settled(change: () => void): Promise<void> {
  await act(async () => {
    change()
  })
}

/** The add dialog's field, or null while the dialog is closed. */
function addField(): HTMLInputElement | null {
  return document.querySelector(
    '[data-id="hilos-maintenance-circle-add-field"]',
  )
}

/** The add dialog's confirm button; the dialog must be open. */
function addConfirm(): HTMLButtonElement {
  return document.querySelector(
    '[data-id="hilos-maintenance-circle-add-confirm"]',
  ) as HTMLButtonElement
}

/** The row's remove button, looked up through the table. */
function trash(identifier: string): HTMLButtonElement {
  return document.querySelector(
    `table [data-id="hilos-maintenance-circle-remove-${identifier}"]`,
  ) as HTMLButtonElement
}

/** The remove dialog's confirm button, or null while the dialog is closed. */
function removeConfirm(): HTMLButtonElement | null {
  return document.querySelector(
    '[data-id="hilos-maintenance-circle-remove-confirm"]',
  )
}

/** The one member the remove cases open their dialog over. */
const ANN: CircleMember = {
  memberId: 1,
  identityType: 'password',
  identifier: 'ann@example.test',
  online: true,
}

function maintenanceState(
  overrides: Partial<HilosMaintenanceState> = {},
): HilosMaintenanceState {
  return {
    phase: 'inactive',
    operation: null,
    manual: false,
    startedAt: null,
    enteredFromBrowser: false,
    passCount: 0,
    circleNamed: 0,
    circleAdmitted: 0,
    initiatorAgentType: null,
    initiatorNodeId: null,
    activatedAt: null,
    progressAt: null,
    isInitiator: false,
    diagnosticsNodeId: null,
    stoppedAgentCount: null,
    preservedAgentTypes: null,
    agentStartGateClosed: null,
    watchdogVerdict: null,
    watchdogPendingNodeIds: [],
    watchdogQuiesceTimeoutSeconds: null,
    watchdogSilenceTimeoutSeconds: null,
    watchdogAlertIntervalSeconds: null,
    watchdogAlertEmails: null,
    watchdogManualRestartIsNormal: null,
    ...overrides,
  }
}

/** The manual window's frame: closed to visitors by hand, verification open. */
function manualState(
  overrides: Partial<HilosMaintenanceState> = {},
): HilosMaintenanceState {
  return maintenanceState({
    phase: 'verifying',
    operation: 'manual_maintenance',
    manual: true,
    ...overrides,
  })
}

/**
 * An element by its data-id anywhere in the document, or null while it is not
 * drawn — a dialog portals out of the page's container.
 *
 * @param dataId The element's data-id.
 */
function byId<T extends HTMLElement = HTMLElement>(dataId: string): T | null {
  return document.querySelector<T>(`[data-id="${dataId}"]`)
}

/**
 * The dialog around a confirmation's body, which must already be open.
 *
 * @param bodyId The data-id of the body the page draws inside the dialog.
 */
function dialogOf(bodyId: string): HTMLElement {
  return byId(bodyId)?.closest<HTMLElement>('[role="dialog"]') as HTMLElement
}

/** Ways a dialog is closed before anything is sent. */
type CloseMethod = 'cancel' | 'close' | 'escape' | 'backdrop'

/**
 * Close a dialog the way a person does.
 *
 * @param dialog The dialog element.
 * @param cancel The dialog's own Cancel button.
 * @param method Which of the four ways.
 */
async function closeBy(
  dialog: HTMLElement,
  cancel: HTMLButtonElement,
  method: CloseMethod,
): Promise<void> {
  await settled(() => {
    if (method === 'cancel') fireEvent.click(cancel)
    if (method === 'close')
      fireEvent.click(
        dialog.querySelector('[data-id="modal-close"]') as HTMLButtonElement,
      )
    if (method === 'escape') fireEvent.keyDown(dialog, { key: 'Escape' })
    if (method === 'backdrop') fireEvent.click(dialog)
  })
}

/**
 * Try every way of closing a dialog at once — the busy dialog must refuse them all.
 *
 * @param dialog The dialog element.
 * @param cancel The dialog's own Cancel button.
 */
async function tryEveryClose(
  dialog: HTMLElement,
  cancel: HTMLButtonElement,
): Promise<void> {
  for (const method of ['cancel', 'close', 'escape', 'backdrop'] as const) {
    await closeBy(dialog, cancel, method)
  }
}

/** Open the add dialog and enter the address without normalizing it. */
function openAndType(typed: string): void {
  fireEvent.click(
    document.querySelector(
      '[data-id="hilos-table-main-action"]',
    ) as HTMLButtonElement,
  )
  fireEvent.change(addField() as HTMLInputElement, { target: { value: typed } })
}

describe('HilosMaintenancePage reader-zone moments', () => {
  it.each([true, false])(
    'reformats the held startedAt and keeps its circle draft when manual=%s',
    async (manual) => {
      vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] })
      vi.setSystemTime(new Date('2026-01-01T00:32:35.123Z'))
      const reader = readerTimeZone()
      const browserZone = hilosBrowserTimeZone.get() ?? 'UTC'
      const firstZone =
        browserZone === 'America/Los_Angeles' || browserZone === 'Europe/Kyiv'
          ? 'Pacific/Honolulu'
          : 'America/Los_Angeles'
      const nextZone =
        browserZone === 'Europe/Kyiv' || browserZone === 'Pacific/Honolulu'
          ? 'Asia/Tokyo'
          : 'Europe/Kyiv'
      const startedAt = '2026-01-01T00:30:05.123456Z'

      try {
        act(() => reader.choose(firstZone))
        const { context, pushState } = seededContext([ANN])
        const view = mountPage(context)
        const state = maintenanceState({
          phase: 'verifying',
          operation: manual ? 'manual_maintenance' : 'restore',
          manual,
          startedAt,
          passCount: 2,
        })
        await settled(() => pushState(state))

        const card = byId(
          `hilos-maintenance-state-${manual ? 'manual' : 'operation'}`,
        )
        const timestamp = byId('hilos-maintenance-started-at')
        const table = view.container.querySelector('table')
        const row = view.container.querySelector(
          'table [data-id="hilos-table-row-1"]',
        )
        expect(firstZone).not.toBe(browserZone)
        expect(timestamp?.textContent).toBe(
          formatMoment(startedAt, { kind: 'dateTime' }),
        )
        const firstTimestamp = timestamp?.textContent
        expect(card?.textContent).toContain('verifying')
        expect(byId('hilos-maintenance-pass-count')?.textContent).toBe('2')

        openAndType('reviewer@example.test')
        const field = addField()
        const dialog = field?.closest('[role="dialog"]')
        const confirm = addConfirm()
        field?.focus()
        expect(confirm.disabled).toBe(false)

        act(() => reader.choose(nextZone))

        expect(nextZone).not.toBe(browserZone)
        expect(hilosTimeZone.get()).toBe(nextZone)
        expect(timestamp?.textContent).toBe(
          formatMoment(startedAt, { kind: 'dateTime' }),
        )
        expect(timestamp?.textContent).not.toBe(firstTimestamp)
        expect(card).toBe(
          byId(`hilos-maintenance-state-${manual ? 'manual' : 'operation'}`),
        )
        expect(card?.textContent).toContain('verifying')
        expect(byId('hilos-maintenance-pass-count')?.textContent).toBe('2')
        expect(view.container.querySelector('table')).toBe(table)
        expect(
          view.container.querySelector('table [data-id="hilos-table-row-1"]'),
        ).toBe(row)
        expect(field).toBe(addField())
        expect(field?.value).toBe('reviewer@example.test')
        expect(document.activeElement).toBe(field)
        expect(field?.closest('[role="dialog"]')).toBe(dialog)
        expect(addConfirm()).toBe(confirm)
        expect(confirm.disabled).toBe(false)

        if (manual) {
          expect(card?.textContent).toContain('2 minutes ago')
          await act(async () => {
            await vi.advanceTimersByTimeAsync(30_000)
          })
          expect(card?.textContent).toContain('3 minutes ago')
          expect(timestamp?.textContent).toBe(
            formatMoment(startedAt, { kind: 'dateTime' }),
          )
        } else {
          expect(card?.textContent).not.toContain('minutes ago')
          expect(card?.textContent).not.toContain(
            HILOS_MAINTENANCE_STATE_COPY.justNow,
          )
        }
        expect(state.startedAt).toBe(startedAt)
        view.unmount()
      } finally {
        cleanup()
        reader.release()
        vi.useRealTimers()
      }
    },
  )

  it('treats an ISO UTC epoch-zero startedAt as a moment and rejects absent or invalid values', async () => {
    const reader = readerTimeZone()
    const epochZero = '1970-01-01T00:00:00.000000Z'

    try {
      act(() => reader.choose('Pacific/Kiritimati'))
      const { context, pushState } = seededContext([])
      mountPage(context)

      for (const manual of [true, false]) {
        await settled(() =>
          pushState(
            maintenanceState({
              phase: 'verifying',
              operation: manual ? 'manual_maintenance' : 'restore',
              manual,
              startedAt: epochZero,
            }),
          ),
        )
        expect(byId('hilos-maintenance-started-at')?.textContent).toBe(
          formatMoment(epochZero, { kind: 'dateTime' }),
        )
        expect(byId('hilos-maintenance-started-at')?.textContent).not.toBe('—')

        for (const startedAt of [
          null,
          'malformed',
          '2026-02-30T00:00:00.000000Z',
        ]) {
          await settled(() =>
            pushState(
              maintenanceState({
                phase: 'verifying',
                operation: manual ? 'manual_maintenance' : 'restore',
                manual,
                startedAt,
              }),
            ),
          )
          expect(byId('hilos-maintenance-started-at')?.textContent).toBe('—')
        }
      }
    } finally {
      cleanup()
      reader.release()
    }
  })
})

describe('HilosMaintenancePage', () => {
  it('offers enable only after the open frame arrives', async () => {
    const { context, pushState } = seededContext([])
    await mountPage(context)
    expect(byId('hilos-maintenance-state-open')).toBeNull()
    expect(byId('hilos-maintenance-enable')).toBeNull()

    await settled(() => pushState(maintenanceState()))

    const stateBlock = byId('hilos-maintenance-state') as HTMLElement
    expect(stateBlock.textContent).toContain(
      HILOS_MAINTENANCE_STATE_COPY.openTitle,
    )
    expect(stateBlock.textContent).toContain(
      HILOS_MAINTENANCE_STATE_COPY.openDescription,
    )
    expect(
      stateBlock.querySelector('[data-id="hilos-maintenance-enable"]')
        ?.textContent,
    ).toBe(HILOS_MAINTENANCE_ENABLE_COPY.enableTitle)
  })

  it.each(['disable', 'pass'] as const)(
    'offers %s only in the manual card, for either entry source',
    async (button) => {
      const { context, pushState } = seededContext([])
      await mountPage(context)
      const shown = () => byId(`hilos-maintenance-${button}`) !== null
      expect(shown()).toBe(false)
      for (const state of [
        maintenanceState(),
        maintenanceState({ phase: 'verifying', operation: 'restore' }),
      ]) {
        await settled(() => pushState(state))
        expect(shown()).toBe(false)
      }
      for (const enteredFromBrowser of [true, false]) {
        await settled(() => pushState(manualState({ enteredFromBrowser })))
        expect(shown()).toBe(true)
      }
    },
  )

  it('renders the manual card and redraws its pass count on a new frame', async () => {
    const { context, pushState } = seededContext([])
    await mountPage(context)
    const manual = manualState({
      startedAt: new Date(Date.now() - 150_000).toISOString(),
      enteredFromBrowser: false,
      circleNamed: 3,
      circleAdmitted: 2,
    })

    await settled(() => pushState(manual))

    const card = byId('hilos-maintenance-state-manual') as HTMLElement
    expect(card.tagName).toBe('SECTION')
    expect(card.getAttribute('aria-label')).toBe(
      HILOS_MAINTENANCE_STATE_COPY.manualTitle,
    )
    expect(card.textContent).toContain(
      HILOS_MAINTENANCE_STATE_COPY.turnedOnFromConsole,
    )
    expect(card.textContent).toContain('3 named · 2 let in')
    expect(card.textContent).toContain('2 minutes ago')
    expect(
      card.querySelector('[data-id="hilos-maintenance-disable"]')?.textContent,
    ).toBe(HILOS_MAINTENANCE_DISABLE_COPY.disableTitle)
    expect(
      card.querySelector('[data-id="hilos-maintenance-pass"]')?.textContent,
    ).toBe(HILOS_MAINTENANCE_PASS_COPY.issue)

    await settled(() => pushState({ ...manual, passCount: 1 }))

    expect(byId('hilos-maintenance-pass-count')?.textContent).toBe('1')
  })

  it("moves the manual card's elapsed time on the browser's clock", async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] })
    try {
      const { context, pushState } = seededContext([])
      const { unmount } = await mountPage(context)
      await settled(() =>
        pushState(manualState({ startedAt: new Date().toISOString() })),
      )
      const card = () => byId('hilos-maintenance-state-manual') as HTMLElement
      expect(card().textContent).toContain(HILOS_MAINTENANCE_STATE_COPY.justNow)

      await settled(() => vi.advanceTimersByTime(120_000))

      expect(card().textContent).toContain('2 minutes ago')
      unmount()
      expect(vi.getTimerCount()).toBe(0)
    } finally {
      vi.useRealTimers()
    }
  })

  it('renders only the planned operation lines in the frozen card', async () => {
    const { context, pushState } = seededContext([])
    await mountPage(context)
    const startedAt = '2026-10-10T10:00:00.000000Z'

    await settled(() =>
      pushState(
        maintenanceState({
          phase: 'verifying',
          operation: 'restore',
          startedAt,
          passCount: 2,
        }),
      ),
    )

    const card = byId('hilos-maintenance-state-operation') as HTMLElement
    expect(card.textContent).toContain(
      HILOS_MAINTENANCE_STATE_COPY.operationTitle,
    )
    expect(card.textContent).toContain(
      HILOS_MAINTENANCE_STATE_COPY.verifyingPhaseNote,
    )
    expect(card.textContent).toContain('restore')
    expect(card.textContent).toContain(
      formatMoment(startedAt, { kind: 'dateTime' }) ?? '—',
    )
    expect(card.textContent).not.toContain('Initiator')
    expect(card.querySelector('button')).toBeNull()
  })

  it('renders a row per member with the mark of whether they are signed in', async () => {
    const { context } = seededContext([
      {
        memberId: 1,
        identityType: 'password',
        identifier: 'ann@example.test',
        online: true,
      },
      {
        memberId: 2,
        identityType: 'sms',
        identifier: '+10000000001',
        online: false,
      },
    ])
    const wrapper = await mountPage(context)

    expect(
      wrapper.container.querySelectorAll('[data-id^="hilos-table-row-"]')
        .length,
    ).toBe(2)
    expect(mark('ann@example.test')?.textContent).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.online,
    )
    expect(mark('+10000000001')?.textContent).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.offline,
    )
    expect(
      document.querySelector(
        '[data-id="hilos-table-cards"] [data-id="hilos-maintenance-circle-online-ann@example.test"]',
      )?.textContent,
    ).toBe(HILOS_MAINTENANCE_CIRCLE_COPY.online)
    expect(
      document
        .querySelector('[data-id="hilos-table-title"]')
        ?.textContent?.trim(),
    ).toBe(HILOS_MAINTENANCE_CIRCLE_COPY.title)
    expect(
      document.querySelector('[data-id="hilos-table-subtitle"]')?.textContent,
    ).toContain(HILOS_MAINTENANCE_CIRCLE_COPY.rule)
    expect(
      document.querySelector('[data-id="hilos-table-subtitle"]')?.textContent,
    ).toContain(HILOS_MAINTENANCE_CIRCLE_COPY.volatile)
    expect(
      document
        .querySelector('[data-id="hilos-table-count"]')
        ?.textContent?.trim(),
    ).toBe('1 – 2 of 2')
  })

  it('moves the mark in place when the live row changes', async () => {
    const member = {
      memberId: 1,
      identityType: 'password',
      identifier: 'ann@example.test',
      online: true,
    }
    const { context, pushUpdate } = seededContext([member])
    await mountPage(context)

    await settled(() => pushUpdate({ ...member, online: false }))

    expect(mark('ann@example.test')?.textContent).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.offline,
    )
  })

  it('says what an empty circle means instead of drawing rows', async () => {
    const { context } = seededContext([])
    const wrapper = await mountPage(context)

    expect(
      wrapper.container.querySelectorAll('[data-id^="hilos-table-row-"]')
        .length,
    ).toBe(0)
    expect(wrapper.container.textContent).toContain(
      HILOS_MAINTENANCE_CIRCLE_COPY.empty.title,
    )
    expect(wrapper.container.textContent).toContain(
      HILOS_MAINTENANCE_CIRCLE_COPY.empty.hint,
    )
    const emptyActions = [
      ...document.querySelectorAll<HTMLButtonElement>(
        'table [data-id="hilos-table-empty-action"]',
      ),
      ...document.querySelectorAll<HTMLButtonElement>(
        '[data-id="hilos-table-cards"] [data-id="hilos-table-empty-action"]',
      ),
    ]
    expect(emptyActions).toHaveLength(2)
    for (const action of emptyActions) {
      expect(action.textContent?.trim()).toBe(
        HILOS_MAINTENANCE_CIRCLE_COPY.addButton,
      )
      await settled(() => fireEvent.click(action))
      expect(addField()).not.toBeNull()
      await settled(() =>
        fireEvent.click(
          document.querySelector(
            '[data-id="hilos-maintenance-circle-add-cancel"]',
          ) as HTMLButtonElement,
        ),
      )
      expect(addField()).toBeNull()
    }
  })

  it('offers adding a member and removing one', async () => {
    const { context } = seededContext([ANN])
    const wrapper = await mountPage(context)
    const panel = wrapper.container.querySelector(
      '[data-id="hilos-maintenance-circle-panel"]',
    )

    expect(
      panel?.querySelector('[data-id="hilos-table-main-action"]')?.textContent,
    ).toBe(HILOS_MAINTENANCE_CIRCLE_COPY.addButton)
    expect(trash('ann@example.test').getAttribute('aria-label')).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.removeTitle,
    )
    expect(trash('ann@example.test').querySelector('.bi-trash')).not.toBeNull()
    expect(wrapper.container.textContent).toContain(
      HILOS_MAINTENANCE_CIRCLE_COPY.title,
    )
  })

  it('opens the remove dialog over the row, naming its address and taking it into focus', async () => {
    const { context, focus } = seededContext([ANN])
    await mountPage(context)

    fireEvent.click(trash('ann@example.test'))

    expect(focus).toEqual(['1'])
    expect(document.querySelector('[data-id="modal"] code')?.textContent).toBe(
      'ann@example.test',
    )
    expect(document.body.textContent).toContain(
      HILOS_MAINTENANCE_CIRCLE_COPY.removeNote,
    )
    expect(removeConfirm()?.disabled).toBe(false)
    expect(removeConfirm()?.textContent?.trim()).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.removeConfirm,
    )
  })

  it("takes the member out by the row key and closes only on the server's word", async () => {
    const { actions, dispatched } = makeActions()
    const { context, focus } = seededContext([ANN], actions)
    await mountPage(context)

    fireEvent.click(trash('ann@example.test'))
    fireEvent.click(removeConfirm() as HTMLButtonElement)

    expect(dispatched).toMatchObject([
      { action: 'maintenance_circle_remove', payload: { memberId: 1 } },
    ])
    expect(removeConfirm()).not.toBeNull()

    await settled(() =>
      dispatched[0]?.settle({
        action: 'maintenance_circle_remove',
      } as ActionResult),
    )

    expect(removeConfirm()).toBeNull()
    expect(focus).toEqual(['1', ''])
  })

  it('keeps the remove dialog open with the refusal on it', async () => {
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([ANN], actions)
    await mountPage(context)

    fireEvent.click(trash('ann@example.test'))
    fireEvent.click(removeConfirm() as HTMLButtonElement)
    await settled(() =>
      dispatched[0]?.refuse(
        new ActionError(
          'maintenance_circle_remove',
          'fail',
          'This verifier is no longer in the circle',
        ),
      ),
    )

    expect(removeConfirm()).not.toBeNull()
    expect(
      document.querySelector('[data-id="hilos-action-error"]')?.textContent,
    ).toContain('This verifier is no longer in the circle')
  })

  it('says the row was taken out elsewhere and locks Remove', async () => {
    const { context, pushRemove } = seededContext([ANN])
    await mountPage(context)

    fireEvent.click(trash('ann@example.test'))
    await settled(() => pushRemove(1))

    expect(
      document.querySelector(
        '[data-id="hilos-maintenance-circle-remove-notice"]',
      )?.textContent,
    ).toContain(HILOS_MAINTENANCE_CIRCLE_COPY.removeGone)
    expect(removeConfirm()?.textContent?.trim()).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.removeGoneConfirm,
    )
    expect(removeConfirm()?.disabled).toBe(true)
    // The address the dialog opened with stays on screen.
    expect(document.querySelector('[data-id="modal"] code')?.textContent).toBe(
      'ann@example.test',
    )
  })

  it('does not read its own removal, echoed before the answer, as taken out elsewhere', async () => {
    const { actions, dispatched } = makeActions()
    const { context, pushRemove } = seededContext([ANN], actions)
    await mountPage(context)

    fireEvent.click(trash('ann@example.test'))
    fireEvent.click(removeConfirm() as HTMLButtonElement)
    await settled(() => pushRemove(1, true))

    // No message: the notice's room is held by its invisible twin only.
    expect(
      document.querySelector(
        '[data-id="hilos-maintenance-circle-remove-notice"]',
      ),
    ).toBeNull()
    expect(removeConfirm()?.textContent?.trim()).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.removeConfirm,
    )

    await settled(() =>
      dispatched[0]?.settle({
        action: 'maintenance_circle_remove',
      } as ActionResult),
    )

    expect(removeConfirm()).toBeNull()
  })

  it('opens the add dialog with an empty field and holds Add until something is typed', async () => {
    const { context } = seededContext([])
    await mountPage(context)

    openAndType('   ')

    expect(document.body.textContent).toContain(
      HILOS_MAINTENANCE_CIRCLE_COPY.addLead,
    )
    expect(addField()?.placeholder).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.addPlaceholder,
    )
    expect(addConfirm().disabled).toBe(true)

    fireEvent.change(addField() as HTMLInputElement, {
      target: { value: 'ann@example.test' },
    })

    expect(addConfirm().disabled).toBe(false)
  })

  it("names the address as typed and closes only on the server's word", async () => {
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([], actions)
    await mountPage(context)

    openAndType('+7 900 000-00-00')
    fireEvent.click(addConfirm())

    expect(dispatched).toMatchObject([
      {
        action: 'maintenance_circle_add',
        payload: { identifier: '+7 900 000-00-00' },
      },
    ])
    expect(addField()).not.toBeNull()

    await settled(() =>
      dispatched[0]?.settle({
        action: 'maintenance_circle_add',
      } as ActionResult),
    )

    expect(addField()).toBeNull()
  })

  it('keeps the dialog open with the typed address and the refusal on it', async () => {
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([], actions)
    await mountPage(context)

    openAndType('nobody@example.test')
    fireEvent.click(addConfirm())
    await settled(() =>
      dispatched[0]?.refuse(
        new ActionError(
          'maintenance_circle_add',
          'fail',
          'Nobody has proven this address',
        ),
      ),
    )

    expect(addField()?.value).toBe('nobody@example.test')
    expect(
      document.querySelector('[data-id="hilos-action-error"]')?.textContent,
    ).toContain('Nobody has proven this address')
    expect(addConfirm().disabled).toBe(false)
  })

  it('opens the dialog again with an empty field and no refusal left over', async () => {
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([], actions)
    await mountPage(context)

    openAndType('nobody@example.test')
    fireEvent.click(addConfirm())
    await settled(() =>
      dispatched[0]?.refuse(
        new ActionError(
          'maintenance_circle_add',
          'fail',
          'Nobody has proven this address',
        ),
      ),
    )
    fireEvent.click(
      document.querySelector(
        '[data-id="modal"] .modal-footer .btn-secondary',
      ) as HTMLButtonElement,
    )
    expect(addField()).toBeNull()
    fireEvent.click(
      document.querySelector(
        '[data-id="hilos-table-main-action"]',
      ) as HTMLButtonElement,
    )

    expect(addField()?.value).toBe('')
    expect(document.body.textContent).not.toContain(
      'Nobody has proven this address',
    )
  })
})

/** One of the three manual actions, with what its confirmation says and guards. */
interface ManualCase {
  /** The action's name in its data-ids. */
  name: 'enable' | 'disable' | 'pass'
  /** The tracked action it sends. */
  action: string
  /** The frame its opener stands on. */
  opensFrom: HilosMaintenanceState
  /** Frames on which an unsent confirmation may not be sent any more. */
  leaves: HilosMaintenanceState[]
  /** The block the first of those frames draws. */
  leftBlock: string
  /** The confirmation's title, which names its dialog. */
  title: string
  /** Every sentence the confirmation explains itself with. */
  texts: string[]
  /** The heading of the refusal's details. */
  refusalTitle: string
  /** The action's note for an outcome nobody confirmed. */
  unconfirmedNote: string
  /** The success sentence the server sends. */
  successMessage: string
}

const MANUAL_CASES: ManualCase[] = [
  {
    name: 'enable',
    action: 'maintenance_enable',
    opensFrom: maintenanceState(),
    leaves: [
      manualState(),
      maintenanceState({ phase: 'active', operation: 'restore' }),
    ],
    leftBlock: 'hilos-maintenance-state-manual',
    title: HILOS_MAINTENANCE_ENABLE_COPY.enableTitle,
    texts: [
      HILOS_MAINTENANCE_ENABLE_COPY.lead,
      HILOS_MAINTENANCE_ENABLE_COPY.outsideNote,
      HILOS_MAINTENANCE_ENABLE_COPY.insideNote,
      HILOS_MAINTENANCE_ENABLE_COPY.agentsNote,
      HILOS_MAINTENANCE_ENABLE_COPY.footer,
    ],
    refusalTitle: HILOS_MAINTENANCE_ENABLE_COPY.refusalTitle,
    unconfirmedNote: HILOS_MAINTENANCE_ENABLE_COPY.unconfirmedNote,
    successMessage: 'Maintenance is on — the system is closed to visitors',
  },
  {
    name: 'disable',
    action: 'maintenance_disable',
    opensFrom: manualState(),
    leaves: [
      maintenanceState(),
      maintenanceState({ phase: 'active', operation: 'restore' }),
    ],
    leftBlock: 'hilos-maintenance-state-open',
    title: HILOS_MAINTENANCE_DISABLE_COPY.disableTitle,
    texts: [
      HILOS_MAINTENANCE_DISABLE_COPY.lead,
      HILOS_MAINTENANCE_DISABLE_COPY.footer,
    ],
    refusalTitle: HILOS_MAINTENANCE_DISABLE_COPY.refusalTitle,
    unconfirmedNote: HILOS_MAINTENANCE_DISABLE_COPY.unconfirmedNote,
    successMessage: 'Maintenance is off — pages are reloading',
  },
  {
    name: 'pass',
    action: 'maintenance_pass',
    opensFrom: manualState(),
    leaves: [
      maintenanceState(),
      maintenanceState({ phase: 'active', operation: 'restore' }),
    ],
    leftBlock: 'hilos-maintenance-state-open',
    title: HILOS_MAINTENANCE_PASS_COPY.mintTitle,
    texts: [
      HILOS_MAINTENANCE_PASS_COPY.lead,
      HILOS_MAINTENANCE_PASS_COPY.reuseNote,
    ],
    refusalTitle: HILOS_MAINTENANCE_PASS_COPY.refusalTitle,
    unconfirmedNote: HILOS_MAINTENANCE_PASS_COPY.unconfirmedNote,
    successMessage: 'Code issued',
  },
]

/**
 * A button of a manual action's confirmation, which must already be open.
 *
 * @param name The action's name in its data-ids.
 * @param button Which of the two buttons.
 */
function manualButton(
  name: ManualCase['name'],
  button: 'confirm' | 'cancel',
): HTMLButtonElement {
  return byId<HTMLButtonElement>(
    `hilos-maintenance-${name}-${button}`,
  ) as HTMLButtonElement
}

/**
 * Mount the real page and open a manual action's confirmation from a server frame.
 *
 * @param manual The action and the frame its opener stands on.
 */
async function openManual(manual: Pick<ManualCase, 'name' | 'opensFrom'>) {
  const { actions, dispatched } = makeActions()
  const { context, pushState } = seededContext([], actions)
  const view = await mountPage(context)
  await settled(() => pushState(manual.opensFrom))
  const opener = byId<HTMLButtonElement>(
    `hilos-maintenance-${manual.name}`,
  ) as HTMLButtonElement
  opener.focus()
  await settled(() => fireEvent.click(opener))

  return { view, pushState, dispatched, opener }
}

describe.each(MANUAL_CASES)(
  'HilosMaintenancePage $name confirmation',
  (manual) => {
    const modal = () => byId(`hilos-maintenance-${manual.name}-modal`)
    const confirm = () => manualButton(manual.name, 'confirm')
    const cancel = () => manualButton(manual.name, 'cancel')

    it.each(['cancel', 'close', 'escape', 'backdrop'] as const)(
      'explains itself and allows %s before dispatch without sending anything',
      async (method) => {
        const { dispatched, opener } = await openManual(manual)
        const dialog = dialogOf(`hilos-maintenance-${manual.name}-modal`)
        expect(dialog.getAttribute('aria-label')).toBe(manual.title)
        expect(dialog.getAttribute('aria-modal')).toBe('true')
        expect(document.activeElement).toBe(dialog)
        expect(dialog.querySelector('input, textarea')).toBeNull()
        for (const text of manual.texts) {
          expect(dialog.textContent).toContain(text)
        }
        expect(
          dialog.querySelector('[data-id="hilos-action-error-idle"]'),
        ).not.toBeNull()

        await closeBy(dialog, cancel(), method)

        expect(modal()).toBeNull()
        expect(dispatched).toHaveLength(0)
        expect(document.activeElement).toBe(opener)
      },
    )

    it('blocks repeat and every close while busy, survives a state push and settles on its one success', async () => {
      const { dispatched, pushState } = await openManual(manual)
      const dialog = dialogOf(`hilos-maintenance-${manual.name}-modal`)
      fireEvent.click(confirm())
      fireEvent.click(confirm())
      await settled(() => {})

      expect(dispatched).toMatchObject([{ action: manual.action, payload: {} }])
      expect(confirm().getAttribute('aria-disabled')).toBe('true')
      expect(cancel().disabled).toBe(true)
      await tryEveryClose(dialog, cancel())
      expect(modal()).not.toBeNull()
      await settled(() => dispatched[0]?.loading.set(true))
      expect(confirm().getAttribute('aria-busy')).toBe('true')

      // A state push replaces the block under the dialog and leaves the dialog be.
      await settled(() => pushState(manual.leaves[0] as HilosMaintenanceState))
      expect(byId(`hilos-maintenance-${manual.name}`)).toBeNull()
      expect(byId(manual.leftBlock)).not.toBeNull()
      expect(modal()?.closest('[role="dialog"]')).toBe(dialog)
      expect(cancel().disabled).toBe(true)
      expect(hilosToasts.toasts.get()).toHaveLength(0)

      await settled(() =>
        dispatched[0]?.settle({
          message: manual.successMessage,
          ...(manual.name === 'pass'
            ? { reply: { pass: 'opaque-secret' } }
            : {}),
        }),
      )

      if (manual.name === 'pass') {
        expect(modal()?.textContent).toContain('opaque-secret')
        expect(byId('hilos-maintenance-pass-result')).not.toBeNull()
      } else {
        expect(modal()).toBeNull()
      }
      expect(hilosToasts.toasts.get()).toMatchObject([
        { message: manual.successMessage, severity: 'success', repeats: 1 },
      ])
      expect(dispatched).toHaveLength(1)
    })

    it.each(manual.leaves)(
      'blocks an unsent confirmation once its frame is gone: %j',
      async (state) => {
        const { pushState, dispatched } = await openManual(manual)

        await settled(() => pushState(state))

        expect(confirm().disabled).toBe(true)
        fireEvent.click(confirm())
        expect(dispatched).toHaveLength(0)
        expect(cancel().disabled).toBe(false)
        await settled(() => fireEvent.click(cancel()))
        expect(modal()).toBeNull()
      },
    )

    it.each(['fail', 'timeout', 'disconnected', 'invalid-reply'] as const)(
      'keeps %s readable in the modal, releases Cancel and never retries',
      async (outcome) => {
        const { dispatched, pushState } = await openManual(manual)
        await settled(() => fireEvent.click(confirm()))
        // A changed state never answers the action, even when its eventual answer is a refusal.
        await settled(() =>
          pushState(manual.leaves[0] as HilosMaintenanceState),
        )
        const reason =
          outcome === 'fail'
            ? 'Another request is awaiting confirmation.'
            : 'No reply received.'

        await settled(() =>
          dispatched[0]?.refuse(
            new ActionError(manual.action, outcome, reason),
          ),
        )

        const message = outcome === 'fail' ? reason : manual.unconfirmedNote
        expect(
          modal()?.querySelector('[data-id="hilos-action-error"]')?.textContent,
        ).toContain(message)
        expect(
          modal()
            ?.querySelector('[data-id="hilos-action-error-slot"]')
            ?.getAttribute('aria-live'),
        ).toBe('assertive')
        expect(cancel().disabled).toBe(false)
        expect(confirm().disabled).toBe(true)
        expect(hilosToasts.toasts.get()).toMatchObject([
          { message, severity: 'error' },
        ])
        expect(dispatched).toHaveLength(1)
        await settled(() => fireEvent.click(cancel()))
        expect(modal()).toBeNull()
      },
    )

    it('shows the entire backend unconfirmed reason and admin details through the standard refusal plate', async () => {
      const { dispatched } = await openManual(manual)
      await settled(() => fireEvent.click(confirm()))
      const reason = `${manual.unconfirmedNote} The agent did not observe its phase.`

      await settled(() =>
        dispatched[0]?.refuse(
          new ActionError(
            manual.action,
            'fail',
            reason,
            'MaintenanceFailure',
            'Original detail',
          ),
        ),
      )
      await settled(() =>
        fireEvent.click(
          modal()?.querySelector(
            '[data-id="hilos-action-error-details"]',
          ) as HTMLButtonElement,
        ),
      )

      expect(document.body.textContent).toContain(reason)
      expect(document.body.textContent).toContain('MaintenanceFailure')
      expect(document.body.textContent).toContain('Original detail')
      expect(
        [...document.querySelectorAll('[role="dialog"]')].map((dialog) =>
          dialog.getAttribute('aria-label'),
        ),
      ).toContain(manual.refusalTitle)
      expect(cancel().disabled).toBe(false)
    })
  },
)

describe('HilosMaintenancePage with the addresses hidden (HIL-1260)', () => {
  afterEach(cleanup)

  it('draws the mark for each address and keys the rows by the membership', () => {
    const { context } = seededContext([
      {
        memberId: 1,
        identityType: 'password',
        identifier: HIDDEN_VALUE,
        online: true,
      },
      {
        memberId: 2,
        identityType: 'sms',
        identifier: HIDDEN_VALUE,
        online: false,
      },
    ])
    mountPage(context)

    const cell = document.querySelector(
      'table [data-id="hilos-maintenance-circle-row-member-1"]',
    )
    expect(
      cell?.querySelector('[data-id="hilos-hidden"]')?.textContent?.trim(),
    ).toBe('Hidden')
    expect(mark('member-1')?.textContent).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.online,
    )
    expect(mark('member-2')?.textContent).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.offline,
    )
  })

  it('names the hidden address in the remove dialog by the mark', () => {
    const { context, focus } = seededContext([
      {
        memberId: 2,
        identityType: 'sms',
        identifier: HIDDEN_VALUE,
        online: false,
      },
    ])
    mountPage(context)

    fireEvent.click(trash('member-2'))

    expect(focus).toEqual(['2'])
    const modal = document.querySelector('[data-id="modal"]')
    expect(modal?.querySelector('code')).toBeNull()
    expect(
      modal?.querySelector('[data-id="hilos-hidden"]')?.textContent?.trim(),
    ).toBe('Hidden')
  })
})

describe('HilosMaintenancePage and the admin view mode', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
    for (const release of releases.splice(0)) release()
  })

  /**
   * Bind the session scope and the admin access the way bootHilos does, over
   * handshakes this harness emits.
   *
   * @param scopes The context's scopes, the ones the page reads its access from.
   */
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
      /**
       * One handshake: who is behind the session, if anybody, and the node's
       * admin view mode, both as the backend stamps them (HIL-1253).
       *
       * @param user The person behind the session, or null for a guest.
       * @param viewMode The node's admin view mode.
       */
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

  it.each(MANUAL_CASES)(
    'disables $name for a viewer and after a live revoke while its confirmation is open',
    async (manual) => {
      const { actions, dispatched } = makeActions()
      const { context, pushState } = seededContext([], actions)
      const { handshake } = bindSession(context.scopes)
      handshake(null, true)
      await mountPage(context)
      await settled(() => pushState(manual.opensFrom))
      const button = byId<HTMLButtonElement>(
        `hilos-maintenance-${manual.name}`,
      ) as HTMLButtonElement
      expect(button.disabled).toBe(true)
      expect(button.getAttribute('aria-describedby')).toContain(
        HILOS_VIEW_MODE_STRIP_TEXT_ID,
      )
      await settled(() => fireEvent.click(button))
      expect(byId(`hilos-maintenance-${manual.name}-modal`)).toBeNull()

      await settled(() => handshake({ id: 7, admin: true }, true))
      expect(button.disabled).toBe(false)
      await settled(() => fireEvent.click(button))
      expect(manualButton(manual.name, 'confirm').disabled).toBe(false)

      await settled(() => handshake({ id: 7, admin: false }, true))
      expect(manualButton(manual.name, 'confirm').disabled).toBe(true)
      fireEvent.click(manualButton(manual.name, 'confirm'))
      expect(dispatched).toHaveLength(0)
      expect(manualButton(manual.name, 'cancel').disabled).toBe(false)
    },
  )

  it('a viewer opens the add dialog, may type an address and has nothing to send it with', async () => {
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([], actions)
    bindSession(context.scopes).handshake(null, true)
    mountPage(context)

    const addButton = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-table-main-action"]',
    )
    expect(addButton?.disabled).toBe(false)

    openAndType('someone@example.test')
    expect(addField()?.disabled).toBe(false)
    expect(addField()?.value).toBe('someone@example.test')
    expect(addConfirm().disabled).toBe(true)
    expect(addConfirm().getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    await settled(() => fireEvent.click(addConfirm()))
    expect(dispatched).toHaveLength(0)

    const cancelButton = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-maintenance-circle-add-cancel"]',
    )
    expect(cancelButton).not.toBeNull()
    expect(cancelButton?.disabled).toBe(false)
    await settled(() => fireEvent.click(cancelButton as Element))
    expect(addField()).toBeNull()
  })

  it('a viewer opens the remove dialog over a row and has nothing to send from it', async () => {
    const { actions, dispatched } = makeActions()
    const { context, focus } = seededContext([ANN], actions)
    bindSession(context.scopes).handshake(null, true)
    mountPage(context)

    const trashButton = trash('ann@example.test')
    expect(trashButton.disabled).toBe(false)
    await settled(() => fireEvent.click(trashButton))

    expect(focus).toEqual(['1'])
    expect(removeConfirm()?.disabled).toBe(true)
    expect(removeConfirm()?.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    await settled(() => fireEvent.click(removeConfirm() as Element))
    expect(dispatched).toHaveLength(0)

    const cancelButton = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-maintenance-circle-remove-cancel"]',
    )
    expect(cancelButton).not.toBeNull()
    expect(cancelButton?.disabled).toBe(false)
    await settled(() => fireEvent.click(cancelButton as Element))

    expect(removeConfirm()).toBeNull()
    expect(focus).toEqual(['1', ''])
  })

  it('an admin on a node in the mode names a verifier as today', () => {
    const { actions } = makeActions()
    const { context } = seededContext([], actions)
    bindSession(context.scopes).handshake({ id: 1, admin: true }, true)
    mountPage(context)

    openAndType('someone@example.test')
    expect(addConfirm().disabled).toBe(false)
    expect(addConfirm().getAttribute('aria-describedby')).toBeNull()
  })
})

describe('HilosMaintenancePage code result', () => {
  const PASS: Pick<ManualCase, 'name' | 'opensFrom'> = {
    name: 'pass',
    opensFrom: manualState(),
  }
  const passModal = () => byId('hilos-maintenance-pass-modal')
  const confirm = () => manualButton('pass', 'confirm')
  const cancel = () => manualButton('pass', 'cancel')

  it.each(['cancel', 'close', 'escape', 'backdrop'] as const)(
    'forgets the secret on %s, returns focus and opens a fresh confirmation',
    async (method) => {
      const { dispatched, opener } = await openManual(PASS)
      await settled(() => fireEvent.click(confirm()))
      const secret = '<opaque&code>'
      await settled(() =>
        dispatched[0]?.settle({
          message: 'Code issued',
          reply: { pass: secret },
        }),
      )
      const dialog = dialogOf('hilos-maintenance-pass-modal')
      const value = byId('hilos-maintenance-pass-value')
      expect(value?.textContent).toBe(secret)
      expect(value?.querySelector('opaque')).toBeNull()
      expect(document.activeElement).toBe(value)
      expect(dialog.getAttribute('aria-label')).toBe(
        HILOS_MAINTENANCE_PASS_COPY.codeTitle,
      )
      expect(cancel().textContent?.trim()).toBe(
        HILOS_MAINTENANCE_PASS_COPY.close,
      )
      expect(byId('hilos-maintenance-pass-confirm')).toBeNull()
      expect(hilosToasts.toasts.get()).toMatchObject([
        { message: 'Code issued' },
      ])
      expect(JSON.stringify(hilosToasts.toasts.get())).not.toContain(secret)
      for (const element of document.querySelectorAll('*')) {
        for (const attribute of element.attributes) {
          expect(attribute.value).not.toContain(secret)
        }
      }
      expect(
        JSON.stringify({ ...localStorage, ...sessionStorage }),
      ).not.toContain(secret)
      expect(window.location.href).not.toContain(secret)

      await closeBy(dialog, cancel(), method)

      expect(passModal()).toBeNull()
      expect(document.body.textContent).not.toContain(secret)
      expect(document.activeElement).toBe(opener)
      await settled(() => fireEvent.click(opener))
      expect(passModal()?.textContent).not.toContain(secret)
      expect(byId('hilos-maintenance-pass-result')).toBeNull()
      expect(confirm().disabled).toBe(false)
      expect(dispatched).toHaveLength(1)
      await settled(() => fireEvent.click(confirm()))
      expect(dispatched).toHaveLength(2)
      await settled(() =>
        dispatched[1]?.settle({ reply: { pass: 'new-code' } }),
      )
      expect(passModal()?.textContent).toContain('new-code')
      expect(passModal()?.textContent).not.toContain(secret)
    },
  )

  it('forgets a shown result on unmount', async () => {
    const { view, dispatched } = await openManual(PASS)
    await settled(() => fireEvent.click(confirm()))
    await settled(() =>
      dispatched[0]?.settle({ reply: { pass: 'departed-code' } }),
    )
    expect(passModal()?.textContent).toContain('departed-code')

    view.unmount()

    expect(document.body.textContent).not.toContain('departed-code')
    expect(passModal()).toBeNull()
  })

  it('cannot resurrect a result from a promise after unmount', async () => {
    const { view, dispatched } = await openManual(PASS)
    await settled(() => fireEvent.click(confirm()))
    view.unmount()

    await settled(() => dispatched[0]?.settle({ reply: { pass: 'late-code' } }))

    expect(document.body.textContent).not.toContain('late-code')
    expect(passModal()).toBeNull()
    const reopened = await openManual(PASS)
    expect(passModal()?.textContent).not.toContain('late-code')
    expect(reopened.dispatched).toHaveLength(0)
  })

  it('a late success after timeout cannot replace a newly opened confirmation', async () => {
    const { dispatched, opener } = await openManual(PASS)
    await settled(() => fireEvent.click(confirm()))
    await settled(() =>
      dispatched[0]?.refuse(
        new ActionError('maintenance_pass', 'timeout', 'Timed out'),
      ),
    )
    expect(passModal()?.textContent).toContain(
      HILOS_MAINTENANCE_PASS_COPY.unconfirmedNote,
    )
    await settled(() => fireEvent.click(cancel()))
    await settled(() => fireEvent.click(opener))

    await settled(() =>
      dispatched[0]?.settle({ reply: { pass: 'orphan-code' } }),
    )

    expect(document.body.textContent).not.toContain('orphan-code')
    expect(byId('hilos-maintenance-pass-result')).toBeNull()
    expect(dispatched).toHaveLength(1)
  })
})
