import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { StrictMode, type ReactNode } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import {
  FocusTrap,
  ScopeManager,
  TableViewportController,
  bindTableViewport,
  registerFocusReturnFallback,
  subscribeSignal,
} from '@hilos/core'
import type {
  ActionHandle,
  HilosConnection,
  HilosTableBulkAccepted,
  HilosTableColumn,
  HilosTableFrame,
  HilosTableProgress,
  HilosTableRowMessage,
  TableGeometryRegistration,
  TableViewportDescriptor,
} from '@hilos/core'

import { normalizeTableRow } from '../../core/src/state/normalizer.js'
import { HilosModal } from '../src/HilosModal.js'
import { HilosViewportTable } from '../src/HilosViewportTable.js'
import { HilosTableText } from '../src/HilosTableText.js'
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

const TWO_COLUMNS: HilosTableColumn[] = [
  { key: 'name', label: 'Name', sortable: true },
  { key: 'id', label: 'Id', sortable: true },
]

function makeController(
  frame: HilosTableFrame = { columns: COLUMNS },
  options: {
    refused?: (row: Row) => boolean
    expandable?: (row: Row) => boolean
  } = {},
): {
  controller: TableViewportController<Row>
  sent: TableViewportDescriptor[]
} {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<Row>({
    resolve: (raw) => ({ name: String(raw.slots.name) }),
    sendViewport: (descriptor) => sent.push(descriptor),
    frame,
    ...(options.refused !== undefined ? { refused: options.refused } : {}),
    ...(options.expandable !== undefined
      ? { expandable: options.expandable }
      : {}),
  })

  return { controller, sent }
}

function renderTable(controller: TableViewportController<Row>) {
  return render(
    <HilosViewportTable
      controller={controller}
      cells={{ name: (r) => <span className="cell">{r.name}</span> }}
    />,
  )
}

describe('HilosViewportTable', () => {
  afterEach(() => {
    cleanup()
    vi.useRealTimers()
  })

  it('renders a row per window row through the cell renderer', () => {
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

  it('numbers the columns of a composite order and says the place in words', () => {
    const { controller } = makeController({ columns: TWO_COLUMNS })
    controller.setOrder([
      { field: 'name', direction: 'desc' },
      { field: 'id', direction: 'asc' },
    ])
    const { container } = renderTable(controller)

    expect(
      Array.from(
        container.querySelectorAll('th sup'),
        (mark) => mark.textContent,
      ),
    ).toEqual(['1', '2'])
    // aria-sort names a direction and cannot say "second by importance", so the
    // place is spoken beside the number instead.
    expect(
      Array.from(
        container.querySelectorAll('th .visually-hidden'),
        (said) => said.textContent,
      ),
    ).toEqual([
      'Sort column 1 of 2',
      'Sort column 2 of 2',
      'Row state and controls',
    ])
  })

  it('numbers nothing under an order of one column, where the arrow says it all', () => {
    const { controller } = makeController({ columns: TWO_COLUMNS })
    controller.setSort('name')
    const { container } = renderTable(controller)

    expect(container.querySelectorAll('th sup')).toHaveLength(0)
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

  it('highlights a row whose new value landed in place, with no waiting mark', () => {
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
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
    })
    const { container } = renderTable(controller)

    expect(
      container
        .querySelector('[data-id="hilos-table-row-a"]')
        ?.classList.contains('table-success'),
    ).toBe(true)
    expect(
      container.querySelectorAll('[data-id^="hilos-table-pending-"]'),
    ).toHaveLength(0)
  })

  it('marks a waiting row amber and says in words what waits on it', () => {
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
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'Alicia' } },
    })
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'b',
      reason: 'deleted',
    })
    const { container } = renderTable(controller)

    expect(
      container
        .querySelector('[data-id="hilos-table-row-a"]')
        ?.classList.contains('table-warning'),
    ).toBe(true)
    expect(
      container
        .querySelector('[data-id="hilos-table-pending-move-a"]')
        ?.textContent?.trim(),
    ).toBe('Will move')
    expect(
      container
        .querySelector('[data-id="hilos-table-row-b"]')
        ?.classList.contains('table-warning'),
    ).toBe(true)
    expect(
      container
        .querySelector('[data-id="hilos-table-pending-remove-b"]')
        ?.textContent?.trim(),
    ).toBe('Will leave')
  })

  it('lets the waiting outrank the highlight on a row that is both', () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'old' } }],
      1,
      true,
      null,
      null,
      10,
    )
    // The value lands and lights the row up; the move that follows is held at the
    // gate, so the row is highlighted and waiting at the same moment.
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
    })
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'newer' } },
    })
    const { container } = renderTable(controller)

    const row = container.querySelector('[data-id="hilos-table-row-a"]')
    expect(row?.classList.contains('table-warning')).toBe(true)
    expect(row?.classList.contains('table-success')).toBe(false)
  })

  it('marks a refused row red, leaves an unrefused row unpainted, and keeps other contextual classes away', () => {
    const { controller: plainController } = makeController()
    plainController.ingestWindow(
      [
        { rowKey: 'a', slots: { name: 'refused' } },
        { rowKey: 'b', slots: { name: 'Bob' } },
      ],
      2,
      true,
      null,
      null,
      10,
    )
    const { container: plainContainer } = renderTable(plainController)
    const plainRowA = plainContainer.querySelector(
      '[data-id="hilos-table-row-a"]',
    )
    const plainRowB = plainContainer.querySelector(
      '[data-id="hilos-table-row-b"]',
    )
    expect(plainRowA?.classList.contains('table-danger')).toBe(false)
    expect(plainRowA?.classList.contains('table-warning')).toBe(false)
    expect(plainRowA?.classList.contains('table-success')).toBe(false)
    expect(plainRowA?.classList.contains('table-active')).toBe(false)
    expect(plainRowB?.classList.contains('table-danger')).toBe(false)

    const { controller } = makeController(undefined, {
      refused: (row) => row.name === 'refused',
    })
    controller.ingestWindow(
      [
        { rowKey: 'a', slots: { name: 'refused' } },
        { rowKey: 'b', slots: { name: 'Bob' } },
      ],
      2,
      true,
      null,
      null,
      10,
    )
    const { container } = renderTable(controller)

    const rowA = container.querySelector('[data-id="hilos-table-row-a"]')
    expect(rowA?.classList.contains('table-danger')).toBe(true)
    expect(rowA?.classList.contains('table-warning')).toBe(false)
    expect(rowA?.classList.contains('table-success')).toBe(false)
    expect(rowA?.classList.contains('table-active')).toBe(false)

    const rowB = container.querySelector('[data-id="hilos-table-row-b"]')
    expect(rowB?.classList.contains('table-danger')).toBe(false)
    expect(rowB?.classList.contains('table-warning')).toBe(false)
    expect(rowB?.classList.contains('table-success')).toBe(false)
    expect(rowB?.classList.contains('table-active')).toBe(false)
  })

  it('lets the waiting outrank the refusal on a row that is both', () => {
    const { controller } = makeController(undefined, {
      refused: (row) => row.name === 'refused',
    })
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'refused' } }],
      1,
      true,
      null,
      null,
      10,
    )
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'refused' } },
    })
    const { container } = renderTable(controller)

    const row = container.querySelector('[data-id="hilos-table-row-a"]')
    expect(row?.classList.contains('table-warning')).toBe(true)
    expect(row?.classList.contains('table-danger')).toBe(false)
    expect(row?.classList.contains('table-success')).toBe(false)
  })

  it('lets a fresh highlight outrank the refusal on a row, returning to red once the highlight expires', () => {
    vi.useFakeTimers()
    const { controller } = makeController(undefined, {
      refused: (row) => row.name === 'refused',
    })
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'refused' } }],
      1,
      true,
      null,
      null,
      10,
    )
    const { container } = renderTable(controller)
    const row = container.querySelector('[data-id="hilos-table-row-a"]')
    expect(row?.classList.contains('table-danger')).toBe(true)
    expect(row?.classList.contains('table-success')).toBe(false)

    // Landing an update highlights the row for 2s
    act(() => {
      controller.ingestDelta({
        kind: 'row_updated',
        rowKey: 'a',
        row: { rowKey: 'a', slots: { name: 'refused' } },
      })
    })
    expect(row?.classList.contains('table-success')).toBe(true)
    expect(row?.classList.contains('table-danger')).toBe(false)
    expect(row?.classList.contains('table-warning')).toBe(false)

    act(() => {
      vi.advanceTimersByTime(2000)
    })
    expect(row?.classList.contains('table-danger')).toBe(true)
    expect(row?.classList.contains('table-success')).toBe(false)
    expect(row?.classList.contains('table-warning')).toBe(false)
  })

  it('clears red when a row refusal is cured, and does not paint a departed placeholder row red', () => {
    vi.useFakeTimers()
    const { controller } = makeController(undefined, {
      refused: (row) => row.name === 'refused',
    })
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'refused' } }],
      1,
      true,
      null,
      null,
      10,
    )
    const { container } = renderTable(controller)
    const row = container.querySelector('[data-id="hilos-table-row-a"]')
    expect(row?.classList.contains('table-danger')).toBe(true)

    // Cure the refusal via update
    act(() => {
      controller.ingestDelta({
        kind: 'row_updated',
        rowKey: 'a',
        row: { rowKey: 'a', slots: { name: 'cured' } },
      })
    })
    act(() => {
      vi.advanceTimersByTime(2000)
    })
    expect(row?.classList.contains('table-danger')).toBe(false)
    expect(row?.classList.contains('table-success')).toBe(false)
    expect(row?.classList.contains('table-warning')).toBe(false)

    // Turn into placeholder
    act(() => {
      controller.ingestDelta({
        kind: 'row_removed',
        rowKey: 'a',
        reason: 'deleted',
      })
      controller.apply()
    })
    const placeholderRow = container.querySelector(
      '[data-id="hilos-table-row-a"]',
    )
    expect(placeholderRow?.classList.contains('table-danger')).toBe(false)
    expect(placeholderRow?.classList.contains('table-warning')).toBe(false)
    expect(placeholderRow?.classList.contains('table-success')).toBe(false)
  })

  it('keeps an expanded refused row red and an expanded unrefused row active', () => {
    const { controller } = makeController(undefined, {
      refused: (row) => row.name === 'refused',
    })
    controller.ingestWindow(
      [
        { rowKey: 'a', slots: { name: 'refused' } },
        { rowKey: 'b', slots: { name: 'Bob' } },
      ],
      2,
      true,
      null,
      null,
      10,
    )
    controller.expandRow('a', true)
    controller.expandRow('b', true)
    const { container } = renderTable(controller)

    const rowA = container.querySelector('[data-id="hilos-table-row-a"]')
    expect(rowA?.classList.contains('table-danger')).toBe(true)
    expect(rowA?.classList.contains('table-active')).toBe(false)
    expect(rowA?.classList.contains('table-warning')).toBe(false)
    expect(rowA?.classList.contains('table-success')).toBe(false)

    const rowB = container.querySelector('[data-id="hilos-table-row-b"]')
    expect(rowB?.classList.contains('table-active')).toBe(true)
    expect(rowB?.classList.contains('table-danger')).toBe(false)
    expect(rowB?.classList.contains('table-warning')).toBe(false)
    expect(rowB?.classList.contains('table-success')).toBe(false)
  })

  it('keeps the state column before and after waiting arrives', () => {
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
    // An applied removal leaves a placeholder with nothing waiting. State still
    // has its own permanent track before another change arrives.
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()
    const { container } = renderTable(controller)
    const placeholderSpan = () =>
      container
        .querySelector('[data-id="hilos-table-placeholder"]')
        ?.getAttribute('colspan')

    expect(container.querySelectorAll('thead th')).toHaveLength(
      COLUMNS.length + 1,
    )
    expect(placeholderSpan()).toBe(String(COLUMNS.length + 1))

    act(() =>
      controller.ingestDelta({
        kind: 'row_moved',
        rowKey: 'b',
        row: { rowKey: 'b', slots: { name: 'Bobby' } },
      }),
    )

    expect(container.querySelectorAll('thead th')).toHaveLength(
      COLUMNS.length + 1,
    )
    expect(placeholderSpan()).toBe(String(COLUMNS.length + 1))
  })

  it('raises one strip for rows announced above or inside the window, and counts them together', () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      1,
      true,
      null,
      null,
      10,
    )
    const { container } = renderTable(controller)
    const strip = () =>
      container.querySelector('[data-id="hilos-table-announce"]')

    expect(strip()).toBeNull()

    // The strip names no place (HIL-1026): a row inside the window raises it as
    // one above does, and the two are one number.
    act(() => controller.ingestAnnounce('x', 'inside', 2, true))

    expect(strip()?.textContent).toContain('1 new row')
    expect(strip()?.textContent).not.toContain('above')

    act(() => controller.ingestAnnounce('y', 'above', 3, true))

    expect(strip()?.textContent).toContain('2 new rows')
    expect(strip()?.textContent).not.toContain('above')
  })

  it('asks for the window again when Show is pressed, and the strip goes', () => {
    const { controller, sent } = makeController()
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      1,
      true,
      null,
      null,
      10,
    )
    controller.ingestAnnounce('x', 'above', 2, true)
    const { container } = renderTable(controller)
    const asked = sent.length

    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-table-announce-show"]',
      ) as Element,
    )

    expect(sent).toHaveLength(asked + 1)
    expect(
      container.querySelector('[data-id="hilos-table-announce"]'),
    ).toBeNull()
  })

  it('gives the one line to the waiting and keeps the new rows as an icon beside it', () => {
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
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'Alicia' } },
    })
    controller.ingestAnnounce('x', 'above', 2, true)
    const { container } = renderTable(controller)

    // One room, one line: the waiting holds it because its button would be hidden
    // otherwise, and the new rows keep speaking by their icon (Flow F4).
    expect(
      container.querySelector('[data-id="hilos-table-announce"]'),
    ).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-table-apply"]'),
    ).not.toBeNull()
    expect(
      container.querySelector('[data-id="hilos-table-pending"]')?.textContent,
    ).toBe('1')
    expect(
      container.querySelector(
        '[data-id="hilos-table-live-rest"] .bi-arrow-down-circle',
      ),
    ).not.toBeNull()
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

  it('names the table with its title and reports sort state to assistive tech', () => {
    const { controller } = makeController({
      title: 'Users',
      search: {},
      columns: COLUMNS,
    })
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      1,
      true,
      null,
      null,
      10,
    )
    const { container } = renderTable(controller)

    const title = container.querySelector('[data-id="hilos-table-title"]')
    expect(title?.textContent).toBe('Users')
    expect(
      container.querySelector('table')?.getAttribute('aria-labelledby'),
    ).toBe(title?.id)
    expect(container.querySelector('caption')).toBeNull()
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
        cells={{ name: (r) => <span className="cell">{r.name}</span> }}
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
        cells={{ name: (r) => <span className="cell">{r.name}</span> }}
      />,
    )

    expect(
      container.querySelector('[data-id="hilos-table-sort-name"]')?.textContent,
    ).toBe('Name')
    expect(container.querySelector('tbody')?.textContent).toBe(
      'No backups yet.',
    )
  })

  it('draws the declared footer with the delivered row range', () => {
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
  })

  it('draws one search bar and keeps Apply in the waiting strip', () => {
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

    // The declared search bar and the waiting strip each stand once.
    expect(
      container.querySelectorAll('[data-id="hilos-table-search"]'),
    ).toHaveLength(1)
    expect(
      container.querySelector('[data-id="hilos-table-apply"]'),
    ).not.toBeNull()
    expect(
      container.querySelector('[data-id="hilos-table-pending"]')?.textContent,
    ).toBe('1')
  })
})

