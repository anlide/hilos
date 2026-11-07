import { Component, DestroyRef } from '@angular/core'
import { By } from '@angular/platform-browser'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  HILOS_MAINTENANCE_DISABLE_COPY,
  HILOS_MAINTENANCE_ENABLE_COPY,
  HILOS_MAINTENANCE_PASS_COPY,
  HILOS_MAINTENANCE_RESTORE_CLOSE_COPY,
  HILOS_MAINTENANCE_RESTORE_OPEN_COPY,
  HILOS_MAINTENANCE_RESTORE_PASS_COPY,
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
import type {
  ActionHandle,
  ActionLifecycle,
  ActionResult,
  HilosConnection,
  HilosMaintenanceContext,
  HilosMaintenanceState,
  HilosPageIdentity,
  HilosRouter,
  PageRouteMatch,
  ProjectSignal,
  WritableSignal,
} from '@hilos/core'
import {
  HILOS_MAINTENANCE_WATCHDOG_COPY,
  hilosMaintenanceElapsed,
} from '@hilos/core/admin/maintenance'
import {
  HIDDEN_VALUE,
  PROTECTED_MODE_INACTIVE,
  RT_STALENESS_FRESH,
  formatMoment,
  type ProtectedModeStatus,
} from '@hilos/core/base'

import { HilosMaintenancePage } from '../src/admin/maintenance/HilosMaintenancePage.js'
import { HilosLayout } from '../src/HilosLayout.js'
import { HilosPageTemplate } from '../src/HilosPageTemplate.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'
import { bindTestTimeZone } from './timeZoneTestHarness.js'

const timeZoneReleases: (() => void)[] = []
const MAINTENANCE_LEAD =
  'Node freeze under a destructive operation and manual maintenance: its state, the verifier circle, closing the system to visitors, and the way out of the window.'

/** The router of a tab standing on Overview, with the identity its answer carries. */
function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.MAINTENANCE,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/maintenance'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal<HilosPageIdentity | undefined>({
      label: 'Maintenance',
      lead: MAINTENANCE_LEAD,
      breadcrumb: [
        { page: HilosPages.DASHBOARD, label: 'Hilos' },
        { page: HilosPages.MAINTENANCE, label: 'Maintenance' },
      ],
      children: [],
      pageHeadOwner: {
        page: HilosPages.MAINTENANCE,
        label: 'Maintenance',
        lead: MAINTENANCE_LEAD,
        icon: 'bi-tools',
      },
      pageTabs: [
        { page: HilosPages.MAINTENANCE, label: 'Overview' },
        { page: HilosPages.MAINTENANCE_CIRCLE, label: 'Circle' },
      ],
    }),
    dashboardSections: createSignal(undefined),
    resolvePath: (page) =>
      (
        ({
          [HilosPages.DASHBOARD]: '/hilos',
          [HilosPages.MAINTENANCE]: '/hilos/maintenance',
          [HilosPages.MAINTENANCE_CIRCLE]: '/hilos/maintenance/circle',
        }) as Record<string, string>
      )[page],
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
 * the test: a confirmation closes on the server's word, so a fake that settled by
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
 * A context whose connection hands the section's state frame to every listener of
 * project signals and records any table traffic: Overview opens no table.
 *
 * @param actions The action lifecycle the confirmations dispatch over.
 */
function seededContext(actions: ActionLifecycle = makeActions().actions): {
  context: HilosMaintenanceContext
  tableTraffic: string[]
  pushState: (state: HilosMaintenanceState) => void
} {
  const tableTraffic: string[] = []
  const stateListeners = new Set<
    (signal: { type: string; data: unknown }) => void
  >()
  const connection = {
    registerTableWindow(): void {
      tableTraffic.push('register')
    },
    unregisterTableWindow(): void {
      tableTraffic.push('unregister')
    },
    sendTableViewport(): boolean {
      tableTraffic.push('viewport')

      return true
    },
    sendTableRendered(): boolean {
      tableTraffic.push('rendered')

      return true
    },
    sendTableRowFocus(): boolean {
      tableTraffic.push('focus')

      return true
    },
    on(
      event: string,
      listener: (signal: { type: string; data: unknown }) => void,
    ): () => void {
      if (event !== 'projectSignal') {
        tableTraffic.push(event)

        return () => {}
      }
      stateListeners.add(listener)

      return () => stateListeners.delete(listener)
    },
  }

  return {
    context: {
      connection:
        connection as unknown as HilosMaintenanceContext['connection'],
      scopes: new ScopeManager(),
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

function mountPage(
  context: HilosMaintenanceContext,
): ComponentFixture<HilosMaintenancePage> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })
  const fixture = TestBed.createComponent(HilosMaintenancePage)
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()

  return fixture
}

afterEach(() => {
  hilosToasts.clear()
  for (const release of timeZoneReleases.splice(0)) release()
})

/** Let a settled action reach the dialog through Angular's render. */
async function settled(
  fixture: ComponentFixture<HilosMaintenancePage>,
): Promise<void> {
  // The test's action promise is outside Angular's pending-task accounting:
  // let the driver and every submit continuation after it — the code result
  // awaits the answer twice — finish before waiting on render.
  await new Promise((resolve) => setTimeout(resolve))
  await fixture.whenStable()
  fixture.detectChanges()
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
 * An element by its data-id anywhere in the document, or null while it is not
 * drawn.
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
 * @param fixture The mounted page.
 * @param dialog The dialog element.
 * @param cancel The dialog's own Cancel button.
 * @param method Which of the four ways.
 */
async function closeBy(
  fixture: ComponentFixture<HilosMaintenancePage>,
  dialog: HTMLElement,
  cancel: HTMLButtonElement,
  method: CloseMethod,
): Promise<void> {
  if (method === 'cancel') cancel.click()
  if (method === 'close')
    dialog.querySelector<HTMLButtonElement>('[data-id="modal-close"]')?.click()
  if (method === 'escape')
    dialog.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
  if (method === 'backdrop')
    dialog.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  await settled(fixture)
}

/**
 * Try every way of closing a dialog at once — the busy dialog must refuse them all.
 *
 * @param fixture The mounted page.
 * @param dialog The dialog element.
 * @param cancel The dialog's own Cancel button.
 */
async function tryEveryClose(
  fixture: ComponentFixture<HilosMaintenancePage>,
  dialog: HTMLElement,
  cancel: HTMLButtonElement,
): Promise<void> {
  for (const method of ['cancel', 'close', 'escape', 'backdrop'] as const) {
    await closeBy(fixture, dialog, cancel, method)
  }
}

describe('HilosMaintenancePage', () => {
  it('offers enable only after the open frame arrives', async () => {
    const { context, pushState } = seededContext()
    const fixture = mountPage(context)
    expect(byId('hilos-maintenance-state-open')).toBeNull()
    expect(byId('hilos-maintenance-enable')).toBeNull()

    pushState(maintenanceState())
    await settled(fixture)

    const stateBlock = byId('hilos-maintenance-state') as HTMLElement
    expect(stateBlock.getAttribute('aria-live')).toBe('polite')
    expect(stateBlock.textContent).toContain(
      HILOS_MAINTENANCE_STATE_COPY.openTitle,
    )
    expect(stateBlock.textContent).toContain(
      HILOS_MAINTENANCE_STATE_COPY.openDescription,
    )
    expect(
      stateBlock
        .querySelector('[data-id="hilos-maintenance-enable"]')
        ?.textContent?.trim(),
    ).toBe(HILOS_MAINTENANCE_ENABLE_COPY.enableTitle)
  })

  it.each(['disable', 'pass'] as const)(
    'offers %s only in the manual card, for either entry source',
    async (button) => {
      const { context, pushState } = seededContext()
      const fixture = mountPage(context)
      const shown = () => byId(`hilos-maintenance-${button}`) !== null
      expect(shown()).toBe(false)
      for (const state of [
        maintenanceState(),
        maintenanceState({ phase: 'verifying', operation: 'restore' }),
      ]) {
        pushState(state)
        await settled(fixture)
        expect(shown()).toBe(false)
      }
      for (const enteredFromBrowser of [true, false]) {
        pushState(manualState({ enteredFromBrowser }))
        await settled(fixture)
        expect(shown()).toBe(true)
      }
    },
  )

  it('renders the manual card and redraws its pass count on a new frame', async () => {
    const { context, pushState } = seededContext()
    const fixture = mountPage(context)
    const manual = manualState({
      startedAt: new Date(Date.now() - 150_000).toISOString(),
      enteredFromBrowser: false,
      circleNamed: 3,
      circleAdmitted: 2,
    })

    pushState(manual)
    await settled(fixture)

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
      card
        .querySelector('[data-id="hilos-maintenance-disable"]')
        ?.textContent?.trim(),
    ).toBe(HILOS_MAINTENANCE_DISABLE_COPY.disableTitle)
    expect(
      card
        .querySelector('[data-id="hilos-maintenance-pass"]')
        ?.textContent?.trim(),
    ).toBe(HILOS_MAINTENANCE_PASS_COPY.issue)

    pushState({ ...manual, passCount: 1 })
    await settled(fixture)

    expect(byId('hilos-maintenance-pass-count')?.textContent?.trim()).toBe('1')
  })

  it("moves the manual card's elapsed time on the browser's clock", async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] })
    // jsdom draws an animation frame on an interval too, and Angular's scheduler
    // asks for frames, so the page's own clock is told apart by its period.
    const startClock = vi.spyOn(globalThis, 'setInterval')
    const stopClock = vi.spyOn(globalThis, 'clearInterval')
    try {
      const { context, pushState } = seededContext()
      const fixture = mountPage(context)
      pushState(manualState({ startedAt: new Date().toISOString() }))
      await settled(fixture)
      const card = () => byId('hilos-maintenance-state-manual') as HTMLElement
      expect(card().textContent).toContain(HILOS_MAINTENANCE_STATE_COPY.justNow)

      vi.advanceTimersByTime(120_000)
      await settled(fixture)

      expect(card().textContent).toContain('2 minutes ago')
      const clocks = startClock.mock.calls.flatMap(([, period], index) =>
        period === 30_000 ? [startClock.mock.results[index]?.value] : [],
      )
      expect(clocks).toHaveLength(1)
      expect(stopClock).not.toHaveBeenCalledWith(clocks[0])
      fixture.destroy()
      expect(stopClock).toHaveBeenCalledWith(clocks[0])
    } finally {
      vi.restoreAllMocks()
      vi.useRealTimers()
    }
  })

  it('renders the operation card from the frame and keeps every unpublished fact a dash', async () => {
    const { context, pushState } = seededContext()
    const fixture = mountPage(context)
    const startedAt = '2026-10-10T10:00:00.000Z'

    pushState(
      maintenanceState({
        phase: 'verifying',
        operation: 'restore',
        startedAt,
        passCount: 2,
      }),
    )
    await settled(fixture)

    const card = byId('hilos-maintenance-state-operation') as HTMLElement
    const fact = (dataId: string) =>
      card.querySelector(`[data-id="${dataId}"]`)?.textContent?.trim()
    expect(card.textContent).toContain(
      HILOS_MAINTENANCE_STATE_COPY.operationTitle,
    )
    expect(card.textContent).toContain(
      HILOS_MAINTENANCE_STATE_COPY.verifyingPhaseNote,
    )
    expect(card.textContent).toContain('restore')
    expect(
      card.querySelector('[data-id="hilos-maintenance-started-at"]')
        ?.textContent,
    ).toBe(formatMoment(startedAt, { kind: 'dateTime' }))
    expect(card.textContent).toContain(HILOS_MAINTENANCE_STATE_COPY.initiator)
    expect(fact('hilos-maintenance-initiator')).toBe('— · node —')
    expect(fact('hilos-maintenance-stopped-agents')).toBe('—')
    expect(fact('hilos-maintenance-diagnostics-node')).toBe('—')
    expect(fact('hilos-maintenance-agent-start')).toBe('—')
    expect(card.textContent).not.toContain(
      HILOS_MAINTENANCE_STATE_COPY.agentStartClosed,
    )
    expect(fact('hilos-maintenance-pass-count')).toBe('2')
    expect(card.querySelector('button')).toBeNull()
  })

  it('opens no circle table, viewport or row focus of its own', async () => {
    const { context, pushState, tableTraffic } = seededContext()
    const fixture = mountPage(context)
    pushState(manualState({ circleNamed: 1 }))
    await settled(fixture)

    const root = fixture.nativeElement as HTMLElement
    expect(root.querySelector('table')).toBeNull()
    expect(
      root.querySelector('[data-id="hilos-maintenance-circle-table"]'),
    ).toBeNull()
    expect(tableTraffic).toEqual([])
    fixture.destroy()
    expect(tableTraffic).toEqual([])
  })
})

