import { flushPromises, mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  MAINTENANCE_STATE_SIGNAL,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
  hilosToasts,
} from '@hilos/core/base'
import { HILOS_MAINTENANCE_CIRCLE_COPY } from '@hilos/core/admin/maintenance'
import type {
  ActionHandle,
  ActionLifecycle,
  ActionResult,
  HilosConnection,
  HilosMaintenanceState,
  HilosRouter,
  HilosPageIdentity,
  PageRouteMatch,
  ProjectSignal,
  WritableSignal,
} from '@hilos/core/base'
import type { HilosMaintenanceContext } from '@hilos/core/admin/maintenance'

import HilosMaintenanceCirclePage from './HilosMaintenanceCirclePage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const CIRCLE_TABLE = 'hilosVerifierCircle'

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
        lead: 'Node freeze under a destructive operation and manual maintenance: its state, the verifier circle, closing the system to visitors, and the way out of the window.',
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

function seededContext(
  initial: CircleMember[],
  actions: ActionLifecycle = makeActions().actions,
): {
  context: HilosMaintenanceContext
  focus: string[]
  pushUpdate: (next: CircleMember) => void
  pushRemove: (memberId: number, own?: boolean) => void
  pushState: (state: HilosMaintenanceState) => void
} {
  const rows = initial.slice()
  const focus: string[] = []
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.MAINTENANCE_CIRCLE)
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const deltaListeners = new Set<(signal: { data: unknown }) => void>()
  const stateListeners = new Set<
    (signal: { type: string; data: unknown }) => void
  >()
  const serveWindow = (
    page: string = HilosPages.MAINTENANCE_CIRCLE,
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

  const pageWindow = () => ({
    type: 'page_response',
    data: {
      page: HilosPages.MAINTENANCE_CIRCLE,
      payload: {
        windows: {
          [CIRCLE_TABLE]: {
            rows: rows.map(wireRow),
            totalCount: rows.length,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            offset: 0,
            limit: 10,
          },
        },
      },
    },
  })
  const connection = {
    registerTableWindow(): void {},
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
        listener(pageWindow())

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
    pushState(state: HilosMaintenanceState): void {
      for (const listener of stateListeners) {
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

const mounted: ReturnType<typeof mount>[] = []

afterEach(() => {
  for (const wrapper of mounted.splice(0)) {
    wrapper.unmount()
  }
  hilosToasts.clear()
})

async function mountPage(context: HilosMaintenanceContext) {
  const wrapper = mount(HilosMaintenanceCirclePage, {
    props: { context: markRaw(context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: router() } },
  })
  mounted.push(wrapper)
  await nextTick()

  return wrapper
}

// A row stands in the document twice — once in the table and once in the card the
// same row becomes on a narrow screen — so a mark is looked up through the table.
function mark(identifier: string): HTMLElement | null {
  return document.querySelector(
    `table [data-id="hilos-maintenance-circle-online-${identifier}"]`,
  )
}

/** Let a settled action reach the dialog: the promise, the driver, then the render. */
async function settled(): Promise<void> {
  await flushPromises()
  await nextTick()
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

/** Open the add dialog and type an address into its field. */
async function openAndType(
  wrapper: Awaited<ReturnType<typeof mountPage>>,
  typed: string,
): Promise<void> {
  await wrapper.get('[data-id="hilos-table-main-action"]').trigger('click')
  await nextTick()
  const field = addField() as HTMLInputElement
  field.value = typed
  field.dispatchEvent(new Event('input'))
  await nextTick()
}

describe('HilosMaintenanceCirclePage', () => {
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

    expect(wrapper.findAll('[data-id^="hilos-table-row-"]').length).toBe(2)
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

    pushUpdate({ ...member, online: false })
    await nextTick()

    expect(mark('ann@example.test')?.textContent).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.offline,
    )
  })

  it('says what an empty circle means instead of drawing rows', async () => {
    const { context } = seededContext([])
    const wrapper = await mountPage(context)

    expect(wrapper.findAll('[data-id^="hilos-table-row-"]').length).toBe(0)
    expect(wrapper.text()).toContain(HILOS_MAINTENANCE_CIRCLE_COPY.empty.title)
    expect(wrapper.text()).toContain(HILOS_MAINTENANCE_CIRCLE_COPY.empty.hint)
    expect(wrapper.find('[data-id="hilos-table-main-action"]').text()).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.addButton,
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
      await nextTick()
      expect(addField()).not.toBeNull()
      document
        .querySelector<HTMLButtonElement>(
          '[data-id="hilos-maintenance-circle-add-cancel"]',
        )
        ?.click()
      await nextTick()
      expect(addField()).toBeNull()
    }
  })

  it('offers adding a member and removing one', async () => {
    const { context } = seededContext([ANN])
    const wrapper = await mountPage(context)
    const table = wrapper.get('[data-id="hilos-maintenance-circle-table"]')
    expect(table.element.closest('.card, .card-body')).toBeNull()
    expect(
      wrapper.find('[data-id="hilos-maintenance-circle-panel"]').exists(),
    ).toBe(false)

    expect(table.get('[data-id="hilos-table-main-action"]').text()).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.addButton,
    )
    expect(trash('ann@example.test').getAttribute('aria-label')).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.removeTitle,
    )
    expect(trash('ann@example.test').querySelector('.bi-trash')).not.toBeNull()
    expect(wrapper.text()).toContain(HILOS_MAINTENANCE_CIRCLE_COPY.title)
  })

  it('opens the remove dialog over the row, naming its address and taking it into focus', async () => {
    const { context, focus } = seededContext([ANN])
    await mountPage(context)

    trash('ann@example.test').click()
    await nextTick()

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

    trash('ann@example.test').click()
    await nextTick()
    removeConfirm()?.click()
    await settled()

    expect(dispatched).toMatchObject([
      { action: 'maintenance_circle_remove', payload: { memberId: 1 } },
    ])
    expect(removeConfirm()).not.toBeNull()
    expect(trash('ann@example.test')).not.toBeNull()

    dispatched[0]?.settle({
      action: 'maintenance_circle_remove',
    } as ActionResult)
    await settled()

    expect(removeConfirm()).toBeNull()
    expect(focus).toEqual(['1', ''])
    expect(trash('ann@example.test')).not.toBeNull()
  })

  it('keeps the remove dialog open with the refusal on it', async () => {
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([ANN], actions)
    await mountPage(context)

    trash('ann@example.test').click()
    await nextTick()
    removeConfirm()?.click()
    await settled()
    dispatched[0]?.refuse(
      new ActionError(
        'maintenance_circle_remove',
        'fail',
        'This verifier is no longer in the circle',
      ),
    )
    await settled()

    expect(removeConfirm()).not.toBeNull()
    expect(
      document.querySelector('[data-id="hilos-action-error"]')?.textContent,
    ).toContain('This verifier is no longer in the circle')
  })

  it('says the row was taken out elsewhere and locks Remove', async () => {
    const { context, pushRemove } = seededContext([ANN])
    await mountPage(context)

    trash('ann@example.test').click()
    await nextTick()
    pushRemove(1)
    await nextTick()

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

    trash('ann@example.test').click()
    await nextTick()
    removeConfirm()?.click()
    await settled()
    pushRemove(1, true)
    await nextTick()

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
    await settled()

    expect(removeConfirm()).toBeNull()
  })

  it('opens the add dialog with an empty field and holds Add until something is typed', async () => {
    const { context } = seededContext([])
    const wrapper = await mountPage(context)

    await openAndType(wrapper, '   ')

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
    await nextTick()

    expect(addConfirm().disabled).toBe(false)
  })

  it("names the address as typed and closes only on the server's word", async () => {
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([], actions)
    const wrapper = await mountPage(context)

    await openAndType(wrapper, '+7 900 000-00-00')
    addConfirm().click()
    await settled()

    expect(dispatched).toMatchObject([
      {
        action: 'maintenance_circle_add',
        payload: { identifier: '+7 900 000-00-00' },
      },
    ])
    expect(addField()).not.toBeNull()
    expect(wrapper.findAll('[data-id^="hilos-table-row-"]')).toHaveLength(0)

    dispatched[0]?.settle({ action: 'maintenance_circle_add' } as ActionResult)
    await settled()

    expect(addField()).toBeNull()
    expect(wrapper.findAll('[data-id^="hilos-table-row-"]')).toHaveLength(0)
  })

  it('keeps the dialog open with the typed address and the refusal on it', async () => {
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([], actions)
    const wrapper = await mountPage(context)

    await openAndType(wrapper, 'nobody@example.test')
    addConfirm().click()
    await settled()
    dispatched[0]?.refuse(
      new ActionError(
        'maintenance_circle_add',
        'fail',
        'Nobody has proven this address',
      ),
    )
    await settled()

    expect(addField()?.value).toBe('nobody@example.test')
    expect(
      document.querySelector('[data-id="hilos-action-error"]')?.textContent,
    ).toContain('Nobody has proven this address')
    expect(addConfirm().disabled).toBe(false)
  })

  it('opens the dialog again with an empty field and no refusal left over', async () => {
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([], actions)
    const wrapper = await mountPage(context)

    await openAndType(wrapper, 'nobody@example.test')
    addConfirm().click()
    await settled()
    dispatched[0]?.refuse(
      new ActionError(
        'maintenance_circle_add',
        'fail',
        'Nobody has proven this address',
      ),
    )
    await settled()
    document
      .querySelector<HTMLButtonElement>(
        '[data-id="modal"] .modal-footer .btn-secondary',
      )
      ?.click()
    await settled()
    expect(addField()).toBeNull()
    await wrapper.get('[data-id="hilos-table-main-action"]').trigger('click')
    await nextTick()

    expect(addField()?.value).toBe('')
    expect(document.body.textContent).not.toContain(
      'Nobody has proven this address',
    )
  })
})