describe('HilosViewportTable drawing the cells of a declared table', () => {
  afterEach(cleanup)

  // Two columns, one of them aligned the way a numeric column is: what the page
  // used to write onto its own `<td>` and now declares once.
  const CELL_COLUMNS: HilosTableColumn[] = [
    { key: 'name', label: 'Name' },
    {
      key: 'size',
      label: 'Size',
      headerClass: 'text-end',
      cellClass: 'text-end',
    },
  ]
  const CELL_FRAME: HilosTableFrame = {
    title: 'Backups',
    columns: CELL_COLUMNS,
  }

  function window(controller: TableViewportController<Row>): void {
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      1,
      true,
      null,
      null,
      10,
    )
  }

  function renderCells(controller: TableViewportController<Row>) {
    return render(
      <HilosViewportTable
        controller={controller}
        cells={{
          name: (r) => <span className="named">{r.name}</span>,
          size: () => <span className="sized">1.2 GB</span>,
        }}
      />,
    )
  }

  function rowCells(container: HTMLElement): HTMLElement[] {
    return Array.from(
      container.querySelectorAll<HTMLElement>(
        '[data-id="hilos-table-row-a"] td',
      ),
    )
  }

  it('draws one cell per declared column and fills it from its own renderer', () => {
    const { controller } = makeController(CELL_FRAME)
    window(controller)
    const { container } = renderCells(controller)

    const cells = rowCells(container)
    expect(cells).toHaveLength(CELL_COLUMNS.length + 1)
    expect(cells[0]?.querySelector('.named')?.textContent).toBe('Alice')
    expect(cells[1]?.querySelector('.sized')?.textContent).toBe('1.2 GB')
  })

  it('puts the declared cell class on the body cell and nowhere else', () => {
    const { controller } = makeController(CELL_FRAME)
    window(controller)
    const { container } = renderCells(controller)

    const cells = rowCells(container)
    expect(cells[0]?.classList.contains('text-end')).toBe(false)
    expect(cells[1]?.classList.contains('text-end')).toBe(true)
  })

  it('leaves the cell standing where the page gave no renderer', () => {
    const { controller } = makeController(CELL_FRAME)
    window(controller)
    const { container } = render(
      <HilosViewportTable
        controller={controller}
        cells={{ name: (r) => r.name }}
      />,
    )

    const cells = rowCells(container)
    expect(cells).toHaveLength(container.querySelectorAll('thead th').length)
    expect(cells[1]?.textContent).toBe('')
  })

  it('uses the same declared columns for the header and cells', () => {
    const { controller } = makeController(CELL_FRAME)
    window(controller)
    const { container } = render(
      <HilosViewportTable
        controller={controller}
        cells={{ size: () => <span className="sized">1.2 GB</span> }}
      />,
    )

    expect(container.querySelectorAll('thead th')).toHaveLength(
      CELL_COLUMNS.length + 1,
    )
    expect(container.querySelector('thead')?.textContent).toContain('Size')
    expect(container.querySelector('.sized')).not.toBeNull()
  })
})

describe('HilosViewportTable drawing a row as a card', () => {
  afterEach(() => {
    cleanup()
    vi.useRealTimers()
  })

  /** The sender of the declared operation, which this block never presses. */
  function neverRun(): ActionHandle<HilosTableBulkAccepted> {
    throw new Error('the declaration is only read here')
  }

  // One column of each place a card has, plus one the page keeps out of it: the
  // whole projection read back through the markup the view writes.
  const CARD_COLUMNS: HilosTableColumn[] = [
    { key: 'name', label: 'Name' },
    { key: 'kind', label: 'Kind', cellClass: 'text-end' },
    { key: 'state', label: 'State', card: 'badge' },
    { key: 'secret', label: 'Secret', card: 'hidden' },
    { key: 'actions', label: '' },
  ]
  const CARD_FRAME: HilosTableFrame = {
    title: 'Backups',
    columns: CARD_COLUMNS,
  }

  /** The same table on a page that also declared an operation over marked rows. */
  const BULK_CARD_FRAME: HilosTableFrame = {
    ...CARD_FRAME,
    bulkActions: [
      {
        key: 'delete',
        label: 'Delete',
        refusalTitle: "Couldn't delete",
        danger: true,
        run: neverRun,
      },
    ],
  }

  const CARD_CELLS = {
    name: (r: Row) => <span className="named">{r.name}</span>,
    kind: () => <span className="kind">full</span>,
    state: () => <span className="state-badge">ready</span>,
    secret: () => <span className="secret">1.2 GB</span>,
    actions: () => (
      <button type="button" className="restore">
        Restore
      </button>
    ),
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

  function renderCards(
    controller: TableViewportController<Row>,
    edge?: HilosTableSelectionEdge,
  ) {
    const table = (
      <HilosViewportTable controller={controller} cells={CARD_CELLS} />
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

  function cardOf(container: HTMLElement, rowKey: string): HTMLElement {
    return container.querySelector<HTMLElement>(
      `[data-id="hilos-table-card-${rowKey}"]`,
    ) as HTMLElement
  }

  it('stands the cards beside the table and shows exactly one of the two', () => {
    const { controller } = makeController(CARD_FRAME)
    window(controller)
    const { container } = renderCards(controller)

    const cards = container.querySelector(
      '[data-id="hilos-table-cards"]',
    ) as HTMLElement
    expect(cards.classList.contains('d-md-none')).toBe(true)
    expect(
      cards.querySelectorAll('[data-id^="hilos-table-card-"]'),
    ).toHaveLength(2)

    const wide = container.querySelector('.table-responsive') as HTMLElement
    expect(wide.classList.contains('d-none')).toBe(true)
    expect(wide.classList.contains('d-md-block')).toBe(true)
    // Nothing to scroll sideways once the columns became lines of a card.
    expect(cards.querySelectorAll('.table-responsive')).toHaveLength(0)
  })

  it('lays the card out the way the core projected it', () => {
    const { controller } = makeController(CARD_FRAME)
    window(controller)
    const { container } = renderCards(controller)
    const card = cardOf(container, 'a')

    expect(card.querySelector('.named')?.textContent).toBe('Alice')
    // The title and the badge are drawn bare; only fields carry a label.
    expect(card.textContent).not.toContain('Name')
    expect(card.querySelector('.state-badge')).not.toBeNull()
    expect(card.textContent).not.toContain('State')
    expect(
      Array.from(card.querySelectorAll('dt')).map((label) => label.textContent),
    ).toEqual(['Kind'])
    expect(card.querySelector('dd .kind')).not.toBeNull()
    expect(card.querySelector('.hilos-button-row .restore')).not.toBeNull()
    // A column the page kept out of the card is nowhere in it, though it still
    // stands in the row.
    expect(card.querySelector('.secret')).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-table-row-a"] .secret'),
    ).not.toBeNull()
  })

  it('fills a cell of the row and a line of the card from one renderer', () => {
    const { controller } = makeController(CARD_FRAME)
    window(controller)
    const { container } = renderCards(controller)

    expect(
      container.querySelector('[data-id="hilos-table-row-a"] .kind'),
    ).not.toBeNull()
    expect(cardOf(container, 'a').querySelector('.kind')).not.toBeNull()
  })

  it('leaves out the card line of a column the page gave no renderer for', () => {
    const { controller } = makeController(CARD_FRAME)
    window(controller)
    const { container } = render(
      <HilosViewportTable
        controller={controller}
        cells={{ name: (r) => <span className="named">{r.name}</span> }}
      />,
    )

    // The row keeps every cell, or it comes out narrower than its header; the
    // card keeps no label with nothing under it.
    expect(
      container.querySelectorAll('[data-id="hilos-table-row-a"] td'),
    ).toHaveLength(CARD_COLUMNS.length + 1)
    expect(cardOf(container, 'a').querySelectorAll('dt')).toHaveLength(0)
    expect(cardOf(container, 'a').querySelector('.hilos-button-row')).toBeNull()
  })

  it('puts the declared cell class on the row cell and not on the card line', () => {
    const { controller } = makeController(CARD_FRAME)
    window(controller)
    const { container } = renderCards(controller)

    const cells = container.querySelectorAll('[data-id="hilos-table-row-a"] td')
    expect(cells[1]?.classList.contains('text-end')).toBe(true)
    expect(
      cardOf(container, 'a')
        .querySelector('dd')
        ?.classList.contains('text-end'),
    ).toBe(false)
  })

  it('wraps actions cell content in an inline-flex gap-1 container on wide rows', () => {
    const { controller } = makeController(CARD_FRAME)
    window(controller)
    const { container } = renderCards(controller)

    const row = container.querySelector('[data-id="hilos-table-row-a"]')
    const cells = row?.querySelectorAll('td')
    const actionsCell = cells?.[CARD_COLUMNS.length - 1]
    const wrapperSpan = actionsCell?.querySelector(
      'span.d-inline-flex.align-items-center.gap-1',
    )
    expect(wrapperSpan).not.toBeNull()
    expect(wrapperSpan?.querySelector('.restore')).not.toBeNull()

    const nameCell = cells?.[0]
    expect(nameCell?.querySelector('span.d-inline-flex')).toBeNull()
  })

  it('draws every placeholder reason in the row and card', () => {
    for (const [reason, text, icon] of [
      ['deleted', 'Removed', 'bi-dash-circle'],
      ['moved_out', 'Moved to another page', 'bi-arrows-move'],
      ['left_set', 'No longer in this list', 'bi-box-arrow-right'],
    ] as const) {
      const { controller } = makeController(CARD_FRAME)
      window(controller)
      controller.ingestDelta({ kind: 'row_removed', rowKey: 'a', reason })
      controller.apply()
      const { container } = renderCards(controller)

      const row = container.querySelector(
        '[data-id="hilos-table-row-a"] [data-id="hilos-table-placeholder"]',
      )
      const card = cardOf(container, 'a').querySelector(
        '[data-id="hilos-table-placeholder"]',
      )
      expect(row?.textContent).toBe(text)
      expect(row?.querySelector('i')?.classList.contains(icon)).toBe(true)
      expect(card?.textContent).toBe(text)
      expect(card?.querySelector('i')?.classList.contains(icon)).toBe(true)
      expect(
        container.querySelectorAll('[data-id^="hilos-table-card-"]'),
      ).toHaveLength(2)
      cleanup()
    }
  })

  it('retains placeholders without an empty tile when the count reaches zero', () => {
    const { controller } = makeController(CARD_FRAME)
    window(controller)
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'b',
      reason: 'left_set',
    })
    controller.ingestCount(0, true)
    controller.apply()
    const { container } = renderCards(controller)

    expect(
      container.querySelectorAll('[data-id="hilos-table-placeholder"]'),
    ).toHaveLength(4)
    expect(
      container.querySelectorAll('[data-id="hilos-table-empty"]'),
    ).toHaveLength(0)
  })

  it('tints a card amber while a change waits and green after one landed', () => {
    const { controller } = makeController(CARD_FRAME)
    window(controller)
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'Alicia' } },
    })
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'b',
      row: { rowKey: 'b', slots: { name: 'Bobby' } },
    })
    const { container } = renderCards(controller)

    expect(cardOf(container, 'a').classList.contains('border-warning')).toBe(
      true,
    )
    expect(cardOf(container, 'b').classList.contains('border-success')).toBe(
      true,
    )
  })

  it('lets the waiting outrank the highlight on a card that is both', () => {
    const { controller } = makeController(CARD_FRAME)
    window(controller)
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'Alicia' } },
    })
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'Alexandra' } },
    })
    const { container } = renderCards(controller)
    const card = cardOf(container, 'a')

    expect(card.classList.contains('border-warning')).toBe(true)
    expect(card.classList.contains('border-success')).toBe(false)
  })

  it('marks a refused card red, leaves an unrefused card unpainted, and keeps other border classes away', () => {
    const { controller: plainController } = makeController(CARD_FRAME)
    plainController.ingestWindow(
      [
        { rowKey: 'a', slots: { name: 'refused' } },
        { rowKey: 'b', slots: { name: 'Bob' } },
      ],
      2,
      true,
      null,
      null,
      10,
    )
    const { container: plainContainer } = renderCards(plainController)
    const plainCardA = cardOf(plainContainer, 'a')
    const plainCardB = cardOf(plainContainer, 'b')
    expect(plainCardA.classList.contains('border-danger')).toBe(false)
    expect(plainCardA.classList.contains('border-warning')).toBe(false)
    expect(plainCardA.classList.contains('border-success')).toBe(false)
    expect(plainCardB.classList.contains('border-danger')).toBe(false)

    const { controller } = makeController(CARD_FRAME, {
      refused: (row) => row.name === 'refused',
    })
    controller.ingestWindow(
      [
        { rowKey: 'a', slots: { name: 'refused' } },
        { rowKey: 'b', slots: { name: 'Bob' } },
      ],
      2,
      true,
      null,
      null,
      10,
    )
    const { container } = renderCards(controller)

    const cardA = cardOf(container, 'a')
    expect(cardA.classList.contains('border-danger')).toBe(true)
    expect(cardA.classList.contains('border-warning')).toBe(false)
    expect(cardA.classList.contains('border-success')).toBe(false)

    const cardB = cardOf(container, 'b')
    expect(cardB.classList.contains('border-danger')).toBe(false)
    expect(cardB.classList.contains('border-warning')).toBe(false)
    expect(cardB.classList.contains('border-success')).toBe(false)
  })

  it('lets the waiting outrank the refusal on a card that is both', () => {
    const { controller } = makeController(CARD_FRAME, {
      refused: (row) => row.name === 'refused',
    })
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'refused' } }],
      1,
      true,
      null,
      null,
      10,
    )
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'refused' } },
    })
    const { container } = renderCards(controller)
    const card = cardOf(container, 'a')

    expect(card.classList.contains('border-warning')).toBe(true)
    expect(card.classList.contains('border-danger')).toBe(false)
    expect(card.classList.contains('border-success')).toBe(false)
  })

  it('lets a fresh highlight outrank the refusal on a card, returning to red once the highlight expires', () => {
    vi.useFakeTimers()
    const { controller } = makeController(CARD_FRAME, {
      refused: (row) => row.name === 'refused',
    })
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'refused' } }],
      1,
      true,
      null,
      null,
      10,
    )
    const { container } = renderCards(controller)
    const card = cardOf(container, 'a')
    expect(card.classList.contains('border-danger')).toBe(true)

    act(() => {
      controller.ingestDelta({
        kind: 'row_updated',
        rowKey: 'a',
        row: { rowKey: 'a', slots: { name: 'refused' } },
      })
    })
    expect(card.classList.contains('border-success')).toBe(true)
    expect(card.classList.contains('border-danger')).toBe(false)
    expect(card.classList.contains('border-warning')).toBe(false)

    act(() => {
      vi.advanceTimersByTime(2000)
    })
    expect(card.classList.contains('border-danger')).toBe(true)
    expect(card.classList.contains('border-success')).toBe(false)
    expect(card.classList.contains('border-warning')).toBe(false)
  })

  it('clears red when a card refusal is cured, and does not paint a departed placeholder card red', () => {
    vi.useFakeTimers()
    const { controller } = makeController(CARD_FRAME, {
      refused: (row) => row.name === 'refused',
    })
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'refused' } }],
      1,
      true,
      null,
      null,
      10,
    )
    const { container } = renderCards(controller)
    const card = cardOf(container, 'a')
    expect(card.classList.contains('border-danger')).toBe(true)

    act(() => {
      controller.ingestDelta({
        kind: 'row_updated',
        rowKey: 'a',
        row: { rowKey: 'a', slots: { name: 'cured' } },
      })
    })
    act(() => {
      vi.advanceTimersByTime(2000)
    })
    expect(card.classList.contains('border-danger')).toBe(false)
    expect(card.classList.contains('border-success')).toBe(false)
    expect(card.classList.contains('border-warning')).toBe(false)

    act(() => {
      controller.ingestDelta({
        kind: 'row_removed',
        rowKey: 'a',
        reason: 'deleted',
      })
      controller.apply()
    })
    const placeholderCard = cardOf(container, 'a')
    expect(placeholderCard.classList.contains('border-danger')).toBe(false)
    expect(placeholderCard.classList.contains('border-warning')).toBe(false)
    expect(placeholderCard.classList.contains('border-success')).toBe(false)
  })

  it('keeps an expanded refused card border-danger without grey, and an expanded unrefused card borderless', () => {
    const { controller } = makeController(CARD_FRAME, {
      refused: (row) => row.name === 'refused',
    })
    controller.ingestWindow(
      [
        { rowKey: 'a', slots: { name: 'refused' } },
        { rowKey: 'b', slots: { name: 'Bob' } },
      ],
      2,
      true,
      null,
      null,
      10,
    )
    controller.expandRow('a', true)
    controller.expandRow('b', true)
    const { container } = renderCards(controller)

    const cardA = cardOf(container, 'a')
    expect(cardA.classList.contains('border-danger')).toBe(true)
    expect(cardA.classList.contains('border-secondary')).toBe(false)
    expect(cardA.classList.contains('border-active')).toBe(false)
    expect(cardA.classList.contains('border-warning')).toBe(false)
    expect(cardA.classList.contains('border-success')).toBe(false)

    const cardB = cardOf(container, 'b')
    expect(cardB.classList.contains('border-danger')).toBe(false)
    expect(cardB.classList.contains('border-secondary')).toBe(false)
    expect(cardB.classList.contains('border-active')).toBe(false)
    expect(cardB.classList.contains('border-warning')).toBe(false)
    expect(cardB.classList.contains('border-success')).toBe(false)
  })

  it('keeps framework marks in their own room after the page badge', () => {
    const { controller } = makeController(CARD_FRAME)
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' }, staleSources: ['sizes'] }],
      1,
      true,
      null,
      null,
      10,
    )
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'Alicia' } },
    })
    const { container } = renderCards(controller)

    const card = cardOf(container, 'a')
    const badge = card.querySelector('.ms-auto')
    const group = card.querySelector('.hilos-table-card-marks')
    expect(badge?.querySelector('.state-badge')).not.toBeNull()
    expect(badge?.nextElementSibling).toBe(group)
    expect(group?.querySelector('.state-badge')).toBeNull()
    expect(
      group?.querySelector('[data-id="hilos-table-stale-row-a"]'),
    ).not.toBeNull()
    expect(
      group?.querySelector('[data-id="hilos-table-pending-move-a"]'),
    ).not.toBeNull()
    const marks = Array.from(
      group?.querySelector('.hilos-table-marks:not(.invisible)')?.children ??
        [],
    ).map((mark) => mark.getAttribute('data-id') ?? mark.className)
    expect(marks).toEqual([
      'hilos-table-stale-row-a',
      'visually-hidden',
      'hilos-table-pending-move-a',
    ])
  })

  it('puts the bar of a running job at the foot of the card', () => {
    const { controller } = makeController(CARD_FRAME)
    window(controller)
    controller.ingestProgress({
      scope: 'row',
      progressKey: 'pack-a',
      rowKey: 'a',
      current: 34,
      total: 110,
    })
    const { container } = renderCards(controller)

    const card = cardOf(container, 'a')
    const bar = card.querySelector('[data-id="hilos-table-progress-row-a"]')
    expect(bar).not.toBeNull()
    expect(bar?.querySelector('[role="progressbar"]')).not.toBeNull()
    expect(card.querySelector('.card-body')?.lastElementChild).toBe(bar)

    // A row shown as a placeholder gets no bar, on a card as in a row.
    act(() => {
      controller.ingestDelta({
        kind: 'row_removed',
        rowKey: 'a',
        reason: 'deleted',
      })
      controller.apply()
    })

    expect(
      cardOf(container, 'a').querySelector(
        '[data-id="hilos-table-progress-row-a"]',
      ),
    ).toBeNull()
  })

  it('says loading and then the page own empty words in both branches', () => {
    const { controller } = makeController(CARD_FRAME)
    const { container } = render(
      <HilosViewportTable
        controller={controller}
        cells={CARD_CELLS}
        empty={<span className="none-yet">No backups yet</span>}
      />,
    )

    expect(
      container.querySelectorAll('[data-id="hilos-table-loading"]'),
    ).toHaveLength(2)

    act(() => controller.ingestWindow([], 0, true, null, null, 10))

    expect(
      container.querySelectorAll('[data-id="hilos-table-loading"]'),
    ).toHaveLength(0)
    expect(container.querySelectorAll('.none-yet')).toHaveLength(2)
  })

  it('names the list of cards with the heading the table is named by', () => {
    const { controller } = makeController(CARD_FRAME)
    window(controller)
    const { container } = renderCards(controller)

    const list = container.querySelector(
      '[data-id="hilos-table-cards"] [role="list"]',
    ) as HTMLElement
    const titleId = container.querySelector('[data-id="hilos-table-title"]')?.id
    expect(list.getAttribute('aria-labelledby')).toBe(titleId)
    expect(
      container.querySelector('table')?.getAttribute('aria-labelledby'),
    ).toBe(titleId)
    expect(cardOf(container, 'a').getAttribute('role')).toBe('listitem')
    // The list owns cards and nothing else.
    expect(list.children).toHaveLength(2)
  })

  it('keeps the words of an empty table beside the list and not inside it', () => {
    const { controller } = makeController(CARD_FRAME)
    const { container } = renderCards(controller)

    expect(
      container.querySelector(
        '[data-id="hilos-table-cards"] [role="list"] [data-id="hilos-table-loading"]',
      ),
    ).toBeNull()
    expect(
      container.querySelector(
        '[data-id="hilos-table-cards"] [data-id="hilos-table-loading"]',
      ),
    ).not.toBeNull()

    act(() => controller.ingestWindow([], 0, true, null, null, 10))

    expect(
      container.querySelector('[data-id="hilos-table-cards"] [role="list"]'),
    ).toBeNull()
  })

  it('carries the row mark in the head of the card, on the edge the app chose', () => {
    const { controller } = makeController(BULK_CARD_FRAME)
    window(controller)
    const left = renderCards(controller)

    const box = cardOf(left.container, 'a').querySelector<HTMLInputElement>(
      '[data-id="hilos-table-select-a"]',
    )
    expect(box).not.toBeNull()
    // On the left edge the mark stands before the title, not in the group of
    // marks pushed to the right.
    expect(
      cardOf(left.container, 'a').querySelector(
        '.hilos-table-card-marks input',
      ),
    ).toBeNull()

    // The card box tells the core the state it is now in, as the row box does.
    fireEvent.click(box as HTMLElement)
    expect(controller.selection.count.get()).toBe(1)
    expect(box?.checked).toBe(true)
    left.unmount()

    const right = renderCards(controller, 'end')
    expect(
      cardOf(right.container, 'a')
        .querySelector('.hilos-table-card-marks input')
        ?.getAttribute('data-id'),
    ).toBe('hilos-table-select-a')
  })

  it('gives a card shown as a placeholder no mark to make', () => {
    const { controller } = makeController(BULK_CARD_FRAME)
    window(controller)
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()
    const { container } = renderCards(controller)

    expect(
      cardOf(container, 'a').querySelector('[data-id="hilos-table-select-a"]'),
    ).toBeNull()
    expect(
      cardOf(container, 'b').querySelector('[data-id="hilos-table-select-b"]'),
    ).not.toBeNull()
  })

  it('projects row message across wide merged tail and narrow card phrase', () => {
    interface WordsRow {
      operation: string
      title: string
      phrase: string
      bar: string
      message?: HilosTableRowMessage | null
    }

    const WORDS_COLUMNS: HilosTableColumn[] = [
      { key: 'operation', label: 'Operation' },
      { key: 'title', label: 'Title' },
      { key: 'phrase', label: 'Phrase' },
      { key: 'bar', label: 'Bar' },
    ]

    const controller = new TableViewportController<WordsRow>({
      resolve: (raw) => ({
        operation: String(raw.slots['operation']),
        title: String(raw.slots['title']),
        phrase: String(raw.slots['phrase']),
        bar: String(raw.slots['bar']),
        message:
          raw.slots['message'] === undefined
            ? null
            : (raw.slots['message'] as HilosTableRowMessage | null),
      }),
      sendViewport: () => {},
      frame: { title: 'Words', columns: WORDS_COLUMNS },
      rowMessage: (row) => row.message ?? null,
    })

    controller.ingestWindow(
      [
        {
          rowKey: 'default',
          slots: {
            operation: 'default',
            title: 'Maintenance in progress',
            phrase: 'Briefly unavailable',
            bar: 'Verification in progress',
          },
        },
        {
          rowKey: 'restore',
          slots: {
            operation: 'restore',
            title: '',
            phrase: '',
            bar: '',
            message: {
              fromColumn: 'title',
              text: 'No own entry — uses default',
            },
          },
        },
      ],
      2,
      true,
      null,
      null,
      10,
    )

    const { container } = render(
      <HilosViewportTable
        controller={controller}
        cells={{
          operation: (row) => <span className="op">{row.operation}</span>,
          title: (row) => <span className="title">{row.title}</span>,
          phrase: (row) => <span className="phrase">{row.phrase}</span>,
          bar: (row) => <span className="bar">{row.bar}</span>,
        }}
      />,
    )

    const normalWideRow = container.querySelector(
      '[data-id="hilos-table-row-default"]',
    )!
    const normalWideCells = normalWideRow.querySelectorAll('td')
    expect(normalWideCells).toHaveLength(5)
    expect(
      normalWideRow.querySelector('[data-id="hilos-table-row-message"]'),
    ).toBeNull()
    expect(normalWideCells[0]?.textContent).toBe('default')
    expect(normalWideCells[1]?.textContent).toBe('Maintenance in progress')
    expect(normalWideCells[2]?.textContent).toBe('Briefly unavailable')
    expect(normalWideCells[3]?.textContent).toBe('Verification in progress')
    expect(normalWideCells[0]?.getAttribute('colspan')).toBeNull()
    expect(normalWideCells[1]?.getAttribute('colspan')).toBeNull()
    expect(normalWideCells[2]?.getAttribute('colspan')).toBeNull()
    expect(normalWideCells[3]?.getAttribute('colspan')).toBeNull()

    const messageWideRow = container.querySelector(
      '[data-id="hilos-table-row-restore"]',
    )!
    const messageWideCells = messageWideRow.querySelectorAll('td')
    expect(messageWideCells).toHaveLength(3)
    expect(messageWideCells[0]?.textContent).toBe('restore')
    expect(messageWideCells[0]?.getAttribute('colspan')).toBeNull()

    const wideMessageCell = messageWideCells[1]!
    expect(wideMessageCell.getAttribute('colspan')).toBe('3')
    expect(wideMessageCell.classList.contains('text-body-secondary')).toBe(true)
    expect(wideMessageCell.classList.contains('fst-italic')).toBe(true)
    expect(wideMessageCell.getAttribute('data-id')).toBe(
      'hilos-table-row-message',
    )
    expect(wideMessageCell.textContent).toBe('No own entry — uses default')

    const normalCard = container.querySelector(
      '[data-id="hilos-table-card-default"]',
    )!
    expect(normalCard.querySelector('.op')?.textContent).toBe('default')
    expect(
      Array.from(normalCard.querySelectorAll('dt')).map((dt) => dt.textContent),
    ).toEqual(['Title', 'Phrase', 'Bar'])
    expect(normalCard.querySelectorAll('dd')).toHaveLength(3)
    expect(
      normalCard.querySelector('[data-id="hilos-table-row-message"]'),
    ).toBeNull()

    const messageCard = container.querySelector(
      '[data-id="hilos-table-card-restore"]',
    )!
    expect(messageCard.querySelector('.op')?.textContent).toBe('restore')
    expect(messageCard.querySelectorAll('dt')).toHaveLength(0)
    const cardMessages = messageCard.querySelectorAll(
      '[data-id="hilos-table-row-message"]',
    )
    expect(cardMessages).toHaveLength(1)
    const cardMessage = cardMessages[0]!
    expect(cardMessage.tagName.toLowerCase()).toBe('dd')
    expect(cardMessage.classList.contains('col-12')).toBe(true)
    expect(cardMessage.classList.contains('text-body-secondary')).toBe(true)
    expect(cardMessage.classList.contains('fst-italic')).toBe(true)
    expect(cardMessage.textContent).toBe('No own entry — uses default')

    controller.dispose()
  })
})