describe('HilosMaintenancePage head', () => {
  it('shares the Maintenance section head and selects only Overview', () => {
    const { context } = seededContext()
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement

    expect(root.querySelectorAll('h1')).toHaveLength(1)
    expect(root.querySelector('h1')?.textContent?.trim()).toBe('Maintenance')
    expect(
      root.querySelector('[data-id="hilos-page-head-block"]')?.textContent,
    ).toContain(MAINTENANCE_LEAD)
    const breadcrumb = root.querySelector('[data-id="hilos-breadcrumb"]')
    expect(breadcrumb?.querySelectorAll('li')).toHaveLength(2)
    expect(breadcrumb?.querySelector('[aria-current="page"]')).not.toBeNull()
    expect(breadcrumb?.querySelector('[aria-current="page"] a')).toBeNull()
    expect(
      [...(breadcrumb?.querySelectorAll('a') ?? [])].map((link) =>
        link.getAttribute('href'),
      ),
    ).toEqual(['/hilos'])
    const tabs = root.querySelector('nav[aria-label="Maintenance pages"]')
    const links = [...(tabs?.querySelectorAll('a') ?? [])].map((link) => [
      link.textContent?.trim(),
      link.getAttribute('href'),
    ])
    expect(links).toHaveLength(2)
    expect(links).toEqual(
      expect.arrayContaining([
        ['Overview', '/hilos/maintenance'],
        ['Circle', '/hilos/maintenance/circle'],
      ]),
    )
    const current = tabs?.querySelectorAll('a[aria-current="page"]')
    expect(current).toHaveLength(1)
    expect(current?.[0]?.textContent?.trim()).toBe('Overview')
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
  const { context, pushState } = seededContext(actions)
  const fixture = mountPage(context)
  pushState(manual.opensFrom)
  await settled(fixture)
  const opener = byId<HTMLButtonElement>(
    `hilos-maintenance-${manual.name}`,
  ) as HTMLButtonElement
  opener.focus()
  opener.click()
  await settled(fixture)

  return { fixture, pushState, dispatched, opener }
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
        const { fixture, dispatched, opener } = await openManual(manual)
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

        await closeBy(fixture, dialog, cancel(), method)

        expect(modal()).toBeNull()
        expect(dispatched).toHaveLength(0)
        expect(document.activeElement).toBe(opener)
      },
    )

    it('blocks repeat and every close while busy, survives a state push and settles on its one success', async () => {
      const { fixture, dispatched, pushState } = await openManual(manual)
      const dialog = dialogOf(`hilos-maintenance-${manual.name}-modal`)
      confirm().click()
      confirm().click()
      await settled(fixture)

      expect(dispatched).toMatchObject([{ action: manual.action, payload: {} }])
      expect(confirm().getAttribute('aria-disabled')).toBe('true')
      expect(cancel().disabled).toBe(true)
      await tryEveryClose(fixture, dialog, cancel())
      expect(modal()).not.toBeNull()
      dispatched[0]?.loading.set(true)
      await settled(fixture)
      expect(confirm().getAttribute('aria-busy')).toBe('true')

      // A state push replaces the block under the dialog and leaves the dialog be.
      pushState(manual.leaves[0] as HilosMaintenanceState)
      await settled(fixture)
      expect(byId(`hilos-maintenance-${manual.name}`)).toBeNull()
      expect(byId(manual.leftBlock)).not.toBeNull()
      expect(modal()?.closest('[role="dialog"]')).toBe(dialog)
      expect(cancel().disabled).toBe(true)
      expect(hilosToasts.toasts.get()).toHaveLength(0)

      dispatched[0]?.settle({
        message: manual.successMessage,
        ...(manual.name === 'pass' ? { reply: { pass: 'opaque-secret' } } : {}),
      })
      await settled(fixture)

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
        const { fixture, pushState, dispatched } = await openManual(manual)

        pushState(state)
        await settled(fixture)

        expect(confirm().disabled).toBe(true)
        confirm().click()
        expect(dispatched).toHaveLength(0)
        expect(cancel().disabled).toBe(false)
        cancel().click()
        await settled(fixture)
        expect(modal()).toBeNull()
      },
    )

    it.each(['fail', 'timeout', 'disconnected', 'invalid-reply'] as const)(
      'keeps %s readable in the modal, releases Cancel and never retries',
      async (outcome) => {
        const { fixture, dispatched, pushState } = await openManual(manual)
        confirm().click()
        await settled(fixture)
        // A changed state never answers the action, even when its eventual answer is a refusal.
        pushState(manual.leaves[0] as HilosMaintenanceState)
        await settled(fixture)
        const reason =
          outcome === 'fail'
            ? 'Another request is awaiting confirmation.'
            : 'No reply received.'

        dispatched[0]?.refuse(new ActionError(manual.action, outcome, reason))
        await settled(fixture)

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
        cancel().click()
        await settled(fixture)
        expect(modal()).toBeNull()
      },
    )

    it('shows the entire backend unconfirmed reason and admin details through the standard refusal plate', async () => {
      const { fixture, dispatched } = await openManual(manual)
      confirm().click()
      await settled(fixture)
      const reason = `${manual.unconfirmedNote} The agent did not observe its phase.`

      dispatched[0]?.refuse(
        new ActionError(
          manual.action,
          'fail',
          reason,
          'MaintenanceFailure',
          'Original detail',
        ),
      )
      await settled(fixture)
      modal()
        ?.querySelector<HTMLButtonElement>(
          '[data-id="hilos-action-error-details"]',
        )
        ?.click()
      await settled(fixture)

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

/** The three steps of the restore ladder, by their name in the data-ids. */
type RestoreId = 'pass' | 'open' | 'close'

const RESTORE_IDS: readonly RestoreId[] = ['pass', 'open', 'close']

/**
 * A restore in its verification window, as the server sends it to the browser
 * that started it: every fact published.
 *
 * @param overrides The fields this frame says otherwise.
 */
function restoreFrame(
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
 * The body of a restore step's dialog, or null while it is closed.
 *
 * @param id The step.
 */
function restoreModal(id: RestoreId): HTMLElement | null {
  return byId(`hilos-maintenance-restore-${id}-modal`)
}

/**
 * A button of a restore step's dialog, which must already be open.
 *
 * @param id The step.
 * @param button Which of the two buttons.
 */
function restoreButton(
  id: RestoreId,
  button: 'confirm' | 'cancel',
): HTMLButtonElement {
  return byId<HTMLButtonElement>(
    `hilos-maintenance-restore-${id}-${button}`,
  ) as HTMLButtonElement
}

/**
 * Press Enter on a focused button: a press the button cancels does not click.
 *
 * @param button The button under the key.
 */
function pressEnter(button: HTMLButtonElement): void {
  button.focus()
  const event = new KeyboardEvent('keydown', {
    key: 'Enter',
    bubbles: true,
    cancelable: true,
  })
  button.dispatchEvent(event)
  if (!event.defaultPrevented) button.click()
}

/**
 * Mount the real page on the initiating browser's restore frame and open one
 * step of its ladder.
 *
 * @param id The step to open.
 */
async function openRestore(id: RestoreId) {
  const { actions, dispatched } = makeActions()
  const { context, pushState } = seededContext(actions)
  const fixture = mountPage(context)
  pushState(restoreFrame())
  await settled(fixture)
  const opener = byId<HTMLButtonElement>(
    `hilos-maintenance-restore-${id}`,
  ) as HTMLButtonElement
  opener.focus()
  opener.click()
  await settled(fixture)

  return { fixture, pushState, dispatched, opener }
}

/** The real shell owns the Overview's embedded view during a refreeze. */
@Component({
  selector: 'test-maintenance-shell',
  imports: [HilosLayout, HilosPageTemplate, HilosMaintenancePage],
  template: `
    <hilos-layout [connection]="connection">
      <ng-template hilosPage>
        <hilos-maintenance-page [context]="context" />
      </ng-template>
    </hilos-layout>
  `,
})
class MaintenanceShellHost {
  connection!: HilosConnection
  context!: HilosMaintenanceContext
}

/** A separately controlled transport snapshot, as in the shell's lifecycle tests. */
function shellFeed() {
  const listeners = new Map<string, Set<(payload: never) => void>>()
  const state = {
    state: 'connected',
    protectedMode: PROTECTED_MODE_INACTIVE,
    rtStaleness: RT_STALENESS_FRESH,
    reconnectDragging: false,
    firstFrameHeld: false,
    on(event: string, listener: (payload: never) => void): () => void {
      const set = listeners.get(event) ?? new Set()
      listeners.set(event, set)
      set.add(listener)
      return () => {
        set.delete(listener)
      }
    },
  }
  return {
    connection: state as unknown as HilosConnection,
    push(protectedMode: ProtectedModeStatus): void {
      state.protectedMode = protectedMode
      for (const listener of listeners.get('protectedMode') ?? [])
        listener(protectedMode as never)
    },
  }
}

/** Open the restore pass on an admitted browser, keeping the shell alive over the freeze. */
async function openRestoreInShell() {
  const { actions, dispatched } = makeActions()
  const { context, pushState } = seededContext(actions)
  const feed = shellFeed()
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })
  const fixture = TestBed.createComponent(MaintenanceShellHost)
  fixture.componentInstance.connection = feed.connection
  fixture.componentInstance.context = context
  // Fake timers drain promises and Angular render tasks without real-clock waits.
  const render = async () => {
    fixture.detectChanges()
    await vi.advanceTimersByTimeAsync(0)
    fixture.detectChanges()
  }
  await render()
  pushState(restoreFrame())
  await render()
  const page = fixture.debugElement.query(By.directive(HilosMaintenancePage))
  const destroyed = vi.fn()
  page.injector.get(DestroyRef).onDestroy(destroyed)
  byId<HTMLButtonElement>('hilos-maintenance-restore-pass')!.click()
  await render()

  return { fixture, feed, pushState, dispatched, render, page, destroyed }
}

/** The trimmed values of the watchdog thresholds card, in display order. */
function thresholdValues(): (string | undefined)[] {
  return [
    ...(byId('hilos-maintenance-watchdog-thresholds')?.querySelectorAll('dd') ??
      []),
  ].map((row) => row.textContent?.trim())
}

/**
 * What each row of the thresholds card says, by its label: which threshold a
 * value belongs to is the fact, not the order the rows are drawn in.
 */
function thresholdFacts(): Record<string, string> {
  const card = byId('hilos-maintenance-watchdog-thresholds')
  const labels = [...(card?.querySelectorAll('dt') ?? [])]
  const values = [...(card?.querySelectorAll('dd') ?? [])]
  expect(values).toHaveLength(labels.length)

  return Object.fromEntries(
    labels.map((label, index) => [
      label.textContent?.trim() ?? '',
      values[index]?.textContent?.trim() ?? '',
    ]),
  )
}

/** Tear down the isolated fixture and remove its root view from the document. */
function leavePage(): void {
  TestBed.resetTestingModule()
}

describe('HilosMaintenancePage and the admin view mode', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
    for (const release of releases.splice(0)) release()
  })

  /**
   * Bind the session scope and the admin access the way bootHilos does, over
   * handshakes this harness emits.
   */
  function bindSession() {
    const listeners: ((signal: ProjectSignal) => void)[] = []
    const connection = {
      on(event: string, listener: (payload: never) => void): () => void {
        if (event === 'projectSignal') {
          listeners.push(listener as (signal: ProjectSignal) => void)
        }

        return () => {}
      },
    } as unknown as HilosConnection
    const scopes = new ScopeManager()
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
      const { handshake } = bindSession()
      handshake(null, true)
      const { actions, dispatched } = makeActions()
      const { context, pushState } = seededContext(actions)
      const fixture = mountPage(context)
      pushState(manual.opensFrom)
      await settled(fixture)
      const button = byId<HTMLButtonElement>(
        `hilos-maintenance-${manual.name}`,
      ) as HTMLButtonElement
      expect(button.disabled).toBe(true)
      expect(button.getAttribute('aria-describedby')).toContain(
        HILOS_VIEW_MODE_STRIP_TEXT_ID,
      )
      button.click()
      await settled(fixture)
      expect(byId(`hilos-maintenance-${manual.name}-modal`)).toBeNull()

      handshake({ id: 7, admin: true }, true)
      await settled(fixture)
      expect(button.disabled).toBe(false)
      button.click()
      await settled(fixture)
      expect(manualButton(manual.name, 'confirm').disabled).toBe(false)

      handshake({ id: 7, admin: false }, true)
      await settled(fixture)
      expect(manualButton(manual.name, 'confirm').disabled).toBe(true)
      manualButton(manual.name, 'confirm').click()
      expect(dispatched).toHaveLength(0)
      expect(manualButton(manual.name, 'cancel').disabled).toBe(false)
    },
  )

  it('shows restore facts to a viewer and blocks the ladder and its confirmations', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { actions, dispatched } = makeActions()
    const { context, pushState } = seededContext(actions)
    const fixture = mountPage(context)
    pushState(restoreFrame())
    await settled(fixture)
    const card = byId('hilos-maintenance-state-operation') as HTMLElement
    expect(
      card
        .querySelector('[data-id="hilos-maintenance-initiator"]')
        ?.textContent?.trim(),
    ).toBe('hilos_backup · node node-1')
    expect(
      card
        .querySelector('[data-id="hilos-maintenance-stopped-agents"]')
        ?.textContent?.trim(),
    ).toBe('6 · hilos_mail left running')
    expect(
      card
        .querySelector('[data-id="hilos-maintenance-diagnostics-node"]')
        ?.textContent?.trim(),
    ).toBe('node-1')
    for (const id of RESTORE_IDS) {
      const opener = byId<HTMLButtonElement>(
        `hilos-maintenance-restore-${id}`,
      ) as HTMLButtonElement
      expect(opener.disabled).toBe(true)
      expect(opener.getAttribute('aria-describedby')).toContain(
        HILOS_VIEW_MODE_STRIP_TEXT_ID,
      )
      opener.click()
      await settled(fixture)
      expect(restoreModal(id)).toBeNull()
    }

    for (const id of RESTORE_IDS) {
      handshake({ id: 7, admin: true }, true)
      await settled(fixture)
      const opener = byId<HTMLButtonElement>(
        `hilos-maintenance-restore-${id}`,
      ) as HTMLButtonElement
      expect(opener.disabled).toBe(false)
      opener.click()
      await settled(fixture)
      expect(restoreButton(id, 'confirm').disabled).toBe(false)

      // The rights leave before the press: nothing is sent, Cancel still closes.
      handshake({ id: 7, admin: false }, true)
      await settled(fixture)
      expect(restoreButton(id, 'confirm').disabled).toBe(true)
      expect(
        restoreButton(id, 'confirm').getAttribute('aria-describedby'),
      ).toContain(HILOS_VIEW_MODE_STRIP_TEXT_ID)
      pressEnter(restoreButton(id, 'confirm'))
      restoreButton(id, 'confirm').click()
      expect(dispatched).toHaveLength(0)
      expect(restoreButton(id, 'cancel').disabled).toBe(false)
      restoreButton(id, 'cancel').click()
      await settled(fixture)
      expect(restoreModal(id)).toBeNull()
    }
  })

  it('keeps watchdog details readable for a viewer and draws hidden thresholds through HiddenMark', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { actions, dispatched } = makeActions()
    const { context, pushState } = seededContext(actions)
    const fixture = mountPage(context)
    pushState(
      restoreFrame({
        watchdogVerdict: 'silent',
        watchdogAlertEmails: HIDDEN_VALUE,
        watchdogManualRestartIsNormal: HIDDEN_VALUE,
      }),
    )
    await settled(fixture)
    const card = byId('hilos-maintenance-watchdog-thresholds') as HTMLElement
    expect(card.querySelectorAll('[data-id="hilos-hidden"]')).toHaveLength(2)
    expect(thresholdValues()).toEqual(['—', '—', '—', 'Hidden', 'Hidden'])
    expect(card.textContent).not.toContain('No recipients configured')
    const opener = byId<HTMLButtonElement>(
      'hilos-maintenance-watchdog-details',
    ) as HTMLButtonElement
    expect(opener.disabled).toBe(false)
    opener.focus()
    opener.click()
    await settled(fixture)
    expect(byId('hilos-maintenance-watchdog-full')?.textContent).toContain(
      'No movement behind the freeze',
    )
    byId<HTMLButtonElement>('hilos-maintenance-watchdog-close')?.click()
    await settled(fixture)
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
      const { fixture, dispatched, opener } = await openManual(PASS)
      confirm().click()
      await settled(fixture)
      const secret = '<opaque&code>'
      dispatched[0]?.settle({ message: 'Code issued', reply: { pass: secret } })
      await settled(fixture)
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

      await closeBy(fixture, dialog, cancel(), method)

      expect(passModal()).toBeNull()
      expect(document.body.textContent).not.toContain(secret)
      expect(document.activeElement).toBe(opener)
      opener.click()
      await settled(fixture)
      expect(passModal()?.textContent).not.toContain(secret)
      expect(byId('hilos-maintenance-pass-result')).toBeNull()
      expect(confirm().disabled).toBe(false)
      expect(dispatched).toHaveLength(1)
      confirm().click()
      await settled(fixture)
      expect(dispatched).toHaveLength(2)
      dispatched[1]?.settle({ reply: { pass: 'new-code' } })
      await settled(fixture)
      expect(passModal()?.textContent).toContain('new-code')
      expect(passModal()?.textContent).not.toContain(secret)
    },
  )

  it('forgets a shown result on unmount', async () => {
    const { fixture, dispatched } = await openManual(PASS)
    confirm().click()
    await settled(fixture)
    dispatched[0]?.settle({ reply: { pass: 'departed-code' } })
    await settled(fixture)
    expect(passModal()?.textContent).toContain('departed-code')

    leavePage()

    expect(document.body.textContent).not.toContain('departed-code')
    expect(passModal()).toBeNull()
  })

  it('cannot resurrect a result from a promise after unmount', async () => {
    const { fixture, dispatched } = await openManual(PASS)
    confirm().click()
    await settled(fixture)
    leavePage()

    dispatched[0]?.settle({ reply: { pass: 'late-code' } })
    await new Promise((resolve) => setTimeout(resolve))

    expect(document.body.textContent).not.toContain('late-code')
    expect(passModal()).toBeNull()
    const reopened = await openManual(PASS)
    expect(passModal()?.textContent).not.toContain('late-code')
    expect(reopened.dispatched).toHaveLength(0)
  })

  it('a late success after timeout cannot replace a newly opened confirmation', async () => {
    const { fixture, dispatched, opener } = await openManual(PASS)
    confirm().click()
    await settled(fixture)
    dispatched[0]?.refuse(
      new ActionError('maintenance_pass', 'timeout', 'Timed out'),
    )
    await settled(fixture)
    expect(passModal()?.textContent).toContain(
      HILOS_MAINTENANCE_PASS_COPY.unconfirmedNote,
    )
    cancel().click()
    await settled(fixture)
    opener.click()
    await settled(fixture)

    dispatched[0]?.settle({ reply: { pass: 'orphan-code' } })
    await settled(fixture)

    expect(document.body.textContent).not.toContain('orphan-code')
    expect(byId('hilos-maintenance-pass-result')).toBeNull()
    expect(dispatched).toHaveLength(1)
  })
})

