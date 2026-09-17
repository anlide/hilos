import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { TableViewportController } from '@hilos/core'
import type {
  ActionHandle,
  HilosTableBulkAccepted,
  HilosTableColumn,
  HilosTableFrame,
  TableViewportDescriptor,
} from '@hilos/core'

import { HilosViewportTable } from '../src/HilosViewportTable.js'
import {
  HilosTableSelectionEdgeContext,
  type HilosTableSelectionEdge,
} from '../src/hilosTableSelectionEdge.js'

interface Row {
  name: string
}

const COLUMNS: HilosTableColumn[] = [
  { key: 'name', label: 'Name', sortable: true },
]

function makeController(frame?: HilosTableFrame): {
  controller: TableViewportController<Row>
  sent: TableViewportDescriptor[]
} {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<Row>({
    resolve: (raw) => ({ name: String(raw.slots.name) }),
    sendViewport: (descriptor) => sent.push(descriptor),
    frame,
  })

  return { controller, sent }
}

function renderTable(controller: TableViewportController<Row>) {
  return render(
    <HilosViewportTable
      controller={controller}
      columns={COLUMNS}
      row={(r) => <td className="cell">{r.name}</td>}
    />,
  )
}

describe('HilosViewportTable', () => {
  afterEach(cleanup)

  it('renders a row per window row through the row render prop', () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [
        { rowKey: 'a', slots: { name: 'Alice' } },
        { rowKey: 'b', slots: { name: 'Bob' } },
      ],
      2,
      true,
      null,
      null,
      10,
    )
    const { container } = renderTable(controller)

    expect(
      container.querySelectorAll('[data-id^="hilos-table-row-"]'),
    ).toHaveLength(2)
    expect(container.textContent).toContain('Alice')
  })

  it('sends a viewport when a sortable header is clicked', () => {
    const { controller, sent } = makeController()
    const { container } = renderTable(controller)

    fireEvent.click(
      container.querySelector('[data-id="hilos-table-sort-name"]') as Element,
    )

    expect(sent.at(-1)).toMatchObject({
      sort: [{ field: 'name', direction: 'asc' }],
    })
  })

  it('shows the apply button with the pending count and applies in place', () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'old' } }],
      1,
      true,
      null,
      null,
      10,
    )
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
    })
    const { container } = renderTable(controller)

    expect(
      container.querySelector('[data-id="hilos-table-pending"]')?.textContent,
    ).toBe('1')
    fireEvent.click(
      container.querySelector('[data-id="hilos-table-apply"]') as Element,
    )

    expect(container.textContent).toContain('new')
    expect(container.querySelector('[data-id="hilos-table-apply"]')).toBeNull()
  })

  it('renders a placeholder for an applied removal', () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      1,
      true,
      null,
      null,
      10,
    )
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()
    const { container } = renderTable(controller)

    expect(
      container.querySelector('[data-id="hilos-table-placeholder"]'),
    ).not.toBeNull()
    expect(container.textContent).not.toContain('Alice')
  })

  it('does not render a list-changed banner on a set change (no layout shift)', () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      1,
      true,
      null,
      null,
      10,
    )
    controller.ingestCount(5, true)
    const { container } = renderTable(controller)

    expect(
      container.querySelector('[data-id="hilos-table-list-changed"]'),
    ).toBeNull()
  })

  it('names the table with a caption and reports sort state to assistive tech', () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      1,
      true,
      null,
      null,
      10,
    )
    const { container } = render(
      <HilosViewportTable
        controller={controller}
        columns={COLUMNS}
        label="Users"
        searchable
        row={(r) => <td className="cell">{r.name}</td>}
      />,
    )

    const caption = container.querySelector('caption')
    expect(caption?.textContent).toBe('Users')
    expect(caption?.classList.contains('visually-hidden')).toBe(true)
    expect(
      container
        .querySelector('[data-id="hilos-table-search"]')
        ?.getAttribute('aria-label'),
    ).toBe('Search…')

    const header = container.querySelector('th')
    expect(header?.getAttribute('aria-sort')).toBe('none')
    const sortName = container.querySelector(
      '[data-id="hilos-table-sort-name"]',
    ) as Element
    fireEvent.click(sortName)
    expect(container.querySelector('th')?.getAttribute('aria-sort')).toBe(
      'ascending',
    )
    fireEvent.click(sortName)
    expect(container.querySelector('th')?.getAttribute('aria-sort')).toBe(
      'descending',
    )
    fireEvent.click(sortName)
    expect(container.querySelector('th')?.getAttribute('aria-sort')).toBe(
      'none',
    )
  })
})

