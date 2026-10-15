import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionError,
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
import type {
  ActionHandle,
  ActionLifecycle,
  ActionResult,
  HilosConnection,
  HilosMaintenanceContext,
  HilosMaintenanceState,
  HilosRouter,
  PageRouteMatch,
  ProjectSignal,
  WritableSignal,
} from '@hilos/core'

import { HilosMaintenancePage } from '../src/admin/maintenance/HilosMaintenancePage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

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
  /** The address, or the hidden mark a viewer of the admin view mode is sent. */
  identifier: string | { readonly _hidden: true }
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

// A row stands in the document twice — once in the table and once in the card the
// same row becomes on a narrow screen — so a mark is looked up through the table.
function mark(identifier: string): HTMLElement | null {
  return document.querySelector(
    `table [data-id="hilos-maintenance-circle-online-${identifier}"]`,
  )
}

afterEach(() => {
  hilosToasts.clear()
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

/** Open the add dialog and enter the address without normalizing it. */
function openAndType(
  fixture: ComponentFixture<HilosMaintenancePage>,
  typed: string,
): void {
  document
    .querySelector<HTMLButtonElement>('[data-id="hilos-table-main-action"]')
    ?.click()
  fixture.detectChanges()
  const field = addField() as HTMLInputElement
  field.value = typed
  field.dispatchEvent(new Event('input', { bubbles: true }))
  fixture.detectChanges()
}

describe('HilosMaintenancePage', () => {
  it('offers enable only after the open frame arrives', async () => {
    const { context, pushState } = seededContext([])
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
      const { context, pushState } = seededContext([])
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
    const { context, pushState } = seededContext([])
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
      const { context, pushState } = seededContext([])
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

  it('renders only the planned operation lines in the frozen card', async () => {
    const { context, pushState } = seededContext([])
    const fixture = mountPage(context)

    pushState(
      maintenanceState({
        phase: 'verifying',
        operation: 'restore',
        startedAt: '2026-10-10T10:00:00+00:00',
        passCount: 2,
      }),
    )
    await settled(fixture)

    const card = byId('hilos-maintenance-state-operation') as HTMLElement
    expect(card.textContent).toContain(
      HILOS_MAINTENANCE_STATE_COPY.operationTitle,
    )
    expect(card.textContent).toContain(
      HILOS_MAINTENANCE_STATE_COPY.verifyingPhaseNote,
    )
    expect(card.textContent).toContain('restore')
    expect(card.textContent).toContain('2026-10-10T10:00:00+00:00')
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
    const fixture = mountPage(context)

    expect(
      (fixture.nativeElement as HTMLElement).querySelectorAll(
        '[data-id^="hilos-table-row-"]',
      ).length,
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
    const fixture = mountPage(context)

    pushUpdate({ ...member, online: false })
    fixture.detectChanges()

    expect(mark('ann@example.test')?.textContent).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.offline,
    )
  })

  it('says what an empty circle means instead of drawing rows', async () => {
    const { context } = seededContext([])
    const fixture = mountPage(context)

    expect(
      (fixture.nativeElement as HTMLElement).querySelectorAll(
        '[data-id^="hilos-table-row-"]',
      ).length,
    ).toBe(0)
    expect((fixture.nativeElement as HTMLElement).textContent).toContain(
      HILOS_MAINTENANCE_CIRCLE_COPY.empty.title,
    )
    expect((fixture.nativeElement as HTMLElement).textContent).toContain(
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
      action.click()
      fixture.detectChanges()
      expect(addField()).not.toBeNull()
      document
        .querySelector<HTMLButtonElement>(
          '[data-id="hilos-maintenance-circle-add-cancel"]',
        )
        ?.click()
      fixture.detectChanges()
      expect(addField()).toBeNull()
    }
  })

  it('offers adding a member and removing one', async () => {
    const { context } = seededContext([ANN])
    const fixture = mountPage(context)
    const panel = (fixture.nativeElement as HTMLElement).querySelector(
      '[data-id="hilos-maintenance-circle-panel"]',
    )

    expect(
      panel
        ?.querySelector('[data-id="hilos-table-main-action"]')
        ?.textContent?.trim(),
    ).toBe(HILOS_MAINTENANCE_CIRCLE_COPY.addButton)
    expect(trash('ann@example.test').getAttribute('aria-label')).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.removeTitle,
    )
    expect(trash('ann@example.test').querySelector('.bi-trash')).not.toBeNull()
    expect((fixture.nativeElement as HTMLElement).textContent).toContain(
      HILOS_MAINTENANCE_CIRCLE_COPY.title,
    )
  })

  it('opens the remove dialog over the row, naming its address and taking it into focus', async () => {
    const { context, focus } = seededContext([ANN])
    const fixture = mountPage(context)

    trash('ann@example.test').click()
    fixture.detectChanges()

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
    const fixture = mountPage(context)

    trash('ann@example.test').click()
    fixture.detectChanges()
    removeConfirm()?.click()
    await settled(fixture)

    expect(dispatched).toMatchObject([
      { action: 'maintenance_circle_remove', payload: { memberId: 1 } },
    ])
    expect(removeConfirm()).not.toBeNull()

    dispatched[0]?.settle({
      action: 'maintenance_circle_remove',
    } as ActionResult)
    await settled(fixture)

    expect(removeConfirm()).toBeNull()
    expect(focus).toEqual(['1', ''])
  })

  it('keeps the remove dialog open with the refusal on it', async () => {
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([ANN], actions)
    const fixture = mountPage(context)

    trash('ann@example.test').click()
    fixture.detectChanges()
    removeConfirm()?.click()
    await settled(fixture)
    dispatched[0]?.refuse(
      new ActionError(
        'maintenance_circle_remove',
        'fail',
        'This verifier is no longer in the circle',
      ),
    )
    await settled(fixture)

    expect(removeConfirm()).not.toBeNull()
    expect(
      document.querySelector('[data-id="hilos-action-error"]')?.textContent,
    ).toContain('This verifier is no longer in the circle')
  })

  it('says the row was taken out elsewhere and locks Remove', async () => {
    const { context, pushRemove } = seededContext([ANN])
    const fixture = mountPage(context)

    trash('ann@example.test').click()
    fixture.detectChanges()
    pushRemove(1)
    fixture.detectChanges()

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
    const fixture = mountPage(context)

    trash('ann@example.test').click()
    fixture.detectChanges()
    removeConfirm()?.click()
    await settled(fixture)
    pushRemove(1, true)
    fixture.detectChanges()

    // No message: the notice's room is held by its invisible twin only.
    expect(
      document.querySelector(
        '[data-id="hilos-maintenance-circle-remove-notice"]',
      ),
    ).toBeNull()
    expect(removeConfirm()?.textContent?.trim()).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.removeConfirm,
    )

    dispatched[0]?.settle({
      action: 'maintenance_circle_remove',
    } as ActionResult)
    await settled(fixture)

    expect(removeConfirm()).toBeNull()
  })

  it('opens the add dialog with an empty field and holds Add until something is typed', async () => {
    const { context } = seededContext([])
    const fixture = mountPage(context)

    openAndType(fixture, '   ')

    expect(document.body.textContent).toContain(
      HILOS_MAINTENANCE_CIRCLE_COPY.addLead,
    )
    expect(addField()?.placeholder).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.addPlaceholder,
    )
    expect(addConfirm().disabled).toBe(true)

    const field = addField() as HTMLInputElement
    field.value = 'ann@example.test'
    field.dispatchEvent(new Event('input'))
    fixture.detectChanges()

    expect(addConfirm().disabled).toBe(false)
  })

  it("names the address as typed and closes only on the server's word", async () => {
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([], actions)
    const fixture = mountPage(context)

    openAndType(fixture, '+7 900 000-00-00')
    addConfirm().click()
    await settled(fixture)

    expect(dispatched).toMatchObject([
      {
        action: 'maintenance_circle_add',
        payload: { identifier: '+7 900 000-00-00' },
      },
    ])
    expect(addField()).not.toBeNull()

    dispatched[0]?.settle({ action: 'maintenance_circle_add' } as ActionResult)
    await settled(fixture)

    expect(addField()).toBeNull()
  })

  it('keeps the dialog open with the typed address and the refusal on it', async () => {
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([], actions)
    const fixture = mountPage(context)

    openAndType(fixture, 'nobody@example.test')
    addConfirm().click()
    await settled(fixture)
    dispatched[0]?.refuse(
      new ActionError(
        'maintenance_circle_add',
        'fail',
        'Nobody has proven this address',
      ),
    )
    await settled(fixture)

    expect(addField()?.value).toBe('nobody@example.test')
    expect(
      document.querySelector('[data-id="hilos-action-error"]')?.textContent,
    ).toContain('Nobody has proven this address')
    expect(addConfirm().disabled).toBe(false)
  })

  it('opens the dialog again with an empty field and no refusal left over', async () => {
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([], actions)
    const fixture = mountPage(context)

    openAndType(fixture, 'nobody@example.test')
    addConfirm().click()
    await settled(fixture)
    dispatched[0]?.refuse(
      new ActionError(
        'maintenance_circle_add',
        'fail',
        'Nobody has proven this address',
      ),
    )
    await settled(fixture)
    document
      .querySelector<HTMLButtonElement>(
        '[data-id="modal"] .modal-footer .btn-secondary',
      )
      ?.click()
    await settled(fixture)
    expect(addField()).toBeNull()
    document
      .querySelector<HTMLButtonElement>('[data-id="hilos-table-main-action"]')
      ?.click()
    fixture.detectChanges()

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
      expect(confirm().disabled).toBe(true)
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

describe('HilosMaintenancePage with the addresses hidden (HIL-1260)', () => {
  const HIDDEN = { _hidden: true } as const

  it('draws the mark for each address and keys the rows by the membership', async () => {
    const { context } = seededContext([
      {
        memberId: 1,
        identityType: 'password',
        identifier: HIDDEN,
        online: true,
      },
      { memberId: 2, identityType: 'sms', identifier: HIDDEN, online: false },
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

  it('names the hidden address in the remove dialog by the mark', async () => {
    const { context, focus } = seededContext([
      { memberId: 2, identityType: 'sms', identifier: HIDDEN, online: false },
    ])
    const fixture = mountPage(context)

    trash('member-2').click()
    fixture.detectChanges()

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
      const { context, pushState } = seededContext([], actions)
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

  it('a viewer opens the add dialog, may type an address and has nothing to send it with', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([], actions)
    const fixture = mountPage(context)

    const addButton = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-table-main-action"]',
    )
    expect(addButton?.disabled).toBe(false)

    openAndType(fixture, 'someone@example.test')
    expect(addField()?.disabled).toBe(false)
    expect(addField()?.value).toBe('someone@example.test')
    expect(addConfirm().disabled).toBe(true)
    expect(addConfirm().getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    addConfirm().click()
    await settled(fixture)
    expect(dispatched).toHaveLength(0)

    const cancelButton = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-maintenance-circle-add-cancel"]',
    )
    expect(cancelButton).not.toBeNull()
    expect(cancelButton?.disabled).toBe(false)
    cancelButton?.click()
    await settled(fixture)
    expect(addField()).toBeNull()
  })

  it('a viewer opens the remove dialog over a row and has nothing to send from it', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { actions, dispatched } = makeActions()
    const { context, focus } = seededContext([ANN], actions)
    const fixture = mountPage(context)

    const trashButton = trash('ann@example.test')
    expect(trashButton.disabled).toBe(false)
    trashButton.click()
    await settled(fixture)

    expect(focus).toEqual(['1'])
    expect(removeConfirm()?.disabled).toBe(true)
    expect(removeConfirm()?.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    removeConfirm()?.click()
    await settled(fixture)
    expect(dispatched).toHaveLength(0)

    const cancelButton = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-maintenance-circle-remove-cancel"]',
    )
    expect(cancelButton).not.toBeNull()
    expect(cancelButton?.disabled).toBe(false)
    cancelButton?.click()
    await settled(fixture)

    expect(removeConfirm()).toBeNull()
    expect(focus).toEqual(['1', ''])
  })

  it('an admin on a node in the mode names a verifier as today', async () => {
    const { handshake } = bindSession()
    handshake({ id: 1, admin: true }, true)
    const { actions } = makeActions()
    const { context } = seededContext([], actions)
    const fixture = mountPage(context)

    openAndType(fixture, 'someone@example.test')
    expect(addConfirm().disabled).toBe(false)
    expect(addConfirm().getAttribute('aria-describedby')).toBeNull()
  })
})

describe('HilosMaintenancePage code result', () => {
  /**
   * Leave the page as a route change does: the view is destroyed and its
   * element leaves the document — a destroyed root view alone stays drawn.
   */
  function leavePage(): void {
    TestBed.resetTestingModule()
  }

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
