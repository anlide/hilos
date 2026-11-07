// The React Maintenance Circle tab over the real core table factory, actions, binder,
// scopes, shared section head and viewport table; only the transport and the action
// lifecycle's answers are stubbed, every window, delta and focus addressed to Circle.
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  createAppPageRouter,
  createSignal,
  HIDDEN_VALUE,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  MAINTENANCE_STATE_SIGNAL,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  hilosToasts,
  type ActionHandle,
  type ActionLifecycle,
  type ActionResult,
  type Hideable,
  type HilosConnection,
  type HilosMaintenanceState,
  type HilosPageIdentity,
  type HilosRouter,
  type PageRouteMatch,
  type ProjectSignal,
  type WritableSignal,
} from '@hilos/core/base'
import {
  createHilosMaintenanceCircleTable,
  HILOS_MAINTENANCE_CIRCLE_COPY,
  type HilosMaintenanceContext,
} from '@hilos/core/admin/maintenance'
import { HilosMaintenanceCirclePage as exportedPage } from '@hilos/react/admin/maintenance'

import { HilosMaintenanceCirclePage } from '../src/admin/maintenance/HilosMaintenanceCirclePage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

const CIRCLE_TABLE = 'hilosVerifierCircle'

const paths = createAppPageRouter({})

function identity(): HilosPageIdentity {
  return {
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
      lead: 'Node freeze and manual maintenance.',
      icon: 'bi-tools',
    },
    pageTabs: [
      { page: HilosPages.MAINTENANCE, label: 'Overview' },
      { page: HilosPages.MAINTENANCE_CIRCLE, label: 'Circle' },
      { page: HilosPages.MAINTENANCE_WORDS, label: 'Words' },
      { page: HilosPages.MAINTENANCE_NODES, label: 'Nodes' },
    ],
  }
}

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
    pageIdentity: createSignal<HilosPageIdentity | undefined>(identity()),
    dashboardSections: createSignal(undefined),
    resolvePath: (page, params) => paths.path(page, params),
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
 * the test: both dialogs close on the server's word, so a fake that settled by
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

type Listener = (signal: { type?: string; data: unknown }) => void

/**
 * Only the transport is stubbed: Circle's own page answer carries the first window,
 * and every delta this harness pushes is addressed to Circle unless a case says
 * otherwise.
 *
 * @param initial The members the first window holds.
 * @param actions The action lifecycle the page dispatches over.
 */
