import { act, cleanup, fireEvent, render } from '@testing-library/react'
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

import { HilosMaintenancePage } from '../src/admin/maintenance/HilosMaintenancePage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

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

afterEach(() => {
  cleanup()
  document.body.classList.remove('modal-open')
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

/** Open the add dialog and enter the address without normalizing it. */
function openAndType(typed: string): void {
  fireEvent.click(
    document.querySelector(
      '[data-id="hilos-maintenance-circle-add"]',
    ) as HTMLButtonElement,
  )
  fireEvent.change(addField() as HTMLInputElement, { target: { value: typed } })
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
      HILOS_MAINTENANCE_CIRCLE_COPY.empty,
    )
  })

  it('offers adding a member and removing one', async () => {
    const { context } = seededContext([ANN])
    const wrapper = await mountPage(context)
    const panel = wrapper.container.querySelector(
      '[data-id="hilos-maintenance-circle-panel"]',
    )

    expect(
      panel?.querySelector('[data-id="hilos-maintenance-circle-add"]')
        ?.textContent,
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
        '[data-id="hilos-maintenance-circle-add"]',
      ) as HTMLButtonElement,
    )

    expect(addField()?.value).toBe('')
    expect(document.body.textContent).not.toContain(
      'Nobody has proven this address',
    )
  })
})
