// The real Angular Circle tab of Maintenance over the core verifier circle: its
// table bound to Circle, the add and remove dialogs, the hidden addresses, the
// admin view mode, what a new context and leaving the page release, and the
// shared section head.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
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
  HilosPageIdentity,
  HilosRouter,
  PageRouteMatch,
  ProjectSignal,
  TableViewportController,
  WritableSignal,
} from '@hilos/core'
import {
  HILOS_MAINTENANCE_CIRCLE_COPY,
  HILOS_MAINTENANCE_DISABLE_COPY,
  HILOS_MAINTENANCE_STATE_COPY,
  MAINTENANCE_STATE_SIGNAL,
  type HilosMaintenanceContext,
  type HilosMaintenanceState,
} from '@hilos/core/admin/maintenance'
import { HilosMaintenanceCirclePage as exportedPage } from '@hilos/angular/admin/maintenance'

import { HilosMaintenanceCirclePage } from '../src/admin/maintenance/HilosMaintenanceCirclePage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

const CIRCLE_TABLE = 'hilosVerifierCircle'
const MAINTENANCE_LEAD =
  'Node freeze under a destructive operation and manual maintenance: its state, the verifier circle, closing the system to visitors, and the way out of the window.'

/** The router of a tab standing on Circle, with the identity its answer carries. */
function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.MAINTENANCE_CIRCLE,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/maintenance/circle'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal<HilosPageIdentity | undefined>({
      label: 'Circle',
      lead: 'Who will check the system after an operation.',
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

/**
 * A context whose connection replays Circle's own page answer, carrying the
 * circle's first window, to every listener of project signals — the window is
 * never asked for on mount.
 *
 * @param initial The members of the first window.
 * @param actions The action lifecycle the dialogs dispatch over.
 */
