import { act, cleanup, fireEvent, render, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  HIDDEN_VALUE,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  MAINTENANCE_STATE_SIGNAL,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
  hilosToasts,
} from '@hilos/core/base'
import {
  HILOS_MAINTENANCE_DISABLE_COPY,
  HILOS_MAINTENANCE_ENABLE_COPY,
  HILOS_MAINTENANCE_PASS_COPY,
  HILOS_MAINTENANCE_RESTORE_CLOSE_COPY,
  HILOS_MAINTENANCE_RESTORE_OPEN_COPY,
  HILOS_MAINTENANCE_RESTORE_PASS_COPY,
  HILOS_MAINTENANCE_STATE_COPY,
  hilosMaintenanceOtherBrowserLine,
} from '@hilos/core/admin/maintenance'
import {
  formatMoment,
  hilosBrowserTimeZone,
  hilosTimeZone,
} from '@hilos/core/base'
import { HILOS_MAINTENANCE_WATCHDOG_COPY } from '@hilos/core/admin/maintenance'
import type {
  ActionHandle,
  ActionLifecycle,
  ActionResult,
  HilosConnection,
  HilosMaintenanceState,
  HilosRouter,
  PageRouteMatch,
  ProjectSignal,
  WritableSignal,
} from '@hilos/core/base'
import type { HilosMaintenanceContext } from '@hilos/core/admin/maintenance'

import { HilosMaintenancePage } from '../src/admin/maintenance/HilosMaintenancePage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import { readerTimeZone } from './readerTimeZone.js'

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

/**
 * Only the transport is stubbed: the section's state frame reaches the page through
 * the connection's project signal. Every call of the table channel is recorded —
 * the Overview draws no table, so a window it registers, a viewport it sends or a
 * table event it listens to would be a circle that came back.
 */