describe('HilosViewportTable marking a source that went quiet', () => {
  afterEach(cleanup)

  // A table assembled from two sources: the name comes from the row's own record,
  // the presence from a second slot — and a slot is what can go quiet on its own.
  const SOURCED_COLUMNS: HilosTableColumn[] = [
    { key: 'name', label: 'Name', sortable: true },
    {
      key: 'presence',
      label: 'Presence',
      sortable: true,
      source: 'connections',
    },
  ]

  function window(
    controller: TableViewportController<Row>,
    staleSources: readonly string[] = [],
  ): void {
    controller.ingestWindow(
      [
        { rowKey: 'a', slots: { name: 'Alice' }, staleSources },
        { rowKey: 'b', slots: { name: 'Bob' } },
      ],
      2,
      true,
      null,
      null,
      10,
    )
  }

  function renderSourced(controller: TableViewportController<Row>) {
    return render(
      <HilosViewportTable
        controller={controller}
        cells={{
          name: (r) => <span className="cell">{r.name}</span>,
          presence: () => <span className="cell">online</span>,
        }}
      />,
    )
  }

  function headers(container: HTMLElement): HTMLElement[] {
    return Array.from(container.querySelectorAll<HTMLElement>('thead th'))
  }

  it('raises the strip and names the columns built from the quiet source', () => {
    const { controller } = makeController({ columns: SOURCED_COLUMNS })
    window(controller, ['connections'])
    const { container } = renderSourced(controller)

    expect(
      container.querySelector('[data-id="hilos-table-stale"]')?.textContent,
    ).toBe(
      'Presence is not updating: the link to its source was lost. The other columns are live.',
    )
  })

  it('raises the strip in the generic wording when no column named the source', () => {
    const { controller } = makeController()
    window(controller, ['connections'])
    // COLUMNS declares no source at all, so there is nothing to name — and saying
    // nothing would leave yesterday's value looking like today's.
    const { container } = renderTable(controller)

    expect(
      container.querySelector('[data-id="hilos-table-stale"]')?.textContent,
    ).toBe(
      'Some values here are not updating: the link to their source was lost. The other columns are live.',
    )
  })

  it('keeps the sort control on a quiet column and carries the warning inside it', () => {
    const { controller } = makeController({ columns: SOURCED_COLUMNS })
    window(controller, ['connections'])
    const { container } = renderSourced(controller)

    const button = container.querySelector(
      '[data-id="hilos-table-sort-presence"]',
    )
    expect(button).not.toBeNull()
    expect(
      container.querySelector('[data-id="hilos-table-sort-name"]'),
    ).not.toBeNull()
    expect(
      button?.querySelector('[data-id="hilos-table-stale-column-presence"]'),
    ).not.toBeNull()
    expect(headers(container)[1]?.textContent).toContain(
      'Sorting by this column may be wrong',
    )
  })

  it('marks a quiet column that was never sortable without giving it a control', () => {
    const { controller } = makeController({
      columns: [
        SOURCED_COLUMNS[0]!,
        { ...SOURCED_COLUMNS[1]!, sortable: false },
      ],
    })
    window(controller, ['connections'])
    const { container } = renderSourced(controller)

    const header = headers(container)[1]
    expect(
      container.querySelector('[data-id="hilos-table-sort-presence"]'),
    ).toBeNull()
    expect(
      header?.querySelector('[data-id="hilos-table-stale-column-presence"]'),
    ).not.toBeNull()
    expect(header?.textContent).toContain("This column's source is lagging")
    expect(header?.textContent).not.toContain(
      'Sorting by this column may be wrong',
    )
  })

  it('sends a viewport when the name of a quiet column is clicked', () => {
    const { controller, sent } = makeController({ columns: SOURCED_COLUMNS })
    window(controller, ['connections'])
    const { container } = renderSourced(controller)
    sent.length = 0
    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-table-sort-presence"]',
      ) as HTMLElement,
    )

    expect(sent).toHaveLength(1)
  })

  it('numbers headers of a composite order whose first column is quiet and speaks both places', () => {
    const { controller } = makeController({ columns: SOURCED_COLUMNS })
    window(controller, ['connections'])
    controller.setOrder([
      { field: 'presence', direction: 'asc' },
      { field: 'name', direction: 'desc' },
    ])
    const { container } = renderSourced(controller)

    expect(
      Array.from(container.querySelectorAll('th sup')).map(
        (mark) => mark.textContent,
      ),
    ).toEqual(['2', '1'])
    expect(
      Array.from(container.querySelectorAll('th sup + .visually-hidden')).map(
        (mark) => mark.textContent,
      ),
    ).toEqual(['Sort column 2 of 2', 'Sort column 1 of 2'])
  })

  it('keeps the standing order over a column that went quiet readable', () => {
    const { controller } = makeController({ columns: SOURCED_COLUMNS })
    window(controller, [])
    const { container } = renderSourced(controller)
    act(() => controller.setSort('presence'))
    act(() =>
      controller.ingestDelta({
        kind: 'row_stale',
        rowKey: 'a',
        staleSources: ['connections'],
      }),
    )

    // The rows lie in that order right now, and saying so is the truth; only
    // choosing or reversing it is gone.
    const header = headers(container)[1]
    expect(header?.getAttribute('aria-sort')).toBe('ascending')
    expect(header?.querySelector('.bi-arrow-up')).not.toBeNull()
  })

  it('marks exactly the rows whose own values are behind', () => {
    const { controller } = makeController({ columns: SOURCED_COLUMNS })
    window(controller, ['connections'])
    const { container } = renderSourced(controller)

    expect(
      container.querySelector('[data-id="hilos-table-stale-row-a"]'),
    ).not.toBeNull()
    expect(
      container.querySelector('[data-id="hilos-table-stale-row-b"]'),
    ).toBeNull()
  })

  it('shows a quiet source in the permanent state cell with nothing waiting', () => {
    const { controller } = makeController({ columns: SOURCED_COLUMNS })
    window(controller, ['connections'])
    const { container } = renderSourced(controller)

    expect(headers(container)).toHaveLength(SOURCED_COLUMNS.length + 1)
    expect(container.querySelector('thead th:last-child')?.textContent).toBe(
      ' Will move Will leaveRow state and controls',
    )
    // The same state track stands in the header and every live row.
    const cells = container.querySelectorAll('[data-id="hilos-table-row-a"] td')
    expect(
      cells[cells.length - 1]?.querySelector(
        '[data-id="hilos-table-stale-row-a"]',
      ),
    ).not.toBeNull()
  })

  it('clears the strip and snowflakes while keeping the permanent state cell', () => {
    const { controller } = makeController({ columns: SOURCED_COLUMNS })
    window(controller, ['connections'])
    const { container } = renderSourced(controller)
    act(() =>
      controller.ingestDelta({
        kind: 'row_stale',
        rowKey: 'a',
        staleSources: [],
      }),
    )

    expect(container.querySelector('[data-id="hilos-table-stale"]')).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-table-stale-column-presence"]'),
    ).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-table-stale-row-a"]'),
    ).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-table-sort-presence"]'),
    ).not.toBeNull()
    expect(headers(container)).toHaveLength(SOURCED_COLUMNS.length + 1)
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
      {
        key: 'delete',
        label: 'Delete',
        refusalTitle: "Couldn't delete",
        danger: true,
        run: neverRun,
      },
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
        cells={{ name: (r) => <span className="cell">{r.name}</span> }}
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
    expect(container.querySelectorAll('thead th')).toHaveLength(
      COLUMNS.length + 1,
    )
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
    ).toBe(String(COLUMNS.length + 2))

    const empty = makeController(BULK_FRAME)
    empty.controller.ingestWindow([], 0, true, null, null, 10)
    const emptyTable = renderWithEdge(empty.controller).container
    expect(emptyTable.querySelector('tbody td')?.getAttribute('colspan')).toBe(
      String(COLUMNS.length + 2),
    )
  })
})