function seededContext(
  initial: CircleMember[],
  actions: ActionLifecycle = makeActions().actions,
) {
  const rows = initial.slice()
  const focus: string[] = []
  // The page every send of the table names, in order.
  const addressed: string[] = []
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.MAINTENANCE_CIRCLE)
  const listeners = new Map<string, Set<Listener>>()
  const registered = new Set<string>()
  const pageWindow = () => ({
    type: 'page_response',
    data: {
      page: HilosPages.MAINTENANCE_CIRCLE,
      payload: {
        windows: {
          [CIRCLE_TABLE]: {
            filter: {},
            rows: rows.map(wireRow),
            totalCount: rows.length,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            rowsBefore: 0,
            limit: 10,
            sort: [{ field: 'identifier', direction: 'asc' }],
          },
        },
      },
    },
  })
  const emit = (event: string, signal: { type?: string; data: unknown }) => {
    for (const listener of listeners.get(event) ?? []) {
      listener(signal)
    }
  }
  const sendTableViewport = vi.fn((page: string): boolean => {
    addressed.push(page)

    return true
  })
  const sendTableRendered = vi.fn((page: string): boolean => {
    addressed.push(page)

    return true
  })
  const connection = {
    on(event: string, listener: Listener): () => void {
      const bucket = listeners.get(event) ?? new Set<Listener>()
      listeners.set(event, bucket)
      bucket.add(listener)
      if (event === 'projectSignal') {
        listener(pageWindow())
      }

      return () => bucket.delete(listener)
    },
    registerTableWindow(tableKey: string): void {
      registered.add(tableKey)
    },
    unregisterTableWindow(tableKey: string): void {
      registered.delete(tableKey)
    },
    tableWindowDescriptors: () => ({}),
    sendTableViewport,
    sendTableRendered,
    sendTableRowFocus(page: string, tableKey: string, rowKey: string): boolean {
      addressed.push(page)
      focus.push(rowKey)

      return true
    },
  }

  return {
    context: {
      connection:
        connection as unknown as HilosMaintenanceContext['connection'],
      scopes,
      actions,
    } satisfies HilosMaintenanceContext,
    focus,
    addressed,
    registered,
    sendTableViewport,
    sendTableRendered,
    listenerCount: () =>
      [...listeners.values()].reduce((count, bucket) => count + bucket.size, 0),
    // How many listeners each connection event has now, leaving out the emptied ones.
    listenersByEvent: () =>
      Object.fromEntries(
        [...listeners]
          .filter(([, bucket]) => bucket.size > 0)
          .map(([event, bucket]) => [event, bucket.size]),
      ),
    // A section state frame, as the connection hands it to every listener.
    pushState(state: HilosMaintenanceState): void {
      emit('projectSignal', { type: MAINTENANCE_STATE_SIGNAL, data: state })
    },
    pushUpdate(next: CircleMember): void {
      emit('tableViewportDelta', {
        data: {
          page: HilosPages.MAINTENANCE_CIRCLE,
          tableKey: CIRCLE_TABLE,
          kind: 'row_updated',
          rowKey: String(next.memberId),
          row: wireRow(next),
        },
      })
    },
    // The membership was taken out: a removal without a body, tagged as this tab's own
    // when this tab is the one that took it out, addressed to Circle unless named.
    pushRemove(
      memberId: number,
      own = false,
      page: string = HilosPages.MAINTENANCE_CIRCLE,
    ): void {
      emit('tableViewportDelta', {
        data: {
          page,
          tableKey: CIRCLE_TABLE,
          kind: 'row_removed',
          rowKey: String(memberId),
          reason: 'deleted',
          own,
        },
      })
    },
  }
}

afterEach(() => {
  cleanup()
  document.body.classList.remove('modal-open')
  hilosToasts.clear()
  vi.restoreAllMocks()
})

function mountPage(context: HilosMaintenanceContext) {
  let view!: ReturnType<typeof render>
  act(() => {
    view = render(
      <HilosRouterContext.Provider value={router()}>
        <HilosMaintenanceCirclePage context={context} />
      </HilosRouterContext.Provider>,
    )
  })

  return view
}

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
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

/** Open the add dialog and enter the address without normalizing it. */
function openAndType(typed: string): void {
  fireEvent.click(
    document.querySelector(
      '[data-id="hilos-table-main-action"]',
    ) as HTMLButtonElement,
  )
  fireEvent.change(addField() as HTMLInputElement, { target: { value: typed } })
}

/**
 * Try every way a person closes the open dialog — its Cancel, its close button,
 * Escape and the backdrop; a busy dialog must refuse them all.
 *
 * @param cancel The dialog's own Cancel button.
 */
async function tryEveryClose(cancel: HTMLButtonElement): Promise<void> {
  const dialog = document.querySelector('[data-id="modal"]') as HTMLElement
  await settled(() => fireEvent.click(cancel))
  await settled(() =>
    fireEvent.click(
      dialog.querySelector('[data-id="modal-close"]') as HTMLButtonElement,
    ),
  )
  await settled(() => fireEvent.keyDown(dialog, { key: 'Escape' }))
  await settled(() => fireEvent.click(dialog))
}