describe('HilosMaintenancePage moments', () => {
  it('reformats the manual start without closing its pass dialog', async () => {
    const timeZone = bindTestTimeZone('UTC')
    timeZoneReleases.push(timeZone.release)
    timeZone.receive('UTC')

    const startedAt = '2026-09-10T00:30:00.000Z'
    const { context, pushState } = seededContext()
    const fixture = mountPage(context)
    pushState(manualState({ startedAt }))
    await settled(fixture)

    const started = byId('hilos-maintenance-started-at')
    const card = byId('hilos-maintenance-state-manual')
    const before = formatMoment(startedAt, { kind: 'dateTime' })
    expect(started?.textContent).toBe(before)

    byId('hilos-maintenance-pass')?.click()
    await settled(fixture)
    const dialog = dialogOf('hilos-maintenance-pass-modal')
    expect(dialog).not.toBeNull()

    timeZone.receive('America/Los_Angeles')
    await fixture.whenStable()
    fixture.detectChanges()

    const after = formatMoment(startedAt, { kind: 'dateTime' })
    expect(after).not.toBe(before)
    expect(started?.textContent).toBe(after)
    expect(byId('hilos-maintenance-state-manual')).toBe(card)
    expect(dialogOf('hilos-maintenance-pass-modal')).toBe(dialog)
  })

  it('reformats operation moments in the reader zone and keeps its card', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] })
    vi.setSystemTime(new Date('2026-09-10T00:45:00.000Z'))
    const timeZone = bindTestTimeZone('UTC')
    timeZoneReleases.push(timeZone.release)
    timeZone.receive('UTC')

    const startedAt = '2026-09-10T00:30:00.000Z'
    const activatedAt = '2026-09-10T00:10:00.000Z'
    const progressAt = '2026-09-10T00:20:00.000Z'

    try {
      const { context, pushState } = seededContext()
      const fixture = mountPage(context)
      pushState(
        maintenanceState({
          phase: 'verifying',
          operation: 'restore',
          startedAt,
          activatedAt,
          progressAt,
        }),
      )
      await settled(fixture)

      const card = byId('hilos-maintenance-state-operation')
      const started = byId('hilos-maintenance-started-at')
      const activated = byId('hilos-maintenance-activated-at')
      const progress = byId('hilos-maintenance-progress-at')
      const elapsed = byId('hilos-maintenance-progress-elapsed')
      const expectedStarted = formatMoment(startedAt, { kind: 'dateTime' })
      const expectedActivated = formatMoment(activatedAt, { kind: 'dateTime' })
      const expectedProgress = formatMoment(progressAt, { kind: 'dateTime' })

      expect(started?.textContent).toBe(expectedStarted)
      expect(activated?.textContent).toBe(expectedActivated)
      expect(progress?.textContent).toBe(expectedProgress)
      expect(elapsed?.textContent).toBe(
        hilosMaintenanceElapsed(progressAt, Date.now()),
      )

      timeZone.receive('America/Los_Angeles')
      await fixture.whenStable()
      fixture.detectChanges()

      const expectedStartedAfter =
        formatMoment(startedAt, { kind: 'dateTime' }) ?? '—'
      const expectedActivatedAfter =
        formatMoment(activatedAt, { kind: 'dateTime' }) ?? '—'
      const expectedProgressAfter =
        formatMoment(progressAt, { kind: 'dateTime' }) ?? '—'
      expect(expectedStartedAfter).not.toBe(expectedStarted)
      expect(expectedActivatedAfter).not.toBe(expectedActivated)
      expect(expectedProgressAfter).not.toBe(expectedProgress)
      expect(started?.textContent).toBe(expectedStartedAfter)
      expect(activated?.textContent).toBe(expectedActivatedAfter)
      expect(progress?.textContent).toBe(expectedProgressAfter)
      expect(elapsed?.textContent).toBe(
        hilosMaintenanceElapsed(progressAt, Date.now()),
      )
      expect(byId('hilos-maintenance-state-operation')).toBe(card)

      pushState(
        maintenanceState({
          phase: 'verifying',
          operation: 'restore',
          startedAt: 'not-a-moment',
          activatedAt: 'not-a-moment',
          progressAt: null,
        }),
      )
      await settled(fixture)

      expect(started?.textContent).toBe('—')
      expect(activated?.textContent).toBe('—')
      expect(progress?.textContent).toBe('—')
      expect(byId('hilos-maintenance-progress-elapsed')).toBeNull()
      expect(document.body.textContent).not.toContain('1970')
      fixture.destroy()
    } finally {
      vi.useRealTimers()
      vi.restoreAllMocks()
    }
  })
})