describe('HilosViewportTable drawing work in progress', () => {
  afterEach(cleanup)

  it('hands the whole bar, detail and all, to the places beside the track', () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      1,
      true,
      null,
      null,
      10,
    )
    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 34,
      total: 120,
      detail: { title: 'Nightly check' },
    })
    const { container } = render(
      <HilosViewportTable
        controller={controller}
        cells={{ name: (r) => r.name }}
        tableProgress={(progress: HilosTableProgress) => (
          <span data-id="page-progress-title">
            {`${String(progress.detail['title'])} — ${progress.current} of ${progress.total}`}
          </span>
        )}
        tableProgressAction={(progress: HilosTableProgress) => (
          <button type="button" data-id="page-progress-stop">
            {`Stop ${progress.progressKey}`}
          </button>
        )}
      />,
    )

    const line = container.querySelector('[data-id="hilos-table-progress"]')
    expect(
      line?.querySelector('[data-id="page-progress-title"]')?.textContent,
    ).toBe('Nightly check — 34 of 120')
    expect(
      line?.querySelector('[data-id="page-progress-stop"]')?.textContent,
    ).toBe('Stop nightly')
  })
})

describe('HilosViewportTable drawing work over one row', () => {
  afterEach(cleanup)

  // Columns that name where the bar goes: under the two content columns and not
  // under the actions one, the way the mockup draws it.
  const MARKED_COLUMNS: HilosTableColumn[] = [
    { key: 'mark', label: '' },
    { key: 'name', label: 'Name', progress: true },
    { key: 'kind', label: 'Kind', progress: true },
    { key: 'actions', label: '' },
  ]

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

  function rowBar(
    controller: TableViewportController<Row>,
    rowKey: string,
  ): void {
    controller.ingestProgress({
      scope: 'row',
      progressKey: `pack-${rowKey}`,
      rowKey,
      current: 34,
      total: 110,
      detail: { note: 'packed 3.4 GB of 11 GB' },
    })
  }

  function renderMarked(controller: TableViewportController<Row>) {
    return render(
      <HilosViewportTable
        controller={controller}
        cells={{ name: (r) => r.name }}
      />,
    )
  }

  function barCells(container: HTMLElement): HTMLElement[] {
    return Array.from(
      container.querySelectorAll<HTMLElement>(
        '[data-id="hilos-table-progress-row-a"] td',
      ),
    )
  }

  it('draws the bar in a row of its own right after the row it belongs to', () => {
    const { controller } = makeController()
    window(controller)
    rowBar(controller, 'a')
    const { container } = renderTable(controller)

    const ids = Array.from(container.querySelectorAll('tbody tr')).map((node) =>
      node.getAttribute('data-id'),
    )
    expect(ids).toEqual([
      'hilos-table-row-a',
      'hilos-table-progress-row-a',
      'hilos-table-row-b',
    ])
  })

  it('stretches the bar under the marked columns and leaves the rest empty', () => {
    const { controller } = makeController({ columns: MARKED_COLUMNS })
    window(controller)
    rowBar(controller, 'a')
    const { container } = renderMarked(controller)

    const cells = barCells(container)
    expect(cells.map((cell) => cell.getAttribute('colspan'))).toEqual([
      '1',
      '2',
      '1',
      '1',
    ])
    expect(cells[1]?.querySelector('[role="progressbar"]')).not.toBeNull()
    expect(cells[0]?.querySelector('[role="progressbar"]')).toBeNull()
    expect(cells[2]?.querySelector('[role="progressbar"]')).toBeNull()
  })

  it('stretches the bar across the whole row when no column is marked', () => {
    const { controller } = makeController()
    window(controller)
    rowBar(controller, 'a')
    const { container } = renderTable(controller)

    const cells = barCells(container)
    expect(cells).toHaveLength(2)
    expect(cells.map((cell) => cell.getAttribute('colspan'))).toEqual([
      '1',
      '1',
    ])
    expect(cells[0]?.querySelector('[role="progressbar"]')).not.toBeNull()
    expect(cells[1]?.querySelector('[role="progressbar"]')).toBeNull()
  })

  it('adds the waiting cell to the bar row exactly as the header does', () => {
    const { controller } = makeController({ columns: MARKED_COLUMNS })
    window(controller)
    rowBar(controller, 'a')
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'b',
      reason: 'deleted',
    })
    const { container } = renderMarked(controller)

    const spans = barCells(container).map((cell) =>
      Number(cell.getAttribute('colspan')),
    )
    // The header grew by the waiting column, and the bar row grew with it: the
    // two are one sum, so the row can never be wider than its header.
    expect(spans.reduce((total, span) => total + span, 0)).toBe(
      container.querySelectorAll('thead th').length,
    )
    expect(spans).toEqual([1, 2, 1, 1])
  })

  it('hands the whole bar to the row-progress place', () => {
    const { controller } = makeController()
    window(controller)
    rowBar(controller, 'a')
    const { container } = render(
      <HilosViewportTable
        controller={controller}
        cells={{ name: (r) => r.name }}
        rowProgress={(progress: HilosTableProgress, rowKey: string) => (
          <span data-id={`page-row-note-${rowKey}`}>
            {String(progress.detail['note'])}
          </span>
        )}
      />,
    )

    expect(
      container.querySelector('[data-id="page-row-note-a"]')?.textContent,
    ).toBe('packed 3.4 GB of 11 GB')
  })

  it('leaves no caption line under a row where the page filled no place', () => {
    const { controller } = makeController()
    window(controller)
    rowBar(controller, 'a')
    const { container } = renderTable(controller)

    const row = container.querySelector(
      '[data-id="hilos-table-progress-row-a"]',
    ) as HTMLElement
    expect(row.querySelectorAll('div.small')).toHaveLength(0)
    expect(row.querySelector('[role="progressbar"]')).not.toBeNull()
  })

  it('draws no bar for a key outside the current window', () => {
    const { controller } = makeController()
    window(controller)
    rowBar(controller, 'z')
    const { container } = renderTable(controller)

    expect(
      container.querySelectorAll('[data-id^="hilos-table-progress-row-"]'),
    ).toHaveLength(0)
  })

  it('draws no bar under a row shown as a placeholder', () => {
    const { controller } = makeController()
    window(controller)
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()
    // The bar arrives AFTER the row became a placeholder, which is the one order
    // in which the core cannot have taken it down already: a removal drops the
    // bar at once, so this race is what the view's own condition is there for.
    rowBar(controller, 'a')
    const { container } = renderTable(controller)

    expect(
      container.querySelector('[data-id="hilos-table-placeholder"]'),
    ).not.toBeNull()
    expect(
      container.querySelector('[data-id="hilos-table-progress-row-a"]'),
    ).toBeNull()
  })

  it('draws no row under any row for a bar over the whole table', () => {
    const { controller } = makeController()
    window(controller)
    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 34,
      total: 120,
    })
    const { container } = renderTable(controller)

    expect(
      container.querySelectorAll('[data-id^="hilos-table-progress-row-"]'),
    ).toHaveLength(0)
  })
})

describe('HilosViewportTable expanding a card', () => {
  afterEach(cleanup)

  // The same table as next door with one field that did not fit a column: on a
  // narrow screen it is what the card opens into.
  const CARD_DETAIL_FRAME: HilosTableFrame = {
    title: 'Backups',
    columns: [
      { key: 'name', label: 'Name' },
      { key: 'kind', label: 'Kind' },
      { key: 'lastError', label: 'Error', detail: true },
      { key: 'actions', label: '' },
    ],
  }

  const CARD_DETAIL_CELLS = {
    name: () => <span className="named">Alice</span>,
    kind: () => <span className="kind">full</span>,
    actions: () => (
      <button type="button" className="restore">
        Restore
      </button>
    ),
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

  function renderDetailCards(
    controller: TableViewportController<Row>,
    details: Partial<
      Record<string, (row: Row, rowKey: string) => ReactNode>
    > = {
      lastError: () => <span className="reason">Mailbox full</span>,
    },
  ) {
    return render(
      <HilosViewportTable
        controller={controller}
        cells={CARD_DETAIL_CELLS}
        details={details}
      />,
    )
  }

  function inCard(
    container: HTMLElement,
    rowKey: string,
    selector: string,
  ): HTMLElement | null {
    return container.querySelector<HTMLElement>(
      `[data-id="hilos-table-card-${rowKey}"] ${selector}`,
    )
  }

  it('opens one card from the control in its head and closes it again', () => {
    const { controller } = makeController(CARD_DETAIL_FRAME)
    window(controller)
    const { container } = renderDetailCards(controller)
    const control = inCard(
      container,
      'a',
      '[data-id="hilos-table-expand-a"]',
    ) as HTMLElement

    expect(control.getAttribute('aria-expanded')).toBe('false')
    expect(control.textContent).toBe('Show details')
    expect(
      inCard(container, 'a', '[data-id="hilos-table-row-detail-a"]'),
    ).toBeNull()

    fireEvent.click(control)

    const panel = inCard(
      container,
      'a',
      '[data-id="hilos-table-row-detail-a"]',
    ) as HTMLElement
    expect(panel.textContent).toContain('Error')
    expect(panel.querySelector('.reason')?.textContent).toBe('Mailbox full')
    expect(
      inCard(container, 'b', '[data-id="hilos-table-row-detail-b"]'),
    ).toBeNull()

    fireEvent.click(
      inCard(container, 'a', '[data-id="hilos-table-expand-a"]') as HTMLElement,
    )

    expect(
      inCard(container, 'a', '[data-id="hilos-table-row-detail-a"]'),
    ).toBeNull()
  })

  it('stands the panel after the fields and before the controls', () => {
    const { controller } = makeController(CARD_DETAIL_FRAME)
    window(controller)
    const { container } = renderDetailCards(controller)
    fireEvent.click(
      inCard(container, 'a', '[data-id="hilos-table-expand-a"]') as HTMLElement,
    )

    const blocks = container.querySelectorAll(
      '[data-id="hilos-table-card-a"] .card-body > *',
    )
    expect(blocks[1]?.tagName).toBe('DL')
    expect(blocks[2]?.getAttribute('data-id')).toBe('hilos-table-row-detail-a')
    expect(blocks[3]?.classList.contains('hilos-button-row')).toBe(true)
  })

  it('gives the card panel an id of its own, apart from the row panel', () => {
    const { controller } = makeController(CARD_DETAIL_FRAME)
    window(controller)
    const { container } = renderDetailCards(controller)
    fireEvent.click(
      inCard(container, 'a', '[data-id="hilos-table-expand-a"]') as HTMLElement,
    )

    const rowPanel = container.querySelector(
      'tr[data-id="hilos-table-row-detail-a"]',
    ) as HTMLElement
    const cardControl = inCard(
      container,
      'a',
      '[data-id="hilos-table-expand-a"]',
    ) as HTMLElement
    const cardPanel = inCard(
      container,
      'a',
      '[data-id="hilos-table-row-detail-a"]',
    ) as HTMLElement

    expect(cardPanel.id).toBeTruthy()
    expect(cardPanel.id).not.toBe(rowPanel.id)
    expect(cardControl.getAttribute('aria-controls')).toBe(cardPanel.id)
    expect(cardControl.getAttribute('aria-expanded')).toBe('true')
  })

  it('offers no control on a card of a table that declared no such field', () => {
    const { controller } = makeController({
      title: 'Backups',
      columns: [
        { key: 'name', label: 'Name' },
        { key: 'actions', label: '' },
      ],
    })
    window(controller)
    const { container } = render(
      <HilosViewportTable
        controller={controller}
        cells={{ name: () => <span className="named">Alice</span> }}
      />,
    )

    expect(
      inCard(container, 'a', '[data-id="hilos-table-expand-a"]'),
    ).toBeNull()
  })

  it('stands a dash in a card field the page drew nothing into', () => {
    const { controller } = makeController(CARD_DETAIL_FRAME)
    window(controller)
    const { container } = renderDetailCards(controller, {})
    fireEvent.click(
      inCard(container, 'a', '[data-id="hilos-table-expand-a"]') as HTMLElement,
    )

    expect(
      inCard(container, 'a', '[data-id="hilos-table-row-detail-a"] dd')
        ?.textContent,
    ).toBe('—')
  })
})

describe('HilosViewportTable expanding a row', () => {
  afterEach(cleanup)

  // A table with one field that did not fit a column of its own: the row shows the
  // name, and the reason waits in the panel under it.
  const DETAIL_COLUMNS: HilosTableColumn[] = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'lastError', label: 'Error', detail: true },
  ]
  // The search the declaration carries is what the panels close on below.
  const DETAIL_FRAME: HilosTableFrame = { search: {}, columns: DETAIL_COLUMNS }

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

  function renderDetailTable(
    controller: TableViewportController<Row>,
    details: Partial<
      Record<string, (row: Row, rowKey: string) => ReactNode>
    > = {
      lastError: () => <span className="reason">Mailbox full</span>,
    },
  ) {
    return render(
      <HilosViewportTable
        controller={controller}
        cells={{ name: (r) => <span className="cell">{r.name}</span> }}
        details={details}
      />,
    )
  }

  function find(container: HTMLElement, selector: string): HTMLElement | null {
    return container.querySelector<HTMLElement>(selector)
  }

  it('keeps a detail column out of the header and out of the width of a row', () => {
    const { controller } = makeController(DETAIL_FRAME)
    window(controller)
    const { container } = renderDetailTable(controller)

    // One declared column left standing, plus the row-state cell the control lives in.
    expect(container.querySelectorAll('thead th')).toHaveLength(2)
    expect(find(container, 'thead')?.textContent).not.toContain('Error')
    expect(find(container, '[data-id="hilos-table-sort-lastError"]')).toBeNull()
  })

  it('offers the control only where a detail field was declared', () => {
    const { controller } = makeController(DETAIL_FRAME)
    window(controller)

    expect(
      find(
        renderDetailTable(controller).container,
        '[data-id="hilos-table-expand-a"]',
      ),
    ).not.toBeNull()
    const plain = makeController()
    window(plain.controller)
    expect(
      find(
        renderTable(plain.controller).container,
        '[data-id="hilos-table-expand-a"]',
      ),
    ).toBeNull()
  })

  it('opens the panel of one row and closes it again from the same control', () => {
    const { controller } = makeController(DETAIL_FRAME)
    window(controller)
    const { container } = renderDetailTable(controller)
    const control = find(
      container,
      '[data-id="hilos-table-expand-a"]',
    ) as HTMLElement

    expect(control.getAttribute('aria-expanded')).toBe('false')
    expect(control.textContent).toBe('Show details')
    expect(find(container, '[data-id="hilos-table-row-detail-a"]')).toBeNull()

    fireEvent.click(control)

    const panel = find(container, '[data-id="hilos-table-row-detail-a"]')
    expect(panel).not.toBeNull()
    expect(panel?.textContent).toContain('Error')
    expect(panel?.querySelector('.reason')?.textContent).toBe('Mailbox full')
    expect(
      find(container, '[data-id="hilos-table-expand-a"]')?.textContent,
    ).toBe('Hide details')
    expect(find(container, '[data-id="hilos-table-row-detail-b"]')).toBeNull()

    fireEvent.click(
      find(container, '[data-id="hilos-table-expand-a"]') as HTMLElement,
    )

    expect(find(container, '[data-id="hilos-table-row-detail-a"]')).toBeNull()
  })

  it('points the control at its own panel and hides the chevron from the reader', () => {
    const { controller } = makeController(DETAIL_FRAME)
    window(controller)
    const { container } = renderDetailTable(controller)
    fireEvent.click(
      find(container, '[data-id="hilos-table-expand-a"]') as HTMLElement,
    )

    const control = find(
      container,
      '[data-id="hilos-table-expand-a"]',
    ) as HTMLElement
    const panel = find(
      container,
      '[data-id="hilos-table-row-detail-a"]',
    ) as HTMLElement
    expect(control.getAttribute('aria-expanded')).toBe('true')
    expect(control.getAttribute('aria-controls')).toBe(panel.id)
    expect(panel.id).toBeTruthy()
    expect(control.querySelector('i')?.getAttribute('aria-hidden')).toBe('true')
    expect(
      control.querySelector('i')?.classList.contains('bi-chevron-up'),
    ).toBe(true)
  })

  it('spans the panel across the whole row, tinted as the row it hangs under', () => {
    const { controller } = makeController(DETAIL_FRAME)
    window(controller)
    const { container } = renderDetailTable(controller)
    fireEvent.click(
      find(container, '[data-id="hilos-table-expand-a"]') as HTMLElement,
    )

    const panel = find(
      container,
      '[data-id="hilos-table-row-detail-a"]',
    ) as HTMLElement
    expect(panel.classList.contains('table-active')).toBe(true)
    expect(panel.querySelector('td')?.getAttribute('colspan')).toBe('2')
    expect(
      find(container, '[data-id="hilos-table-row-a"]')?.classList.contains(
        'table-active',
      ),
    ).toBe(true)
  })

  it('stands a dash in a field the page declared but drew nothing into', () => {
    const { controller } = makeController(DETAIL_FRAME)
    window(controller)
    const { container } = renderDetailTable(controller, {})
    fireEvent.click(
      find(container, '[data-id="hilos-table-expand-a"]') as HTMLElement,
    )

    expect(
      find(container, '[data-id="hilos-table-row-detail-a"] dd')?.textContent,
    ).toBe('—')
  })

  it('closes every panel when the window changes', () => {
    const { controller } = makeController(DETAIL_FRAME)
    window(controller)
    const { container } = renderDetailTable(controller)
    fireEvent.click(
      find(container, '[data-id="hilos-table-expand-a"]') as HTMLElement,
    )
    fireEvent.click(
      find(container, '[data-id="hilos-table-expand-b"]') as HTMLElement,
    )

    fireEvent.change(
      find(container, '[data-id="hilos-table-search"]') as HTMLElement,
      { target: { value: 'failed' } },
    )

    expect(find(container, '[data-id="hilos-table-row-detail-a"]')).toBeNull()
    expect(find(container, '[data-id="hilos-table-row-detail-b"]')).toBeNull()
  })

  it('hands a detail field the row and its key, as a cell is handed them', () => {
    const { controller } = makeController(DETAIL_FRAME)
    window(controller)
    const { container } = renderDetailTable(controller, {
      lastError: (r, rowKey) => (
        <span className="reason">{`${r.name} ${rowKey}`}</span>
      ),
    })
    fireEvent.click(
      find(container, '[data-id="hilos-table-expand-b"]') as HTMLElement,
    )

    expect(
      find(container, '[data-id="hilos-table-row-detail-b"] .reason')
        ?.textContent,
    ).toBe('Bob b')
  })
})

