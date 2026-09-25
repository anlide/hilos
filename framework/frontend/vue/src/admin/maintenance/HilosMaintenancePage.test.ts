import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionError,
  HILOS_MAINTENANCE_CIRCLE_COPY,
  HilosPages,
  ScopeManager,
  createSignal,
} from '@hilos/core'
import type {
  ActionHandle,
  ActionLifecycle,
  ActionResult,
  HilosMaintenanceContext,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'

import HilosMaintenancePage from './HilosMaintenancePage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

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
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

interface CircleMember {
  memberId: number
  identityType: string
  identifier: string
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
      dispatched.push({ action, payload, settle, refuse })

      return {
        requestId: String(dispatched.length),
        loading: createSignal(false),
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
  pushUpdate: (next: CircleMember) => void
} {
  const rows = initial.slice()
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.MAINTENANCE)
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const deltaListeners = new Set<(signal: { data: unknown }) => void>()
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

  return {
    context: {
      connection:
        connection as unknown as HilosMaintenanceContext['connection'],
      scopes,
      actions,
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
  }
}

const mounted: ReturnType<typeof mount>[] = []

afterEach(() => {
  for (const wrapper of mounted.splice(0)) {
    wrapper.unmount()
  }
})

async function mountPage(context: HilosMaintenanceContext) {
  const wrapper = mount(HilosMaintenancePage, {
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
  await nextTick()
  await nextTick()
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

/** Open the add dialog and type an address into its field. */
async function openAndType(
  wrapper: Awaited<ReturnType<typeof mountPage>>,
  typed: string,
): Promise<void> {
  await wrapper.get('[data-id="hilos-maintenance-circle-add"]').trigger('click')
  await nextTick()
  const field = addField() as HTMLInputElement
  field.value = typed
  field.dispatchEvent(new Event('input'))
  await nextTick()
}

describe('HilosMaintenancePage', () => {
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
    expect(wrapper.text()).toContain(HILOS_MAINTENANCE_CIRCLE_COPY.empty)
  })

  it('offers adding a member and not removing one', async () => {
    const { context } = seededContext([
      {
        memberId: 1,
        identityType: 'password',
        identifier: 'ann@example.test',
        online: true,
      },
    ])
    const wrapper = await mountPage(context)
    const panel = wrapper.get('[data-id="hilos-maintenance-circle-panel"]')

    // Taking a member out is still the backup page's until the remove leaf lands.
    expect(panel.get('[data-id="hilos-maintenance-circle-add"]').text()).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.addButton,
    )
    expect(panel.findAll('[data-id*="circle-remove"]').length).toBe(0)
    expect(panel.findAll('.bi-trash').length).toBe(0)
    expect(wrapper.text()).toContain(HILOS_MAINTENANCE_CIRCLE_COPY.title)
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

    dispatched[0]?.settle({ action: 'maintenance_circle_add' } as ActionResult)
    await settled()

    expect(addField()).toBeNull()
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
    await wrapper
      .get('[data-id="hilos-maintenance-circle-add"]')
      .trigger('click')
    await nextTick()

    expect(addField()?.value).toBe('')
    expect(document.body.textContent).not.toContain(
      'Nobody has proven this address',
    )
  })
})
