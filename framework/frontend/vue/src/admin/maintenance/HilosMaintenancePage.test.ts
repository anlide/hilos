import { flushPromises, mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
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
import {
  HILOS_MAINTENANCE_CIRCLE_COPY,
  HILOS_MAINTENANCE_DISABLE_COPY,
  HILOS_MAINTENANCE_ENABLE_COPY,
  HILOS_MAINTENANCE_PASS_COPY,
  HILOS_MAINTENANCE_STATE_COPY,
} from '@hilos/core/admin/maintenance'
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

const mounted: ReturnType<typeof mount>[] = []

afterEach(() => {
  for (const wrapper of mounted.splice(0)) {
    wrapper.unmount()
  }
  hilosToasts.clear()
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

describe('HilosMaintenancePage', () => {
  it('offers enable only after the open frame arrives', async () => {
    const { context, pushState } = seededContext([])
    const wrapper = await mountPage(context)
    expect(
      wrapper.find('[data-id="hilos-maintenance-state-open"]').exists(),
    ).toBe(false)
    expect(wrapper.find('[data-id="hilos-maintenance-enable"]').exists()).toBe(
      false,
    )
    pushState(maintenanceState())
    await nextTick()
    const stateBlock = wrapper.get('[data-id="hilos-maintenance-state"]')
    expect(stateBlock.text()).toContain(HILOS_MAINTENANCE_STATE_COPY.openTitle)
    expect(stateBlock.text()).toContain(
      HILOS_MAINTENANCE_STATE_COPY.openDescription,
    )
    expect(stateBlock.get('[data-id="hilos-maintenance-enable"]').text()).toBe(
      HILOS_MAINTENANCE_ENABLE_COPY.enableTitle,
    )
  })

  it('offers disable only in the manual card, for either entry source', async () => {
    const { context, pushState } = seededContext([])
    const wrapper = await mountPage(context)
    const button = () => wrapper.find('[data-id="hilos-maintenance-disable"]')
    expect(button().exists()).toBe(false)
    for (const state of [
      maintenanceState(),
      maintenanceState({ phase: 'verifying', operation: 'restore' }),
    ]) {
      pushState(state)
      await settled()
      expect(button().exists()).toBe(false)
    }
    for (const enteredFromBrowser of [true, false]) {
      pushState(
        maintenanceState({
          phase: 'verifying',
          operation: 'manual_maintenance',
          manual: true,
          enteredFromBrowser,
        }),
      )
      await settled()
      expect(button().exists()).toBe(true)
    }
  })

  it('offers pass only in the manual card, for either entry source', async () => {
    const { context, pushState } = seededContext([])
    const wrapper = await mountPage(context)
    const button = () => wrapper.find('[data-id="hilos-maintenance-pass"]')
    expect(button().exists()).toBe(false)
    for (const state of [
      maintenanceState(),
      maintenanceState({ phase: 'verifying', operation: 'restore' }),
    ]) {
      pushState(state)
      await settled()
      expect(button().exists()).toBe(false)
    }
    for (const enteredFromBrowser of [true, false]) {
      pushState(
        maintenanceState({
          phase: 'verifying',
          operation: 'manual_maintenance',
          manual: true,
          enteredFromBrowser,
        }),
      )
      await settled()
      expect(button().exists()).toBe(true)
    }
  })

  it('renders the manual card and redraws its pass count on a new frame', async () => {
    const { context, pushState } = seededContext([])
    const wrapper = await mountPage(context)
    const manual = maintenanceState({
      phase: 'verifying',
      operation: 'manual_maintenance',
      manual: true,
      startedAt: new Date(Date.now() - 150_000).toISOString(),
      enteredFromBrowser: false,
      circleNamed: 3,
      circleAdmitted: 2,
    })
    pushState(manual)
    await nextTick()
    const card = wrapper.get('[data-id="hilos-maintenance-state-manual"]')
    expect(card.text()).toContain(HILOS_MAINTENANCE_STATE_COPY.manualTitle)
    expect(card.text()).toContain(
      HILOS_MAINTENANCE_STATE_COPY.turnedOnFromConsole,
    )
    expect(card.text()).toContain('3 named · 2 let in')
    expect(card.text()).toContain('2 minutes ago')
    expect(card.get('[data-id="hilos-maintenance-disable"]').text()).toBe(
      HILOS_MAINTENANCE_DISABLE_COPY.disableTitle,
    )
    pushState({ ...manual, passCount: 1 })
    await nextTick()
    expect(wrapper.get('[data-id="hilos-maintenance-pass-count"]').text()).toBe(
      '1',
    )
  })

  it('renders only the planned operation lines in the frozen card', async () => {
    const { context, pushState } = seededContext([])
    const wrapper = await mountPage(context)
    pushState(
      maintenanceState({
        phase: 'verifying',
        operation: 'restore',
        startedAt: '2026-10-10T10:00:00+00:00',
        passCount: 2,
      }),
    )
    await nextTick()
    const card = wrapper.get('[data-id="hilos-maintenance-state-operation"]')
    expect(card.text()).toContain(HILOS_MAINTENANCE_STATE_COPY.operationTitle)
    expect(card.text()).toContain(
      HILOS_MAINTENANCE_STATE_COPY.verifyingPhaseNote,
    )
    expect(card.text()).toContain('restore')
    expect(card.text()).toContain('2026-10-10T10:00:00+00:00')
    expect(card.text()).not.toContain('Initiator')
    expect(card.find('button').exists()).toBe(false)
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
    const panel = wrapper.get('[data-id="hilos-maintenance-circle-panel"]')

    expect(panel.get('[data-id="hilos-table-main-action"]').text()).toBe(
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

    dispatched[0]?.settle({
      action: 'maintenance_circle_remove',
    } as ActionResult)
    await settled()

    expect(removeConfirm()).toBeNull()
    expect(focus).toEqual(['1', ''])
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
    await wrapper.get('[data-id="hilos-table-main-action"]').trigger('click')
    await nextTick()

    expect(addField()?.value).toBe('')
    expect(document.body.textContent).not.toContain(
      'Nobody has proven this address',
    )
  })
})

/** The enable confirmation's body; its parent HilosModal teleports to the document. */
function enableModal(): HTMLElement | null {
  return document.querySelector('[data-id="hilos-maintenance-enable-modal"]')
}

/** A button of the enable confirmation, which must already be open. */
function enableButton(action: 'confirm' | 'cancel'): HTMLButtonElement {
  return document.querySelector(
    `[data-id="hilos-maintenance-enable-${action}"]`,
  ) as HTMLButtonElement
}

/** Mount the real page and open its enable confirmation from a server open frame. */
async function openEnable() {
  const { actions, dispatched } = makeActions()
  const { context, pushState } = seededContext([], actions)
  const wrapper = await mountPage(context)
  pushState(maintenanceState())
  await nextTick()
  const opener = wrapper.get<HTMLButtonElement>(
    '[data-id="hilos-maintenance-enable"]',
  )
  opener.element.focus()
  opener.element.click()
  await settled()

  return { wrapper, pushState, dispatched, opener }
}

describe('HilosMaintenancePage enable confirmation', () => {
  it.each(['cancel', 'close', 'escape', 'backdrop'] as const)(
    'explains entry and allows %s before dispatch without sending anything',
    async (method) => {
      const { dispatched, opener } = await openEnable()
      const dialog = enableModal()?.closest<HTMLElement>(
        '[role="dialog"]',
      ) as HTMLElement
      expect(dialog.getAttribute('aria-label')).toBe(
        HILOS_MAINTENANCE_ENABLE_COPY.enableTitle,
      )
      expect(dialog.getAttribute('aria-modal')).toBe('true')
      expect(document.activeElement).toBe(dialog)
      expect(dialog.querySelector('input, textarea')).toBeNull()
      for (const text of [
        HILOS_MAINTENANCE_ENABLE_COPY.lead,
        HILOS_MAINTENANCE_ENABLE_COPY.outsideNote,
        HILOS_MAINTENANCE_ENABLE_COPY.insideNote,
        HILOS_MAINTENANCE_ENABLE_COPY.agentsNote,
        HILOS_MAINTENANCE_ENABLE_COPY.footer,
      ])
        expect(dialog.textContent).toContain(text)
      expect(
        dialog.querySelector('[data-id="hilos-action-error-idle"]'),
      ).not.toBeNull()

      if (method === 'cancel') enableButton('cancel').click()
      if (method === 'close')
        dialog
          .querySelector<HTMLButtonElement>('[data-id="modal-close"]')
          ?.click()
      if (method === 'escape')
        dialog.dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        )
      if (method === 'backdrop') dialog.click()
      await settled()

      expect(enableModal()).toBeNull()
      expect(dispatched).toHaveLength(0)
      expect(document.activeElement).toBe(opener.element)
    },
  )

  it('blocks repeat and every close while busy, survives manual push and closes on its one success', async () => {
    const { wrapper, pushState, dispatched } = await openEnable()
    const dialog = enableModal()?.closest<HTMLElement>(
      '[role="dialog"]',
    ) as HTMLElement
    enableButton('confirm').click()
    enableButton('confirm').click()
    await settled()

    expect(dispatched).toMatchObject([
      { action: 'maintenance_enable', payload: {} },
    ])
    expect(enableButton('confirm').getAttribute('aria-disabled')).toBe('true')
    expect(enableButton('cancel').disabled).toBe(true)
    enableButton('cancel').click()
    dialog.querySelector<HTMLButtonElement>('[data-id="modal-close"]')?.click()
    dialog.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    dialog.click()
    await settled()
    expect(enableModal()).not.toBeNull()
    dispatched[0]?.loading.set(true)
    await settled()
    expect(enableButton('confirm').getAttribute('aria-busy')).toBe('true')

    pushState(
      maintenanceState({
        phase: 'verifying',
        operation: 'manual_maintenance',
        manual: true,
      }),
    )
    await settled()
    expect(wrapper.find('[data-id="hilos-maintenance-enable"]').exists()).toBe(
      false,
    )
    expect(
      wrapper.find('[data-id="hilos-maintenance-state-manual"]').exists(),
    ).toBe(true)
    expect(enableModal()?.closest('[role="dialog"]')).toBe(dialog)
    expect(enableButton('cancel').disabled).toBe(true)
    expect(hilosToasts.toasts.get()).toHaveLength(0)

    const message = 'Maintenance is on — the system is closed to visitors'
    dispatched[0]?.settle({ message })
    await settled()
    expect(enableModal()).toBeNull()
    expect(hilosToasts.toasts.get()).toMatchObject([
      { message, severity: 'success', repeats: 1 },
    ])
    expect(dispatched).toHaveLength(1)
  })

  it.each([
    maintenanceState({
      phase: 'verifying',
      operation: 'manual_maintenance',
      manual: true,
    }),
    maintenanceState({ phase: 'active', operation: 'restore' }),
  ])(
    'blocks an unsent confirmation when the node is no longer open: %j',
    async (state) => {
      const { pushState, dispatched } = await openEnable()
      pushState(state)
      await settled()
      expect(enableButton('confirm').disabled).toBe(true)
      enableButton('confirm').click()
      expect(dispatched).toHaveLength(0)
      expect(enableButton('cancel').disabled).toBe(false)
      enableButton('cancel').click()
      await settled()
      expect(enableModal()).toBeNull()
    },
  )

  it.each(['fail', 'timeout', 'disconnected', 'invalid-reply'] as const)(
    'keeps %s readable in the modal, releases Cancel and never retries',
    async (outcome) => {
      const { dispatched, pushState } = await openEnable()
      enableButton('confirm').click()
      await settled()
      // A changed state never answers the action, even when its eventual answer is a refusal.
      pushState(
        maintenanceState({
          phase: 'verifying',
          operation: 'manual_maintenance',
          manual: true,
        }),
      )
      const reason =
        outcome === 'fail'
          ? 'Another request is awaiting confirmation.'
          : 'No reply received.'
      dispatched[0]?.refuse(
        new ActionError('maintenance_enable', outcome, reason),
      )
      await settled()

      const message =
        outcome === 'fail'
          ? reason
          : HILOS_MAINTENANCE_ENABLE_COPY.unconfirmedNote
      expect(
        enableModal()?.querySelector('[data-id="hilos-action-error"]')
          ?.textContent,
      ).toContain(message)
      expect(
        enableModal()
          ?.querySelector('[data-id="hilos-action-error-slot"]')
          ?.getAttribute('aria-live'),
      ).toBe('assertive')
      expect(enableButton('cancel').disabled).toBe(false)
      expect(enableButton('confirm').disabled).toBe(true)
      expect(hilosToasts.toasts.get()).toMatchObject([
        { message, severity: 'error' },
      ])
      expect(dispatched).toHaveLength(1)
      enableButton('cancel').click()
      await settled()
      expect(enableModal()).toBeNull()
    },
  )

  it('shows the entire backend unconfirmed reason and admin details through the standard refusal plate', async () => {
    const { dispatched } = await openEnable()
    enableButton('confirm').click()
    await settled()
    const reason = `${HILOS_MAINTENANCE_ENABLE_COPY.unconfirmedNote} The agent did not observe ready.`
    dispatched[0]?.refuse(
      new ActionError(
        'maintenance_enable',
        'fail',
        reason,
        'MaintenanceFailure',
        'Original detail',
      ),
    )
    await settled()
    enableModal()
      ?.querySelector<HTMLButtonElement>(
        '[data-id="hilos-action-error-details"]',
      )
      ?.click()
    await settled()
    expect(document.body.textContent).toContain(reason)
    expect(document.body.textContent).toContain('MaintenanceFailure')
    expect(document.body.textContent).toContain('Original detail')
    expect(
      [...document.querySelectorAll('[role="dialog"]')].map((dialog) =>
        dialog.getAttribute('aria-label'),
      ),
    ).toContain(HILOS_MAINTENANCE_ENABLE_COPY.refusalTitle)
    expect(enableButton('cancel').disabled).toBe(false)
  })
})

/** The disable confirmation's body; its parent HilosModal teleports to the document. */
function disableModal(): HTMLElement | null {
  return document.querySelector('[data-id="hilos-maintenance-disable-modal"]')
}

/** A button of the disable confirmation, which must already be open. */
function disableButton(action: 'confirm' | 'cancel'): HTMLButtonElement {
  return document.querySelector(
    `[data-id="hilos-maintenance-disable-${action}"]`,
  ) as HTMLButtonElement
}

/** Mount the real page and open its disable confirmation from a server manual frame. */
async function openDisable() {
  const { actions, dispatched } = makeActions()
  const { context, pushState } = seededContext([], actions)
  const wrapper = await mountPage(context)
  pushState(
    maintenanceState({
      phase: 'verifying',
      operation: 'manual_maintenance',
      manual: true,
    }),
  )
  await nextTick()
  const opener = wrapper.get<HTMLButtonElement>(
    '[data-id="hilos-maintenance-disable"]',
  )
  opener.element.focus()
  opener.element.click()
  await settled()

  return { wrapper, pushState, dispatched, opener }
}

describe('HilosMaintenancePage disable confirmation', () => {
  it.each(['cancel', 'close', 'escape', 'backdrop'] as const)(
    'explains exit and allows %s before dispatch without sending anything',
    async (method) => {
      const { dispatched, opener } = await openDisable()
      const dialog = disableModal()?.closest<HTMLElement>(
        '[role="dialog"]',
      ) as HTMLElement
      expect(dialog.getAttribute('aria-label')).toBe(
        HILOS_MAINTENANCE_DISABLE_COPY.disableTitle,
      )
      expect(dialog.getAttribute('aria-modal')).toBe('true')
      expect(document.activeElement).toBe(dialog)
      expect(dialog.querySelector('input, textarea')).toBeNull()
      for (const text of [
        HILOS_MAINTENANCE_DISABLE_COPY.lead,
        HILOS_MAINTENANCE_DISABLE_COPY.footer,
      ])
        expect(dialog.textContent).toContain(text)
      expect(
        dialog.querySelector('[data-id="hilos-action-error-idle"]'),
      ).not.toBeNull()

      if (method === 'cancel') disableButton('cancel').click()
      if (method === 'close')
        dialog
          .querySelector<HTMLButtonElement>('[data-id="modal-close"]')
          ?.click()
      if (method === 'escape')
        dialog.dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        )
      if (method === 'backdrop') dialog.click()
      await settled()

      expect(disableModal()).toBeNull()
      expect(dispatched).toHaveLength(0)
      expect(document.activeElement).toBe(opener.element)
    },
  )

  it('blocks repeat and every close while busy, survives inactive push and closes on its one success', async () => {
    const { wrapper, pushState, dispatched } = await openDisable()
    const dialog = disableModal()?.closest<HTMLElement>(
      '[role="dialog"]',
    ) as HTMLElement
    disableButton('confirm').click()
    disableButton('confirm').click()
    await settled()

    expect(dispatched).toMatchObject([
      { action: 'maintenance_disable', payload: {} },
    ])
    expect(disableButton('confirm').getAttribute('aria-disabled')).toBe('true')
    expect(disableButton('cancel').disabled).toBe(true)
    disableButton('cancel').click()
    dialog.querySelector<HTMLButtonElement>('[data-id="modal-close"]')?.click()
    dialog.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    dialog.click()
    await settled()
    expect(disableModal()).not.toBeNull()
    dispatched[0]?.loading.set(true)
    await settled()
    expect(disableButton('confirm').getAttribute('aria-busy')).toBe('true')

    pushState(maintenanceState())
    await settled()
    expect(wrapper.find('[data-id="hilos-maintenance-disable"]').exists()).toBe(
      false,
    )
    expect(
      wrapper.find('[data-id="hilos-maintenance-state-open"]').exists(),
    ).toBe(true)
    expect(disableModal()?.closest('[role="dialog"]')).toBe(dialog)
    expect(disableButton('cancel').disabled).toBe(true)
    expect(hilosToasts.toasts.get()).toHaveLength(0)

    const message = 'Maintenance is off — pages are reloading'
    dispatched[0]?.settle({ message })
    await settled()
    expect(disableModal()).toBeNull()
    expect(hilosToasts.toasts.get()).toMatchObject([
      { message, severity: 'success', repeats: 1 },
    ])
    expect(dispatched).toHaveLength(1)
  })

  it.each([
    maintenanceState(),
    maintenanceState({ phase: 'active', operation: 'restore' }),
  ])(
    'blocks an unsent confirmation when the window is no longer manual: %j',
    async (state) => {
      const { pushState, dispatched } = await openDisable()
      pushState(state)
      await settled()
      expect(disableButton('confirm').disabled).toBe(true)
      disableButton('confirm').click()
      expect(dispatched).toHaveLength(0)
      expect(disableButton('cancel').disabled).toBe(false)
      disableButton('cancel').click()
      await settled()
      expect(disableModal()).toBeNull()
    },
  )

  it.each(['fail', 'timeout', 'disconnected', 'invalid-reply'] as const)(
    'keeps %s readable in the modal, releases Cancel and never retries',
    async (outcome) => {
      const { dispatched, pushState } = await openDisable()
      disableButton('confirm').click()
      await settled()
      // A changed state never answers the action, even when its eventual answer is a refusal.
      pushState(maintenanceState())
      const reason =
        outcome === 'fail'
          ? 'Another request is awaiting confirmation.'
          : 'No reply received.'
      dispatched[0]?.refuse(
        new ActionError('maintenance_disable', outcome, reason),
      )
      await settled()

      const message =
        outcome === 'fail'
          ? reason
          : HILOS_MAINTENANCE_DISABLE_COPY.unconfirmedNote
      expect(
        disableModal()?.querySelector('[data-id="hilos-action-error"]')
          ?.textContent,
      ).toContain(message)
      expect(
        disableModal()
          ?.querySelector('[data-id="hilos-action-error-slot"]')
          ?.getAttribute('aria-live'),
      ).toBe('assertive')
      expect(disableButton('cancel').disabled).toBe(false)
      expect(disableButton('confirm').disabled).toBe(true)
      expect(hilosToasts.toasts.get()).toMatchObject([
        { message, severity: 'error' },
      ])
      expect(dispatched).toHaveLength(1)
      disableButton('cancel').click()
      await settled()
      expect(disableModal()).toBeNull()
    },
  )

  it('shows the entire backend unconfirmed reason and admin details through the standard refusal plate', async () => {
    const { dispatched } = await openDisable()
    disableButton('confirm').click()
    await settled()
    const reason = `${HILOS_MAINTENANCE_DISABLE_COPY.unconfirmedNote} The agent did not observe inactive.`
    dispatched[0]?.refuse(
      new ActionError(
        'maintenance_disable',
        'fail',
        reason,
        'MaintenanceFailure',
        'Original detail',
      ),
    )
    await settled()
    disableModal()
      ?.querySelector<HTMLButtonElement>(
        '[data-id="hilos-action-error-details"]',
      )
      ?.click()
    await settled()
    expect(document.body.textContent).toContain(reason)
    expect(document.body.textContent).toContain('MaintenanceFailure')
    expect(document.body.textContent).toContain('Original detail')
    expect(
      [...document.querySelectorAll('[role="dialog"]')].map((dialog) =>
        dialog.getAttribute('aria-label'),
      ),
    ).toContain(HILOS_MAINTENANCE_DISABLE_COPY.refusalTitle)
    expect(disableButton('cancel').disabled).toBe(false)
  })
})

/** The pass confirmation's body; its parent HilosModal teleports to the document. */
function passModal(): HTMLElement | null {
  return document.querySelector('[data-id="hilos-maintenance-pass-modal"]')
}

/** A button of the pass confirmation, which must already be open. */
function passButton(action: 'confirm' | 'cancel'): HTMLButtonElement {
  return document.querySelector(
    `[data-id="hilos-maintenance-pass-${action}"]`,
  ) as HTMLButtonElement
}

/** Mount the real page and open its pass confirmation from a server manual frame. */
async function openPass() {
  const { actions, dispatched } = makeActions()
  const { context, pushState } = seededContext([], actions)
  const wrapper = await mountPage(context)
  pushState(
    maintenanceState({
      phase: 'verifying',
      operation: 'manual_maintenance',
      manual: true,
    }),
  )
  await nextTick()
  const opener = wrapper.get<HTMLButtonElement>(
    '[data-id="hilos-maintenance-pass"]',
  )
  opener.element.focus()
  opener.element.click()
  await settled()

  return { wrapper, pushState, dispatched, opener }
}

describe('HilosMaintenancePage pass confirmation', () => {
  it.each(['cancel', 'close', 'escape', 'backdrop'] as const)(
    'explains issuance and allows %s before dispatch without sending anything',
    async (method) => {
      const { dispatched, opener } = await openPass()
      const dialog = passModal()?.closest<HTMLElement>(
        '[role="dialog"]',
      ) as HTMLElement
      expect(dialog.getAttribute('aria-label')).toBe(
        HILOS_MAINTENANCE_PASS_COPY.mintTitle,
      )
      expect(dialog.getAttribute('aria-modal')).toBe('true')
      expect(document.activeElement).toBe(dialog)
      expect(dialog.querySelector('input, textarea')).toBeNull()
      for (const text of [
        HILOS_MAINTENANCE_PASS_COPY.lead,
        HILOS_MAINTENANCE_PASS_COPY.reuseNote,
      ])
        expect(dialog.textContent).toContain(text)
      expect(
        dialog.querySelector('[data-id="hilos-action-error-idle"]'),
      ).not.toBeNull()

      if (method === 'cancel') passButton('cancel').click()
      if (method === 'close')
        dialog
          .querySelector<HTMLButtonElement>('[data-id="modal-close"]')
          ?.click()
      if (method === 'escape')
        dialog.dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        )
      if (method === 'backdrop') dialog.click()
      await settled()

      expect(passModal()).toBeNull()
      expect(dispatched).toHaveLength(0)
      expect(document.activeElement).toBe(opener.element)
    },
  )

  it('blocks repeat and every close while busy, survives inactive push and reveals a result only on its one success', async () => {
    const { wrapper, pushState, dispatched } = await openPass()
    const dialog = passModal()?.closest<HTMLElement>(
      '[role="dialog"]',
    ) as HTMLElement
    passButton('confirm').click()
    passButton('confirm').click()
    await settled()

    expect(dispatched).toMatchObject([
      { action: 'maintenance_pass', payload: {} },
    ])
    expect(passButton('confirm').getAttribute('aria-disabled')).toBe('true')
    expect(passButton('cancel').disabled).toBe(true)
    passButton('cancel').click()
    dialog.querySelector<HTMLButtonElement>('[data-id="modal-close"]')?.click()
    dialog.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    dialog.click()
    await settled()
    expect(passModal()).not.toBeNull()
    dispatched[0]?.loading.set(true)
    await settled()
    expect(passButton('confirm').getAttribute('aria-busy')).toBe('true')

    pushState(maintenanceState())
    await settled()
    expect(wrapper.find('[data-id="hilos-maintenance-pass"]').exists()).toBe(
      false,
    )
    expect(
      wrapper.find('[data-id="hilos-maintenance-state-open"]').exists(),
    ).toBe(true)
    expect(passModal()?.closest('[role="dialog"]')).toBe(dialog)
    expect(passButton('cancel').disabled).toBe(true)
    expect(hilosToasts.toasts.get()).toHaveLength(0)

    const message = 'Code issued'
    dispatched[0]?.settle({ message, reply: { pass: 'opaque-secret' } })
    await settled()
    expect(passModal()?.textContent).toContain('opaque-secret')
    expect(
      document.querySelector('[data-id="hilos-maintenance-pass-result"]'),
    ).not.toBeNull()
    expect(hilosToasts.toasts.get()).toMatchObject([
      { message, severity: 'success', repeats: 1 },
    ])
    expect(dispatched).toHaveLength(1)
  })

  it.each([
    maintenanceState(),
    maintenanceState({ phase: 'active', operation: 'restore' }),
  ])(
    'blocks an unsent confirmation when the window is no longer manual: %j',
    async (state) => {
      const { pushState, dispatched } = await openPass()
      pushState(state)
      await settled()
      expect(passButton('confirm').disabled).toBe(true)
      passButton('confirm').click()
      expect(dispatched).toHaveLength(0)
      expect(passButton('cancel').disabled).toBe(false)
      passButton('cancel').click()
      await settled()
      expect(passModal()).toBeNull()
    },
  )

  it.each(['fail', 'timeout', 'disconnected', 'invalid-reply'] as const)(
    'keeps %s readable in the modal, releases Cancel and never retries',
    async (outcome) => {
      const { dispatched, pushState } = await openPass()
      passButton('confirm').click()
      await settled()
      // A changed state never answers the action, even when its eventual answer is a refusal.
      pushState(maintenanceState())
      const reason =
        outcome === 'fail'
          ? 'Another request is awaiting confirmation.'
          : 'No reply received.'
      dispatched[0]?.refuse(
        new ActionError('maintenance_pass', outcome, reason),
      )
      await settled()

      const message =
        outcome === 'fail'
          ? reason
          : HILOS_MAINTENANCE_PASS_COPY.unconfirmedNote
      expect(
        passModal()?.querySelector('[data-id="hilos-action-error"]')
          ?.textContent,
      ).toContain(message)
      expect(
        passModal()
          ?.querySelector('[data-id="hilos-action-error-slot"]')
          ?.getAttribute('aria-live'),
      ).toBe('assertive')
      expect(passButton('cancel').disabled).toBe(false)
      expect(passButton('confirm').disabled).toBe(true)
      expect(hilosToasts.toasts.get()).toMatchObject([
        { message, severity: 'error' },
      ])
      expect(dispatched).toHaveLength(1)
      passButton('cancel').click()
      await settled()
      expect(passModal()).toBeNull()
    },
  )

  it('shows the entire backend unconfirmed reason and admin details through the standard refusal plate', async () => {
    const { dispatched } = await openPass()
    passButton('confirm').click()
    await settled()
    const reason = `${HILOS_MAINTENANCE_PASS_COPY.unconfirmedNote} The agent did not observe inactive.`
    dispatched[0]?.refuse(
      new ActionError(
        'maintenance_pass',
        'fail',
        reason,
        'MaintenanceFailure',
        'Original detail',
      ),
    )
    await settled()
    passModal()
      ?.querySelector<HTMLButtonElement>(
        '[data-id="hilos-action-error-details"]',
      )
      ?.click()
    await settled()
    expect(document.body.textContent).toContain(reason)
    expect(document.body.textContent).toContain('MaintenanceFailure')
    expect(document.body.textContent).toContain('Original detail')
    expect(
      [...document.querySelectorAll('[role="dialog"]')].map((dialog) =>
        dialog.getAttribute('aria-label'),
      ),
    ).toContain(HILOS_MAINTENANCE_PASS_COPY.refusalTitle)
    expect(passButton('cancel').disabled).toBe(false)
  })
})

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

  it('disables enable for a viewer and after a live revoke while confirmation is open', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { actions, dispatched } = makeActions()
    const { context, pushState } = seededContext([], actions)
    const wrapper = await mountPage(context)
    pushState(maintenanceState())
    await settled()
    const button = wrapper.get<HTMLButtonElement>(
      '[data-id="hilos-maintenance-enable"]',
    ).element
    expect(button.disabled).toBe(true)
    expect(button.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    button.click()
    await settled()
    expect(enableModal()).toBeNull()

    handshake({ id: 7, admin: true }, true)
    await settled()
    expect(button.disabled).toBe(false)
    button.click()
    await settled()
    expect(enableButton('confirm').disabled).toBe(false)
    handshake({ id: 7, admin: false }, true)
    await settled()
    expect(enableButton('confirm').disabled).toBe(true)
    enableButton('confirm').click()
    expect(dispatched).toHaveLength(0)
    expect(enableButton('cancel').disabled).toBe(false)
  })

  it('disables disable for a viewer and after a live revoke while confirmation is open', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { actions, dispatched } = makeActions()
    const { context, pushState } = seededContext([], actions)
    const wrapper = await mountPage(context)
    pushState(
      maintenanceState({
        phase: 'verifying',
        operation: 'manual_maintenance',
        manual: true,
      }),
    )
    await settled()
    const button = wrapper.get<HTMLButtonElement>(
      '[data-id="hilos-maintenance-disable"]',
    ).element
    expect(button.disabled).toBe(true)
    expect(button.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    button.click()
    await settled()
    expect(disableModal()).toBeNull()

    handshake({ id: 7, admin: true }, true)
    await settled()
    expect(button.disabled).toBe(false)
    button.click()
    await settled()
    expect(disableButton('confirm').disabled).toBe(false)
    handshake({ id: 7, admin: false }, true)
    await settled()
    expect(disableButton('confirm').disabled).toBe(true)
    disableButton('confirm').click()
    expect(dispatched).toHaveLength(0)
    expect(disableButton('cancel').disabled).toBe(false)
  })

  it('disables code issuance for a viewer and after a live revoke while confirmation is open', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { actions, dispatched } = makeActions()
    const { context, pushState } = seededContext([], actions)
    const wrapper = await mountPage(context)
    pushState(
      maintenanceState({
        phase: 'verifying',
        operation: 'manual_maintenance',
        manual: true,
      }),
    )
    await settled()
    const button = wrapper.get<HTMLButtonElement>(
      '[data-id="hilos-maintenance-pass"]',
    ).element
    expect(button.disabled).toBe(true)
    expect(button.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    button.click()
    await settled()
    expect(passModal()).toBeNull()

    handshake({ id: 7, admin: true }, true)
    await settled()
    expect(button.disabled).toBe(false)
    button.click()
    await settled()
    expect(passButton('confirm').disabled).toBe(false)
    handshake({ id: 7, admin: false }, true)
    await settled()
    expect(passButton('confirm').disabled).toBe(true)
    passButton('confirm').click()
    expect(dispatched).toHaveLength(0)
    expect(passButton('cancel').disabled).toBe(false)
  })

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

describe('HilosMaintenancePage code result', () => {
  it.each(['cancel', 'close', 'escape', 'backdrop'] as const)(
    'forgets the secret on %s, returns focus and opens a fresh confirmation',
    async (method) => {
      const { dispatched, opener } = await openPass()
      passButton('confirm').click()
      await settled()
      const secret = '<opaque&code>'
      dispatched[0]?.settle({ message: 'Code issued', reply: { pass: secret } })
      await settled()
      const dialog = passModal()?.closest<HTMLElement>(
        '[role="dialog"]',
      ) as HTMLElement
      const value = dialog.querySelector<HTMLElement>(
        '[data-id="hilos-maintenance-pass-value"]',
      )
      expect(value?.textContent).toBe(secret)
      expect(value?.querySelector('opaque')).toBeNull()
      expect(document.activeElement).toBe(value)
      expect(dialog.getAttribute('aria-label')).toBe(
        HILOS_MAINTENANCE_PASS_COPY.codeTitle,
      )
      expect(passButton('cancel').textContent?.trim()).toBe('Close')
      expect(hilosToasts.toasts.get()).toMatchObject([
        { message: 'Code issued' },
      ])
      expect(JSON.stringify(hilosToasts.toasts.get())).not.toContain(secret)
      expect(
        JSON.stringify({ ...localStorage, ...sessionStorage }),
      ).not.toContain(secret)
      expect(window.location.href).not.toContain(secret)
      if (method === 'cancel') passButton('cancel').click()
      if (method === 'close')
        dialog
          .querySelector<HTMLButtonElement>('[data-id="modal-close"]')
          ?.click()
      if (method === 'escape')
        dialog.dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        )
      if (method === 'backdrop') dialog.click()
      await settled()
      expect(passModal()).toBeNull()
      expect(document.body.textContent).not.toContain(secret)
      expect(document.activeElement).toBe(opener.element)
      opener.element.click()
      await settled()
      expect(passModal()?.textContent).not.toContain(secret)
      expect(
        document.querySelector('[data-id="hilos-maintenance-pass-result"]'),
      ).toBeNull()
      expect(passButton('confirm').disabled).toBe(false)
      expect(dispatched).toHaveLength(1)
      passButton('confirm').click()
      await settled()
      expect(dispatched).toHaveLength(2)
      dispatched[1]?.settle({ reply: { pass: 'new-code' } })
      await settled()
      expect(passModal()?.textContent).toContain('new-code')
      expect(passModal()?.textContent).not.toContain(secret)
    },
  )

  it('forgets a shown result on unmount', async () => {
    const { wrapper, dispatched } = await openPass()
    passButton('confirm').click()
    dispatched[0]?.settle({ reply: { pass: 'departed-code' } })
    await settled()
    expect(passModal()?.textContent).toContain('departed-code')
    wrapper.unmount()
    await settled()
    expect(document.body.textContent).not.toContain('departed-code')
    expect(passModal()).toBeNull()
  })

  it('cannot resurrect a result from a promise after unmount', async () => {
    const { wrapper, dispatched } = await openPass()
    passButton('confirm').click()
    await settled()
    wrapper.unmount()
    dispatched[0]?.settle({ reply: { pass: 'late-code' } })
    await settled()
    expect(document.body.textContent).not.toContain('late-code')
    expect(passModal()).toBeNull()
    const reopened = await openPass()
    expect(passModal()?.textContent).not.toContain('late-code')
    expect(reopened.dispatched).toHaveLength(0)
  })

  it('a late success after timeout cannot replace a newly opened confirmation', async () => {
    const { dispatched, opener } = await openPass()
    passButton('confirm').click()
    await settled()
    dispatched[0]?.refuse(
      new ActionError('maintenance_pass', 'timeout', 'Timed out'),
    )
    await settled()
    expect(passModal()?.textContent).toContain(
      HILOS_MAINTENANCE_PASS_COPY.unconfirmedNote,
    )
    passButton('cancel').click()
    await settled()
    opener.element.click()
    await settled()
    dispatched[0]?.settle({ reply: { pass: 'orphan-code' } })
    await settled()
    expect(document.body.textContent).not.toContain('orphan-code')
    expect(
      document.querySelector('[data-id="hilos-maintenance-pass-result"]'),
    ).toBeNull()
    expect(dispatched).toHaveLength(1)
  })
})