describe('HilosViewportTable with a declared frame', () => {
  afterEach(cleanup)

  const FRAME: HilosTableFrame = {
    title: 'Backups',
    search: {},
    columns: COLUMNS,
  }

  function renderDeclared(controller: TableViewportController<Row>) {
    return render(
      <HilosViewportTable
        controller={controller}
        columns={COLUMNS}
        label="Users"
        searchable
        row={(r) => <td className="cell">{r.name}</td>}
      />,
    )
  }

  it('draws the declared bar and names the table by its visible title', () => {
    const { controller } = makeController(FRAME)
    const { container } = renderDeclared(controller)

    const title = container.querySelector('[data-id="hilos-table-title"]')
    expect(title?.textContent).toBe('Backups')
    expect(
      container.querySelector('table')?.getAttribute('aria-labelledby'),
    ).toBe(title?.id)
    expect(container.querySelector('caption')).toBeNull()
  })

  it('draws the header and the empty words from the declaration when handed no columns', () => {
    const { controller } = makeController({
      ...FRAME,
      empty: { title: 'No backups yet.' },
    })
    controller.ingestWindow([], 0, true, null, null, 20)
    const { container } = render(
      <HilosViewportTable
        controller={controller}
        row={(r) => <td className="cell">{r.name}</td>}
      />,
    )

    expect(
      container.querySelector('[data-id="hilos-table-sort-name"]')?.textContent,
    ).toBe('Name')
    expect(container.querySelector('tbody')?.textContent).toBe(
      'No backups yet.',
    )
  })

  it('draws the declared footer instead of the one built from props', () => {
    const { controller } = makeController(FRAME)
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      128,
      true,
      null,
      null,
      20,
    )
    const { container } = renderDeclared(controller)

    expect(
      container.querySelector('[data-id="hilos-table-count"]')?.textContent,
    ).toBe('1 – 1 of 128')
    expect(container.querySelector('[data-id="hilos-table-page"]')).toBeNull()
  })

  // Apply stays: the room of live messages that carries it in Vue is not ported yet,
  // and the framework pages on this view take their pending changes through it.
  it('leaves the props-driven search out but keeps its Apply button', () => {
    const { controller } = makeController(FRAME)
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      1,
      true,
      null,
      null,
      20,
    )
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'Alicia' } },
    })
    const { container } = renderDeclared(controller)

    expect(
      container.querySelectorAll('[data-id="hilos-table-apply"]'),
    ).toHaveLength(1)
    expect(
      container.querySelectorAll('[data-id="hilos-table-search"]'),
    ).toHaveLength(1)
  })
})