describe('HilosViewportTable drawing the states of the body', () => {
  afterEach(() => {
    cleanup()
    vi.useRealTimers()
  })

  const STATE_COLUMNS: HilosTableColumn[] = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'kind', label: 'Kind' },
  ]
  const STATE_FRAME: HilosTableFrame = {
    title: 'Backups',
    search: {},
    columns: STATE_COLUMNS,
    empty: { title: 'Nothing here yet' },
  }

  function renderStates(controller: TableViewportController<Row>) {
    return render(
      <HilosViewportTable
        controller={controller}
        cells={{ name: (r) => <span className="named">{r.name}</span> }}
      />,
    )
  }

  function twoRows(controller: TableViewportController<Row>): void {
    controller.ingestWindow(
      [
        { rowKey: 'a', slots: { name: 'Alice' } },
        { rowKey: 'b', slots: { name: 'Bob' } },
      ],
      2,
      true,
      null,
      null,
      25,
    )
  }

  it('keeps the rows through a quick change and draws a skeleton as tall as the window past the threshold', () => {
    vi.useFakeTimers()
    const { controller } = makeController(STATE_FRAME)
    twoRows(controller)
    const { container } = renderStates(controller)

    act(() => {
      controller.setSort('name')
      vi.advanceTimersByTime(399)
    })
    expect(
      container.querySelectorAll('[data-id="hilos-table-loading"]'),
    ).toHaveLength(0)
    expect(container.querySelectorAll('.named')).toHaveLength(4)

    act(() => {
      vi.advanceTimersByTime(1)
    })

    // Both branches say it, each in its own shape: rows of cells in the table,
    // one bar per card in the list.
    const loading = container.querySelectorAll(
      '[data-id="hilos-table-loading"]',
    )
    expect(loading).toHaveLength(2)
    expect(loading[0]?.getAttribute('aria-busy')).toBe('true')
    const tableRows = container.querySelectorAll(
      'tbody[data-id="hilos-table-loading"] [data-id="hilos-table-skeleton-row"]',
    )
    expect(tableRows).toHaveLength(2)
    // A cell for every column standing in the row, so the widths hold.
    expect(tableRows[0]?.querySelectorAll('td')).toHaveLength(3)
    expect(
      container.querySelectorAll(
        '[data-id="hilos-table-cards"] [data-id="hilos-table-skeleton-row"]',
      ),
    ).toHaveLength(2)
    expect(
      container.querySelectorAll('.placeholder[aria-hidden="true"]'),
    ).toHaveLength(2 * 3)
    expect(
      Array.from(container.querySelectorAll('[role="status"]')).filter(
        (node) => node.textContent === 'Loading…',
      ),
    ).toHaveLength(2)
    expect(container.querySelectorAll('.named')).toHaveLength(0)
  })

  it('counts the skeleton by the window size when the window before was empty', () => {
    vi.useFakeTimers()
    const { controller } = makeController(STATE_FRAME)
    controller.ingestWindow([], 0, true, null, null, 3)
    const { container } = renderStates(controller)

    act(() => {
      controller.setSearch('x')
      vi.advanceTimersByTime(400)
    })

    expect(
      container.querySelectorAll(
        'tbody[data-id="hilos-table-loading"] [data-id="hilos-table-skeleton-row"]',
      ),
    ).toHaveLength(3)
  })

  it('says nothing was found in both branches and resets out of it', () => {
    const { controller, sent } = makeController(STATE_FRAME)
    twoRows(controller)
    const { container } = renderStates(controller)

    act(() => {
      controller.setSearch('night')
      controller.ingestWindow([], 0, true, null, null, 25)
    })

    const states = container.querySelectorAll(
      '[data-id="hilos-table-no-matches"]',
    )
    expect(states).toHaveLength(2)
    expect(
      container.querySelectorAll('[data-id="hilos-table-no-matches-terms"]')[1]
        ?.textContent,
    ).toBe('No rows match “night”')
    expect(container.querySelector('[data-id="hilos-table-empty"]')).toBeNull()

    act(() => {
      fireEvent.click(
        container.querySelectorAll(
          '[data-id="hilos-table-no-matches-reset"]',
        )[1] as HTMLElement,
      )
    })
    expect(sent.at(-1)?.filter).toEqual({})
  })

  it('draws the declared empty state in both branches when nothing filters the set', () => {
    const { controller } = makeController(STATE_FRAME)
    const { container } = renderStates(controller)

    act(() => controller.ingestWindow([], 0, true, null, null, 25))

    expect(
      container.querySelectorAll('[data-id="hilos-table-empty-title"]'),
    ).toHaveLength(2)
    expect(
      container.querySelector('[data-id="hilos-table-no-matches"]'),
    ).toBeNull()
  })

  it('draws List unavailable in both branches when the window was refused, with no footer', () => {
    const { controller } = makeController(STATE_FRAME)
    twoRows(controller)
    const { container } = renderStates(controller)

    act(() => controller.ingestRefusal('internal_error'))

    const tiles = container.querySelectorAll(
      '[data-id="hilos-table-unavailable"]',
    )
    expect(tiles).toHaveLength(2)
    expect(tiles[0]?.getAttribute('role')).toBe('status')
    expect(
      container.querySelector('[data-id="hilos-table-unavailable-title"]')
        ?.textContent,
    ).toBe('List unavailable')
    expect(
      container.querySelector('[data-id="hilos-table-unavailable-hint"]')
        ?.textContent,
    ).toBe(
      'The rows of this list could not be fetched. The rest of the page still works.',
    )
    expect(container.querySelector('[data-id="hilos-table-count"]')).toBeNull()
  })
})

describe('HilosViewportTable rows with nothing to expand and wide fields', () => {
  afterEach(cleanup)

  // A names table in miniature: Bob has no corrections, so his row has nothing to open,
  // and the corrections are a list that takes the panel's whole width.
  const WIDE_FRAME: HilosTableFrame = {
    title: 'Names',
    columns: [
      { key: 'name', label: 'Name' },
      { key: 'lastError', label: 'Error', detail: true },
      {
        key: 'corrections',
        label: 'Locale corrections',
        detail: true,
        detailWide: true,
      },
    ],
  }

  function renderWide() {
    const controller = new TableViewportController<Row>({
      resolve: (raw) => ({ name: String(raw.slots.name) }),
      sendViewport: () => {},
      frame: WIDE_FRAME,
      expandable: (row) => row.name !== 'Bob',
    })
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
    const rendered = render(
      <HilosViewportTable
        controller={controller}
        cells={{ name: (row) => <span className="named">{row.name}</span> }}
      />,
    )

    return { controller, container: rendered.container }
  }

  it('draws no control, in the row or in the card, on a row with nothing to open', () => {
    const { container } = renderWide()

    expect(
      container.querySelectorAll('[data-id="hilos-table-expand-a"]'),
    ).toHaveLength(2)
    expect(
      container.querySelector('[data-id="hilos-table-expand-b"]'),
    ).toBeNull()
  })

  it('gives a wide field the whole width of the panel and of the card', () => {
    const { container } = renderWide()
    const control = container.querySelector<HTMLElement>(
      'tr [data-id="hilos-table-expand-a"]',
    )
    expect(control).not.toBeNull()

    fireEvent.click(control as HTMLElement)

    const fields = container.querySelectorAll(
      'tr[data-id="hilos-table-row-detail-a"] dl > div',
    )
    expect(fields).toHaveLength(2)
    expect(fields[0]?.classList.contains('col-md-12')).toBe(false)
    expect(fields[1]?.classList.contains('col-md-12')).toBe(true)

    const card = container.querySelector(
      '[data-id="hilos-table-card-a"] [data-id="hilos-table-row-detail-a"]',
    )
    const labels = card?.querySelectorAll('dt') ?? []
    expect(labels[0]?.classList.contains('col-5')).toBe(true)
    expect(labels[1]?.classList.contains('col-12')).toBe(true)
    expect(card?.querySelectorAll('dd')[1]?.classList.contains('col-12')).toBe(
      true,
    )
  })

  it('closes the panel of a row that lost what it had to show', () => {
    const { controller, container } = renderWide()
    act(() => controller.expandRow('a', true))

    expect(
      container.querySelector('tr[data-id="hilos-table-row-detail-a"]'),
    ).not.toBeNull()

    act(() =>
      controller.ingestDelta({
        kind: 'row_updated',
        rowKey: 'a',
        row: { rowKey: 'a', slots: { name: 'Bob' } },
      }),
    )

    expect(
      container.querySelector('[data-id="hilos-table-row-detail-a"]'),
    ).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-table-expand-a"]'),
    ).toBeNull()
  })
})

// These synthetic boxes prove production ref wiring and commit timing. Actual
// browser layout, Sass and first-paint stability belong to the browser slice.
function tableGeometryDOM() {
  const layout = {
    width: 600,
    main: 41.25,
    card: 260.75,
    reason: 18,
    fallback: 48,
    font: 16,
    cardHidden: false,
    wideHidden: false,
  }
  const reads: string[] = []
  const firstPlaceholders: { projection: string; pixels: string }[] = []
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(
    function (this: HTMLElement) {
      const card = this.closest('[data-id="hilos-table-cards"]') !== null
      if (
        (layout.cardHidden && card) ||
        (layout.wideHidden && this.closest('.table-responsive'))
      )
        return new DOMRect()
      const projection = card ? 'card' : 'wide'
      const rect = (top: number, height: number, width = layout.width, x = 0) =>
        new DOMRect(x, top, width, height)
      const id = this.dataset.id ?? ''
      if (/^hilos-table-(row|card|progress-row|row-detail)-/.test(id))
        reads.push(`${projection}:${id}`)
      if (/^hilos-table-(row|card)-[ab]$/.test(id))
        return rect(card ? 300 : 40, card ? layout.card : layout.main)
      if (
        id.startsWith('hilos-table-progress-row-') ||
        this.classList.contains('hilos-table-work-reserve')
      )
        return rect(card ? 450 : 40 + layout.main, card ? 24.5 : 18.5)
      if (id.startsWith('hilos-table-row-detail-'))
        return rect(card ? 380 : 58.5 + layout.main, 60.25)
      if (this.matches('.hilos-button-row')) return rect(400, 46)
      if (this.matches('.hilos-table-replacement-row'))
        return rect(0, layout.reason + 1)
      if (
        this.matches(
          '.hilos-table-reason-twins, .hilos-table-record-space > span',
        )
      ) {
        if (this.matches('.hilos-table-record-space > span'))
          firstPlaceholders.push({
            projection,
            pixels: this.parentElement!.style.getPropertyValue(
              '--hilos-table-record-px',
            ),
          })
        return rect(0, layout.reason)
      }
      if (this.tagName === 'TH') {
        const cells = [...this.parentElement!.children]
        const width = layout.width / cells.length
        return rect(0, 20, width, cells.indexOf(this) * width)
      }
      return rect(0, 200)
    },
  )
  const nativeStyle = window.getComputedStyle.bind(window)
  vi.spyOn(window, 'getComputedStyle').mockImplementation((node) => {
    const style = nativeStyle(node)
    const card = node.classList.contains('card')
    const body = node.classList.contains('card-body')
    const values: Record<string, string> = {
      fontSize: `${layout.font}px`,
      borderCollapse: 'collapse',
      borderSpacing: '0px',
      paddingTop: body ? '8px' : '0px',
      paddingBottom: body ? '8px' : '0px',
      borderTopWidth: card ? '1px' : '0px',
      borderBottomWidth: card ? '1px' : '0px',
      marginTop: node.matches(
        '.card-body > [data-id^="hilos-table-progress-row-"]',
      )
        ? '4px'
        : '0px',
      marginBottom:
        card ||
        node.matches('.card-body > [data-id^="hilos-table-row-detail-"]')
          ? '8px'
          : '0px',
      ...(node.classList.contains('hilos-table-fallback')
        ? { height: `${layout.fallback}px` }
        : {}),
    }
    return new Proxy(style, {
      get(target, key) {
        return typeof key === 'string' && key in values
          ? values[key]
          : Reflect.get(target, key)
      },
    })
  })
  const resizes: { fire(): void; disconnect: ReturnType<typeof vi.fn> }[] = []
  class Resize {
    observe = vi.fn()
    unobserve = vi.fn()
    disconnect = vi.fn()
    constructor(callback: () => void) {
      resizes.push({ fire: callback, disconnect: this.disconnect })
    }
  }
  vi.stubGlobal('ResizeObserver', Resize)
  const frames = new Map<number, FrameRequestCallback>()
  let sequence = 0
  vi.spyOn(window, 'requestAnimationFrame').mockImplementation((callback) => {
    frames.set(++sequence, callback)
    return sequence
  })
  vi.spyOn(window, 'cancelAnimationFrame').mockImplementation((id) => {
    frames.delete(id)
  })
  return {
    layout,
    reads,
    firstPlaceholders,
    resizes,
    frames,
    flush() {
      const callbacks = [...frames.values()]
      frames.clear()
      act(() => callbacks.forEach((callback) => callback(0)))
    },
  }
}