/** The enable confirmation's body; its parent HilosModal teleports to the document. */
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
    await mountPage(context)

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
    await mountPage(context)

    trash('member-2').click()
    await nextTick()

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
    const wrapper = await mountPage(context)

    const addButton = wrapper.get<HTMLButtonElement>(
      '[data-id="hilos-table-main-action"]',
    )
    expect(addButton.element.disabled).toBe(false)

    await openAndType(wrapper, 'someone@example.test')
    expect(addField()?.disabled).toBe(false)
    expect(addField()?.value).toBe('someone@example.test')
    expect(addConfirm().disabled).toBe(true)
    expect(addConfirm().getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    addConfirm().click()
    await settled()
    expect(dispatched).toHaveLength(0)

    const cancelButton = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-maintenance-circle-add-cancel"]',
    )
    expect(cancelButton).not.toBeNull()
    expect(cancelButton?.disabled).toBe(false)
    cancelButton?.click()
    await settled()
    expect(addField()).toBeNull()
  })

  it('a viewer opens the remove dialog over a row and has nothing to send from it', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { actions, dispatched } = makeActions()
    const { context, focus } = seededContext([ANN], actions)
    await mountPage(context)

    const trashButton = trash('ann@example.test')
    expect(trashButton.disabled).toBe(false)
    trashButton.click()
    await settled()

    expect(focus).toEqual(['1'])
    expect(removeConfirm()?.disabled).toBe(true)
    expect(removeConfirm()?.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    removeConfirm()?.click()
    await settled()
    expect(dispatched).toHaveLength(0)

    const cancelButton = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-maintenance-circle-remove-cancel"]',
    )
    expect(cancelButton).not.toBeNull()
    expect(cancelButton?.disabled).toBe(false)
    cancelButton?.click()
    await settled()

    expect(removeConfirm()).toBeNull()
    expect(focus).toEqual(['1', ''])
  })

  it.each(['add', 'remove'] as const)(
    'blocks %s after a live admin revoke while its dialog is open',
    async (dialog) => {
      const { handshake } = bindSession()
      handshake({ id: 1, admin: true }, true)
      const { actions, dispatched } = makeActions()
      const { context } = seededContext([ANN], actions)
      const wrapper = await mountPage(context)
      if (dialog === 'add') await openAndType(wrapper, 'someone@example.test')
      else {
        trash('ann@example.test').click()
        await settled()
      }
      const confirm = dialog === 'add' ? addConfirm() : removeConfirm()!
      expect(confirm.disabled).toBe(false)
      handshake({ id: 1, admin: false }, true)
      await settled()
      expect(confirm.disabled).toBe(true)
      expect(confirm.getAttribute('aria-describedby')).toContain(
        HILOS_VIEW_MODE_STRIP_TEXT_ID,
      )
      confirm.click()
      await settled()
      expect(dispatched).toEqual([])
    },
  )

  it('an admin on a node in the mode names a verifier as today', async () => {
    const { handshake } = bindSession()
    handshake({ id: 1, admin: true }, true)
    const { actions } = makeActions()
    const { context } = seededContext([], actions)
    const wrapper = await mountPage(context)

    await openAndType(wrapper, 'someone@example.test')
    expect(addConfirm().disabled).toBe(false)
    expect(addConfirm().getAttribute('aria-describedby')).toBeNull()
  })
})