describe('HilosMaintenancePage restore card', () => {
  it('renders the core initiator, diagnostic and other-browser labels as literal text with id fallbacks', async () => {
    const { context, pushState } = seededContext()
    const fixture = mountPage(context)
    pushState(
      restoreFrame({
        isInitiator: false,
        initiatorNodeName: 'Київ <b>主节点</b>',
        diagnosticsNodeId: 'node-2',
        diagnosticsNodeName: '診断 <em>Ω</em>',
      }),
    )
    await settled(fixture)

    expect(byId('hilos-maintenance-initiator')?.textContent?.trim()).toBe(
      'hilos_backup · node Київ <b>主节点</b> (node-1)',
    )
    expect(
      byId('hilos-maintenance-diagnostics-node')?.textContent?.trim(),
    ).toBe('診断 <em>Ω</em> (node-2)')
    expect(byId('hilos-maintenance-other-browser')?.textContent?.trim()).toBe(
      'The operation is run by hilos_backup on node Київ <b>主节点</b> (node-1). Only the browser that started it can open the system.',
    )
    expect(byId('hilos-maintenance-initiator')?.querySelector('b')).toBeNull()
    expect(
      byId('hilos-maintenance-diagnostics-node')?.querySelector('em'),
    ).toBeNull()
    expect(
      byId('hilos-maintenance-other-browser')?.querySelector('b'),
    ).toBeNull()

    pushState(restoreFrame({ isInitiator: false, diagnosticsNodeId: 'node-2' }))
    await settled(fixture)
    expect(byId('hilos-maintenance-initiator')?.textContent?.trim()).toBe(
      'hilos_backup · node node-1',
    )
    expect(
      byId('hilos-maintenance-diagnostics-node')?.textContent?.trim(),
    ).toBe('node-2')
    expect(byId('hilos-maintenance-other-browser')?.textContent?.trim()).toBe(
      'The operation is run by hilos_backup on node node-1. Only the browser that started it can open the system.',
    )
  })

  it('draws published facts in the reader zone and ages the last movement on the shared clock', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] })
    vi.setSystemTime(new Date('2026-09-11T03:24:14.000Z'))
    const timeZone = bindTestTimeZone('UTC')
    timeZoneReleases.push(timeZone.release)
    timeZone.receive('UTC')

    try {
      const { context, pushState } = seededContext()
      const fixture = mountPage(context)
      const state = restoreFrame()
      pushState(state)
      await settled(fixture)

      const card = byId('hilos-maintenance-state-operation') as HTMLElement
      const fact = (dataId: string) =>
        card.querySelector(`[data-id="${dataId}"]`)?.textContent?.trim()
      const moments = () => [
        fact('hilos-maintenance-started-at'),
        fact('hilos-maintenance-activated-at'),
        fact('hilos-maintenance-progress-at'),
      ]
      const expectedMoments = () =>
        [state.startedAt, state.activatedAt, state.progressAt].map((moment) =>
          formatMoment(moment ?? '', { kind: 'dateTime' }),
        )
      expect(fact('hilos-maintenance-initiator')).toBe(
        'hilos_backup · node node-1',
      )
      const inUtc = expectedMoments()
      expect(moments()).toEqual(inUtc)
      expect(fact('hilos-maintenance-progress-elapsed')).toBe('2 minutes ago')
      expect(fact('hilos-maintenance-stopped-agents')).toBe(
        '6 · hilos_mail left running',
      )
      expect(fact('hilos-maintenance-diagnostics-node')).toBe('node-1')
      expect(fact('hilos-maintenance-agent-start')).toBe(
        HILOS_MAINTENANCE_STATE_COPY.agentStartOpen,
      )

      // The gate and the held agents are what the node published, never guessed.
      pushState(restoreFrame({ agentStartGateClosed: true }))
      await settled(fixture)
      expect(fact('hilos-maintenance-agent-start')).toBe(
        HILOS_MAINTENANCE_STATE_COPY.agentStartClosed,
      )
      pushState(restoreFrame({ stoppedAgentCount: 0, preservedAgentTypes: [] }))
      await settled(fixture)
      expect(fact('hilos-maintenance-stopped-agents')).toBe('0')
      expect(fact('hilos-maintenance-agent-start')).toBe(
        HILOS_MAINTENANCE_STATE_COPY.agentStartOpen,
      )
      pushState(
        restoreFrame({
          agentStartGateClosed: null,
          stoppedAgentCount: null,
          diagnosticsNodeId: null,
        }),
      )
      await settled(fixture)
      expect(fact('hilos-maintenance-agent-start')).toBe('—')
      expect(fact('hilos-maintenance-stopped-agents')).toBe('—')
      expect(fact('hilos-maintenance-diagnostics-node')).toBe('—')
      pushState(state)
      await settled(fixture)

      timeZone.receive('America/Los_Angeles')
      await settled(fixture)
      const inLosAngeles = expectedMoments()
      expect(inLosAngeles).not.toEqual(inUtc)
      expect(moments()).toEqual(inLosAngeles)
      expect(fact('hilos-maintenance-progress-elapsed')).toBe('2 minutes ago')
      timeZone.receive('Europe/Kyiv')
      await settled(fixture)
      const inKyiv = expectedMoments()
      expect(inKyiv).not.toEqual(inLosAngeles)
      expect(moments()).toEqual(inKyiv)
      expect(state.activatedAt).toBe('2026-09-11T03:14:09.000Z')
      expect(state.progressAt).toBe('2026-09-11T03:21:44.000Z')

      vi.advanceTimersByTime(29_999)
      await settled(fixture)
      expect(fact('hilos-maintenance-progress-elapsed')).toBe('2 minutes ago')
      vi.advanceTimersByTime(1)
      await settled(fixture)
      expect(fact('hilos-maintenance-progress-elapsed')).toBe('3 minutes ago')
      expect(moments()).toEqual(inKyiv)
      expect(byId('hilos-maintenance-state-operation')).toBe(card)
      fixture.destroy()
    } finally {
      vi.useRealTimers()
      vi.restoreAllMocks()
    }
  })

  it('gives the initiating browser three live buttons and another browser only the line', async () => {
    const { context, pushState } = seededContext()
    const fixture = mountPage(context)
    const ladder = () => byId('hilos-maintenance-restore-ladder')
    const line = () => byId('hilos-maintenance-other-browser')
    const noStep = () =>
      RESTORE_IDS.every(
        (id) => byId(`hilos-maintenance-restore-${id}`) === null,
      )
    expect(ladder()).toBeNull()
    expect(line()).toBeNull()

    // Before the window opens, and in a manual window, no restore ladder is drawn.
    for (const state of [
      restoreFrame({ phase: 'active' }),
      restoreFrame({ phase: 'activating' }),
      manualState({ isInitiator: true, enteredFromBrowser: true }),
    ]) {
      pushState(state)
      await settled(fixture)
      expect(ladder()).toBeNull()
      expect(line()).toBeNull()
      expect(noStep()).toBe(true)
    }

    pushState(restoreFrame())
    await settled(fixture)
    expect(line()).toBeNull()
    const steps = [
      ['pass', HILOS_MAINTENANCE_RESTORE_PASS_COPY.issue],
      ['open', HILOS_MAINTENANCE_RESTORE_OPEN_COPY.issue],
      ['close', HILOS_MAINTENANCE_RESTORE_CLOSE_COPY.issue],
    ] as const
    for (const [id, label] of steps) {
      const button = ladder()?.querySelector<HTMLButtonElement>(
        `[data-id="hilos-maintenance-restore-${id}"]`,
      ) as HTMLButtonElement
      expect(button.tagName).toBe('BUTTON')
      expect(button.textContent?.trim()).toBe(label)
      expect(button.disabled).toBe(false)
      expect(button.getAttribute('aria-disabled')).toBeNull()
    }
    expect(
      byId('hilos-maintenance-state-operation')?.querySelector('button'),
    ).toBeNull()

    // Another admitted browser, and every browser of a restore started from the
    // console, which has no initiating browser at all: the line names who leads.
    for (const state of [
      restoreFrame({ isInitiator: false, enteredFromBrowser: true }),
      restoreFrame({ isInitiator: false, enteredFromBrowser: false }),
    ]) {
      pushState(state)
      await settled(fixture)
      expect(ladder()).toBeNull()
      expect(noStep()).toBe(true)
      expect(line()?.getAttribute('role')).toBe('status')
      expect(line()?.textContent?.trim()).toBe(
        'The operation is run by hilos_backup on node node-1. Only the browser that started it can open the system.',
      )
      expect(line()?.querySelector('button')).toBeNull()
    }

    pushState(
      restoreFrame({
        isInitiator: false,
        initiatorAgentType: null,
        initiatorNodeId: null,
      }),
    )
    await settled(fixture)
    expect(line()?.textContent?.trim()).toBe(
      'The operation is run by — on node —. Only the browser that started it can open the system.',
    )
    expect(byId('hilos-maintenance-initiator')?.textContent?.trim()).toBe(
      '— · node —',
    )
  })
})