function seededContext(
  initial: CircleMember[],
  actions: ActionLifecycle = makeActions().actions,
): {
  context: HilosMaintenanceContext
  focus: string[]
  registered: Map<string, TableViewportController<unknown>>
  pushUpdate: (next: CircleMember) => void
  pushRemove: (memberId: number, own?: boolean) => void
  pushState: (state: HilosMaintenanceState) => void
} {
  const rows = initial.slice()
  const focus: string[] = []
  const registered = new Map<string, TableViewportController<unknown>>()
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.MAINTENANCE_CIRCLE)
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const deltaListeners = new Set<(signal: { data: unknown }) => void>()
  const projectListeners = new Set<
    (signal: { type: string; data: unknown }) => void
  >()
  const firstWindow = () => ({
    filter: {},
    rows: rows.map(wireRow),
    totalCount: rows.length,
    totalExact: true,
    firstAnchor: null,
    lastAnchor: null,
    offset: 0,
    limit: 10,
  })
  const serveWindow = (page: string, tableKey: string): void => {
    for (const listener of windowListeners) {
      listener({ data: { page, tableKey, ...firstWindow() } })
    }
  }
  const pageAnswer = () => ({
    type: 'page_response',
    data: {
      page: HilosPages.MAINTENANCE_CIRCLE,
      payload: { windows: { [CIRCLE_TABLE]: firstWindow() } },
    },
  })

  const connection = {
    registerTableWindow(
      tableKey: string,
      controller: TableViewportController<unknown>,
    ): void {
      registered.set(tableKey, controller)
    },
    unregisterTableWindow(tableKey: string): void {
      registered.delete(tableKey)
    },
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
        projectListeners.add(listener)
        listener(pageAnswer())

        return () => projectListeners.delete(listener)
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
    registered,
    // The section's state frame, as the connection hands it to every listener of
    // project signals — every tab of the section hears it.
    pushState(state: HilosMaintenanceState): void {
      for (const listener of projectListeners) {
        listener({ type: MAINTENANCE_STATE_SIGNAL, data: state })
      }
    },
    pushUpdate(next: CircleMember): void {
      for (const listener of deltaListeners) {
        listener({
          data: {
            page: HilosPages.MAINTENANCE_CIRCLE,
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
            page: HilosPages.MAINTENANCE_CIRCLE,
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
): ComponentFixture<HilosMaintenanceCirclePage> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })
  const fixture = TestBed.createComponent(HilosMaintenanceCirclePage)
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()

  return fixture
}

/**
 * Leave the page as a route change does: the view is destroyed and its element
 * leaves the document — a destroyed root view alone stays drawn.
 */
function leavePage(): void {
  TestBed.resetTestingModule()
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
  fixture: ComponentFixture<HilosMaintenanceCirclePage>,
): Promise<void> {
  // The test's action promise is outside Angular's pending-task accounting: let
  // the driver and the submit continuation after it finish before the render.
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

/** The member a new context's table carries in place of the first one's. */
const BOB: CircleMember = {
  memberId: 2,
  identityType: 'password',
  identifier: 'bob@example.test',
  online: false,
}

/** A whole state frame of the manual window: closed by hand, one verifier named. */
const MANUAL_STATE: HilosMaintenanceState = {
  phase: 'verifying',
  operation: 'manual_maintenance',
  manual: true,
  startedAt: '2026-09-11T03:14:02.000Z',
  enteredFromBrowser: true,
  passCount: 1,
  circleNamed: 1,
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
  watchdogQuiesceTimeoutSeconds: 60,
  watchdogSilenceTimeoutSeconds: 900,
  watchdogAlertIntervalSeconds: 1800,
  watchdogAlertEmails: ['ops@example.com'],
  watchdogManualRestartIsNormal: true,
}

/** Open the add dialog and enter the address without normalizing it. */
function openAndType(
  fixture: ComponentFixture<HilosMaintenanceCirclePage>,
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

describe('HilosMaintenanceCirclePage', () => {
  it('is exported by the maintenance entry and draws the first window of its own answer without asking for one', () => {
    expect(exportedPage).toBe(HilosMaintenanceCirclePage)
    const { context, registered, pushState } = seededContext([ANN])
    const viewport = vi.spyOn(context.connection, 'sendTableViewport')
    const rendered = vi.spyOn(context.connection, 'sendTableRendered')
    const fixture = mountPage(context)

    expect([...registered.keys()]).toEqual([CIRCLE_TABLE])
    expect(viewport).not.toHaveBeenCalled()
    expect(mark('ann@example.test')?.textContent).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.online,
    )
    expect(rendered).toHaveBeenCalled()
    for (const [page] of rendered.mock.calls as unknown as [string][]) {
      expect(page).toBe(HilosPages.MAINTENANCE_CIRCLE)
    }

    // The section's state is the Overview's to draw: Circle hears the frame and
    // shows neither the window it describes nor the way out of it.
    pushState(MANUAL_STATE)
    fixture.detectChanges()
    const text = (fixture.nativeElement as HTMLElement).textContent
    expect(text).not.toContain(HILOS_MAINTENANCE_STATE_COPY.manualTitle)
    expect(text).not.toContain(HILOS_MAINTENANCE_DISABLE_COPY.disableTitle)
    expect(mark('ann@example.test')?.textContent).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.online,
    )
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
    const table = (fixture.nativeElement as HTMLElement).querySelector(
      '[data-id="hilos-maintenance-circle-table"]',
    )

    expect(
      table
        ?.querySelector('[data-id="hilos-table-main-action"]')
        ?.textContent?.trim(),
    ).toBe(HILOS_MAINTENANCE_CIRCLE_COPY.addButton)
    // The row's one way to remove is a button named for what it does; its icon
    // is for the eye only and leaves the name to the label.
    const removers = [
      ...document.querySelectorAll<HTMLButtonElement>('table tbody button'),
    ].filter(
      (button) =>
        button.getAttribute('aria-label') ===
        HILOS_MAINTENANCE_CIRCLE_COPY.removeTitle,
    )
    expect(removers).toEqual([trash('ann@example.test')])
    expect(removers[0]?.children.length).toBeGreaterThan(0)
    for (const icon of removers[0]?.children ?? []) {
      expect(icon.getAttribute('aria-hidden')).toBe('true')
    }
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
        '[data-id="hilos-maintenance-circle-add-cancel"]',
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

describe('HilosMaintenanceCirclePage with the addresses hidden (HIL-1260)', () => {
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

describe('HilosMaintenanceCirclePage and the admin view mode', () => {
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

  it.each(['add', 'remove'] as const)(
    'blocks %s after a live admin revoke while its dialog is open',
    async (dialog) => {
      const { handshake } = bindSession()
      handshake({ id: 1, admin: true }, true)
      const { actions, dispatched } = makeActions()
      const { context } = seededContext([ANN], actions)
      const fixture = mountPage(context)
      if (dialog === 'add') openAndType(fixture, 'someone@example.test')
      else {
        trash('ann@example.test').click()
        await settled(fixture)
      }
      const confirm = dialog === 'add' ? addConfirm() : removeConfirm()!
      expect(confirm.disabled).toBe(false)

      handshake({ id: 1, admin: false }, true)
      await settled(fixture)

      expect(confirm.disabled).toBe(true)
      expect(confirm.getAttribute('aria-describedby')).toContain(
        HILOS_VIEW_MODE_STRIP_TEXT_ID,
      )
      confirm.click()
      await settled(fixture)
      expect(dispatched).toEqual([])
    },
  )
})

describe('HilosMaintenanceCirclePage and a new context', () => {
  it('binds the table of a new context, lets the old one go and closes the remove dialog over its row', async () => {
    const first = seededContext([ANN])
    const second = seededContext([BOB])
    const fixture = mountPage(first.context)
    trash('ann@example.test').click()
    fixture.detectChanges()
    expect(first.focus).toEqual(['1'])
    expect(removeConfirm()).not.toBeNull()

    fixture.componentRef.setInput('context', second.context)
    await settled(fixture)

    expect(first.registered.size).toBe(0)
    expect(first.focus).toEqual(['1', ''])
    expect(second.registered.has(CIRCLE_TABLE)).toBe(true)
    expect(mark('bob@example.test')?.textContent).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.offline,
    )
    expect(mark('ann@example.test')).toBeNull()
    // The dialog spoke for a row of the old table: it closes instead of saying
    // the row was taken out elsewhere, and the new table's focus stays untouched.
    expect(removeConfirm()).toBeNull()
    expect(document.body.textContent).not.toContain(
      HILOS_MAINTENANCE_CIRCLE_COPY.removeGone,
    )
    expect(second.focus).toEqual([])

    // The new table's own row opens its dialog as usual.
    trash('bob@example.test').click()
    fixture.detectChanges()
    expect(second.focus).toEqual(['2'])
    expect(document.querySelector('[data-id="modal"] code')?.textContent).toBe(
      'bob@example.test',
    )
    expect(removeConfirm()?.disabled).toBe(false)
    expect(removeConfirm()?.textContent?.trim()).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.removeConfirm,
    )
  })

  it('closes the add dialog of the old context and opens the next one empty', async () => {
    const first = seededContext([ANN])
    const second = seededContext([BOB])
    const fixture = mountPage(first.context)
    openAndType(fixture, 'someone@example.test')
    expect(addField()?.value).toBe('someone@example.test')

    fixture.componentRef.setInput('context', second.context)
    await settled(fixture)

    expect(addField()).toBeNull()
    document
      .querySelector<HTMLButtonElement>('[data-id="hilos-table-main-action"]')
      ?.click()
    fixture.detectChanges()
    expect(addField()?.value).toBe('')
  })
})

describe('HilosMaintenanceCirclePage disposal', () => {
  it('releases the focused row and every window listener and ignores a late action answer', async () => {
    const { actions, dispatched } = makeActions()
    const { context, focus, registered, pushRemove } = seededContext(
      [ANN],
      actions,
    )
    const unregister = vi.spyOn(context.connection, 'unregisterTableWindow')
    const viewport = vi.spyOn(context.connection, 'sendTableViewport')
    const rendered = vi.spyOn(context.connection, 'sendTableRendered')
    const rowFocus = vi.spyOn(context.connection, 'sendTableRowFocus')
    // Circle keeps no clock of its own: nothing on it moves by time. The one
    // interval allowed is jsdom's own frame loop, which it runs at 60 Hz behind
    // requestAnimationFrame while a frame is asked for.
    const clock = vi.spyOn(globalThis, 'setInterval')
    const fixture = mountPage(context)
    expect(clock.mock.calls.filter(([, delay]) => delay !== 1000 / 60)).toEqual(
      [],
    )
    clock.mockRestore()
    const controller = registered.get(CIRCLE_TABLE)!
    expect(viewport).not.toHaveBeenCalled()

    controller.refresh()
    await settled(fixture)
    expect(viewport).toHaveBeenCalledOnce()
    trash('ann@example.test').click()
    await settled(fixture)
    expect(focus).toEqual(['1'])
    removeConfirm()?.click()
    await settled(fixture)
    // The window, its requests and the row focus all speak for Circle.
    for (const send of [viewport, rendered, rowFocus]) {
      for (const args of send.mock.calls as unknown as unknown[][])
        expect(args[0]).toBe(HilosPages.MAINTENANCE_CIRCLE)
    }
    expect(rendered).toHaveBeenCalled()

    leavePage()

    expect(focus).toEqual(['1', ''])
    expect(unregister).toHaveBeenCalledOnce()
    pushRemove(ANN.memberId)
    expect(controller.rows.get()).toHaveLength(1)
    dispatched[0]?.settle({} as ActionResult)
    await new Promise((resolve) => setTimeout(resolve))
    expect(removeConfirm()).toBeNull()
    expect(focus).toEqual(['1', ''])
    expect(unregister).toHaveBeenCalledOnce()
  })
})

describe('HilosMaintenanceCirclePage head', () => {
  it('shares the Maintenance section head and selects only Circle', () => {
    const { context } = seededContext([])
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement

    expect(root.querySelectorAll('h1')).toHaveLength(1)
    expect(root.querySelector('h1')?.textContent?.trim()).toBe('Maintenance')
    expect(
      root.querySelector('[data-id="hilos-page-head-block"]')?.textContent,
    ).toContain(MAINTENANCE_LEAD)
    const breadcrumb = root.querySelector('[data-id="hilos-breadcrumb"]')
    // What each crumb says, without the separator drawn for the eye only.
    const crumbs = [...(breadcrumb?.querySelectorAll('li') ?? [])]
    const said = (item: Element): string | undefined => {
      const copy = item.cloneNode(true) as HTMLElement
      copy
        .querySelectorAll('[aria-hidden="true"]')
        .forEach((node) => node.remove())
      return copy.textContent?.trim()
    }
    expect(crumbs.map(said).sort()).toEqual(['Hilos', 'Maintenance'])
    // The section is the current crumb; the way back to Hilos is a real link.
    const currentCrumb = breadcrumb?.querySelectorAll('[aria-current="page"]')
    expect(currentCrumb).toHaveLength(1)
    expect(said(currentCrumb![0]!)).toBe('Maintenance')
    expect(
      [...(breadcrumb?.querySelectorAll('a') ?? [])].map((link) => [
        link.textContent?.trim(),
        link.getAttribute('href'),
      ]),
    ).toEqual([['Hilos', '/hilos']])
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
    expect(current?.[0]?.textContent?.trim()).toBe('Circle')
  })
})