describe('Circle disposal', () => {
  it('releases the focused row and all window listeners and ignores a late action answer', async () => {
    const { actions, dispatched } = makeActions()
    const { context, focus, pushRemove } = seededContext([ANN], actions)
    const unregister = vi.spyOn(context.connection, 'unregisterTableWindow')
    const viewport = vi.spyOn(context.connection, 'sendTableViewport')
    const rendered = vi.spyOn(context.connection, 'sendTableRendered')
    const rowFocus = vi.spyOn(context.connection, 'sendTableRowFocus')
    const clock = vi.spyOn(globalThis, 'setInterval')
    const wrapper = await mountPage(context)
    expect(clock).not.toHaveBeenCalled()
    clock.mockRestore()
    const controller = wrapper
      .getComponent({ name: 'HilosViewportTable' })
      .props('controller')
    expect(viewport).not.toHaveBeenCalled()
    controller.refresh()
    await settled()
    expect(viewport).toHaveBeenCalledOnce()
    trash('ann@example.test').click()
    await settled()
    expect(focus).toEqual(['1'])
    removeConfirm()?.click()
    await settled()
    for (const send of [viewport, rendered, rowFocus]) {
      for (const args of send.mock.calls)
        expect(args[0]).toBe(HilosPages.MAINTENANCE_CIRCLE)
    }
    expect(rendered).toHaveBeenCalled()
    wrapper.unmount()
    mounted.splice(mounted.indexOf(wrapper), 1)
    expect(focus).toEqual(['1', ''])
    expect(unregister).toHaveBeenCalledOnce()
    pushRemove(ANN.memberId)
    expect(controller.rows.get()).toHaveLength(1)
    dispatched[0].settle({})
    await settled()
    expect(removeConfirm()).toBeNull()
    expect(unregister).toHaveBeenCalledOnce()
  })
})