const clipboardWritten: string[] = []
const realClipboard = navigator.clipboard

/**
 * Stand a clipboard in for the browser's, or take it away.
 *
 * @param clipboard The clipboard the page sees, or undefined for none.
 */
function setClipboard(clipboard: Clipboard | undefined): void {
  Object.defineProperty(navigator, 'clipboard', {
    value: clipboard,
    configurable: true,
  })
}

describe('HilosMaintenancePage restore code', () => {
  afterEach(() => {
    setClipboard(realClipboard)
    clipboardWritten.length = 0
  })

  it.each(['cancel', 'close', 'escape', 'backdrop'] as const)(
    'explains minting and allows %s before dispatch without sending anything',
    async (method) => {
      const { fixture, dispatched, opener } = await openRestore('pass')
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
      expect(dialog.textContent?.toLowerCase()).not.toContain(
        'turn maintenance off',
      )
      expect(dialog.textContent).not.toContain(
        HILOS_MAINTENANCE_RESTORE_OPEN_COPY.issue,
      )
      expect(
        dialog.querySelector('[data-id="hilos-action-error-idle"]'),
      ).not.toBeNull()
      dialog.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
      )
      await settled(fixture)
      expect(dispatched).toHaveLength(0)
      expect(restoreModal('pass')).not.toBeNull()

      await closeBy(fixture, dialog, restoreButton('pass', 'cancel'), method)

      expect(restoreModal('pass')).toBeNull()
      expect(dispatched).toHaveLength(0)
      expect(document.activeElement).toBe(opener)
    },
  )

  it('blocks repeat, Enter and every close while busy, outlives a state push and reveals the code once', async () => {
    setClipboard({
      writeText: async (text: string) => {
        clipboardWritten.push(text)
      },
    } as unknown as Clipboard)
    const { fixture, dispatched, opener, pushState } = await openRestore('pass')
    const dialog = dialogOf('hilos-maintenance-restore-pass-modal')
    const confirm = () => restoreButton('pass', 'confirm')
    const cancel = () => restoreButton('pass', 'cancel')
    pressEnter(confirm())
    pressEnter(confirm())
    await settled(fixture)

    expect(dispatched).toMatchObject([
      { action: 'maintenance_restore_pass', payload: {} },
    ])
    expect(confirm().getAttribute('aria-disabled')).toBe('true')
    expect(cancel().disabled).toBe(true)
    pressEnter(confirm())
    await tryEveryClose(fixture, dialog, cancel())
    expect(restoreModal('pass')).not.toBeNull()
    expect(dispatched).toHaveLength(1)

    // A live frame is not the answer: the dialog stays busy and keeps waiting.
    pushState(restoreFrame({ passCount: 3 }))
    await settled(fixture)
    expect(dialogOf('hilos-maintenance-restore-pass-modal')).toBe(dialog)
    expect(cancel().disabled).toBe(true)
    expect(byId('hilos-maintenance-restore-pass-result')).toBeNull()

    const secret = '<opaque&code>'
    dispatched[0]?.settle({ message: 'Code minted', reply: { pass: secret } })
    await settled(fixture)
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
    expect(
      byId('hilos-maintenance-state-operation')?.textContent,
    ).not.toContain(secret)
    expect(hilosToasts.toasts.get()).toMatchObject([{ message: 'Code minted' }])
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
    pressEnter(copy)
    await settled(fixture)
    expect(clipboardWritten).toEqual([secret])
    expect(JSON.stringify(hilosToasts.toasts.get())).not.toContain(secret)

    cancel().click()
    await settled(fixture)
    expect(restoreModal('pass')).toBeNull()
    expect(document.body.textContent).not.toContain(secret)
    expect(document.activeElement).toBe(opener)
    opener.click()
    await settled(fixture)
    expect(restoreModal('pass')?.textContent).not.toContain(secret)
    expect(byId('hilos-maintenance-restore-pass-result')).toBeNull()
    expect(dispatched).toHaveLength(1)
  })

  it.each(['fail', 'timeout', 'disconnected', 'invalid-reply'] as const)(
    'keeps %s in the mint dialog, with no control that opens the system, and never retries',
    async (outcome) => {
      const { fixture, dispatched } = await openRestore('pass')
      restoreButton('pass', 'confirm').click()
      await settled(fixture)
      const reason =
        outcome === 'fail'
          ? 'This browser did not start the restore.'
          : 'No reply received.'

      dispatched[0]?.refuse(
        new ActionError('maintenance_restore_pass', outcome, reason),
      )
      await settled(fixture)

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
      expect(
        dialog.querySelector(
          '[data-id="hilos-maintenance-restore-open-confirm"]',
        ),
      ).toBeNull()
      expect(byId('hilos-maintenance-restore-pass-result')).toBeNull()
      expect(hilosToasts.toasts.get()).toMatchObject([
        { message, severity: 'error' },
      ])
      expect(restoreButton('pass', 'cancel').disabled).toBe(false)
      expect(restoreButton('pass', 'confirm').disabled).toBe(false)
      await settled(fixture)
      expect(dispatched).toHaveLength(1)
    },
  )

  it('shows the entire refusal and its admin details through the standard plate', async () => {
    const { fixture, dispatched } = await openRestore('pass')
    restoreButton('pass', 'confirm').click()
    await settled(fixture)
    const reason = `${HILOS_MAINTENANCE_RESTORE_PASS_COPY.unconfirmedNote} The initiator did not answer.`

    dispatched[0]?.refuse(
      new ActionError(
        'maintenance_restore_pass',
        'fail',
        reason,
        'MaintenanceFailure',
        'Original detail',
      ),
    )
    await settled(fixture)
    restoreModal('pass')
      ?.querySelector<HTMLButtonElement>(
        '[data-id="hilos-action-error-details"]',
      )
      ?.click()
    await settled(fixture)

    expect(document.body.textContent).toContain(reason)
    expect(document.body.textContent).toContain('MaintenanceFailure')
    expect(document.body.textContent).toContain('Original detail')
    expect(
      [...document.querySelectorAll('[role="dialog"]')].map((dialog) =>
        dialog.getAttribute('aria-label'),
      ),
    ).toContain(HILOS_MAINTENANCE_RESTORE_PASS_COPY.refusalTitle)
  })

  it('blocks an unsent mint when this browser is no longer the initiator', async () => {
    const { fixture, pushState, dispatched } = await openRestore('pass')
    pushState(restoreFrame({ isInitiator: false }))
    await settled(fixture)

    expect(byId('hilos-maintenance-restore-ladder')).toBeNull()
    expect(byId('hilos-maintenance-other-browser')).not.toBeNull()
    expect(restoreButton('pass', 'confirm').disabled).toBe(true)
    pressEnter(restoreButton('pass', 'confirm'))
    restoreButton('pass', 'confirm').click()
    expect(dispatched).toHaveLength(0)
    restoreButton('pass', 'cancel').click()
    await settled(fixture)
    expect(restoreModal('pass')).toBeNull()
  })

  it('forgets a shown code when the shell refreezes the page', async () => {
    vi.useFakeTimers()
    const shell = await openRestoreInShell()
    try {
      restoreButton('pass', 'confirm').click()
      await shell.render()
      shell.dispatched[0]!.settle({ reply: { pass: 'departed-code' } })
      await shell.render()
      expect(restoreModal('pass')?.textContent).toContain('departed-code')
      expect(document.body.classList.contains('modal-open')).toBe(true)

      shell.feed.push({ ...PROTECTED_MODE_INACTIVE, active: true })
      await shell.render()

      expect(shell.destroyed).toHaveBeenCalledTimes(1)
      expect(byId('maintenance')).not.toBeNull()
      expect(document.body.textContent).not.toContain('departed-code')
      expect(restoreModal('pass')).toBeNull()
      expect(document.body.classList.contains('modal-open')).toBe(false)

      shell.feed.push(PROTECTED_MODE_INACTIVE)
      await shell.render()
      const freshPage = shell.fixture.debugElement.query(
        By.directive(HilosMaintenancePage),
      )
      expect(freshPage.componentInstance).not.toBe(shell.page.componentInstance)
      shell.pushState(restoreFrame())
      await shell.render()
      expect(byId('hilos-maintenance-restore-ladder')).not.toBeNull()
      expect(restoreModal('pass')).toBeNull()
      byId<HTMLButtonElement>('hilos-maintenance-restore-pass')!.click()
      await shell.render()
      expect(restoreModal('pass')).not.toBeNull()
      expect(restoreModal('pass')?.textContent).not.toContain('departed-code')
      expect(byId('hilos-maintenance-restore-pass-result')).toBeNull()
      expect(restoreButton('pass', 'confirm').disabled).toBe(false)
      expect(shell.dispatched).toHaveLength(1)
    } finally {
      shell.fixture.destroy()
      vi.useRealTimers()
    }
  })

  it('cannot resurrect a code from a reply after the shell refroze the page', async () => {
    vi.useFakeTimers()
    const shell = await openRestoreInShell()
    try {
      restoreButton('pass', 'confirm').click()
      await shell.render()
      expect(document.body.classList.contains('modal-open')).toBe(true)
      shell.feed.push({ ...PROTECTED_MODE_INACTIVE, active: true })
      await shell.render()
      expect(shell.destroyed).toHaveBeenCalledTimes(1)
      expect(byId('maintenance')).not.toBeNull()
      expect(restoreModal('pass')).toBeNull()
      expect(document.body.classList.contains('modal-open')).toBe(false)

      shell.dispatched[0]!.settle({ reply: { pass: 'late-code' } })
      await shell.render()
      expect(document.body.textContent).not.toContain('late-code')
      expect(restoreModal('pass')).toBeNull()

      shell.feed.push(PROTECTED_MODE_INACTIVE)
      await shell.render()
      expect(
        shell.fixture.debugElement.query(By.directive(HilosMaintenancePage))
          .componentInstance,
      ).not.toBe(shell.page.componentInstance)
      shell.pushState(restoreFrame())
      await shell.render()
      expect(byId('hilos-maintenance-restore-ladder')).not.toBeNull()
      expect(restoreModal('pass')).toBeNull()
      byId<HTMLButtonElement>('hilos-maintenance-restore-pass')!.click()
      await shell.render()
      expect(restoreModal('pass')).not.toBeNull()
      expect(restoreModal('pass')?.textContent).not.toContain('late-code')
      expect(byId('hilos-maintenance-restore-pass-result')).toBeNull()
      expect(restoreButton('pass', 'confirm').disabled).toBe(false)
      expect(shell.dispatched).toHaveLength(1)
    } finally {
      shell.fixture.destroy()
      vi.useRealTimers()
    }
  })

  it('a late success after timeout cannot replace a newly opened confirmation', async () => {
    const { fixture, dispatched, opener } = await openRestore('pass')
    restoreButton('pass', 'confirm').click()
    await settled(fixture)
    dispatched[0]?.refuse(
      new ActionError('maintenance_restore_pass', 'timeout', 'Timed out'),
    )
    await settled(fixture)
    expect(restoreModal('pass')?.textContent).toContain(
      HILOS_MAINTENANCE_RESTORE_PASS_COPY.unconfirmedNote,
    )
    restoreButton('pass', 'cancel').click()
    await settled(fixture)
    opener.click()
    await settled(fixture)

    dispatched[0]?.settle({ reply: { pass: 'orphan-code' } })
    await settled(fixture)

    expect(document.body.textContent).not.toContain('orphan-code')
    expect(byId('hilos-maintenance-restore-pass-result')).toBeNull()
    expect(restoreButton('pass', 'confirm').disabled).toBe(false)
    expect(dispatched).toHaveLength(1)
  })
})

