import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, screen, within } from '@testing-library/react'
import {
  ActionLifecycle,
  HILOS_CHANGE_LOG_RECEIPT_READ,
  CHANGE_LOG_TABLE_COPY as copy,
  HilosPages,
  bindAdminAccess,
  bindSessionScope,
  hilosAdminAccess,
} from '@hilos/core'
import type {
  ActionErrorSignal,
  ActionLifecycleSource,
  ActionSuccessSignal,
  ConnectionState,
} from '@hilos/core'

import { HilosChangeLogTableHistoryPage } from '../src/admin/changeLog/HilosChangeLogTableHistoryPage.js'
import {
  BOT,
  answerTable,
  mountMetadataPage,
  tableHarness,
  tableRouter,
} from './helpers/changeLogTable.js'

const PAGE = HilosPages.CHANGE_LOG_TABLE_HISTORY
const HISTORY = 'hilosChangeLogHistory'
function historySlot(overrides: Record<string, unknown> = {}) {
  return {
    createdAt: '2026-10-09T10:11:12.123Z',
    recordKey: [57],
    mutation: 'update',
    changes: [
      {
        field: 'description',
        kind: 'inline',
        oldPresent: true,
        newPresent: true,
        oldValue: 'before',
        newValue: 'after',
        bodyOmitted: false,
      },
    ],
    receiptId: 7,
    actorId: 3,
    actorLabel: 'Ada',
    actorDeleted: false,
    subjectId: null,
    subjectLabel: null,
    subjectDeleted: false,
    channel: 'web',
    ...overrides,
  }
}

const ROWS = [
  { rowKey: '101', slots: { history: historySlot() } },
  {
    rowKey: '102',
    slots: {
      history: historySlot({
        recordKey: [null],
        mutation: 'delete',
        changes: [],
        receiptId: null,
        actorId: null,
        actorLabel: null,
        channel: null,
      }),
    },
  },
]

interface Events {
  actionSuccess: ActionSuccessSignal
  actionError: ActionErrorSignal
  state: ConnectionState
}

/** Exercise the real tracked lifecycle the receipt reader sends through. */
class Source implements ActionLifecycleSource {
  connected = true
  readonly sent: { action: string; data: unknown; requestId?: string }[] = []
  private readonly listeners: {
    [E in keyof Events]: ((payload: Events[E]) => void)[]
  } = { actionSuccess: [], actionError: [], state: [] }

  sendAction(action: string, data: unknown, requestId?: string): boolean {
    this.sent.push({ action, data, requestId })
    return this.connected
  }

  on<E extends keyof Events>(
    event: E,
    listener: (payload: Events[E]) => void,
  ): () => void {
    this.listeners[event].push(listener)
    return () => {
      const index = this.listeners[event].indexOf(listener)
      if (index !== -1) this.listeners[event].splice(index, 1)
    }
  }

  success(index: number, reply?: unknown): void {
    const request = this.sent[index]!
    for (const listener of this.listeners.actionSuccess) {
      listener({
        kind: 'actionSuccess',
        action: request.action,
        requestId: request.requestId,
        reply,
        message: undefined,
        envelope: { type: 'action_success', data: {} },
      })
    }
  }
}

function harness(section: unknown = BOT, answered = true) {
  const h = tableHarness(section, PAGE, answered)
  const source = new Source()
  return {
    ...h,
    context: { ...h.context, actions: new ActionLifecycle(source) },
    source,
    pushWindow(rows = ROWS) {
      h.pushMetadataWindow(PAGE, HISTORY, rows)
    },
    pushPageWindow() {
      h.pushMetadataWindow(PAGE, HISTORY, [], true, {
        sort: [{ field: 'createdAt', direction: 'desc' }],
      })
    },
  }
}

function router(
  h: ReturnType<typeof harness>,
  params: Record<string, string> = { tableId: 'bot' },
) {
  const r = tableRouter(h, params)
  vi.spyOn(r.router, 'resolvePath')
  const leaves = new Set<() => void>()
  r.router.onLeave = (listener) => {
    leaves.add(listener)
    return () => {
      leaves.delete(listener)
    }
  }
  return {
    ...r,
    value: r.router,
    go(next: Record<string, string>) {
      leaves.forEach((listener) => listener())
      r.route.set({ page: PAGE, params: next, admin: true })
    },
  }
}

const releases: (() => void)[] = []