function overviewContext(actions: ActionLifecycle = makeActions().actions): {
  context: HilosMaintenanceContext
  tableTraffic: string[]
  pushState: (state: HilosMaintenanceState) => void
} {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.MAINTENANCE)
  const tableTraffic: string[] = []
  const stateListeners = new Set<
    (signal: { type: string; data: unknown }) => void
  >()
  const connection = {
    registerTableWindow(): void {
      tableTraffic.push('registerTableWindow')
    },
    unregisterTableWindow(): void {
      tableTraffic.push('unregisterTableWindow')
    },
    tableWindowDescriptors: () => {
      tableTraffic.push('tableWindowDescriptors')

      return {}
    },
    sendTableViewport(): boolean {
      tableTraffic.push('sendTableViewport')

      return true
    },
    sendTableRowFocus(): boolean {
      tableTraffic.push('sendTableRowFocus')

      return true
    },
    sendTableRendered(): boolean {
      tableTraffic.push('sendTableRendered')

      return true
    },
    on(
      event: string,
      listener: (signal: { type: string; data: unknown }) => void,
    ): () => void {
      if (event === 'projectSignal') {
        stateListeners.add(listener)

        return () => stateListeners.delete(listener)
      }
      if (event === 'tableWindow' || event === 'tableViewportDelta') {
        tableTraffic.push(`on ${event}`)
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
    tableTraffic,
    // The section's state frame, as the connection hands it to every listener.
    pushState(state: HilosMaintenanceState): void {
      for (const listener of stateListeners) {
        listener({ type: MAINTENANCE_STATE_SIGNAL, data: state })
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

/** Flush the action's promise and the resulting React render together. */
async function settled(change: () => void): Promise<void> {
  await act(async () => {
    change()
  })
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
    initiatorNodeName: null,
    activatedAt: null,
    progressAt: null,
    isInitiator: false,
    diagnosticsNodeId: null,
    diagnosticsNodeName: null,
    stoppedAgentCount: null,
    preservedAgentTypes: null,
    agentStartGateClosed: null,
    watchdogVerdict: null,
    watchdogPendingNodes: [],
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
 * A restore in its verification window with every live fact published, as the
 * initiating browser receives it.
 *
 * @param overrides The fields this case changes.
 */
function restoreState(
  overrides: Partial<HilosMaintenanceState> = {},
): HilosMaintenanceState {
  return maintenanceState({
    phase: 'verifying',
    operation: 'restore',
    isInitiator: true,
    initiatorAgentType: 'hilos_backup',
    initiatorNodeId: 'node-1',
    startedAt: '2026-09-11T03:14:02.000Z',
    activatedAt: '2026-09-11T03:14:09.000Z',
    progressAt: '2026-09-11T03:21:44.000Z',
    diagnosticsNodeId: 'node-1',
    stoppedAgentCount: 6,
    preservedAgentTypes: ['hilos_mail'],
    agentStartGateClosed: false,
    passCount: 2,
    circleNamed: 3,
    circleAdmitted: 2,
    ...overrides,
  })
}

/**
 * Keep the real Intl output but in one locale, so a moment can be written out
 * by hand; the zone still comes from the reader's binding.
 *
 * @returns Restores the real formatter.
 */
function englishMoments(): () => void {
  const DateTimeFormat = Intl.DateTimeFormat
  const spy = vi
    .spyOn(Intl, 'DateTimeFormat')
    .mockImplementation(function (_locales, options) {
      return new DateTimeFormat('en-US', options)
    })

  return () => spy.mockRestore()
}

/**
 * The text of an element the page must have drawn.
 *
 * @param dataId The element's data-id.
 */
function textOf(dataId: string): string | null | undefined {
  return byId(dataId)?.textContent
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

/**
 * The value lines of a definition list, in order.
 *
 * @param root The element holding the list.
 */
function definitions(root: Element): (string | null)[] {
  return [...root.querySelectorAll('dd')].map((row) => row.textContent)
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

/** One of the three restore actions, by its name in the data-ids. */
type RestoreName = 'pass' | 'open' | 'close'

const RESTORE_NAMES: readonly RestoreName[] = ['pass', 'open', 'close']

/** The ways a dialog's request may end other than its success. */
const FAILURE_OUTCOMES = [
  'fail',
  'timeout',
  'disconnected',
  'invalid-reply',
] as const

/**
 * Frames on which this browser no longer holds the restore ladder: another
 * browser is the initiator, the window closed again, the system opened.
 */
const LADDER_GONE: [string, HilosMaintenanceState][] = [
  ['another browser', restoreState({ isInitiator: false })],
  ['the window closed', restoreState({ phase: 'active' })],
  ['the system opened', maintenanceState()],
]

/**
 * The body a restore dialog draws, or null while it is closed.
 *
 * @param name The restore action's name in its data-ids.
 */
function restoreModal(name: RestoreName): HTMLElement | null {
  return byId(`hilos-maintenance-restore-${name}-modal`)
}

/**
 * A button of a restore dialog, which must already be open.
 *
 * @param name The restore action's name in its data-ids.
 * @param button Which of the two buttons.
 */
function restoreButton(
  name: RestoreName,
  button: 'confirm' | 'cancel',
): HTMLButtonElement {
  return byId<HTMLButtonElement>(
    `hilos-maintenance-restore-${name}-${button}`,
  ) as HTMLButtonElement
}

/**
 * Press Enter on a focused button: a key the page lets through clicks it, a
 * prevented one does not.
 *
 * @param button The button that has the focus.
 */
function pressEnter(button: HTMLButtonElement): void {
  button.focus()
  if (fireEvent.keyDown(button, { key: 'Enter' })) {
    fireEvent.click(button)
  }
}

/**
 * Mount the real page as the initiating browser of a restore in its
 * verification window and open one of the ladder's dialogs.
 *
 * @param name The restore action's name in its data-ids.
 */
async function openRestore(name: RestoreName) {
  const { actions, dispatched } = makeActions()
  const { context, pushState } = overviewContext(actions)
  const view = await mountPage(context)
  await settled(() => pushState(restoreState()))
  const opener = byId<HTMLButtonElement>(
    `hilos-maintenance-restore-${name}`,
  ) as HTMLButtonElement
  opener.focus()
  await settled(() => fireEvent.click(opener))

  return { view, pushState, dispatched, opener }
}

const realClipboard = navigator.clipboard

/**
 * Put a clipboard in the document, or give back the real one.
 *
 * @param clipboard The clipboard the document carries.
 */
function setClipboard(clipboard: Clipboard | undefined): void {
  Object.defineProperty(navigator, 'clipboard', {
    value: clipboard,
    configurable: true,
  })
}

/**
 * A clipboard that records what is written to it.
 *
 * @returns What the page wrote, in order.
 */
function recordingClipboard(): string[] {
  const written: string[] = []
  setClipboard({
    writeText: async (text: string) => {
      written.push(text)
    },
  } as unknown as Clipboard)

  return written
}

describe('HilosMaintenancePage reader-zone moments', () => {
  it.each([true, false])(
    'reformats the held startedAt and keeps the card when manual=%s',
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
        const { context, pushState } = overviewContext()
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
        expect(firstZone).not.toBe(browserZone)
        expect(timestamp?.textContent).toBe(
          formatMoment(startedAt, { kind: 'dateTime' }),
        )
        const firstTimestamp = timestamp?.textContent
        expect(card?.textContent).toContain('verifying')
        expect(byId('hilos-maintenance-pass-count')?.textContent).toBe('2')

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
      const { context, pushState } = overviewContext()
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
    const { context, pushState } = overviewContext()
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
      const { context, pushState } = overviewContext()
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
    const { context, pushState } = overviewContext()
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
      const { context, pushState } = overviewContext()
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

  it('renders the operation card from the frame and keeps an unpublished fact as a dash', async () => {
    const { context, pushState } = overviewContext()
    await mountPage(context)
    const startedAt = '2026-10-10T10:00:00.000000Z'

    await settled(() =>
      pushState(
        maintenanceState({
          phase: 'verifying',
          operation: 'restore',
          startedAt,
          passCount: 2,
          circleNamed: 3,
          circleAdmitted: 2,
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
    expect(card.textContent).toContain(HILOS_MAINTENANCE_STATE_COPY.initiator)
    expect(textOf('hilos-maintenance-initiator')).toBe('— · node —')
    expect(textOf('hilos-maintenance-started-at')).toBe(
      formatMoment(startedAt, { kind: 'dateTime' }),
    )
    expect(textOf('hilos-maintenance-activated-at')).toBe('—')
    expect(textOf('hilos-maintenance-progress-at')).toBe('—')
    expect(byId('hilos-maintenance-progress-elapsed')).toBeNull()
    expect(card.textContent).not.toContain(HILOS_MAINTENANCE_STATE_COPY.justNow)
    // An unpublished hold is unknown, not zero agents and not a closed gate.
    expect(textOf('hilos-maintenance-stopped-agents')).toBe('—')
    expect(textOf('hilos-maintenance-diagnostics-node')).toBe('—')
    expect(textOf('hilos-maintenance-agent-start')).toBe('—')
    expect(card.textContent).not.toContain(
      HILOS_MAINTENANCE_STATE_COPY.agentStartClosed,
    )
    expect(textOf('hilos-maintenance-pass-count')).toBe('2')
    expect(card.textContent).toContain('3 named · 2 let in')
    expect(card.querySelector('button')).toBeNull()

    for (const [activatedAt, progressAt] of [
      ['', 'malformed'],
      ['2026-02-30T00:00:00.000000Z', ''],
    ]) {
      await settled(() =>
        pushState(
          maintenanceState({
            phase: 'verifying',
            operation: 'restore',
            startedAt,
            activatedAt,
            progressAt,
          }),
        ),
      )
      expect(textOf('hilos-maintenance-activated-at')).toBe('—')
      expect(textOf('hilos-maintenance-progress-at')).toBe('—')
      expect(byId('hilos-maintenance-progress-elapsed')).toBeNull()
      expect(card.textContent).not.toContain(
        HILOS_MAINTENANCE_STATE_COPY.justNow,
      )
    }
  })

  it('keeps the operation lines off the manual card', async () => {
    const { context, pushState } = overviewContext()
    await mountPage(context)

    await settled(() =>
      pushState(
        manualState({
          initiatorAgentType: 'hilos_backup',
          initiatorNodeId: 'node-1',
          stoppedAgentCount: 0,
          agentStartGateClosed: false,
        }),
      ),
    )

    for (const line of [
      'initiator',
      'activated-at',
      'progress-at',
      'stopped-agents',
      'diagnostics-node',
      'agent-start',
    ]) {
      expect(byId(`hilos-maintenance-${line}`)).toBeNull()
    }
    expect(byId('hilos-maintenance-other-browser')).toBeNull()
  })

  it('draws no circle table and opens no circle window', async () => {
    const { context, pushState, tableTraffic } = overviewContext()
    const view = mountPage(context)

    expect(view.container.querySelector('table')).toBeNull()
    expect(tableTraffic).toEqual([])

    await settled(() => pushState(manualState()))

    expect(view.container.querySelector('table')).toBeNull()
    expect(tableTraffic).toEqual([])
  })
})

describe('HilosMaintenancePage restore card', () => {
  it('draws published facts in the reader zone and ages the last movement on the shared clock', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] })
    vi.setSystemTime(new Date('2026-09-11T03:24:14.000Z'))
    const restoreFormatter = englishMoments()
    const reader = readerTimeZone()

    try {
      act(() => reader.choose('UTC'))
      const { context, pushState } = overviewContext()
      mountPage(context)
      const state = restoreState()
      await settled(() => pushState(state))

      const card = byId('hilos-maintenance-state-operation') as HTMLElement
      expect(textOf('hilos-maintenance-initiator')).toBe(
        'hilos_backup · node node-1',
      )
      expect(textOf('hilos-maintenance-started-at')).toBe(
        'Sep 11, 2026, 3:14:02 AM',
      )
      expect(textOf('hilos-maintenance-activated-at')).toBe(
        'Sep 11, 2026, 3:14:09 AM',
      )
      expect(textOf('hilos-maintenance-progress-at')).toBe(
        'Sep 11, 2026, 3:21:44 AM',
      )
      expect(textOf('hilos-maintenance-progress-elapsed')).toBe('2 minutes ago')
      expect(textOf('hilos-maintenance-stopped-agents')).toBe(
        '6 · hilos_mail left running',
      )
      expect(textOf('hilos-maintenance-diagnostics-node')).toBe('node-1')
      // The gate as published: open in the verification window.
      expect(textOf('hilos-maintenance-agent-start')).toBe(
        HILOS_MAINTENANCE_STATE_COPY.agentStartOpen,
      )
      expect(card.textContent).not.toContain(
        HILOS_MAINTENANCE_STATE_COPY.agentStartClosed,
      )

      await settled(() =>
        pushState(
          restoreState({ stoppedAgentCount: 0, preservedAgentTypes: [] }),
        ),
      )
      // Zero is a published count, and an empty preserved list is a value.
      expect(textOf('hilos-maintenance-stopped-agents')).toBe('0')
      await settled(() =>
        pushState(restoreState({ agentStartGateClosed: true })),
      )
      expect(textOf('hilos-maintenance-agent-start')).toBe(
        HILOS_MAINTENANCE_STATE_COPY.agentStartClosed,
      )
      await settled(() =>
        pushState(restoreState({ agentStartGateClosed: null })),
      )
      expect(textOf('hilos-maintenance-agent-start')).toBe('—')
      await settled(() => pushState(state))

      act(() => reader.choose('America/Los_Angeles'))
      expect(textOf('hilos-maintenance-activated-at')).toBe(
        'Sep 10, 2026, 8:14:09 PM',
      )
      expect(textOf('hilos-maintenance-progress-at')).toBe(
        'Sep 10, 2026, 8:21:44 PM',
      )
      expect(textOf('hilos-maintenance-progress-elapsed')).toBe('2 minutes ago')
      act(() => reader.choose('Europe/Kyiv'))
      expect(textOf('hilos-maintenance-activated-at')).toBe(
        'Sep 11, 2026, 6:14:09 AM',
      )
      expect(textOf('hilos-maintenance-progress-at')).toBe(
        'Sep 11, 2026, 6:21:44 AM',
      )
      expect(state.activatedAt).toBe('2026-09-11T03:14:09.000Z')
      expect(state.progressAt).toBe('2026-09-11T03:21:44.000Z')

      await act(async () => {
        await vi.advanceTimersByTimeAsync(29_999)
      })
      expect(textOf('hilos-maintenance-progress-elapsed')).toBe('2 minutes ago')
      await act(async () => {
        await vi.advanceTimersByTimeAsync(1)
      })
      expect(textOf('hilos-maintenance-progress-elapsed')).toBe('3 minutes ago')
      expect(textOf('hilos-maintenance-progress-at')).toBe(
        'Sep 11, 2026, 6:21:44 AM',
      )
      expect(textOf('hilos-maintenance-started-at')).toBe(
        'Sep 11, 2026, 6:14:02 AM',
      )
    } finally {
      cleanup()
      reader.release()
      restoreFormatter()
      vi.useRealTimers()
    }
  })

  it('gives a browser that did not start the restore only the line', async () => {
    const { context, pushState } = overviewContext()
    await mountPage(context)

    for (const state of [
      restoreState({ phase: 'active', isInitiator: false }),
      manualState({ initiatorAgentType: 'hilos_backup' }),
      restoreState(),
    ]) {
      await settled(() => pushState(state))
      expect(byId('hilos-maintenance-other-browser')).toBeNull()
    }

    // Another admitted browser, and a restore the console started: the console
    // makes no browser the initiator, so every browser reads the same line.
    for (const state of [
      restoreState({ isInitiator: false }),
      restoreState({ isInitiator: false, initiatorNodeId: 'node-2' }),
    ]) {
      await settled(() => pushState(state))
      const line = byId('hilos-maintenance-other-browser') as HTMLElement
      expect(line.getAttribute('role')).toBe('status')
      expect(line.textContent).toContain(
        hilosMaintenanceOtherBrowserLine(state),
      )
      expect(line.querySelector('button')).toBeNull()
      expect(byId('hilos-maintenance-restore-ladder')).toBeNull()
      for (const id of ['restore-pass', 'restore-open', 'restore-close']) {
        expect(byId(`hilos-maintenance-${id}`)).toBeNull()
      }
      expect(
        byId('hilos-maintenance-state-operation')?.querySelector('button'),
      ).toBeNull()
      expect(textOf('hilos-maintenance-initiator')).toBe(
        `hilos_backup · node ${state.initiatorNodeId}`,
      )
    }

    await settled(() => pushState(maintenanceState()))
    expect(byId('hilos-maintenance-other-browser')).toBeNull()
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
  const { context, pushState } = overviewContext(actions)
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

      expect(dispatched).toMatchObject([{ action: manual.action }])
      expect(dispatched[0]?.payload).toEqual({})
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
      const { context, pushState } = overviewContext(actions)
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

  it('shows restore facts to a viewer', async () => {
    const { actions, dispatched } = makeActions()
    const { context, pushState } = overviewContext(actions)
    const { handshake } = bindSession(context.scopes)
    handshake(null, true)
    await mountPage(context)

    for (const isInitiator of [true, false]) {
      await settled(() =>
        pushState(restoreState({ isInitiator, preservedAgentTypes: null })),
      )
      expect(textOf('hilos-maintenance-initiator')).toContain('hilos_backup')
      expect(textOf('hilos-maintenance-stopped-agents')).toBe('6')
      expect(textOf('hilos-maintenance-diagnostics-node')).toBe('node-1')
      expect(textOf('hilos-maintenance-agent-start')).toBe(
        HILOS_MAINTENANCE_STATE_COPY.agentStartOpen,
      )
    }
    expect(textOf('hilos-maintenance-other-browser')).toContain('hilos_backup')
    expect(dispatched).toHaveLength(0)
  })

  it('blocks the restore ladder and its confirmations for a viewer and after a live revoke', async () => {
    const { actions, dispatched } = makeActions()
    const { context, pushState } = overviewContext(actions)
    const { handshake } = bindSession(context.scopes)
    handshake(null, true)
    await mountPage(context)
    await settled(() => pushState(restoreState()))

    for (const name of RESTORE_NAMES) {
      const opener = byId<HTMLButtonElement>(
        `hilos-maintenance-restore-${name}`,
      ) as HTMLButtonElement
      expect(opener.disabled).toBe(true)
      expect(opener.getAttribute('aria-describedby')).toContain(
        HILOS_VIEW_MODE_STRIP_TEXT_ID,
      )
      await settled(() => fireEvent.click(opener))
      expect(restoreModal(name)).toBeNull()
    }

    for (const name of RESTORE_NAMES) {
      await settled(() => handshake({ id: 7, admin: true }, true))
      const opener = byId<HTMLButtonElement>(
        `hilos-maintenance-restore-${name}`,
      ) as HTMLButtonElement
      expect(opener.disabled).toBe(false)
      await settled(() => fireEvent.click(opener))
      expect(restoreButton(name, 'confirm').disabled).toBe(false)

      await settled(() => handshake({ id: 7, admin: false }, true))
      const confirm = restoreButton(name, 'confirm')
      expect(confirm.disabled).toBe(true)
      expect(confirm.getAttribute('aria-describedby')).toContain(
        HILOS_VIEW_MODE_STRIP_TEXT_ID,
      )
      fireEvent.click(confirm)
      pressEnter(confirm)
      await settled(() => {})
      expect(dispatched).toHaveLength(0)
      expect(restoreButton(name, 'cancel').disabled).toBe(false)
      await settled(() => fireEvent.click(restoreButton(name, 'cancel')))
      expect(restoreModal(name)).toBeNull()
    }
    expect(byId('hilos-maintenance-restore-pass-value')).toBeNull()
  })

  it('keeps watchdog details readable for a viewer and draws hidden thresholds through the hidden mark', async () => {
    const { actions, dispatched } = makeActions()
    const { context, pushState } = overviewContext(actions)
    const { handshake } = bindSession(context.scopes)
    handshake(null, true)
    await mountPage(context)
    await settled(() =>
      pushState(
        restoreState({
          watchdogVerdict: 'silent',
          watchdogAlertEmails: HIDDEN_VALUE,
          watchdogManualRestartIsNormal: HIDDEN_VALUE,
        }),
      ),
    )
    const card = byId('hilos-maintenance-watchdog-thresholds') as HTMLElement
    expect(card.querySelectorAll('[data-id="hilos-hidden"]')).toHaveLength(2)
    // One mark in place of the list, never its size.
    expect(definitions(card)).toEqual(['—', '—', '—', 'Hidden', 'Hidden'])
    expect(card.textContent).not.toContain(
      HILOS_MAINTENANCE_WATCHDOG_COPY.noRecipients,
    )
    const opener = byId<HTMLButtonElement>(
      'hilos-maintenance-watchdog-details',
    ) as HTMLButtonElement
    expect(opener.disabled).toBe(false)
    opener.focus()
    await settled(() => fireEvent.click(opener))
    expect(textOf('hilos-maintenance-watchdog-full')).toContain(
      'No movement behind the freeze',
    )
    await settled(() =>
      fireEvent.click(
        byId<HTMLButtonElement>(
          'hilos-maintenance-watchdog-close',
        ) as HTMLButtonElement,
      ),
    )
    expect(byId('hilos-maintenance-watchdog-full')).toBeNull()
    expect(document.activeElement).toBe(opener)
    expect(dispatched).toEqual([])
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

describe('HilosMaintenancePage restore ladder', () => {
  it('gives the initiator three live buttons and another browser only the line', async () => {
    const { context, pushState } = overviewContext()
    await mountPage(context)

    // The ladder belongs to a restore's verification window and nowhere else.
    for (const state of [
      restoreState({ phase: 'active' }),
      manualState({ isInitiator: true }),
      maintenanceState(),
    ]) {
      await settled(() => pushState(state))
      expect(byId('hilos-maintenance-restore-ladder')).toBeNull()
      for (const name of RESTORE_NAMES) {
        expect(byId(`hilos-maintenance-restore-${name}`)).toBeNull()
      }
    }

    await settled(() => pushState(restoreState()))
    expect(byId('hilos-maintenance-other-browser')).toBeNull()
    const ladder = byId('hilos-maintenance-restore-ladder') as HTMLElement
    for (const [name, label] of [
      ['pass', HILOS_MAINTENANCE_RESTORE_PASS_COPY.issue],
      ['open', HILOS_MAINTENANCE_RESTORE_OPEN_COPY.issue],
      ['close', HILOS_MAINTENANCE_RESTORE_CLOSE_COPY.issue],
    ] as const) {
      const button = ladder.querySelector<HTMLButtonElement>(
        `[data-id="hilos-maintenance-restore-${name}"]`,
      )
      expect(button?.tagName).toBe('BUTTON')
      expect(button?.textContent).toBe(label)
      expect(button?.disabled).toBe(false)
      expect(button?.getAttribute('aria-disabled')).toBeNull()
    }
    // The operation card keeps its facts and no control of its own.
    expect(
      byId('hilos-maintenance-state-operation')?.querySelector('button'),
    ).toBeNull()

    const other = restoreState({ isInitiator: false })
    await settled(() => pushState(other))
    expect(byId('hilos-maintenance-restore-ladder')).toBeNull()
    for (const name of RESTORE_NAMES) {
      expect(byId(`hilos-maintenance-restore-${name}`)).toBeNull()
    }
    const line = byId('hilos-maintenance-other-browser') as HTMLElement
    expect(line.getAttribute('role')).toBe('status')
    expect(line.textContent).toContain(hilosMaintenanceOtherBrowserLine(other))
    expect(line.querySelector('button')).toBeNull()
  })
})

describe('HilosMaintenancePage restore code', () => {
  const confirm = () => restoreButton('pass', 'confirm')
  const cancel = () => restoreButton('pass', 'cancel')

  afterEach(() => {
    setClipboard(realClipboard)
  })

  it.each(['cancel', 'close', 'escape', 'backdrop'] as const)(
    'explains minting and allows %s before dispatch without sending anything',
    async (method) => {
      const { dispatched, opener } = await openRestore('pass')
      const dialog = dialogOf('hilos-maintenance-restore-pass-modal')
      expect(dialog.getAttribute('aria-label')).toBe(
        HILOS_MAINTENANCE_RESTORE_PASS_COPY.mintTitle,
      )
      expect(dialog.getAttribute('aria-modal')).toBe('true')
      expect(document.activeElement).toBe(dialog)
      expect(dialog.querySelector('input, textarea')).toBeNull()
      for (const text of [
        HILOS_MAINTENANCE_RESTORE_PASS_COPY.lead,
        HILOS_MAINTENANCE_RESTORE_PASS_COPY.reuseNote,
      ]) {
        expect(dialog.textContent).toContain(text)
      }
      // A restore code is not the manual window's: nothing here turns maintenance off.
      expect(dialog.textContent?.toLowerCase()).not.toContain(
        'turn maintenance off',
      )
      expect(dialog.textContent).not.toContain(
        HILOS_MAINTENANCE_RESTORE_OPEN_COPY.issue,
      )
      expect(
        dialog.querySelector('[data-id="hilos-action-error-idle"]'),
      ).not.toBeNull()
      await settled(() => fireEvent.keyDown(dialog, { key: 'Enter' }))
      expect(dispatched).toHaveLength(0)
      expect(restoreModal('pass')).not.toBeNull()

      await closeBy(dialog, cancel(), method)

      expect(restoreModal('pass')).toBeNull()
      expect(dispatched).toHaveLength(0)
      expect(document.activeElement).toBe(opener)
    },
  )

  it.each(['cancel', 'close', 'escape', 'backdrop'] as const)(
    'blocks repeat, Enter and every close while busy, reveals the code once and forgets it on %s',
    async (method) => {
      const written = recordingClipboard()
      const { dispatched, opener } = await openRestore('pass')
      const dialog = dialogOf('hilos-maintenance-restore-pass-modal')
      pressEnter(confirm())
      pressEnter(confirm())
      await settled(() => {})

      expect(dispatched).toMatchObject([{ action: 'maintenance_restore_pass' }])
      expect(dispatched[0]?.payload).toEqual({})
      expect(confirm().getAttribute('aria-disabled')).toBe('true')
      expect(cancel().disabled).toBe(true)
      pressEnter(confirm())
      await tryEveryClose(dialog, cancel())
      expect(restoreModal('pass')).not.toBeNull()
      expect(dispatched).toHaveLength(1)
      await settled(() => dispatched[0]?.loading.set(true))
      expect(confirm().getAttribute('aria-busy')).toBe('true')

      const secret = '<opaque&code>'
      await settled(() =>
        dispatched[0]?.settle({
          message: 'Code minted',
          reply: { pass: secret },
        }),
      )
      const value = byId('hilos-maintenance-restore-pass-value')
      expect(value?.textContent).toBe(secret)
      expect(value?.querySelector('opaque')).toBeNull()
      expect(document.activeElement).toBe(value)
      expect(dialog.getAttribute('aria-label')).toBe(
        HILOS_MAINTENANCE_RESTORE_PASS_COPY.codeTitle,
      )
      expect(dialog.textContent).toContain(
        HILOS_MAINTENANCE_RESTORE_PASS_COPY.resultLead,
      )
      expect(dialog.textContent).toContain(
        HILOS_MAINTENANCE_RESTORE_PASS_COPY.resultNote,
      )
      expect(cancel().textContent?.trim()).toBe(
        HILOS_MAINTENANCE_RESTORE_PASS_COPY.close,
      )
      expect(byId('hilos-maintenance-restore-pass-confirm')).toBeNull()
      expect(textOf('hilos-maintenance-state-operation')).not.toContain(secret)
      expect(hilosToasts.toasts.get()).toMatchObject([
        { message: 'Code minted', severity: 'success', repeats: 1 },
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

      const copy = dialog.querySelector<HTMLButtonElement>(
        '[data-id="modal-copy"]',
      ) as HTMLButtonElement
      expect(copy.tagName).toBe('BUTTON')
      expect(copy.disabled).toBe(false)
      await act(async () => {
        pressEnter(copy)
      })
      expect(written).toEqual([secret])
      expect(dispatched).toHaveLength(1)

      await closeBy(dialog, cancel(), method)

      expect(restoreModal('pass')).toBeNull()
      expect(document.body.textContent).not.toContain(secret)
      expect(document.activeElement).toBe(opener)
      await settled(() => fireEvent.click(opener))
      expect(restoreModal('pass')?.textContent).not.toContain(secret)
      expect(byId('hilos-maintenance-restore-pass-result')).toBeNull()
      expect(confirm().disabled).toBe(false)
      expect(dispatched).toHaveLength(1)
    },
  )

  it.each(FAILURE_OUTCOMES)(
    'keeps %s in the mint dialog with no control that opens the system and never retries',
    async (outcome) => {
      const { dispatched } = await openRestore('pass')
      await settled(() => fireEvent.click(confirm()))
      const reason =
        outcome === 'fail'
          ? 'Another request is awaiting confirmation.'
          : 'No reply received.'

      await settled(() =>
        dispatched[0]?.refuse(
          new ActionError('maintenance_restore_pass', outcome, reason),
        ),
      )

      const message =
        outcome === 'fail'
          ? reason
          : HILOS_MAINTENANCE_RESTORE_PASS_COPY.unconfirmedNote
      const dialog = dialogOf('hilos-maintenance-restore-pass-modal')
      expect(
        dialog.querySelector('[data-id="hilos-action-error"]')?.textContent,
      ).toContain(message)
      expect(
        dialog
          .querySelector('[data-id="hilos-action-error-slot"]')
          ?.getAttribute('aria-live'),
      ).toBe('assertive')
      expect(message.toLowerCase()).not.toContain('turn maintenance off')
      expect(dialog.textContent).not.toContain(
        HILOS_MAINTENANCE_RESTORE_OPEN_COPY.issue,
      )
      expect(byId('hilos-maintenance-restore-pass-result')).toBeNull()
      expect(cancel().disabled).toBe(false)
      expect(confirm().disabled).toBe(false)
      expect(hilosToasts.toasts.get()).toMatchObject([
        { message, severity: 'error' },
      ])
      expect(dispatched).toHaveLength(1)
    },
  )

  it.each(LADDER_GONE)(
    'blocks an unsent mint once this browser no longer holds the ladder: %s',
    async (_case, state) => {
      const { pushState, dispatched } = await openRestore('pass')

      await settled(() => pushState(state))

      expect(confirm().disabled).toBe(true)
      fireEvent.click(confirm())
      pressEnter(confirm())
      await settled(() => {})
      expect(dispatched).toHaveLength(0)
      expect(cancel().disabled).toBe(false)
      await settled(() => fireEvent.click(cancel()))
      expect(restoreModal('pass')).toBeNull()
    },
  )

  it('forgets a shown code on unmount', async () => {
    const { view, dispatched } = await openRestore('pass')
    await settled(() => fireEvent.click(confirm()))
    await settled(() =>
      dispatched[0]?.settle({ reply: { pass: 'departed-code' } }),
    )
    expect(restoreModal('pass')?.textContent).toContain('departed-code')

    view.unmount()

    expect(document.body.textContent).not.toContain('departed-code')
    expect(restoreModal('pass')).toBeNull()
  })

  it('cannot resurrect a code from a reply after unmount', async () => {
    const { view, dispatched } = await openRestore('pass')
    await settled(() => fireEvent.click(confirm()))
    view.unmount()

    await settled(() => dispatched[0]?.settle({ reply: { pass: 'late-code' } }))

    expect(document.body.textContent).not.toContain('late-code')
    expect(restoreModal('pass')).toBeNull()
    const reopened = await openRestore('pass')
    expect(restoreModal('pass')?.textContent).not.toContain('late-code')
    expect(reopened.dispatched).toHaveLength(0)
  })

  it('a late success after timeout cannot replace a newly opened confirmation', async () => {
    const { dispatched, opener } = await openRestore('pass')
    await settled(() => fireEvent.click(confirm()))
    await settled(() =>
      dispatched[0]?.refuse(
        new ActionError('maintenance_restore_pass', 'timeout', 'Timed out'),
      ),
    )
    expect(restoreModal('pass')?.textContent).toContain(
      HILOS_MAINTENANCE_RESTORE_PASS_COPY.unconfirmedNote,
    )
    await settled(() => fireEvent.click(cancel()))
    await settled(() => fireEvent.click(opener))

    await settled(() =>
      dispatched[0]?.settle({ reply: { pass: 'orphan-code' } }),
    )

    expect(document.body.textContent).not.toContain('orphan-code')
    expect(byId('hilos-maintenance-restore-pass-result')).toBeNull()
    expect(confirm().disabled).toBe(false)
    expect(dispatched).toHaveLength(1)
  })
})

describe.each(['open', 'close'] as const)(
  'HilosMaintenancePage restore %s confirmation',
  (name) => {
    const copy =
      name === 'open'
        ? {
            ...HILOS_MAINTENANCE_RESTORE_OPEN_COPY,
            note: HILOS_MAINTENANCE_RESTORE_OPEN_COPY.noWayBack,
          }
        : {
            ...HILOS_MAINTENANCE_RESTORE_CLOSE_COPY,
            note: HILOS_MAINTENANCE_RESTORE_CLOSE_COPY.footer,
          }
    const action = `maintenance_restore_${name}`
    const confirm = () => restoreButton(name, 'confirm')
    const cancel = () => restoreButton(name, 'cancel')

    it.each(['cancel', 'close', 'escape', 'backdrop'] as const)(
      'explains itself and allows %s before dispatch without sending anything',
      async (method) => {
        const { dispatched, opener } = await openRestore(name)
        const dialog = dialogOf(`hilos-maintenance-restore-${name}-modal`)
        expect(dialog.getAttribute('aria-label')).toBe(copy.title)
        expect(dialog.getAttribute('aria-modal')).toBe('true')
        expect(document.activeElement).toBe(dialog)
        expect(dialog.querySelector('input, textarea')).toBeNull()
        expect(dialog.textContent).toContain(copy.lead)
        expect(dialog.textContent).toContain(copy.note)
        expect(
          dialog.querySelector('[data-id="hilos-action-error-idle"]'),
        ).not.toBeNull()
        await settled(() => fireEvent.keyDown(dialog, { key: 'Enter' }))
        expect(dispatched).toHaveLength(0)
        expect(restoreModal(name)).not.toBeNull()

        await closeBy(dialog, cancel(), method)

        expect(restoreModal(name)).toBeNull()
        expect(dispatched).toHaveLength(0)
        expect(document.activeElement).toBe(opener)
      },
    )

    it('blocks repeat, Enter and every close while busy and closes on its one success', async () => {
      const { dispatched, opener } = await openRestore(name)
      const dialog = dialogOf(`hilos-maintenance-restore-${name}-modal`)
      pressEnter(confirm())
      pressEnter(confirm())
      await settled(() => {})

      expect(dispatched).toMatchObject([{ action }])
      expect(dispatched[0]?.payload).toEqual({})
      expect(confirm().getAttribute('aria-disabled')).toBe('true')
      expect(cancel().disabled).toBe(true)
      pressEnter(confirm())
      await tryEveryClose(dialog, cancel())
      expect(restoreModal(name)).not.toBeNull()
      expect(dispatched).toHaveLength(1)
      await settled(() => dispatched[0]?.loading.set(true))
      expect(confirm().getAttribute('aria-busy')).toBe('true')
      expect(hilosToasts.toasts.get()).toHaveLength(0)

      await settled(() => dispatched[0]?.settle({ message: 'Done' }))

      expect(restoreModal(name)).toBeNull()
      expect(document.activeElement).toBe(opener)
      expect(hilosToasts.toasts.get()).toMatchObject([
        { message: 'Done', severity: 'success', repeats: 1 },
      ])
      expect(dispatched).toHaveLength(1)
    })

    it('keeps its dialog through a frame that takes the ladder away until its own answer', async () => {
      const { dispatched, pushState } = await openRestore(name)
      const dialog = dialogOf(`hilos-maintenance-restore-${name}-modal`)
      await settled(() => fireEvent.click(confirm()))

      // The answer's own frame may come first: the ladder goes, the dialog stays busy.
      await settled(() =>
        pushState(
          name === 'open'
            ? maintenanceState()
            : restoreState({ phase: 'active' }),
        ),
      )
      expect(byId('hilos-maintenance-restore-ladder')).toBeNull()
      expect(restoreModal(name)?.closest('[role="dialog"]')).toBe(dialog)
      expect(cancel().disabled).toBe(true)
      await tryEveryClose(dialog, cancel())
      expect(restoreModal(name)).not.toBeNull()

      await settled(() => dispatched[0]?.settle({ message: 'Done' }))

      expect(restoreModal(name)).toBeNull()
      expect(dispatched).toHaveLength(1)
    })

    it.each(FAILURE_OUTCOMES)(
      'keeps %s readable in the dialog, releases Cancel and never retries',
      async (outcome) => {
        const { dispatched } = await openRestore(name)
        await settled(() => fireEvent.click(confirm()))
        const reason =
          outcome === 'fail'
            ? 'Another request is awaiting confirmation.'
            : 'No reply received.'

        await settled(() =>
          dispatched[0]?.refuse(new ActionError(action, outcome, reason)),
        )

        const message = outcome === 'fail' ? reason : copy.unconfirmedNote
        expect(
          restoreModal(name)?.querySelector('[data-id="hilos-action-error"]')
            ?.textContent,
        ).toContain(message)
        expect(
          restoreModal(name)
            ?.querySelector('[data-id="hilos-action-error-slot"]')
            ?.getAttribute('aria-live'),
        ).toBe('assertive')
        expect(cancel().disabled).toBe(false)
        expect(confirm().disabled).toBe(false)
        expect(hilosToasts.toasts.get()).toMatchObject([
          { message, severity: 'error' },
        ])
        expect(dispatched).toHaveLength(1)
        await settled(() => fireEvent.click(cancel()))
        expect(restoreModal(name)).toBeNull()
      },
    )

    it.each(LADDER_GONE)(
      'blocks an unsent confirmation once this browser no longer holds the ladder: %s',
      async (_case, state) => {
        const { pushState, dispatched } = await openRestore(name)

        await settled(() => pushState(state))

        expect(confirm().disabled).toBe(true)
        fireEvent.click(confirm())
        pressEnter(confirm())
        await settled(() => {})
        expect(dispatched).toHaveLength(0)
        expect(cancel().disabled).toBe(false)
        await settled(() => fireEvent.click(cancel()))
        expect(restoreModal(name)).toBeNull()
      },
    )
  },
)

describe('HilosMaintenancePage watchdog', () => {
  it('reserves the alarm slot before a frame without inventing threshold values', async () => {
    const { context, tableTraffic } = overviewContext()
    await mountPage(context)
    const slot = byId('hilos-maintenance-watchdog-slot') as HTMLElement
    expect(slot.getAttribute('role')).toBe('status')
    expect(slot.getAttribute('aria-live')).toBe('polite')
    expect(within(slot).queryAllByRole('button')).toHaveLength(0)
    expect(slot.querySelector('[aria-hidden="true"][inert]')).toBe(
      byId('hilos-maintenance-watchdog-idle'),
    )
    expect(byId('hilos-maintenance-watchdog-thresholds')).toBeNull()
    expect(document.body.textContent).not.toContain(
      HILOS_MAINTENANCE_WATCHDOG_COPY.thresholdsTitle,
    )
    expect(document.body.textContent).not.toContain(
      HILOS_MAINTENANCE_WATCHDOG_COPY.noRecipients,
    )
    expect(tableTraffic).toEqual([])
  })

  it.each([
    ['open', 'hilos-maintenance-enable'],
    ['manual', 'hilos-maintenance-disable'],
    ['operation', 'hilos-maintenance-restore-close'],
  ] as const)(
    'places alarm, state, operator actions and read-only thresholds in order when %s',
    async (position, actionId) => {
      const { context, pushState } = overviewContext()
      await mountPage(context)
      const state =
        position === 'operation'
          ? restoreState()
          : position === 'manual'
            ? manualState()
            : maintenanceState()
      await settled(() =>
        pushState({
          ...state,
          watchdogQuiesceTimeoutSeconds: 61,
          watchdogSilenceTimeoutSeconds: 901,
          watchdogAlertIntervalSeconds: 1801,
          watchdogAlertEmails: ['ops@example.com', 'alerts@example.com'],
          watchdogManualRestartIsNormal: true,
        }),
      )
      const slot = byId('hilos-maintenance-watchdog-slot') as HTMLElement
      const stateRoot = byId('hilos-maintenance-state') as HTMLElement
      const action = byId(actionId) as HTMLElement
      const thresholds = byId(
        'hilos-maintenance-watchdog-thresholds',
      ) as HTMLElement
      for (const [before, after] of [
        [slot, stateRoot],
        [stateRoot, action],
        [action, thresholds],
      ] as const) {
        expect(
          before.compareDocumentPosition(after) &
            Node.DOCUMENT_POSITION_FOLLOWING,
        ).not.toBe(0)
      }
      expect(stateRoot.contains(slot)).toBe(false)
      expect(stateRoot.contains(thresholds)).toBe(false)
      expect(
        within(thresholds).getByRole('heading', { level: 2 }).textContent,
      ).toBe('Watchdog thresholds')
      expect(
        [...thresholds.querySelectorAll('dt')].map((row) => row.textContent),
      ).toEqual([
        'Wait for nodes to quiesce',
        'Consider silent after',
        'Repeat alert',
        'Send mail to',
        'Restart under manual maintenance',
      ])
      expect(definitions(thresholds)).toEqual([
        '61 seconds',
        '901 seconds',
        '1801 seconds',
        'ops@example.com, alerts@example.com',
        'No mail — a log entry',
      ])
      expect(thresholds.textContent).toContain(
        'Thresholds and addresses come from the environment. Restart under manual maintenance is a setting changed in Settings.',
      )
      // Read-only: no editor, no control, no way out of the card.
      expect(
        thresholds.querySelectorAll('input, select, textarea, button, a'),
      ).toHaveLength(0)
      expect(slot.querySelectorAll('button')).toHaveLength(0)
    },
  )

  it('keeps unknown, empty and real configuration distinct and redraws a live restart policy', async () => {
    const { context, pushState } = overviewContext()
    await mountPage(context)
    await settled(() => pushState(maintenanceState()))
    const card = byId('hilos-maintenance-watchdog-thresholds') as HTMLElement
    expect(definitions(card)).toEqual(['—', '—', '—', '—', '—'])

    await settled(() =>
      pushState(
        maintenanceState({
          watchdogAlertEmails: [],
          watchdogManualRestartIsNormal: false,
        }),
      ),
    )
    expect(byId('hilos-maintenance-watchdog-thresholds')).toBe(card)
    expect(definitions(card)).toEqual([
      '—',
      '—',
      '—',
      'No recipients configured',
      'Send an alert',
    ])

    await settled(() =>
      pushState(
        maintenanceState({
          watchdogQuiesceTimeoutSeconds: 60,
          watchdogSilenceTimeoutSeconds: 900,
          watchdogAlertIntervalSeconds: 1800,
          watchdogAlertEmails: ['ops@example.com'],
          watchdogManualRestartIsNormal: true,
        }),
      ),
    )
    expect(definitions(card)).toEqual([
      '60 seconds',
      '900 seconds',
      '1800 seconds',
      'ops@example.com',
      'No mail — a log entry',
    ])
    expect(card.textContent).not.toContain('No recipients configured')
  })

  it('uses only the server verdict and keeps manual maintenance and a still operation clear across elapsed time', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] })
    vi.setSystemTime(new Date('2026-10-28T12:00:00.000Z'))

    try {
      const { context, pushState } = overviewContext()
      await mountPage(context)

      // Every threshold long past, and still no verdict: the browser judges nothing.
      for (const state of [
        manualState({ startedAt: '2026-01-01T00:00:00.000Z' }),
        restoreState({
          phase: 'activating',
          progressAt: '2026-01-01T00:00:00.000Z',
        }),
      ]) {
        await settled(() =>
          pushState({
            ...state,
            watchdogQuiesceTimeoutSeconds: 1,
            watchdogSilenceTimeoutSeconds: 1,
            watchdogAlertIntervalSeconds: 1,
            watchdogManualRestartIsNormal: true,
          }),
        )
        await act(async () => {
          await vi.advanceTimersByTimeAsync(60_000)
        })
        expect(byId('hilos-maintenance-watchdog-alert')).toBeNull()
        expect(byId('hilos-maintenance-watchdog-idle')).not.toBeNull()
      }

      await settled(() =>
        pushState(
          manualState({
            watchdogVerdict: 'restored-from-disk',
            watchdogManualRestartIsNormal: false,
          }),
        ),
      )
      expect(textOf('hilos-maintenance-watchdog-alert')).toContain(
        HILOS_MAINTENANCE_WATCHDOG_COPY.manualRestartSummary,
      )
    } finally {
      vi.useRealTimers()
    }
  })

  it('updates diagnostic moments through the reader-zone bridge without another state frame or backend action', async () => {
    const restoreMoments = englishMoments()
    const reader = readerTimeZone()

    try {
      act(() => reader.choose('UTC'))
      const { actions, dispatched } = makeActions()
      const { context, pushState, tableTraffic } = overviewContext(actions)
      await mountPage(context)
      await settled(() =>
        pushState(
          restoreState({
            watchdogVerdict: 'silent',
            watchdogPendingNodes: [
              { nodeId: 'node-3', nodeName: null },
              { nodeId: 'node-4', nodeName: null },
            ],
          }),
        ),
      )
      const opener = byId<HTMLButtonElement>(
        'hilos-maintenance-watchdog-details',
      ) as HTMLButtonElement
      opener.focus()
      await settled(() => fireEvent.click(opener))
      const full = byId('hilos-maintenance-watchdog-full') as HTMLElement
      const moments = () => definitions(full).slice(4, 7)
      expect(moments()).toEqual([
        'Sep 11, 2026, 3:14:02 AM',
        'Sep 11, 2026, 3:14:09 AM',
        'Sep 11, 2026, 3:21:44 AM',
      ])
      expect(full.textContent).toContain('node-3, node-4')

      act(() => reader.choose('America/Los_Angeles'))
      expect(moments()).toEqual([
        'Sep 10, 2026, 8:14:02 PM',
        'Sep 10, 2026, 8:14:09 PM',
        'Sep 10, 2026, 8:21:44 PM',
      ])
      act(() => reader.choose('Europe/Kyiv'))
      expect(moments()).toEqual([
        'Sep 11, 2026, 6:14:02 AM',
        'Sep 11, 2026, 6:14:09 AM',
        'Sep 11, 2026, 6:21:44 AM',
      ])

      await settled(() =>
        pushState(
          restoreState({
            watchdogVerdict: 'initiator-lost',
            watchdogPendingNodes: [],
          }),
        ),
      )
      expect(full.textContent).toContain('The initiator is gone')
      expect(full.textContent).not.toContain('No movement behind the freeze')
      expect(full.textContent).not.toContain('node-3')

      await settled(() => pushState(maintenanceState()))
      expect(byId('hilos-maintenance-watchdog-full')).toBeNull()
      expect(document.body.textContent).not.toContain('The initiator is gone')
      expect(dispatched).toEqual([])
      expect(tableTraffic).toEqual([])
    } finally {
      cleanup()
      reader.release()
      restoreMoments()
    }
  })
})