/** What the open and close steps of the ladder send and say. */
const RESTORE_EXITS = [
  {
    id: 'open',
    action: 'maintenance_restore_open',
    copy: HILOS_MAINTENANCE_RESTORE_OPEN_COPY,
    texts: [
      HILOS_MAINTENANCE_RESTORE_OPEN_COPY.lead,
      HILOS_MAINTENANCE_RESTORE_OPEN_COPY.noWayBack,
    ],
  },
  {
    id: 'close',
    action: 'maintenance_restore_close',
    copy: HILOS_MAINTENANCE_RESTORE_CLOSE_COPY,
    texts: [
      HILOS_MAINTENANCE_RESTORE_CLOSE_COPY.lead,
      HILOS_MAINTENANCE_RESTORE_CLOSE_COPY.footer,
    ],
  },
] as const

describe.each(RESTORE_EXITS)(
  'HilosMaintenancePage restore $id confirmation',
  (exit) => {
    const confirm = () => restoreButton(exit.id, 'confirm')
    const cancel = () => restoreButton(exit.id, 'cancel')

    it.each(['cancel', 'close', 'escape', 'backdrop'] as const)(
      'explains itself, ignores Enter on the dialog and allows %s without sending anything',
      async (method) => {
        const { fixture, dispatched, opener } = await openRestore(exit.id)
        const dialog = dialogOf(`hilos-maintenance-restore-${exit.id}-modal`)
        expect(dialog.getAttribute('aria-label')).toBe(exit.copy.title)
        expect(dialog.getAttribute('aria-modal')).toBe('true')
        expect(document.activeElement).toBe(dialog)
        expect(dialog.querySelector('input, textarea')).toBeNull()
        for (const text of exit.texts) {
          expect(dialog.textContent).toContain(text)
        }
        expect(
          dialog.querySelector('[data-id="hilos-action-error-idle"]'),
        ).not.toBeNull()
        dialog.dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
        )
        await settled(fixture)
        expect(dispatched).toHaveLength(0)
        expect(restoreModal(exit.id)).not.toBeNull()

        await closeBy(fixture, dialog, cancel(), method)

        expect(restoreModal(exit.id)).toBeNull()
        expect(dispatched).toHaveLength(0)
        expect(document.activeElement).toBe(opener)
      },
    )

    it('blocks repeat, Enter and every close while busy, outlives a state push and closes on its one success', async () => {
      const { fixture, dispatched, pushState } = await openRestore(exit.id)
      const dialog = dialogOf(`hilos-maintenance-restore-${exit.id}-modal`)
      pressEnter(confirm())
      pressEnter(confirm())
      await settled(fixture)

      expect(dispatched).toMatchObject([{ action: exit.action, payload: {} }])
      expect(confirm().getAttribute('aria-disabled')).toBe('true')
      expect(cancel().disabled).toBe(true)
      pressEnter(confirm())
      await tryEveryClose(fixture, dialog, cancel())
      expect(restoreModal(exit.id)).not.toBeNull()
      dispatched[0]?.loading.set(true)
      await settled(fixture)
      expect(confirm().getAttribute('aria-busy')).toBe('true')

      // The ladder leaves with the live frame; the dialog waits for its own answer.
      pushState(restoreFrame({ phase: 'deactivating' }))
      await settled(fixture)
      expect(byId('hilos-maintenance-restore-ladder')).toBeNull()
      expect(dialogOf(`hilos-maintenance-restore-${exit.id}-modal`)).toBe(
        dialog,
      )
      expect(cancel().disabled).toBe(true)
      expect(hilosToasts.toasts.get()).toHaveLength(0)

      dispatched[0]?.settle({ message: 'Done' })
      await settled(fixture)

      expect(restoreModal(exit.id)).toBeNull()
      expect(hilosToasts.toasts.get()).toMatchObject([
        { message: 'Done', severity: 'success', repeats: 1 },
      ])
      expect(dispatched).toHaveLength(1)
    })

    it.each(['fail', 'timeout', 'disconnected', 'invalid-reply'] as const)(
      'keeps %s readable in the dialog, releases Cancel and never retries',
      async (outcome) => {
        const { fixture, dispatched } = await openRestore(exit.id)
        confirm().click()
        await settled(fixture)
        const reason =
          outcome === 'fail'
            ? 'This browser did not start the restore.'
            : 'No reply received.'

        dispatched[0]?.refuse(new ActionError(exit.action, outcome, reason))
        await settled(fixture)

        const message = outcome === 'fail' ? reason : exit.copy.unconfirmedNote
        const modal = restoreModal(exit.id)
        expect(
          modal?.querySelector('[data-id="hilos-action-error"]')?.textContent,
        ).toContain(message)
        expect(
          modal
            ?.querySelector('[data-id="hilos-action-error-slot"]')
            ?.getAttribute('aria-live'),
        ).toBe('assertive')
        expect(hilosToasts.toasts.get()).toMatchObject([
          { message, severity: 'error' },
        ])
        expect(cancel().disabled).toBe(false)
        expect(confirm().disabled).toBe(false)
        await settled(fixture)
        expect(dispatched).toHaveLength(1)
        cancel().click()
        await settled(fixture)
        expect(restoreModal(exit.id)).toBeNull()
      },
    )

    it.each([
      ['the window is gone', restoreFrame({ phase: 'deactivating' })],
      ['this browser no longer leads', restoreFrame({ isInitiator: false })],
    ] as const)(
      'blocks an unsent confirmation when %s',
      async (_reason, state) => {
        const { fixture, pushState, dispatched } = await openRestore(exit.id)
        pushState(state)
        await settled(fixture)

        expect(confirm().disabled).toBe(true)
        pressEnter(confirm())
        confirm().click()
        expect(dispatched).toHaveLength(0)
        expect(cancel().disabled).toBe(false)
        cancel().click()
        await settled(fixture)
        expect(restoreModal(exit.id)).toBeNull()
      },
    )

    it('brings neither the dialog nor its refusal back when the answer comes after the page went', async () => {
      const { fixture, dispatched } = await openRestore(exit.id)
      confirm().click()
      await settled(fixture)

      leavePage()
      dispatched[0]?.refuse(
        new ActionError(exit.action, 'disconnected', 'Connection lost.'),
      )
      await new Promise((resolve) => setTimeout(resolve))

      // What the page owes is its own surface: no dialog comes alive and no
      // late refusal is drawn. The toast the shared action driver files is the
      // driver's, and this page does not promise it away.
      expect(restoreModal(exit.id)).toBeNull()
      expect(document.querySelector('[role="dialog"]')).toBeNull()
      expect(byId('hilos-action-error')).toBeNull()
      expect(document.body.textContent).not.toContain(exit.copy.unconfirmedNote)
      expect(dispatched).toHaveLength(1)
    })
  },
)