describe('HilosMaintenanceCirclePage', () => {
  it('renders a row per member with the mark of whether they are signed in', () => {
    const { context } = seededContext([
      ANN,
      {
        memberId: 2,
        identityType: 'sms',
        identifier: '+10000000001',
        online: false,
      },
    ])
    const view = mountPage(context)

    expect(
      view.container.querySelectorAll('[data-id^="hilos-table-row-"]').length,
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
    expect(byId('hilos-table-title')?.textContent?.trim()).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.title,
    )
    expect(byId('hilos-table-subtitle')?.textContent).toContain(
      HILOS_MAINTENANCE_CIRCLE_COPY.rule,
    )
    expect(byId('hilos-table-subtitle')?.textContent).toContain(
      HILOS_MAINTENANCE_CIRCLE_COPY.volatile,
    )
    expect(byId('hilos-table-count')?.textContent?.trim()).toBe('1 – 2 of 2')
  })

  it('moves the mark in place when the live row changes', async () => {
    const { context, pushUpdate } = seededContext([ANN])
    mountPage(context)

    await settled(() => pushUpdate({ ...ANN, online: false }))

    expect(mark('ann@example.test')?.textContent).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.offline,
    )
  })

  it('says what an empty circle means instead of drawing rows', async () => {
    const { context } = seededContext([])
    const view = mountPage(context)

    expect(
      view.container.querySelectorAll('[data-id^="hilos-table-row-"]').length,
    ).toBe(0)
    expect(view.container.textContent).toContain(
      HILOS_MAINTENANCE_CIRCLE_COPY.empty.title,
    )
    expect(view.container.textContent).toContain(
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
          byId('hilos-maintenance-circle-add-cancel') as HTMLButtonElement,
        ),
      )
      expect(addField()).toBeNull()
    }
  })

  it('offers adding a member and removing one in the table itself', () => {
    const { context } = seededContext([ANN])
    mountPage(context)
    const table = byId('hilos-maintenance-circle-table')

    expect(
      table?.querySelector('[data-id="hilos-table-main-action"]')?.textContent,
    ).toBe(HILOS_MAINTENANCE_CIRCLE_COPY.addButton)
    expect(trash('ann@example.test').getAttribute('aria-label')).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.removeTitle,
    )
    // The icon is decoration: the button is named by its label alone.
    expect(
      trash('ann@example.test').querySelector('[aria-hidden="true"]'),
    ).not.toBeNull()
  })

  it('opens the remove dialog over the row, naming its address and taking it into focus', () => {
    const { context, focus } = seededContext([ANN])
    mountPage(context)

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
    mountPage(context)

    fireEvent.click(trash('ann@example.test'))
    fireEvent.click(removeConfirm() as HTMLButtonElement)

    expect(dispatched).toMatchObject([
      { action: 'maintenance_circle_remove', payload: { memberId: 1 } },
    ])
    expect(removeConfirm()).not.toBeNull()
    expect(trash('ann@example.test')).not.toBeNull()

    await settled(() =>
      dispatched[0]?.settle({
        action: 'maintenance_circle_remove',
      } as ActionResult),
    )

    expect(removeConfirm()).toBeNull()
    expect(focus).toEqual(['1', ''])
    // The row leaves over the live table, not on the answer.
    expect(trash('ann@example.test')).not.toBeNull()
  })

  it('keeps the remove dialog open with the refusal on it', async () => {
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([ANN], actions)
    mountPage(context)

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
    expect(byId('hilos-action-error')?.textContent).toContain(
      'This verifier is no longer in the circle',
    )
  })

  it('says the row was taken out elsewhere and locks Remove', async () => {
    const { context, pushRemove } = seededContext([ANN])
    mountPage(context)

    fireEvent.click(trash('ann@example.test'))
    await settled(() => pushRemove(1))

    expect(
      byId('hilos-maintenance-circle-remove-notice')?.textContent,
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
    mountPage(context)

    fireEvent.click(trash('ann@example.test'))
    fireEvent.click(removeConfirm() as HTMLButtonElement)
    await settled(() => pushRemove(1, true))

    // No message: the notice's room is held by its invisible twin only.
    expect(byId('hilos-maintenance-circle-remove-notice')).toBeNull()
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

  it("leaves the row alone when the removal is addressed to Overview's circle", async () => {
    const { context, pushRemove } = seededContext([ANN])
    mountPage(context)

    fireEvent.click(trash('ann@example.test'))
    await settled(() => pushRemove(1, false, HilosPages.MAINTENANCE))

    expect(byId('hilos-maintenance-circle-remove-notice')).toBeNull()
    expect(removeConfirm()?.disabled).toBe(false)
    expect(trash('ann@example.test')).not.toBeNull()
  })

  it('opens the add dialog with an empty field and holds Add until something is typed', () => {
    const { context } = seededContext([])
    mountPage(context)

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

  it("names the address as typed, draws no row of its own and closes only on the server's word", async () => {
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([], actions)
    const view = mountPage(context)

    openAndType('+7 900 000-00-00')
    fireEvent.click(addConfirm())

    expect(dispatched).toMatchObject([
      {
        action: 'maintenance_circle_add',
        payload: { identifier: '+7 900 000-00-00' },
      },
    ])
    expect(addField()).not.toBeNull()
    expect(
      view.container.querySelectorAll('[data-id^="hilos-table-row-"]'),
    ).toHaveLength(0)

    await settled(() =>
      dispatched[0]?.settle({
        action: 'maintenance_circle_add',
      } as ActionResult),
    )

    expect(addField()).toBeNull()
    expect(
      view.container.querySelectorAll('[data-id^="hilos-table-row-"]'),
    ).toHaveLength(0)
  })

  it('keeps the dialog open with the typed address and the refusal on it', async () => {
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([], actions)
    mountPage(context)

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
    expect(byId('hilos-action-error')?.textContent).toContain(
      'Nobody has proven this address',
    )
    expect(addConfirm().disabled).toBe(false)
  })

  it('opens the dialog again with an empty field and no refusal left over', async () => {
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([], actions)
    mountPage(context)

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
      byId('hilos-maintenance-circle-add-cancel') as HTMLButtonElement,
    )
    expect(addField()).toBeNull()
    fireEvent.click(byId('hilos-table-main-action') as HTMLButtonElement)

    expect(addField()?.value).toBe('')
    expect(document.body.textContent).not.toContain(
      'Nobody has proven this address',
    )
  })
})

describe('HilosMaintenanceCirclePage while an answer is in flight', () => {
  it.each(['add', 'remove'] as const)(
    'holds the %s dialog against a repeat and every close until its one answer',
    async (dialog) => {
      const action = `maintenance_circle_${dialog}`
      const { actions, dispatched } = makeActions()
      const { context } = seededContext([ANN], actions)
      mountPage(context)
      if (dialog === 'add') openAndType('bob@example.test')
      else fireEvent.click(trash('ann@example.test'))
      const confirm = byId(
        `hilos-maintenance-circle-${dialog}-confirm`,
      ) as HTMLButtonElement
      const cancel = byId(
        `hilos-maintenance-circle-${dialog}-cancel`,
      ) as HTMLButtonElement

      fireEvent.click(confirm)
      fireEvent.click(confirm)
      await settled(() => {})

      expect(dispatched).toMatchObject([{ action }])
      expect(confirm.getAttribute('aria-busy')).toBe('true')
      expect(confirm.getAttribute('aria-disabled')).toBe('true')
      expect(cancel.disabled).toBe(true)
      await tryEveryClose(cancel)
      await settled(() => fireEvent.click(confirm))
      expect(byId(`hilos-maintenance-circle-${dialog}-confirm`)).toBe(confirm)
      expect(dispatched).toHaveLength(1)

      await settled(() => dispatched[0]?.settle({ action } as ActionResult))

      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      expect(dispatched).toHaveLength(1)
    },
  )
})

describe('HilosMaintenanceCirclePage with the addresses hidden (HIL-1260)', () => {
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

describe('HilosMaintenanceCirclePage and the admin view mode', () => {
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

  it('a viewer opens the add dialog, may type an address and has nothing to send it with', async () => {
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([], actions)
    bindSession(context.scopes).handshake(null, true)
    mountPage(context)

    expect(
      (byId('hilos-table-main-action') as HTMLButtonElement | null)?.disabled,
    ).toBe(false)

    openAndType('someone@example.test')
    expect(addField()?.disabled).toBe(false)
    expect(addField()?.value).toBe('someone@example.test')
    expect(addConfirm().disabled).toBe(true)
    expect(addConfirm().getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    await settled(() => fireEvent.click(addConfirm()))
    expect(dispatched).toHaveLength(0)

    const cancelButton = byId(
      'hilos-maintenance-circle-add-cancel',
    ) as HTMLButtonElement | null
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

    const cancelButton = byId(
      'hilos-maintenance-circle-remove-cancel',
    ) as HTMLButtonElement | null
    expect(cancelButton?.disabled).toBe(false)
    await settled(() => fireEvent.click(cancelButton as Element))

    expect(removeConfirm()).toBeNull()
    expect(focus).toEqual(['1', ''])
  })

  it.each(['add', 'remove'] as const)(
    'blocks %s after a live admin revoke while its dialog is open',
    async (dialog) => {
      const { actions, dispatched } = makeActions()
      const { context } = seededContext([ANN], actions)
      const { handshake } = bindSession(context.scopes)
      handshake({ id: 1, admin: true }, true)
      mountPage(context)
      if (dialog === 'add') openAndType('someone@example.test')
      else fireEvent.click(trash('ann@example.test'))
      const confirm = dialog === 'add' ? addConfirm() : removeConfirm()!
      expect(confirm.disabled).toBe(false)

      await settled(() => handshake({ id: 1, admin: false }, true))

      expect(confirm.disabled).toBe(true)
      expect(confirm.getAttribute('aria-describedby')).toContain(
        HILOS_VIEW_MODE_STRIP_TEXT_ID,
      )
      await settled(() => fireEvent.click(confirm))
      expect(dispatched).toEqual([])
    },
  )

  it('an admin on a node in the mode names a verifier as today', () => {
    const { context } = seededContext([])
    bindSession(context.scopes).handshake({ id: 1, admin: true }, true)
    mountPage(context)

    openAndType('someone@example.test')
    expect(addConfirm().disabled).toBe(false)
    expect(addConfirm().getAttribute('aria-describedby')).toBeNull()
  })
})

describe('HilosMaintenanceCirclePage disposal', () => {
  it('runs no state listener or clock of the Overview and addresses every send to Circle', async () => {
    // What the circle table's own binding listens to, over a harness of its own.
    const alone = seededContext([ANN])
    const table = createHilosMaintenanceCircleTable(
      alone.context,
      { openAdd: () => {} },
      HilosPages.MAINTENANCE_CIRCLE,
    )
    table.start()
    const tableListeners = alone.listenersByEvent()
    table.dispose()
    const clock = vi.spyOn(globalThis, 'setInterval')
    const { actions, dispatched } = makeActions()
    const harness = seededContext([ANN], actions)
    mountPage(harness.context)
    expect(clock).not.toHaveBeenCalled()
    // The page listens to the connection only through its table: a listener of
    // the Overview's state frame would stand beside the table's on projectSignal.
    expect(tableListeners).toHaveProperty('projectSignal')
    expect(harness.listenersByEvent()).toEqual(tableListeners)

    await settled(() =>
      harness.pushState({
        phase: 'verifying',
        operation: 'manual_maintenance',
        manual: true,
      } as HilosMaintenanceState),
    )
    expect(byId('hilos-maintenance-state')).toBeNull()
    expect(
      document.querySelector('[data-id^="hilos-maintenance-state-"]'),
    ).toBeNull()

    fireEvent.click(trash('ann@example.test'))
    fireEvent.click(removeConfirm() as HTMLButtonElement)
    expect(dispatched).toHaveLength(1)
    expect(harness.sendTableViewport).not.toHaveBeenCalled()
    expect(harness.sendTableRendered).toHaveBeenCalled()
    expect(harness.addressed.length).toBeGreaterThan(0)
    expect(new Set(harness.addressed)).toEqual(
      new Set([HilosPages.MAINTENANCE_CIRCLE]),
    )
  })

  it('releases the focused row and every window listener on unmount and ignores a late answer', async () => {
    const { actions, dispatched } = makeActions()
    const harness = seededContext([ANN], actions)
    expect(harness.listenerCount()).toBe(0)
    const view = mountPage(harness.context)
    expect(harness.registered).toEqual(new Set([CIRCLE_TABLE]))
    expect(harness.listenerCount()).toBeGreaterThan(0)

    fireEvent.click(trash('ann@example.test'))
    fireEvent.click(removeConfirm() as HTMLButtonElement)
    expect(harness.focus).toEqual(['1'])

    view.unmount()

    expect(harness.focus).toEqual(['1', ''])
    expect(harness.registered.size).toBe(0)
    expect(harness.listenerCount()).toBe(0)

    await settled(() =>
      dispatched[0]?.settle({
        action: 'maintenance_circle_remove',
      } as ActionResult),
    )

    expect(removeConfirm()).toBeNull()
    expect(harness.focus).toEqual(['1', ''])
  })

  it('ignores a late answer to Add once the page is gone', async () => {
    const { actions, dispatched } = makeActions()
    const harness = seededContext([], actions)
    const view = mountPage(harness.context)

    openAndType('bob@example.test')
    fireEvent.click(addConfirm())
    expect(dispatched).toMatchObject([{ action: 'maintenance_circle_add' }])

    view.unmount()
    const addressed = [...harness.addressed]

    await settled(() =>
      dispatched[0]?.settle({
        action: 'maintenance_circle_add',
      } as ActionResult),
    )

    expect(addField()).toBeNull()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(harness.registered.size).toBe(0)
    expect(harness.listenerCount()).toBe(0)
    expect(harness.addressed).toEqual(addressed)
    expect(harness.focus).toEqual([])
  })
})

describe('HilosMaintenanceCirclePage head', () => {
  it('is the public section entry and stands under the shared Maintenance head with Circle current', () => {
    expect(exportedPage).toBe(HilosMaintenanceCirclePage)
    const { context } = seededContext([])
    mountPage(context)

    expect(byId('hilos-admin-page')?.getAttribute('data-page')).toBe(
      HilosPages.MAINTENANCE_CIRCLE,
    )
    expect(byId('hilos-breadcrumb')?.querySelectorAll('li')).toHaveLength(2)
    expect(
      [...document.querySelectorAll('h1')].map((h) => h.textContent),
    ).toEqual(['Maintenance'])
    const tabs = byId('hilos-page-tabs')
    expect(tabs?.getAttribute('aria-label')).toBe('Maintenance pages')
    expect(
      [...(tabs?.querySelectorAll('a') ?? [])].map((link) => [
        link.textContent,
        link.getAttribute('href'),
      ]),
    ).toEqual([
      ['Overview', '/hilos/maintenance'],
      ['Circle', '/hilos/maintenance/circle'],
      ['Words', '/hilos/maintenance/words'],
      ['Nodes', '/hilos/maintenance/nodes'],
    ])
    expect(
      [...(tabs?.querySelectorAll('[aria-current="page"]') ?? [])].map(
        (link) => link.textContent,
      ),
    ).toEqual(['Circle'])
    expect(byId('hilos-admin-children')).toBeNull()
    expect(byId('hilos-maintenance-state')).toBeNull()

    const title = byId('hilos-table-title')
    expect(
      document
        .querySelector('[data-id="hilos-maintenance-circle-table"] table')
        ?.getAttribute('aria-labelledby'),
    ).toBe(title?.id)
  })
})