describe('HilosViewportTable with a selection column', () => {
  afterEach(cleanup)

  /**
   * The sender of the declared operation, which this file never presses: the column
   * is what it is about, and the panel that presses is tested next door.
   */
  function neverRun(): ActionHandle<HilosTableBulkAccepted> {
    throw new Error('the declaration is only read here')
  }

  // The table of a page that declared one bulk operation — the one sign that it
  // has marks at all, and the whole reason the column stands.
  const BULK_FRAME: HilosTableFrame = {
    title: 'Backups',
    columns: COLUMNS,
    bulkActions: [
      { key: 'delete', label: 'Delete', danger: true, run: neverRun },
    ],
  }

  /** The same table with nothing declared for marked rows. */
  const PLAIN_FRAME: HilosTableFrame = { title: 'Backups', columns: COLUMNS }

  function renderWithEdge(
    controller: TableViewportController<Row>,
    edge?: HilosTableSelectionEdge,
  ) {
    const table = (
      <HilosViewportTable
        controller={controller}
        columns={COLUMNS}
        row={(r) => <td className="cell">{r.name}</td>}
      />
    )

    return render(
      edge === undefined ? (
        table
      ) : (
        <HilosTableSelectionEdgeContext.Provider value={edge}>
          {table}
        </HilosTableSelectionEdgeContext.Provider>
      ),
    )
  }

  function window(controller: TableViewportController<Row>): void {
    controller.ingestWindow(
      [
        { rowKey: 'a', slots: { name: 'Alice' } },
        { rowKey: 'b', slots: { name: 'Bob' } },
      ],
      2,
      true,
      null,
      null,
      10,
    )
  }

  function input(container: HTMLElement, id: string): HTMLInputElement | null {
    return container.querySelector<HTMLInputElement>(`[data-id="${id}"]`)
  }

  it('draws no checkbox column for a table that declared no bulk operations', () => {
    const { controller } = makeController(PLAIN_FRAME)
    window(controller)
    const { container } = renderWithEdge(controller)

    expect(input(container, 'hilos-table-select-page')).toBeNull()
    expect(input(container, 'hilos-table-select-a')).toBeNull()
    expect(container.querySelectorAll('thead th')).toHaveLength(COLUMNS.length)
  })

  it('puts the column first by default and last where the app asked for the end', () => {
    const { controller } = makeController(BULK_FRAME)
    window(controller)

    const left = renderWithEdge(controller).container
    expect(
      left
        .querySelectorAll('thead th')[0]
        ?.classList.contains('hilos-table-selection-cell'),
    ).toBe(true)
    const leftCells = left.querySelectorAll('[data-id="hilos-table-row-a"] td')
    expect(leftCells[0]?.querySelector('input')?.dataset['id']).toBe(
      'hilos-table-select-a',
    )
    cleanup()

    const right = renderWithEdge(controller, 'end').container
    const headers = right.querySelectorAll('thead th')
    expect(
      headers[headers.length - 1]?.classList.contains(
        'hilos-table-selection-cell',
      ),
    ).toBe(true)
    const rightCells = right.querySelectorAll(
      '[data-id="hilos-table-row-a"] td',
    )
    expect(
      rightCells[rightCells.length - 1]?.querySelector('input')?.dataset['id'],
    ).toBe('hilos-table-select-a')
  })

  it('tells the core the state each checkbox is now in', () => {
    const { controller } = makeController(BULK_FRAME)
    window(controller)
    const { container } = renderWithEdge(controller)

    fireEvent.click(input(container, 'hilos-table-select-a') as HTMLElement)
    expect(controller.selection.count.get()).toBe(1)
    fireEvent.click(input(container, 'hilos-table-select-a') as HTMLElement)
    expect(controller.selection.count.get()).toBe(0)

    fireEvent.click(input(container, 'hilos-table-select-page') as HTMLElement)
    expect(controller.selection.count.get()).toBe(2)
    fireEvent.click(input(container, 'hilos-table-select-page') as HTMLElement)
    expect(controller.selection.count.get()).toBe(0)
  })

  it('shows the header checkbox empty, half-marked and full', () => {
    const { controller } = makeController(BULK_FRAME)
    window(controller)
    const { container } = renderWithEdge(controller)
    const header = () =>
      input(container, 'hilos-table-select-page') as HTMLInputElement

    expect(header().checked).toBe(false)
    expect(header().indeterminate).toBe(false)

    act(() => controller.selectRow('a', true))
    expect(header().checked).toBe(false)
    expect(header().indeterminate).toBe(true)

    act(() => controller.selectRow('b', true))
    expect(header().checked).toBe(true)
    expect(header().indeterminate).toBe(false)
  })

  it('gives a row shown as a placeholder no checkbox', () => {
    const { controller } = makeController(BULK_FRAME)
    window(controller)
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()
    const { container } = renderWithEdge(controller)

    expect(
      container.querySelector('[data-id="hilos-table-placeholder"]'),
    ).not.toBeNull()
    expect(input(container, 'hilos-table-select-a')).toBeNull()
    expect(input(container, 'hilos-table-select-b')).not.toBeNull()
  })

  it('counts the checkbox column into every cell that spans the row', () => {
    const { controller } = makeController(BULK_FRAME)
    window(controller)
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()
    const { container } = renderWithEdge(controller)

    expect(
      container
        .querySelector('[data-id="hilos-table-placeholder"]')
        ?.getAttribute('colspan'),
    ).toBe(String(COLUMNS.length + 1))
    cleanup()

    const empty = makeController(BULK_FRAME)
    empty.controller.ingestWindow([], 0, true, null, null, 10)
    const emptyTable = renderWithEdge(empty.controller).container
    expect(emptyTable.querySelector('tbody td')?.getAttribute('colspan')).toBe(
      String(COLUMNS.length + 1),
    )
  })
})