describe('HilosMaintenancePage watchdog', () => {
  it('reserves the alarm slot before a frame without inventing threshold values', () => {
    const { context, tableTraffic } = seededContext()
    mountPage(context)
    const slot = byId('hilos-maintenance-watchdog-slot') as HTMLElement
    expect(slot.getAttribute('role')).toBe('status')
    expect(slot.getAttribute('aria-live')).toBe('polite')
    expect(slot.querySelectorAll('button')).toHaveLength(0)
    expect(slot.querySelector('[aria-hidden="true"][inert]')).not.toBeNull()
    expect(byId('hilos-maintenance-watchdog-alert')).toBeNull()
    expect(byId('hilos-maintenance-watchdog-thresholds')).toBeNull()
    expect(document.body.textContent).not.toContain('No recipients configured')
    expect(tableTraffic).toEqual([])
  })

  it('keeps unknown, empty and real configuration distinct and redraws a live restart policy', async () => {
    const { context, pushState } = seededContext()
    const fixture = mountPage(context)
    pushState(maintenanceState())
    await settled(fixture)
    const card = byId('hilos-maintenance-watchdog-thresholds') as HTMLElement
    expect(card.querySelector('h2')?.textContent).toBe('Watchdog thresholds')
    expect(
      [...card.querySelectorAll('dt')].map((row) => row.textContent),
    ).toEqual([
      'Wait for nodes to quiesce',
      'Consider silent after',
      'Repeat alert',
      'Send mail to',
      'Restart under manual maintenance',
    ])
    expect(thresholdValues()).toEqual(['—', '—', '—', '—', '—'])
    expect(
      card.querySelectorAll('input, select, textarea, button, a'),
    ).toHaveLength(0)

    pushState(
      maintenanceState({
        watchdogAlertEmails: [],
        watchdogManualRestartIsNormal: false,
      }),
    )
    await settled(fixture)
    expect(byId('hilos-maintenance-watchdog-thresholds')).toBe(card)
    expect(thresholdValues()).toEqual([
      '—',
      '—',
      '—',
      'No recipients configured',
      'Send an alert',
    ])

    pushState(
      maintenanceState({
        watchdogQuiesceTimeoutSeconds: 60,
        watchdogSilenceTimeoutSeconds: 900,
        watchdogAlertIntervalSeconds: 1800,
        watchdogAlertEmails: ['ops@example.com'],
        watchdogManualRestartIsNormal: true,
      }),
    )
    await settled(fixture)
    expect(thresholdValues()).toEqual([
      '60 seconds',
      '900 seconds',
      '1800 seconds',
      'ops@example.com',
      'No mail — a log entry',
    ])
    expect(card.textContent).not.toContain('No recipients configured')
  })

  it.each([
    [
      'open',
      HILOS_MAINTENANCE_STATE_COPY.openTitle,
      'hilos-maintenance-enable',
      HILOS_MAINTENANCE_ENABLE_COPY.enableTitle,
    ],
    [
      'manual',
      HILOS_MAINTENANCE_STATE_COPY.manualTitle,
      'hilos-maintenance-disable',
      HILOS_MAINTENANCE_DISABLE_COPY.disableTitle,
    ],
    [
      'operation',
      HILOS_MAINTENANCE_STATE_COPY.operationTitle,
      'hilos-maintenance-restore-close',
      HILOS_MAINTENANCE_RESTORE_CLOSE_COPY.issue,
    ],
  ] as const)(
    'shows the alarm slot, the state, its way out and the real thresholds read-only when %s',
    async (position, stateTitle, actionId, actionName) => {
      const { context, pushState } = seededContext()
      const fixture = mountPage(context)
      const state =
        position === 'open'
          ? maintenanceState()
          : position === 'manual'
            ? manualState()
            : restoreFrame()
      pushState({
        ...state,
        watchdogQuiesceTimeoutSeconds: 61,
        watchdogSilenceTimeoutSeconds: 901,
        watchdogAlertIntervalSeconds: 1801,
        watchdogAlertEmails: ['ops@example.com', 'alerts@example.com'],
        watchdogManualRestartIsNormal: true,
      })
      await settled(fixture)

      const slot = byId('hilos-maintenance-watchdog-slot') as HTMLElement
      expect(slot.getAttribute('role')).toBe('status')
      expect(slot.querySelectorAll('button')).toHaveLength(0)
      const headings = [...document.querySelectorAll('h2')].map((heading) =>
        heading.textContent?.trim(),
      )
      expect(headings).toContain(stateTitle)
      const action = byId<HTMLButtonElement>(actionId) as HTMLButtonElement
      expect(action.tagName).toBe('BUTTON')
      expect(action.textContent?.trim()).toBe(actionName)
      expect(action.disabled).toBe(false)

      const card = byId('hilos-maintenance-watchdog-thresholds') as HTMLElement
      expect(card.querySelector('h2')?.textContent?.trim()).toBe(
        'Watchdog thresholds',
      )
      expect(thresholdFacts()).toEqual({
        'Wait for nodes to quiesce': '61 seconds',
        'Consider silent after': '901 seconds',
        'Repeat alert': '1801 seconds',
        'Send mail to': 'ops@example.com, alerts@example.com',
        'Restart under manual maintenance': 'No mail — a log entry',
      })
      expect(
        card.querySelectorAll(
          'input, select, textarea, button, a, [role="button"], [role="link"]',
        ),
      ).toHaveLength(0)
    },
  )

  it('uses only the server verdict and keeps manual maintenance clear across elapsed time', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] })
    vi.setSystemTime(new Date('2026-10-28T12:00:00.000Z'))

    try {
      const { context, pushState } = seededContext()
      const fixture = mountPage(context)
      pushState(
        manualState({
          startedAt: '2026-01-01T00:00:00.000Z',
          watchdogSilenceTimeoutSeconds: 1,
          watchdogManualRestartIsNormal: true,
        }),
      )
      await settled(fixture)
      vi.advanceTimersByTime(60_000)
      await settled(fixture)
      expect(byId('hilos-maintenance-watchdog-alert')).toBeNull()
      expect(byId('hilos-maintenance-watchdog-idle')).not.toBeNull()

      pushState(
        manualState({
          watchdogVerdict: 'restored-from-disk',
          watchdogManualRestartIsNormal: false,
        }),
      )
      await settled(fixture)
      expect(byId('hilos-maintenance-watchdog-alert')?.textContent).toContain(
        HILOS_MAINTENANCE_WATCHDOG_COPY.manualRestartSummary,
      )
      fixture.destroy()
    } finally {
      vi.useRealTimers()
    }
  })

  it('updates diagnostic moments through the reader-zone bridge without another state frame or backend action', async () => {
    const timeZone = bindTestTimeZone('UTC')
    timeZoneReleases.push(timeZone.release)
    timeZone.receive('UTC')
    const { actions, dispatched } = makeActions()
    const { context, pushState, tableTraffic } = seededContext(actions)
    const fixture = mountPage(context)
    const frame = restoreFrame({
      watchdogVerdict: 'silent',
      watchdogPendingNodes: [
        { nodeId: 'node-3', nodeName: null },
        { nodeId: 'node-4', nodeName: null },
      ],
    })
    pushState(frame)
    await settled(fixture)
    const opener = byId<HTMLButtonElement>(
      'hilos-maintenance-watchdog-details',
    ) as HTMLButtonElement
    opener.focus()
    opener.click()
    await settled(fixture)
    const full = byId('hilos-maintenance-watchdog-full') as HTMLElement
    const moments = () =>
      [...full.querySelectorAll('dd')].slice(4, 7).map((row) => row.textContent)
    const published = () =>
      [frame.startedAt, frame.activatedAt, frame.progressAt].map((moment) =>
        formatMoment(moment!),
      )
    const inUtc = published()
    expect(inUtc).not.toContain(null)
    expect(moments()).toEqual(inUtc)
    expect(full.textContent).toContain('node-3, node-4')

    timeZone.receive('America/Los_Angeles')
    await fixture.whenStable()
    fixture.detectChanges()
    const inLosAngeles = published()
    expect(inLosAngeles).not.toEqual(inUtc)
    expect(moments()).toEqual(inLosAngeles)

    timeZone.receive('Europe/Kyiv')
    await fixture.whenStable()
    fixture.detectChanges()
    const inKyiv = published()
    expect(inKyiv).not.toEqual(inUtc)
    expect(inKyiv).not.toEqual(inLosAngeles)
    expect(moments()).toEqual(inKyiv)
    expect(byId('hilos-maintenance-watchdog-full')).toBe(full)

    pushState(
      restoreFrame({
        watchdogVerdict: 'initiator-lost',
        watchdogPendingNodes: [],
      }),
    )
    await settled(fixture)
    expect(full.textContent).toContain('The initiator is gone')
    expect(full.textContent).not.toContain('No movement behind the freeze')
    expect(full.textContent).not.toContain('node-3')

    pushState(maintenanceState())
    await settled(fixture)
    expect(byId('hilos-maintenance-watchdog-full')).toBeNull()
    expect(document.body.textContent).not.toContain('The initiator is gone')
    expect(dispatched).toEqual([])
    expect(tableTraffic).toEqual([])
  })
})