describe('Maintenance Circle head', () => {
  it('shares the Maintenance section head and selects only Circle', async () => {
    const { context } = seededContext([])
    const wrapper = await mountPage(context)
    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('h1').text()).toBe('Maintenance')
    const head = wrapper.get('[data-id="hilos-page-head-block"]')
    const icon = head.get('i.bi-tools')
    expect(
      icon.element.parentElement?.classList.contains('rounded-circle'),
    ).toBe(true)
    expect(head.text()).toContain(
      'Node freeze under a destructive operation and manual maintenance: its state, the verifier circle, closing the system to visitors, and the way out of the window.',
    )
    const breadcrumb = wrapper.get('[data-id="hilos-breadcrumb"]')
    expect(breadcrumb.findAll('li').map((item) => item.text())).toEqual([
      'Hilos',
      'Maintenance',
    ])
    expect(
      breadcrumb.findAll('a').map((link) => link.attributes('href')),
    ).toEqual(['/hilos'])
    const tabs = wrapper.get('nav[aria-label="Maintenance pages"]')
    expect(
      tabs.findAll('a').map((link) => [link.text(), link.attributes('href')]),
    ).toEqual([
      ['Overview', '/hilos/maintenance'],
      ['Circle', '/hilos/maintenance/circle'],
    ])
    expect(tabs.findAll('a[aria-current="page"]')).toHaveLength(1)
    expect(tabs.get('a[aria-current="page"]').text()).toBe('Circle')
    expect(tabs.get('ul').classes()).toContain('overflow-x-auto')
    expect(tabs.get('ul').classes()).toContain('flex-nowrap')
    expect(wrapper.find('[data-id="hilos-admin-children"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-maintenance-state"]').exists()).toBe(
      false,
    )
  })
})