describe('HilosViewportTable connected geometry', () => {
  const targets: TableViewportController<Row>[] = []
  const extras: HTMLElement[] = []
  afterEach(() => {
    cleanup()
    targets.splice(0).forEach((target) => target.dispose())
    extras.splice(0).forEach((element) => element.remove())
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
  })

  function make(
    options: {
      detail?: boolean
      search?: boolean
      bulk?: boolean
      names?: string[]
      text?: 'text' | 'parts'
    } = {},
  ) {
    const { controller } = makeController({
      title: 'Geometry',
      search: options.search ? {} : undefined,
      ...(options.text
        ? { rowHeight: { mode: 'lines', count: 2 } as const }
        : {}),
      columns: [
        {
          key: 'name',
          label: 'Name',
          source: 'user',
          sortable: true,
          heightContent: options.text,
        },
        { key: 'badge', label: 'Status', card: 'badge' },
        { key: 'actions', label: '' },
        ...(options.detail === false
          ? []
          : [{ key: 'detail', label: 'Detail', detail: true }]),
      ],
      bulkActions: options.bulk
        ? [
            {
              key: 'delete',
              label: 'Delete',
              refusalTitle: 'Could not delete',
              run: () => {
                throw new Error('unused bulk action')
              },
            },
          ]
        : undefined,
    })
    targets.push(controller)
    snapshot(controller, options.names ?? ['a', 'b'])
    const source = controller.geometry
    const registrations: TableGeometryRegistration[] = []
    const captures = vi.fn()
    Object.defineProperty(controller, 'geometry', {
      value: {
        register(capture: Parameters<typeof source.register>[0]) {
          const owner = source.register((request) => {
            const result = capture(request)
            captures(result)
            return result
          })
          const registration = { ...owner, dispose: vi.fn(owner.dispose) }
          registrations.push(registration)
          return registration
        },
      },
    })
    return { controller, registrations, captures }
  }

  function snapshot(target: TableViewportController<Row>, names = ['a', 'b']) {
    target.ingestWindow(
      names.map((rowKey) => ({ rowKey, slots: { name: `Private ${rowKey}` } })),
      names.length,
      true,
      null,
      null,
      10,
    )
  }

  function depart(
    target: TableViewportController<Row>,
    rowKey = 'a',
    own = true,
  ) {
    target.ingestDelta({ kind: 'row_removed', rowKey, reason: 'deleted', own })
  }

  function work(target: TableViewportController<Row>, ended = false) {
    target.ingestProgress({
      scope: 'row',
      rowKey: 'a',
      progressKey: 'job',
      current: ended ? 2 : 1,
      total: 2,
      ended,
    })
  }

  function content() {
    return {
      cells: {
        name: vi.fn((row: Row) => <span>{row.name}</span>),
        badge: vi.fn(() => <span data-id="project-badge">Ready</span>),
        actions: vi.fn((_row: Row, key: string) => (
          <button data-id={`project-action-${key}`}>Edit</button>
        )),
      },
      details: { detail: vi.fn((row: Row) => <span>{row.name} detail</span>) },
      rowProgress: vi.fn(() => <span>Private work payload</span>),
    }
  }

  function pixels(
    container: HTMLElement,
    projection: 'wide' | 'card',
    rowKey = 'a',
  ) {
    return container
      .querySelector<HTMLElement>(
        `[data-id="hilos-table-${projection === 'wide' ? 'row' : 'card'}-${rowKey}"] .hilos-table-record-space`,
      )
      ?.style.getPropertyValue('--hilos-table-record-px')
  }

  function textDOM() {
    const dom = tableGeometryDOM()
    const overflow = { height: 80 }
    for (const property of [
      'clientWidth',
      'clientHeight',
      'scrollWidth',
      'scrollHeight',
    ] as const)
      vi.spyOn(HTMLElement.prototype, property, 'get').mockImplementation(() =>
        property === 'clientHeight'
          ? 40
          : property === 'scrollHeight'
            ? overflow.height
            : 200,
      )
    return { ...dom, overflow }
  }

  function textControls(container: HTMLElement) {
    return [
      ...container.querySelectorAll<HTMLButtonElement>(
        '[data-id="hilos-table-text-toggle"]',
      ),
    ]
  }

  it.each(['text', 'parts'] as const)(
    'connects real %s consumers on the first committed reader, independently of row detail',
    (text) => {
      const dom = textDOM()
      const { controller, registrations } = make({ text, names: ['a'] })
      const { container } = render(
        <HilosViewportTable
          controller={controller}
          cells={{
            name: (row) =>
              text === 'parts' ? (
                <HilosTableText value={row.name} />
              ) : (
                <em>{row.name}</em>
              ),
            badge: () => <span className="badge">Ready</span>,
            actions: () => <button type="button">Act</button>,
          }}
          details={{
            detail: () => <button type="button">Detail action</button>,
          }}
        />,
      )
      dom.flush()
      const [wide, card] = textControls(container)
      expect(registrations).toHaveLength(1)
      expect(wide.getAttribute('aria-label')).toBe('Show in full: Name')
      expect(wide.getAttribute('aria-controls')).not.toBe(
        card.getAttribute('aria-controls'),
      )
      expect(container.querySelector('span.fw-medium')).toBeNull()
      expect(
        container.querySelector(
          '.card-body > div > div.fw-medium .hilos-table-text',
        ),
      ).not.toBeNull()
      fireEvent.click(wide)
      expect(
        textControls(container).map((button) => button.textContent),
      ).toEqual(['Collapse', 'Collapse'])
      expect(controller.rows.get()[0].expanded).toBe(false)
      fireEvent.click(
        container.querySelector('[data-id="hilos-table-expand-a"]')!,
      )
      expect(controller.rows.get()[0].expanded).toBe(true)
      expect(
        textControls(container).every(
          (button) => button.getAttribute('aria-expanded') === 'true',
        ),
      ).toBe(true)
      const detail = container.querySelector(
        '[data-id="hilos-table-row-detail-a"]',
      )!
      expect(detail.querySelector('.hilos-table-text')).toBeNull()
      expect(detail.querySelector('button')?.textContent).toBe('Detail action')
      expect(wide.getAttribute('aria-controls')).not.toBe(detail.id)
      expect(container.querySelector('.ms-auto .hilos-table-text')).toBeNull()
      expect(
        container.querySelector('.hilos-button-row .hilos-table-text'),
      ).toBeNull()
    },
  )

  it.each(['text', 'parts'] as const)(
    'keeps the same focused %s button across live renderer changes and collapse',
    (text) => {
      const dom = textDOM()
      const { controller } = make({ text, names: ['a'], bulk: true })
      const { container } = render(
        <HilosViewportTable
          controller={controller}
          cells={{
            name: (row) =>
              text === 'parts' ? (
                <HilosTableText value={row.name} />
              ) : (
                <em>{row.name}</em>
              ),
          }}
        />,
      )
      dom.flush()
      const button = textControls(container)[0]
      act(() => button.focus())
      fireEvent.click(button)
      act(() =>
        controller.ingestDelta({
          kind: 'row_moved',
          rowKey: 'a',
          row: { rowKey: 'a', slots: { name: 'Moved live text' } },
        }),
      )
      expect(textControls(container)[0]).toBe(button)
      expect(button.textContent).toBe('Collapse')
      expect(controller.rows.get()[0].pending).toBe('move')
      dom.overflow.height = 40
      act(() =>
        controller.ingestDelta({
          kind: 'row_updated',
          rowKey: 'a',
          row: { rowKey: 'a', slots: { name: 'Short' } },
        }),
      )
      dom.flush()
      expect(textControls(container)[0]).toBe(button)
      expect(button.textContent).toBe('Collapse')
      expect(document.activeElement).toBe(button)
      expect(controller.rows.get()[0].selected).toBe(false)
      expect(controller.rows.get()[0].expanded).toBe(false)
      fireEvent.click(button)
      dom.flush()
      expect(textControls(container)).toEqual([button])
      expect(button.textContent).toBe('Show in full')
      expect(document.activeElement).toBe(button)
      act(() => button.blur())
      expect(textControls(container)).toHaveLength(0)
    },
  )

  it.each(['reset', 'replacement', 'refusal', 'reuse', 'controller'] as const)(
    'rebinds the real consumer after %s with empty intent and rejects old queued work',
    (change) => {
      const dom = textDOM()
      const first = make({ text: 'parts', names: ['a'] })
      const second = make({ text: 'parts', names: ['a'] })
      const cells = { name: (row: Row) => <HilosTableText value={row.name} /> }
      const { container, rerender, unmount } = render(
        <StrictMode>
          <HilosViewportTable controller={first.controller} cells={cells} />
        </StrictMode>,
      )
      dom.flush()
      const old = textControls(container)[0]
      expect(first.controller.expandedText.get().size).toBe(0)
      const queued = [...dom.frames.values()]
      const resizes = [...dom.resizes]
      const command = vi.spyOn(first.controller, 'expandText')
      act(() => {
        if (change === 'reset') first.controller.resetWindowRequests()
        if (change === 'replacement') snapshot(first.controller, ['a'])
        if (change === 'refusal') first.controller.ingestRefusal('forbidden')
        if (change === 'reuse') {
          depart(first.controller)
          snapshot(first.controller, ['a'])
        }
      })
      if (change === 'controller')
        rerender(
          <StrictMode>
            <HilosViewportTable controller={second.controller} cells={cells} />
          </StrictMode>,
        )
      act(() => {
        for (const callback of queued) callback(0)
        for (const observer of resizes) observer.fire()
      })
      dom.flush()
      expect(old.isConnected).toBe(false)
      fireEvent.click(old)
      expect(command).not.toHaveBeenCalled()
      if (change === 'refusal' || change === 'reset') {
        expect(textControls(container)).toHaveLength(0)
        if (change === 'refusal')
          expect(container.textContent).not.toContain('Private')
        else {
          act(() => snapshot(first.controller, ['a']))
          dom.flush()
          expect(textControls(container)).toHaveLength(2)
        }
      } else {
        expect(textControls(container)).toHaveLength(2)
        expect(
          textControls(container).every(
            (button) => button.textContent === 'Show in full',
          ),
        ).toBe(true)
      }
      unmount()
      act(() => {
        for (const callback of queued) callback(0)
        for (const observer of resizes) observer.fire()
      })
      expect(container.textContent).toBe('')
    },
  )

  it('distinguishes missing card renderers from empty declared regions and preserves wide cells', () => {
    tableGeometryDOM()
    const { controller } = makeController({
      title: 'Empty renderers',
      rowHeight: { mode: 'lines', count: 2 },
      columns: [
        { key: 'name', label: 'Name', card: 'title', heightContent: 'text' },
        { key: 'empty', label: 'Empty', heightContent: 'text' },
        { key: 'missing', label: 'Missing', heightContent: 'text' },
        { key: 'intrinsic', label: 'Intrinsic' },
      ],
    })
    targets.push(controller)
    snapshot(controller, ['a'])
    const { container } = render(
      <HilosViewportTable
        controller={controller}
        cells={{
          name: () => null,
          empty: () => null,
          intrinsic: () => <button type="button">Act</button>,
        }}
      />,
    )
    const card = container.querySelector('[data-id="hilos-table-card-a"]')!
    expect(card.textContent).toContain('Empty')
    expect(card.textContent).not.toContain('Missing')
    expect(card.querySelectorAll('.hilos-table-text-lines-2')).toHaveLength(2)
    expect(
      card.querySelector('button')?.closest('.hilos-table-text'),
    ).toBeNull()
    const row = container.querySelector('[data-id="hilos-table-row-a"]')!
    expect(row.querySelectorAll('td')).toHaveLength(5)
    expect(row.querySelectorAll('.hilos-table-text-lines-2')).toHaveLength(3)
  })

  it('captures expanded text plus work/detail synchronously, holds pending removal, and retires both projections data-free', () => {
    const dom = textDOM()
    const { controller, captures, registrations } = make({
      text: 'parts',
      names: ['a'],
    })
    work(controller)
    const renderers = content()
    const name = vi.fn((row: Row) => <HilosTableText value={row.name} />)
    const { container } = render(
      <HilosViewportTable
        controller={controller}
        {...renderers}
        cells={{ ...renderers.cells, name }}
      />,
    )
    dom.flush()
    dom.layout.main = 180.5
    dom.layout.card = 500.75
    fireEvent.click(textControls(container)[0])
    fireEvent.click(
      container.querySelector('[data-id="hilos-table-expand-a"]')!,
    )
    dom.flush()
    act(() => depart(controller, 'a', false))
    expect(textControls(container).map((button) => button.textContent)).toEqual(
      ['Collapse', 'Collapse'],
    )
    expect(container.textContent).toContain('Private a')
    captures.mockClear()
    name.mockClear()
    renderers.details.detail.mockClear()
    renderers.rowProgress.mockClear()
    const stop = subscribeSignal(controller.rows, () => {
      expect(captures).toHaveBeenCalledTimes(1)
      expect(
        captures.mock.calls[0][0].records.find(
          (sample: { measurement: { layout: { projection: string } } }) =>
            sample.measurement.layout.projection === 'wide',
        ).extent,
      ).toMatchObject({ main: 180.5, work: 18.5, detail: 60.25 })
    })
    try {
      act(() => controller.apply())
    } finally {
      stop()
    }
    expect(captures).toHaveBeenCalledTimes(1)
    expect(pixels(container, 'wide')).toBe('258.25')
    expect(pixels(container, 'card')).toBe('508.75')
    expect(registrations[0].record('a', 'wide')?.retained).toBe(259.25)
    expect(container.textContent).not.toContain('Private')
    expect(
      container.querySelector(
        '.hilos-table-text, [data-id="hilos-table-text-toggle"], [data-id^="project-action-"], [data-id^="hilos-table-row-detail-"], [role="progressbar"]',
      ),
    ).toBeNull()
    expect(name).not.toHaveBeenCalled()
    expect(renderers.details.detail).not.toHaveBeenCalled()
    expect(renderers.rowProgress).not.toHaveBeenCalled()
    expect(controller.expandedText.get().size).toBe(0)
    dom.layout.main = 10
    dom.layout.card = 30
    fireEvent(window, new Event('resize'))
    dom.flush()
    expect(pixels(container, 'wide')).toBe('258.25')
    expect(pixels(container, 'card')).toBe('508.75')
  })

  it.each(['search', 'root', 'outside'] as const)(
    'returns focus from a departing disclosure through the existing %s path',
    (fallback) => {
      const dom = textDOM()
      const { controller } = make({
        text: 'parts',
        names: ['a'],
        search: fallback !== 'root',
      })
      const { container } = render(
        <HilosViewportTable
          controller={controller}
          cells={{ name: (row) => <HilosTableText value={row.name} /> }}
        />,
      )
      dom.flush()
      const button = textControls(container)[0]
      const other = document.createElement('button')
      other.textContent = 'Outer choice'
      document.body.append(other)
      extras.push(other)
      act(() => button.focus())
      if (fallback === 'outside') other.focus()
      act(() => depart(controller))
      const expected =
        fallback === 'outside'
          ? other
          : fallback === 'search'
            ? container.querySelector('[data-id="hilos-table-search"]')
            : container.querySelector('[data-id="hilos-viewport-table"]')
      expect(document.activeElement).toBe(expected)
      expect(textControls(container)).toHaveLength(0)
    },
  )

  it.each([false, true])(
    'returns a departed disclosure through the modal path, honoring a nearer offer (%s)',
    (nearer) => {
      const dom = textDOM()
      const { controller } = make({ text: 'parts', names: ['a'], search: true })
      const cells = { name: (row: Row) => <HilosTableText value={row.name} /> }
      const draw = (open: boolean) => (
        <>
          <HilosViewportTable controller={controller} cells={cells} />
          <HilosModal open={open} title="Read text" initialFocus="dialog">
            Modal body
          </HilosModal>
        </>
      )
      const { container, rerender } = render(draw(false))
      dom.flush()
      const other = document.createElement('button')
      document.body.append(other)
      extras.push(other)
      const offer = nearer
        ? registerFocusReturnFallback(
            container.querySelector('.table-responsive')!,
            () => other,
          )
        : () => {}
      act(() => textControls(container)[0].focus())
      rerender(draw(true))
      const modal = document.querySelector('[role="dialog"]')!
      expect(document.activeElement).toBe(modal)
      act(() => depart(controller))
      expect(document.activeElement).toBe(modal)
      rerender(draw(false))
      expect(document.activeElement).toBe(
        nearer
          ? other
          : container.querySelector('[data-id="hilos-table-search"]'),
      )
      offer()
    },
  )

  it('captures every connected record before a destructive batch and sizes the first replacement in both projections', () => {
    const dom = tableGeometryDOM()
    const { controller, captures, registrations } = make({ bulk: true })
    controller.expandRow('a', true)
    work(controller)
    const renderers = content()
    const { container } = render(
      <HilosViewportTable controller={controller} {...renderers} />,
    )
    const stop = subscribeSignal(controller.rows, () => {
      expect(dom.reads).toEqual(
        expect.arrayContaining([
          'wide:hilos-table-row-a',
          'wide:hilos-table-progress-row-a',
          'wide:hilos-table-row-detail-a',
          'wide:hilos-table-row-b',
          'card:hilos-table-card-a',
          'card:hilos-table-card-b',
        ]),
      )
    })
    Object.values(renderers.cells).forEach((renderer) => renderer.mockClear())
    renderers.details.detail.mockClear()
    renderers.rowProgress.mockClear()
    dom.reads.length = 0
    act(() =>
      controller.withPresentationUpdate(
        { kind: 'rows', rowKeys: ['a', 'b'] },
        () => {
          depart(controller)
          depart(controller, 'b')
          controller.ingestCount(0, true)
        },
      ),
    )
    stop()
    expect(captures).toHaveBeenCalledTimes(1)
    expect(registrations[0]?.record('a', 'wide')?.retained).toBe(120)
    expect(pixels(container, 'wide')).toBe('119')
    expect(pixels(container, 'card')).toBe('268.75')
    expect(
      dom.firstPlaceholders.find((read) => read.projection === 'wide')?.pixels,
    ).toBe('119')
    expect(
      dom.firstPlaceholders.find((read) => read.projection === 'card')?.pixels,
    ).toBe('268.75')
    expect(
      container.querySelectorAll('[data-id="hilos-table-placeholder"]'),
    ).toHaveLength(4)
    expect(
      container.querySelector('[data-id="hilos-table-count"]')?.textContent,
    ).toBe('0 – 0 of 0')
    expect(container.textContent).not.toContain('Private')
    expect(
      container.querySelector(
        '[data-id="hilos-table-apply"], [data-id^="project-action-"], [data-id^="project-badge"], [data-id^="hilos-table-expand-"], [data-id="hilos-table-select-a"], [data-id="hilos-table-select-b"], [role="progressbar"], [data-id^="hilos-table-row-detail-"], [data-id="hilos-table-empty"]',
      ),
    ).toBeNull()
    Object.values(renderers.cells).forEach((renderer) =>
      expect(renderer).not.toHaveBeenCalled(),
    )
    expect(renderers.details.detail).not.toHaveBeenCalled()
    expect(renderers.rowProgress).not.toHaveBeenCalled()
    expect(
      container.querySelector('table')?.classList.contains('hilos-table-fixed'),
    ).toBe(true)
    expect(
      Array.from(container.querySelectorAll<HTMLElement>('col'), (col) =>
        col.style.getPropertyValue('--hilos-table-track-px'),
      ),
    ).toEqual(['120', '120', '120', '120', '120'])
  })

  it('holds empty work room before Apply and combines it once with main/detail after work ends', () => {
    tableGeometryDOM()
    const { controller } = make()
    controller.expandRow('a', true)
    work(controller)
    const renderers = content()
    const { container } = render(
      <HilosViewportTable controller={controller} {...renderers} />,
    )
    renderers.rowProgress.mockClear()
    act(() => depart(controller, 'a', false))
    const reserves = container.querySelectorAll<HTMLElement>(
      '.hilos-table-work-reserve',
    )
    expect(reserves).toHaveLength(2)
    expect(reserves[0]?.querySelector('td')?.colSpan).toBe(4)
    expect(
      reserves[0]
        ?.querySelector<HTMLElement>('.hilos-table-work-space')
        ?.style.getPropertyValue('--hilos-table-work-px'),
    ).toBe('18.5')
    expect(reserves[1]?.style.getPropertyValue('--hilos-table-work-px')).toBe(
      '28.5',
    )
    reserves.forEach((reserve) => {
      expect(reserve.textContent).toBe('')
      expect(reserve.hasAttribute('inert')).toBe(true)
      expect(
        reserve.querySelector('[data-id], button, input, [role="progressbar"]'),
      ).toBeNull()
    })
    expect(container.textContent).not.toContain('Private work payload')
    expect(renderers.rowProgress).not.toHaveBeenCalled()
    expect(
      container.querySelectorAll('[data-id="hilos-table-row-detail-a"]'),
    ).toHaveLength(2)
    act(() => work(controller, true))
    act(() => controller.apply())
    expect(pixels(container, 'wide')).toBe('119')
    expect(pixels(container, 'card')).toBe('268.75')
    expect(
      container.querySelectorAll('.hilos-table-work-reserve'),
    ).toHaveLength(0)
    expect(
      container.querySelectorAll('[data-id="hilos-table-row-detail-a"]'),
    ).toHaveLength(0)
  })

  it('does not restore completed work and keeps natural live updates and intentional detail collapse', () => {
    const dom = tableGeometryDOM()
    const { controller } = make()
    controller.expandRow('a', true)
    work(controller)
    const { container } = render(
      <HilosViewportTable controller={controller} {...content()} />,
    )
    act(() => work(controller, true))
    act(() => controller.expandRow('a', false))
    dom.layout.main = 52.5
    act(() =>
      controller.ingestDelta({
        kind: 'row_updated',
        rowKey: 'a',
        row: { rowKey: 'a', slots: { name: 'Changed live' } },
      }),
    )
    expect(container.textContent).toContain('Changed live')
    expect(
      container.querySelectorAll('[data-id="hilos-table-row-detail-a"]'),
    ).toHaveLength(0)
    act(() => depart(controller))
    expect(pixels(container, 'wide')).toBe('51.5')
    expect(container.querySelector('.hilos-table-work-reserve')).toBeNull()
  })

  it('uses the real binder boundary before synchronous normalization can change the DOM sample', () => {
    const dom = tableGeometryDOM()
    const { controller, captures } = make({ names: ['a'] })
    const scopes = new ScopeManager()
    const scope = scopes.openPage('geometry')
    normalizeTableRow(scope, {
      rowKey: 'a',
      slots: { user: { id: 7, name: 'Old' } },
    })
    const listeners = new Map<string, (signal: unknown) => void>()
    const connection = {
      on: (event: string, listener: (signal: unknown) => void) => {
        listeners.set(event, listener)
        return () => listeners.delete(event)
      },
      registerTableWindow: () => {},
      unregisterTableWindow: () => {},
    } as unknown as HilosConnection
    const unbind = bindTableViewport(
      connection,
      scopes,
      { tableKey: 'people', page: 'geometry' },
      controller,
    )
    render(<HilosViewportTable controller={controller} {...content()} />)
    const stop = subscribeSignal(
      scope.entities.signal({ type: 'user', id: 7 }),
      () => {
        expect(captures).toHaveBeenCalledTimes(1)
        expect(captures.mock.calls[0]![0].records[0].extent.main).toBe(41.25)
        dom.layout.main = 1
      },
    )
    act(() =>
      listeners.get('tableWindow')!({
        data: {
          page: 'geometry',
          tableKey: 'people',
          rows: [{ rowKey: 'a', slots: { user: { id: 7, name: 'New' } } }],
          totalCount: 1,
          totalExact: true,
          limit: 10,
          firstAnchor: null,
          lastAnchor: null,
        },
      }),
    )
    expect(captures).toHaveBeenCalledTimes(1)
    stop()
    unbind()
    scopes.dropPage()
  })

  it('waits for the first live controls before fixing tracks and releases them before measuring a changed layout', () => {
    const dom = tableGeometryDOM()
    const { controller } = make({ names: [] })
    const { container } = render(
      <HilosViewportTable controller={controller} {...content()} />,
    )
    const table = container.querySelector('table')!
    expect(table.classList.contains('hilos-table-fixed')).toBe(false)
    act(() =>
      controller.ingestAppend(
        { rowKey: 'a', slots: { name: 'First live control' } },
        1,
        true,
        null,
        null,
      ),
    )
    expect(table.classList.contains('hilos-table-fixed')).toBe(true)
    expect(table.style.getPropertyValue('--hilos-table-width-px')).toBe('600')
    dom.layout.width = 800
    dom.resizes[0]!.fire()
    dom.flush()
    expect(table.style.getPropertyValue('--hilos-table-width-px')).toBe('800')
    act(() => depart(controller))
    expect(table.style.getPropertyValue('--hilos-table-width-px')).toBe('800')
  })

  it('allocates a previously hidden card from Sass fallback and then grows only for its readable reason', () => {
    const dom = tableGeometryDOM()
    dom.layout.cardHidden = true
    const { controller } = make()
    const { container } = render(
      <HilosViewportTable controller={controller} {...content()} />,
    )
    act(() => depart(controller))
    expect(pixels(container, 'wide')).toBe('40.25')
    expect(pixels(container, 'card')).toBe('')
    dom.layout.cardHidden = false
    dom.resizes[0]!.fire()
    dom.flush()
    expect(pixels(container, 'card')).toBe('74')
    dom.layout.font = 24
    dom.layout.fallback = 72
    dom.resizes[0]!.fire()
    dom.flush()
    expect(pixels(container, 'card')).toBe('74')
    dom.layout.reason = 100
    dom.resizes[0]!.fire()
    dom.flush()
    expect(pixels(container, 'card')).toBe('126')
    expect(pixels(container, 'wide')).toBe('100')
    dom.layout.font = 12
    dom.layout.reason = 10
    dom.layout.fallback = 36
    dom.resizes[0]!.fire()
    dom.flush()
    expect(pixels(container, 'card')).toBe('126')
    expect(pixels(container, 'wide')).toBe('100')
  })

  it('invalidates hidden live card measurements before departure and uses that projection own fallback', () => {
    const dom = tableGeometryDOM()
    const { controller } = make()
    const { container } = render(
      <HilosViewportTable controller={controller} {...content()} />,
    )
    dom.layout.cardHidden = true
    dom.layout.width = 800
    dom.resizes[0]!.fire()
    dom.flush()
    act(() => depart(controller))
    expect(pixels(container, 'card')).toBe('')
    dom.layout.cardHidden = false
    dom.resizes[0]!.fire()
    dom.flush()
    expect(pixels(container, 'card')).toBe('74')
    expect(pixels(container, 'wide')).toBe('40.25')
  })

  it.each(['start', 'end'] as const)(
    'keeps state, selection and full-row spans aligned at the %s edge',
    (edge) => {
      tableGeometryDOM()
      const { controller } = make({ bulk: true })
      controller.expandRow('a', true)
      work(controller)
      const { container } = render(
        <HilosTableSelectionEdgeContext.Provider value={edge}>
          <HilosViewportTable controller={controller} {...content()} />
        </HilosTableSelectionEdgeContext.Provider>,
      )
      const cells = [...container.querySelectorAll('thead th')]
      expect(cells).toHaveLength(5)
      expect(
        (edge === 'start' ? cells[0] : cells[4])?.querySelector('input')
          ?.dataset.id,
      ).toBe('hilos-table-select-page')
      expect(
        container
          .querySelector('[data-id="hilos-table-row-detail-a"] td')
          ?.getAttribute('colspan'),
      ).toBe('5')
      expect(
        [
          ...container.querySelectorAll<HTMLTableCellElement>(
            'tr[data-id="hilos-table-progress-row-a"] td',
          ),
        ].map((cell) => cell.colSpan),
      ).toEqual(edge === 'start' ? [1, 3, 1] : [3, 1, 1])
      act(() => depart(controller))
      expect(
        container
          .querySelector('[data-id="hilos-table-row-a"] td')
          ?.getAttribute('colspan'),
      ).toBe('5')
      const reserves = container.querySelectorAll(
        '.hilos-table-marks.invisible, .hilos-table-room',
      )
      expect(reserves.length).toBeGreaterThan(0)
      reserves.forEach((reserve) => {
        expect(reserve.hasAttribute('inert')).toBe(true)
        expect(reserve.getAttribute('aria-hidden')).toBe('true')
        expect(
          reserve.querySelector(
            '[id], [data-id], button, input, select, textarea, a, [tabindex]',
          ),
        ).toBeNull()
      })
    },
  )

  const markCases = [false, true].flatMap((stale) =>
    (['none', 'move', 'remove'] as const).flatMap((pending) =>
      (['absent', 'closed', 'open'] as const).map((detail) => ({
        stale,
        pending,
        detail,
      })),
    ),
  )
  it.each(markCases)(
    'reserves simultaneous marks: stale=$stale pending=$pending detail=$detail',
    ({ stale, pending, detail }) => {
      tableGeometryDOM()
      const { controller } = make({ detail: detail !== 'absent', names: ['a'] })
      if (detail === 'open') controller.expandRow('a', true)
      if (stale)
        controller.ingestDelta({
          kind: 'row_stale',
          rowKey: 'a',
          staleSources: ['user'],
        })
      if (pending === 'remove') depart(controller, 'a', false)
      if (pending === 'move')
        controller.ingestDelta({
          kind: 'row_moved',
          rowKey: 'a',
          row: { rowKey: 'a', slots: { name: 'Private a' } },
        })
      const { container } = render(
        <HilosViewportTable controller={controller} {...content()} />,
      )
      expect(container.querySelectorAll('thead th')).toHaveLength(4)
      expect(container.querySelectorAll('col')).toHaveLength(4)
      expect(
        container.querySelectorAll('[data-id="hilos-table-row-a"] > td'),
      ).toHaveLength(4)
      expect(
        container.querySelectorAll('.hilos-table-marks.invisible'),
      ).toHaveLength(3)
      expect(
        container.querySelectorAll('.hilos-table-marks.invisible > .btn'),
      ).toHaveLength(detail === 'absent' ? 0 : 3)
      expect(
        container.querySelectorAll('thead th:first-child .bi-snow.invisible'),
      ).toHaveLength(1)
      expect(
        container.querySelectorAll('[data-id="hilos-table-stale-column-name"]'),
      ).toHaveLength(stale ? 1 : 0)
      expect(
        container.querySelectorAll('[data-id="hilos-table-stale-row-a"]'),
      ).toHaveLength(stale ? 2 : 0)
      expect(
        container.querySelectorAll('[data-id="hilos-table-expand-a"]'),
      ).toHaveLength(detail === 'absent' ? 0 : 2)
      expect(
        container.querySelectorAll('[data-id="hilos-table-row-detail-a"]'),
      ).toHaveLength(detail === 'open' ? 2 : 0)
      expect(
        container.querySelectorAll(
          `[data-id="hilos-table-pending-${pending}-a"]`,
        ),
      ).toHaveLength(pending === 'none' ? 0 : 2)
      const card = container.querySelector('[data-id="hilos-table-card-a"]')!
      expect(
        card
          .querySelector('[data-id="project-badge"]')
          ?.closest('.hilos-table-card-marks'),
      ).toBeNull()
      expect(
        card.querySelector('.hilos-table-card-marks [data-id="project-badge"]'),
      ).toBeNull()
      container
        .querySelectorAll('.hilos-table-marks.invisible')
        .forEach((twin) => {
          expect(twin.getAttribute('aria-hidden')).toBe('true')
          expect(twin.hasAttribute('inert')).toBe(true)
          expect(
            twin.querySelector('[id], [data-id], button, input, a, [tabindex]'),
          ).toBeNull()
        })
    },
  )

  it('keeps retained slots through append and clears their numbers on a same-key fresh snapshot and refusal', () => {
    tableGeometryDOM()
    const { controller } = make()
    const { container } = render(
      <HilosViewportTable controller={controller} {...content()} />,
    )
    act(() => {
      depart(controller)
      depart(controller, 'b')
      controller.ingestCount(0, true)
    })
    expect(pixels(container, 'wide')).toBe('40.25')
    expect(
      container.querySelector('[data-id="hilos-table-count"]')?.textContent,
    ).toBe('0 – 0 of 0')
    act(() =>
      controller.ingestAppend(
        { rowKey: 'c', slots: { name: 'New arrival' } },
        1,
        true,
        null,
        null,
      ),
    )
    expect(
      container.querySelectorAll('[data-id="hilos-table-placeholder"]'),
    ).toHaveLength(4)
    expect(
      container.querySelector('[data-id="hilos-table-count"]')?.textContent,
    ).toBe('1 – 1 of 1')
    expect(container.textContent).toContain('New arrival')
    expect(pixels(container, 'wide')).toBe('40.25')
    const old = container.querySelector('[data-id="hilos-table-row-a"]')
    act(() => snapshot(controller, ['a']))
    expect(container.querySelector('[data-id="hilos-table-row-a"]')).not.toBe(
      old,
    )
    expect(container.querySelector('.hilos-table-record-space')).toBeNull()
    expect(container.textContent).toContain('Private a')
    act(() => controller.ingestRefusal('internal_error'))
    expect(container.querySelector('.hilos-table-record-space')).toBeNull()
    expect(container.textContent).not.toContain('Private')
    expect(
      container.querySelector('table')?.classList.contains('hilos-table-fixed'),
    ).toBe(false)
    expect(container.querySelector('[data-id="hilos-table-count"]')).toBeNull()
  })

  it('keys both projections by occurrence and releases the old controller and StrictMode registrations', () => {
    const dom = tableGeometryDOM()
    const first = make()
    const second = make()
    const renderers = content()
    const { container, rerender, unmount } = render(
      <StrictMode>
        <HilosViewportTable controller={first.controller} {...renderers} />
      </StrictMode>,
    )
    expect(first.registrations).toHaveLength(2)
    expect(first.registrations[0]!.dispose).toHaveBeenCalledTimes(1)
    const oldWide = container.querySelector('[data-id="hilos-table-row-a"]')
    const oldCard = container.querySelector('[data-id="hilos-table-card-a"]')
    act(() => snapshot(first.controller))
    expect(container.querySelector('[data-id="hilos-table-row-a"]')).not.toBe(
      oldWide,
    )
    expect(container.querySelector('[data-id="hilos-table-card-a"]')).not.toBe(
      oldCard,
    )
    rerender(
      <StrictMode>
        <HilosViewportTable controller={second.controller} {...renderers} />
      </StrictMode>,
    )
    first.registrations.forEach((owner) =>
      expect(owner.dispose).toHaveBeenCalledTimes(1),
    )
    act(() => depart(first.controller))
    expect(container.textContent).toContain('Private a')
    unmount()
    second.registrations.forEach((owner) =>
      expect(owner.dispose).toHaveBeenCalledTimes(1),
    )
    dom.resizes.forEach((observer) => {
      expect(observer.disconnect).toHaveBeenCalledTimes(1)
      observer.fire()
    })
    expect(dom.frames.size).toBe(0)
  })

  it.each([true, false])(
    'returns a removed active control to the visible search or stable root (search=%s)',
    (search) => {
      tableGeometryDOM()
      const { controller } = make({ search })
      const { container } = render(
        <HilosViewportTable controller={controller} {...content()} />,
      )
      container
        .querySelector<HTMLElement>('[data-id="project-action-a"]')!
        .focus()
      act(() => depart(controller))
      const root = container.querySelector<HTMLElement>(
        '[data-id="hilos-viewport-table"]',
      )!
      expect(root.tabIndex).toBe(-1)
      expect(document.activeElement).toBe(
        search
          ? container.querySelector('[data-id="hilos-table-search"]')
          : root,
      )
    },
  )

  it.each(['replacement', 'departure', 'unmarked'] as const)(
    'returns a gone modal opener through the mounted table: %s',
    (change) => {
      tableGeometryDOM()
      const { controller } = make({ search: true })
      const { container } = render(
        <HilosViewportTable controller={controller} {...content()} />,
      )
      const opener = container.querySelector<HTMLElement>(
        '[data-id="project-action-a"]',
      )!
      if (change !== 'unmarked')
        opener.setAttribute('data-hilos-focus-return', 'edit')
      const modal = document.createElement('div')
      modal.tabIndex = -1
      extras.push(modal)
      document.body.append(modal)
      const trap = new FocusTrap()
      opener.focus()
      trap.activate(modal, 'dialog')
      trap.release()
      expect(document.activeElement).toBe(opener)
      trap.activate(modal, 'dialog')
      act(() => {
        if (change === 'departure') depart(controller)
        else snapshot(controller)
      })
      if (change === 'replacement')
        container
          .querySelector<HTMLElement>('[data-id="project-action-a"]')!
          .setAttribute('data-hilos-focus-return', 'edit')
      expect(document.activeElement).toBe(modal)
      expect(container.querySelector('[data-id="project-action-a"]')).not.toBe(
        opener,
      )
      trap.release()
      expect(document.activeElement).toBe(
        container.querySelector(
          change === 'replacement'
            ? '[data-id="project-action-a"]'
            : '[data-id="hilos-table-search"]',
        ),
      )
      if (change === 'departure') act(() => snapshot(controller))
      container
        .querySelector<HTMLElement>('[data-id="project-action-a"]')!
        .focus()
      act(() => {
        depart(controller)
        modal.focus()
      })
      expect(document.activeElement).toBe(modal)
    },
  )

  it('renders the production table statically without DOM reads or registration', () => {
    tableGeometryDOM()
    const { controller, registrations } = make()
    const read = vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect')
    read.mockClear()
    vi.stubGlobal('window', undefined)
    vi.stubGlobal('document', undefined)
    const html = renderToStaticMarkup(
      <HilosViewportTable controller={controller} {...content()} />,
    )
    vi.unstubAllGlobals()
    expect(registrations).toHaveLength(0)
    expect(read).not.toHaveBeenCalled()
    expect(html).toContain('tabindex="-1"')
    expect(html).toContain('Private a')
    expect(html).toContain('hilos-table-room-card')
    expect(html).not.toContain('hilos-table-fixed')
  })
})