async function settle(): Promise<void> {
  await act(async () => {
    await Promise.resolve()
  })
}

function mountPage(h = harness(), r = router(h), push = true) {
  const result = mountMetadataPage(HilosChangeLogTableHistoryPage, h, r)
  if (push) act(() => h.pushWindow())
  return { ...result, r }
}

function element(id: string, root: ParentNode = document): HTMLElement {
  const found = root.querySelector(`[data-id="${id}"]`)
  expect(found).not.toBeNull()
  return found as HTMLElement
}

function get(root: ParentNode, selector: string): HTMLElement {
  const found = root.querySelector(selector)
  expect(found).not.toBeNull()
  return found as HTMLElement
}

function text(node: Element): string {
  return (node.textContent ?? '').replace(/\s+/g, ' ').trim()
}

describe('HilosChangeLogTableHistoryPage', () => {
  afterEach(() => {
    cleanup()
    for (const release of releases.splice(0)) release()
  })

  it('builds the history with the table preset and links unmasked records', () => {
    const h = harness()
    const { container, r } = mountPage(h)
    const heading = screen.getByRole('heading', { name: 'bot', level: 1 })
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
    expect(screen.queryByRole('heading', { name: 'History' })).toBeNull()
    expect(
      screen
        .getByRole('table', { name: 'bot' })
        .getAttribute('aria-labelledby'),
    ).toBe(heading.id)
    expect(
      screen.getByRole('list', { name: 'bot' }).getAttribute('aria-labelledby'),
    ).toBe(heading.id)
    expect(text(element('hilos-table-subtitle', container))).toBe(
      'All records of this table',
    )
    expect(
      container.querySelectorAll('[data-id="hilos-table-subtitle"]'),
    ).toHaveLength(1)
    expect(container.querySelector('[data-id="hilos-table-title"]')).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-change-log-table-overview"]'),
    ).toBeNull()
    const trail = screen.getByRole('navigation', { name: 'Breadcrumb' })
    expect(
      within(trail)
        .getAllByRole('link')
        .map((link) => [text(link), link.getAttribute('href')]),
    ).toEqual([
      ['Hilos', '/hilos'],
      ['Change Log', '/hilos/change-log'],
      ['Tracked tables', '/hilos/change-log/tables'],
    ])
    expect(trail.querySelectorAll('[aria-current="page"]')).toHaveLength(1)
    expect(trail.querySelector('[aria-current="page"]')?.textContent).toContain(
      'bot',
    )
    const tabs = screen.getByRole('navigation', { name: 'Table pages' })
    expect(
      within(tabs)
        .getAllByRole('link')
        .map((link) => [text(link), link.getAttribute('href')]),
    ).toEqual([
      ['Overview', '/hilos/change-log/tables/bot'],
      ['Columns', '/hilos/change-log/tables/bot/columns'],
      ['Triggers', '/hilos/change-log/tables/bot/triggers'],
      ['History', '/hilos/change-log/tables/bot/history'],
    ])
    expect(tabs.querySelectorAll('[aria-current="page"]')).toHaveLength(1)
    expect(
      within(tabs)
        .getByRole('link', { name: 'History' })
        .getAttribute('aria-current'),
    ).toBe('page')
    expect(h.connection.registerTableWindow).toHaveBeenCalledWith(
      HISTORY,
      expect.anything(),
    )
    expect(container.textContent).toContain('All records of this table')
    expect(
      container.querySelector('[data-id="hilos-change-log-record-filter"]'),
    ).toBeNull()

    const row = get(container, 'table [data-id="hilos-table-row-101"]')
    const link = element('hilos-change-log-history-record-101', row)
    expect(text(link)).toBe('#57')
    expect(link.getAttribute('href')).toBe(
      '/hilos/change-log/tables/bot/history/57',
    )
    expect(r.value.resolvePath).toHaveBeenCalledWith(
      HilosPages.CHANGE_LOG_TABLE_HISTORY,
      { tableId: 'bot', record: '57' },
    )
    act(() => link.click())
    expect(r.value.navigate).toHaveBeenCalledWith(
      '/hilos/change-log/tables/bot/history/57',
    )
    expect(row.textContent).toContain('updated')
    expect(row.querySelector('code')?.textContent).toBe('description')
    expect(within(row).getByText('before').textContent).toBe('before')
    expect(row.querySelector('strong')?.textContent).toBe('after')
    expect(row.textContent).toContain('Ada')
    expect(row.textContent).toContain('Web')
    expect(text(element('hilos-change-log-history-receipt-101', row))).toBe(
      'Receipt',
    )

    const masked = get(container, 'table [data-id="hilos-table-row-102"]')
    expect(
      masked.querySelector('[data-id="hilos-change-log-history-record-102"]'),
    ).toBeNull()
    expect(masked.querySelector('a')).toBeNull()
    expect(masked.textContent).toContain('#?')
    expect(masked.textContent).toContain('deleted')
    expect(masked.textContent).toContain('Row snapshot')
    expect(masked.textContent).toContain('Unknown')
    expect(masked.textContent).toContain(copy.pastApplication)
    expect(masked.textContent).toContain(copy.noReceipt)
    expect(
      masked.querySelector('[data-id="hilos-change-log-history-receipt-102"]'),
    ).toBeNull()
  })

  it('renders operations and each field kind without exposing personal or long bodies', () => {
    const h = harness()
    const { container } = mountPage(h, router(h), false)
    const change = {
      field: 'name',
      kind: 'inline',
      oldPresent: true,
      newPresent: true,
      oldValue: '<b>before</b>',
      newValue: '<img src=x>',
      bodyOmitted: false,
    }
    const changes = [
      change,
      {
        ...change,
        field: 'email',
        kind: 'fact',
        oldValue: 'private old',
        newValue: 'private new',
      },
      {
        ...change,
        field: 'description',
        kind: 'long',
        oldValue: 'long old body',
        newValue: 'long new body',
      },
    ]
    act(() =>
      h.pushWindow([
        { rowKey: '201', slots: { history: historySlot({ changes }) } },
        {
          rowKey: '202',
          slots: { history: historySlot({ mutation: 'create', changes }) },
        },
        {
          rowKey: '203',
          slots: { history: historySlot({ mutation: 'delete', changes }) },
        },
      ]),
    )
    const update = get(container, 'table [data-id="hilos-table-row-201"]')
    const fields = Array.from(update.querySelectorAll('code'))
    expect(fields.map((field) => field.textContent)).toEqual([
      'name',
      'email',
      'description',
    ])
    const inline = fields[0]!.parentElement!
    expect(within(inline).getByText('<b>before</b>').textContent).toBe(
      '<b>before</b>',
    )
    expect(inline.querySelector('strong')?.textContent).toBe('<img src=x>')
    expect(
      Array.from(
        inline.querySelectorAll('.visually-hidden'),
        (label) => label.textContent,
      ),
    ).toEqual(['Was', 'Now'])
    const fact = fields[1]!.parentElement!
    expect(fact.textContent).toContain('changed')
    expect(fact.textContent).toContain('Personal — no value')
    expect(fact.querySelector('strong')).toBeNull()
    expect(fields[2]!.parentElement!.textContent).toContain('long text changed')
    for (const value of [
      'private old',
      'private new',
      'long old body',
      'long new body',
    ])
      expect(container.textContent).not.toContain(value)
    expect(container.querySelector('b, img, pre')).toBeNull()
    for (const [key, label, message] of [
      ['201', 'updated', null],
      ['202', 'created', 'Created as a fact'],
      ['203', 'deleted', 'Row snapshot — in the receipt'],
    ]) {
      const row = get(container, `table [data-id="hilos-table-row-${key}"]`)
      const operation = within(row).getByText(label!)
      expect(operation.querySelector('i')?.getAttribute('aria-hidden')).toBe(
        'true',
      )
      if (message) {
        expect(row.textContent).toContain(message)
        expect(row.querySelector('code, strong')).toBeNull()
      }
    }
    const cards = screen.getByRole('list', { name: 'bot' })
    expect(cards.textContent).toContain('long text changed')
    expect(cards.textContent).toContain('Personal — no value')
    expect(cards.querySelector('strong')?.textContent).toBe('<img src=x>')
    for (const icon of container.querySelectorAll('i'))
      expect(icon.closest('[aria-hidden="true"]')).not.toBeNull()
  })

  it('preserves absent, NULL, empty and omitted inline sides from the projection', () => {
    const h = harness()
    const { container } = mountPage(h, router(h), false)
    const change = {
      kind: 'inline',
      oldPresent: true,
      newPresent: true,
      oldValue: null,
      newValue: '',
      bodyOmitted: false,
    }
    act(() =>
      h.pushWindow([
        {
          rowKey: '301',
          slots: {
            history: historySlot({
              changes: [
                { ...change, field: 'nullable' },
                {
                  ...change,
                  field: 'added',
                  oldPresent: false,
                  newValue: 'yes',
                },
                {
                  ...change,
                  field: 'removed',
                  newPresent: false,
                  oldValue: 'was there',
                },
                {
                  ...change,
                  field: 'omitted',
                  oldValue: 'raw old',
                  newValue: 'raw new',
                  bodyOmitted: true,
                },
              ],
            }),
          },
        },
      ]),
    )
    const row = get(container, 'table [data-id="hilos-table-row-301"]')
    const lines = Array.from(
      row.querySelectorAll('code'),
      (code) => code.parentElement!,
    )
    for (const [index, oldValue, newValue] of [
      [0, 'NULL', 'Empty string'],
      [1, 'Absent', 'yes'],
      [2, 'was there', 'Absent'],
      [3, 'Value omitted', 'Value omitted'],
    ] as const) {
      expect(
        lines[index]!.querySelector('span:not(.visually-hidden)')?.textContent,
      ).toBe(oldValue)
      expect(lines[index]!.querySelector('strong')?.textContent).toBe(newValue)
    }
    expect(container.textContent).not.toContain('raw old')
    expect(container.textContent).not.toContain('raw new')
  })

  it('waits for the answered shell and releases the name on route, scope, page and unmount changes', () => {
    const h = harness(BOT, false)
    const { container, r, unmount } = mountPage(h, router(h), false)
    expect(
      element('hilos-page-head-skeleton', container).getAttribute(
        'aria-hidden',
      ),
    ).toBe('true')
    expect(screen.queryByRole('heading')).toBeNull()
    expect(screen.queryByRole('navigation')).toBeNull()
    expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
    act(() => answerTable(h.scope))
    expect(screen.getByRole('heading', { level: 1, name: 'bot' })).toBeTruthy()
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(1)
    act(() => r.go({ tableId: 'other' }))
    expect(r.value.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Tracked table',
    )
    expect(
      container.querySelector('[data-id="hilos-viewport-table"]'),
    ).toBeNull()
    act(() => answerTable(h.context.scopes.page()!, { ...BOT, name: 'other' }))
    expect(
      screen.getByRole('heading', { level: 1, name: 'other' }),
    ).toBeTruthy()
    act(() => h.context.scopes.openPage(PAGE))
    expect(r.value.pageIdentity.get()).toBeUndefined()
    expect(screen.queryByRole('heading')).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-viewport-table"]'),
    ).toBeNull()
    act(() => answerTable(h.context.scopes.page()!, { ...BOT, name: 'other' }))
    expect(
      screen.getByRole('heading', { level: 1, name: 'other' }),
    ).toBeTruthy()
    act(() =>
      r.route.set({
        page: HilosPages.CHANGE_LOG_TABLE_COLUMNS,
        params: { tableId: 'other' },
        admin: true,
      }),
    )
    expect(r.value.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Tracked table',
    )
    expect(
      container.querySelector('[data-id="hilos-viewport-table"]'),
    ).toBeNull()
    act(() =>
      r.route.set({ page: PAGE, params: { tableId: 'other' }, admin: true }),
    )
    expect(r.value.pageIdentity.get()?.pageHeadOwner?.label).toBe('other')
    unmount()
    expect(r.value.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Tracked table',
    )
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
  })

  it.each(['identity', 'scope'] as const)(
    'closes a pending receipt on a new %s and ignores its late reply',
    async (change) => {
      const h = harness()
      mountPage(h)
      act(() =>
        element(
          'hilos-change-log-history-receipt-101',
          screen.getByRole('table', { name: 'bot' }),
        ).click(),
      )
      await settle()
      expect(element('modal').getAttribute('aria-label')).toBe('Receipt #7')
      act(() => {
        if (change === 'identity')
          h.scope.data.set('pageLabel', 'Changed identity')
        else answerTable(h.context.scopes.openPage(PAGE))
      })
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      act(() => h.source.success(0))
      await settle()
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
    },
  )

  it('narrows to the address record behind a plate whose × leaves the record', () => {
    const h = harness()
    const { container, r } = mountPage(
      h,
      router(h, { tableId: 'bot', record: '57' }),
      false,
    )
    act(() => h.pushPageWindow())
    expect(h.connection.sendTableViewport).toHaveBeenCalledWith(
      HilosPages.CHANGE_LOG_TABLE_HISTORY,
      HISTORY,
      expect.objectContaining({
        filter: expect.objectContaining({ table: 'bot', record: ['57'] }),
      }),
    )
    const plate = element('hilos-change-log-record-filter', container)
    expect(text(plate)).toContain('Only record #57')
    expect(container.textContent).not.toContain('All records of this table')
    const clear = element('hilos-change-log-record-filter-clear', plate)
    expect(clear.getAttribute('href')).toBe(
      '/hilos/change-log/tables/bot/history',
    )
    expect(clear.getAttribute('aria-label')).toBe('Show all records')
    expect(get(clear, 'i').getAttribute('aria-hidden')).toBe('true')
    act(() => clear.click())
    expect(r.value.navigate).toHaveBeenCalledWith(
      '/hilos/change-log/tables/bot/history',
    )
  })

  it('rebuilds the history window when the address gains a record', async () => {
    const h = harness()
    const { container, r } = mountPage(h)
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(2)
    act(() => r.go({ tableId: 'bot', record: '57' }))
    await settle()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledWith(
      HISTORY,
      h.connection.registerTableWindow.mock.calls[1]?.[1],
    )
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(3)
    act(() => h.pushPageWindow())
    expect(h.connection.sendTableViewport).toHaveBeenCalledTimes(1)
    expect(h.connection.sendTableViewport).toHaveBeenLastCalledWith(
      HilosPages.CHANGE_LOG_TABLE_HISTORY,
      HISTORY,
      expect.objectContaining({
        filter: expect.objectContaining({ table: 'bot', record: ['57'] }),
      }),
    )
    expect(
      text(element('hilos-change-log-record-filter', container)),
    ).toContain('Only record #57')
  })

  it('opens the row receipt through the Change Log reading action and closes it on an address change', async () => {
    const h = harness()
    const { r } = mountPage(h)
    act(() =>
      element(
        'hilos-change-log-history-receipt-101',
        document.querySelector('[data-id="hilos-viewport-table"] table')!,
      ).click(),
    )
    await settle()
    expect(h.source.sent[0]).toMatchObject({
      action: HILOS_CHANGE_LOG_RECEIPT_READ,
      data: { receiptId: 7, afterEntryId: null },
    })
    expect(element('modal').getAttribute('aria-label')).toBe('Receipt #7')
    act(() => r.go({ tableId: 'bot', record: '57' }))
    await settle()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it.each(['full', 'view'] as const)(
    'shows the same table under %s access and hides people from a viewer',
    (access) => {
      const h = harness()
      bindSessionScope(h.context.connection, h.context.scopes)
      releases.push(bindAdminAccess(h.context.scopes))
      h.emit('projectSignal', {
        type: 'handshake_response',
        data: {
          entities: {
            currentUser: { id: 9, name: 'Ada', admin: access === 'full' },
          },
          data: { adminViewMode: true },
        },
      })
      expect(hilosAdminAccess.get()).toBe(access)
      const { container } = mountPage(h, router(h, { tableId: 'bot' }), false)
      act(() =>
        h.pushWindow([
          {
            rowKey: '101',
            slots: {
              history: historySlot({
                actorLabel: access === 'view' ? { _hidden: true } : 'Ada',
                subjectId: 4,
                subjectLabel: access === 'view' ? { _hidden: true } : 'Grace',
              }),
            },
          },
        ]),
      )
      const row = get(container, 'table [data-id="hilos-table-row-101"]')
      if (access === 'view') {
        expect(text(row)).toContain('Hidden (#3)')
        expect(text(row)).toContain('Hidden (#4)')
        expect(row.textContent).not.toContain('Ada')
        expect(row.textContent).not.toContain('Grace')
      } else {
        expect(row.textContent).toContain('Ada')
        expect(row.textContent).toContain('Grace')
      }
      expect(text(element('hilos-change-log-history-receipt-101', row))).toBe(
        'Receipt',
      )
    },
  )

  it('releases the history window and every connection binding on unmount', () => {
    const h = harness()
    const { unmount } = mountPage(h)
    expect([...h.listeners.values()].some((set) => set.size > 0)).toBe(true)
    unmount()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledWith(
      HISTORY,
      h.connection.registerTableWindow.mock.calls[1]?.[1],
    )
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
  })
})
