import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionError,
  HILOS_MAINTENANCE_CIRCLE_COPY,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
} from '@hilos/core'
import type {
  ActionHandle,
  ActionLifecycle,
  ActionResult,
  HilosConnection,
  HilosMaintenanceContext,
  HilosRouter,
  PageRouteMatch,
  ProjectSignal,
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
  focus: string[]
  pushUpdate: (next: CircleMember) => void
  pushRemove: (memberId: number, own?: boolean) => void
} {
  const rows = initial.slice()
  const focus: string[] = []
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