describe('HilosViewportTable modal focus lifetime', () => {
  const targets: TableViewportController<Row>[] = []
  const releases: (() => void)[] = []
  afterEach(() => {
    cleanup()
    releases.splice(0).forEach((release) => release())
    targets.splice(0).forEach((target) => target.dispose())
    vi.restoreAllMocks()
  })

  function fixture({
    search = true,
    projection = 'wide',
    marked = true,
    strict = false,
  }: {
    search?: boolean
    projection?: 'wide' | 'card'
    marked?: boolean
    strict?: boolean
  } = {}) {
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(
      function (this: HTMLElement) {
        return this.closest('[hidden]')
          ? new DOMRect()
          : new DOMRect(0, 0, 400, 40)
      },
    )
    function make() {
      const { controller } = makeController({
        title: 'People',
        columns: COLUMNS,
        ...(search ? { search: {} } : {}),
      })
      targets.push(controller)
      controller.ingestWindow(
        [{ rowKey: 'a', slots: { name: 'Alice' } }],
        1,
        true,
        null,
        null,
        10,
      )
      return controller
    }
    const controller = make()
    let current = controller
    let open = false
    let mounted = true
    let marker: string | undefined = marked ? 'person-open' : undefined
    let duplicates = false
    function tree() {
      const content = (
        <div>
          {mounted ? (
            <HilosViewportTable
              controller={current}
              cells={{
                name: (row) => (
                  <fieldset>
                    <button
                      data-id="open-person"
                      data-hilos-focus-return={marker}
                      onClick={() => {
                        open = true
                        redraw()
                      }}
                    >
                      Inspect {row.name}
                    </button>
                    {duplicates ? (
                      <button data-hilos-focus-return={marker}>
                        Ambiguous
                      </button>
                    ) : null}
                  </fieldset>
                ),
              }}
            />
          ) : null}
          <HilosModal
            open={open}
            title="Inspect person"
            initialFocus="dialog"
            onClose={() => {
              open = false
              redraw()
            }}
          />
          <button data-id="unrelated">Other control</button>
        </div>
      )
      return strict ? <StrictMode>{content}</StrictMode> : content
    }
    const view = render(tree())
    function redraw() {
      view.rerender(tree())
    }
    function show(next: 'wide' | 'card') {
      view.container.querySelector<HTMLElement>('.table-responsive')!.hidden =
        next !== 'wide'
      view.container.querySelector<HTMLElement>(
        '[data-id="hilos-table-cards"]',
      )!.hidden = next !== 'card'
    }
    show(projection)
    function control(target = projection): HTMLElement {
      return view.container.querySelector<HTMLElement>(
        `${target === 'wide' ? 'tr' : '[role="list"]'} [data-id="open-person"]`,
      )!
    }
    const opener = control()
    const other = view.container.querySelector<HTMLElement>(
      '[data-id="unrelated"]',
    )!
    const modal = () =>
      document.querySelector<HTMLElement>(
        '[data-id="modal"][aria-label="Inspect person"]',
      )!
    function snapshot(name = 'Alice') {
      act(() =>
        controller.ingestWindow(
          [{ rowKey: 'a', slots: { name } }],
          1,
          true,
          null,
          null,
          10,
        ),
      )
    }
    function remove() {
      act(() =>
        controller.ingestDelta({
          kind: 'row_removed',
          rowKey: 'a',
          reason: 'deleted',
          own: true,
        }),
      )
    }
    return {
      ...view,
      controller,
      opener,
      other,
      control,
      modal,
      show,
      snapshot,
      remove,
      search: () =>
        view.container.querySelector<HTMLElement>(
          '[data-id="hilos-table-search"]',
        )!,
      root: () =>
        view.container.querySelector<HTMLElement>(
          '[data-id="hilos-viewport-table"]',
        )!,
      activate() {
        opener.focus()
        fireEvent.click(opener)
        expect(document.activeElement).toBe(modal())
      },
      close() {
        fireEvent.keyDown(modal(), { key: 'Escape' })
        expect(modal()).toBeNull()
      },
      hide() {
        act(() => {
          open = false
          redraw()
        })
      },
      changeMarker(value?: string) {
        act(() => {
          marker = value
          redraw()
        })
      },
      duplicate() {
        act(() => {
          duplicates = true
          redraw()
        })
      },
      replaceController() {
        act(() => {
          current = make()
          // Occurrences belong to a controller. Give the new controller a fresh
          // DOM key so this case exercises the disposed offer, not the still
          // available native opener that always has priority.
          current.ingestWindow(
            [{ rowKey: 'a', slots: { name: 'Other controller' } }],
            1,
            true,
            null,
            null,
            10,
          )
          redraw()
        })
      },
      unmountTable() {
        act(() => {
          mounted = false
          redraw()
        })
      },
      offerOuter() {
        releases.push(registerFocusReturnFallback(view.container, () => other))
      },
    }
  }

  it('returns to the connected opener after a live value update', () => {
    const f = fixture()
    f.activate()
    act(() =>
      f.controller.ingestDelta({
        kind: 'row_updated',
        rowKey: 'a',
        row: { rowKey: 'a', slots: { name: 'Updated' } },
      }),
    )
    expect(f.opener.isConnected).toBe(true)
    f.close()
    expect(document.activeElement).toBe(f.opener)
  })

  describe.each([false, true])('StrictMode=%s', (strict) => {
    it.each(['wide', 'card'] as const)(
      'returns to the fresh marked logical control in %s',
      (projection) => {
        const f = fixture({ projection, strict })
        const occurrence = f.controller.rows.get()[0]!.occurrence
        f.activate()
        const modal = f.modal()
        f.snapshot('Updated person')
        expect(f.controller.rows.get()[0]!.occurrence).not.toBe(occurrence)
        const fresh = f.control()
        expect(f.opener.isConnected).toBe(false)
        expect(fresh).not.toBe(f.opener)
        expect(fresh.isConnected).toBe(true)
        expect(document.activeElement).toBe(modal)
        f.close()
        expect(document.activeElement).toBe(fresh)
      },
    )

    it.each(['controller', 'unmount'] as const)(
      'invalidates captured return after table %s',
      (change) => {
        const f = fixture({ strict })
        f.offerOuter()
        f.activate()
        const modal = f.modal()
        if (change === 'controller') f.replaceController()
        else f.unmountTable()
        expect(f.opener.isConnected).toBe(false)
        expect(document.activeElement).toBe(modal)
        f.close()
        expect(document.activeElement).toBe(f.other)
      },
    )
  })

  it.each(['same key', 'reused key'])(
    'returns an unmarked data-id-only %s control to search',
    (change) => {
      const f = fixture({ marked: false })
      f.activate()
      const modal = f.modal()
      f.snapshot(change === 'reused key' ? 'Another person' : 'Alice')
      expect(f.opener.isConnected).toBe(false)
      expect(f.control()).not.toBe(f.opener)
      expect(document.activeElement).toBe(modal)
      f.close()
      expect(document.activeElement).toBe(f.search())
    },
  )

  it.each([
    'removed',
    'placeholder',
    'missing marker',
    'changed marker',
    'duplicate marker',
    'hidden control',
    'disabled control',
    'inert control',
    'disabled fieldset',
    'hidden projection',
    'foreign parts',
    'foreign table',
  ] as const)(
    'returns to search when the replacement is unavailable: %s',
    (change) => {
      const f = fixture()
      // An identical address on another table must never be selected.
      const foreign = change === 'foreign table' ? fixture() : null
      f.activate()
      const modal = f.modal()
      if (change === 'removed')
        act(() => f.controller.ingestWindow([], 0, true, null, null, 10))
      else if (change === 'placeholder') f.remove()
      else {
        if (change === 'missing marker' || change === 'foreign table')
          f.changeMarker()
        if (change === 'changed marker') f.changeMarker('another-action')
        if (change === 'duplicate marker') f.duplicate()
        f.snapshot()
      }
      if (change === 'hidden control') f.control().hidden = true
      if (change === 'disabled control')
        f.control().setAttribute('disabled', '')
      if (change === 'inert control') f.control().setAttribute('inert', '')
      if (change === 'disabled fieldset')
        f.control().closest('fieldset')!.disabled = true
      if (change === 'hidden projection') f.show('card')
      const row = change === 'foreign parts' ? f.control().closest('tr')! : null
      const parent = row?.parentElement
      if (row) document.body.append(row)
      expect(f.opener.isConnected).toBe(false)
      expect(document.activeElement).toBe(modal)
      f.close()
      expect(document.activeElement).toBe(f.search())
      if (foreign) expect(document.activeElement).not.toBe(foreign.control())
      // Restore externally moved React children before their normal cleanup.
      if (row) parent!.append(row)
    },
  )

  it.each(['absent', 'hidden'])(
    'returns a removed opener to the root with %s search',
    (search) => {
      const f = fixture({ search: search === 'hidden' })
      f.activate()
      if (search === 'hidden') f.search().hidden = true
      f.remove()
      expect(document.activeElement).toBe(f.modal())
      f.close()
      expect(document.activeElement).toBe(f.root())
      expect(f.root().tabIndex).toBe(-1)
    },
  )

  it('keeps an explicit focus choice outside the dialog', () => {
    const f = fixture()
    f.activate()
    f.other.focus()
    f.snapshot()
    expect(document.activeElement).toBe(f.other)
    f.close()
    expect(document.activeElement).toBe(f.other)
  })

  it('honors a nearer registered offer before the table replacement', () => {
    const f = fixture()
    releases.push(
      registerFocusReturnFallback(
        f.container.querySelector<HTMLElement>('.table-responsive')!,
        () => f.other,
      ),
    )
    f.activate()
    f.snapshot()
    expect(f.opener.isConnected).toBe(false)
    expect(document.activeElement).toBe(f.modal())
    f.close()
    expect(document.activeElement).toBe(f.other)
  })

  it('keeps independent modal addresses and the upper dialog focus', () => {
    const lower = fixture()
    const upper = fixture()
    lower.activate()
    upper.opener.focus()
    fireEvent.click(upper.opener)
    const modal = [
      ...document.querySelectorAll<HTMLElement>('[data-id="modal"]'),
    ].at(-1)!
    lower.snapshot('Lower person')
    upper.snapshot('Upper person')
    expect(lower.opener.isConnected).toBe(false)
    expect(upper.opener.isConnected).toBe(false)
    expect(document.activeElement).toBe(modal)
    lower.hide()
    expect(document.activeElement).toBe(modal)
    upper.close()
    expect(document.activeElement).toBe(upper.control())
    expect(document.activeElement).not.toBe(lower.control())
  })
})
