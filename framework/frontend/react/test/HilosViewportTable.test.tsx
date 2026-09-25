import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import type { ReactNode } from 'react'
import { TableViewportController } from '@hilos/core'
import type {
  ActionHandle,
  HilosTableBulkAccepted,
  HilosTableColumn,
  HilosTableFrame,
  HilosTableProgress,
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

const TWO_COLUMNS: HilosTableColumn[] = [
  { key: 'name', label: 'Name', sortable: true },
  { key: 'id', label: 'Id', sortable: true },
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

function renderTable(
  controller: TableViewportController<Row>,
  columns: HilosTableColumn[] = COLUMNS,
) {
  return render(
    <HilosViewportTable
      controller={controller}
      columns={columns}
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

  it('numbers the columns of a composite order and says the place in words', () => {
    const { controller } = makeController()
    controller.setOrder([
      { field: 'name', direction: 'desc' },
      { field: 'id', direction: 'asc' },
    ])
    const { container } = renderTable(controller, TWO_COLUMNS)

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
    ).toEqual(['Sort column 1 of 2', 'Sort column 2 of 2'])
  })

  it('numbers nothing under an order of one column, where the arrow says it all', () => {
    const { controller } = makeController()
    controller.setSort('name')
    const { container } = renderTable(controller, TWO_COLUMNS)

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

  it('grows the mark column with the waiting, header and body at once', () => {
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
    // An applied removal leaves a placeholder behind and nothing waiting, which is
    // the state where the mark column must not stand.
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

    expect(container.querySelectorAll('thead th')).toHaveLength(COLUMNS.length)
    expect(placeholderSpan()).toBe(String(COLUMNS.length))

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

  it('leaves the props-driven bar out and keeps Apply in the waiting strip', () => {
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

    // One search box, the declared one; and the strips speak in both epochs of the
    // frame, because they are about the rows and not about what the page declared.
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
    expect(cells).toHaveLength(CELL_COLUMNS.length)
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

  it('reads the columns off the declaration rather than off the prop', () => {
    const { controller } = makeController(CELL_FRAME)
    window(controller)
    const { container } = render(
      <HilosViewportTable
        controller={controller}
        // A prop left behind from the props epoch: the declared table ignores it,
        // so its row and its header cannot be assembled from two different lists.
        columns={[{ key: 'name', label: 'Name' }]}
        cells={{ size: () => <span className="sized">1.2 GB</span> }}
      />,
    )

    expect(container.querySelectorAll('thead th')).toHaveLength(
      CELL_COLUMNS.length,
    )
    expect(container.querySelector('thead')?.textContent).toContain('Size')
    expect(container.querySelector('.sized')).not.toBeNull()
  })

  it('keeps handing the whole row over while the page passes columns as a prop', () => {
    const { controller } = makeController()
    window(controller)
    const { container } = renderTable(controller)

    expect(
      container.querySelector('[data-id="hilos-table-row-a"] td.cell')
        ?.textContent,
    ).toBe('Alice')
  })
})

describe('HilosViewportTable drawing a row as a card', () => {
  afterEach(cleanup)

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
      { key: 'delete', label: 'Delete', danger: true, run: neverRun },
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

  it('draws no cards and keeps the table at every width without a declaration', () => {
    const { controller } = makeController()
    window(controller)
    const { container } = renderTable(controller)

    expect(container.querySelector('[data-id="hilos-table-cards"]')).toBeNull()
    expect(
      container
        .querySelector('.table-responsive')
        ?.classList.contains('d-none'),
    ).toBe(false)
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
    ).toHaveLength(CARD_COLUMNS.length)
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

  it('draws the empty tile instead of placeholders after convergence', () => {
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
    ).toHaveLength(0)
    expect(
      container.querySelectorAll('[data-id="hilos-table-empty"]'),
    ).toHaveLength(2)
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

  it('stands the framework marks beside the page badge, not instead of it', () => {
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

    const group = cardOf(container, 'a').querySelector('.ms-auto')
    expect(group?.querySelector('.state-badge')).not.toBeNull()
    expect(
      group?.querySelector('[data-id="hilos-table-stale-row-a"]'),
    ).not.toBeNull()
    expect(
      group?.querySelector('[data-id="hilos-table-pending-move-a"]'),
    ).not.toBeNull()
    // The page's badge first, then the freshness mark, then the waiting badge.
    const marks = Array.from(group?.children ?? []).map(
      (mark) => mark.getAttribute('data-id') ?? mark.className,
    )
    expect(marks.slice(0, 3)).toEqual([
      'state-badge',
      'hilos-table-stale-row-a',
      'visually-hidden',
    ])
    expect(marks[3]).toBe('hilos-table-pending-move-a')
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
      cardOf(left.container, 'a').querySelector('.ms-auto input'),
    ).toBeNull()

    // The card box tells the core the state it is now in, as the row box does.
    fireEvent.click(box as HTMLElement)
    expect(controller.selection.count.get()).toBe(1)
    expect(box?.checked).toBe(true)
    left.unmount()

    const right = renderCards(controller, 'end')
    expect(
      cardOf(right.container, 'a')
        .querySelector('.ms-auto input')
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

  function renderSourced(
    controller: TableViewportController<Row>,
    columns: HilosTableColumn[] = SOURCED_COLUMNS,
  ) {
    return render(
      <HilosViewportTable
        controller={controller}
        columns={columns}
        row={(r) => (
          <>
            <td className="cell">{r.name}</td>
            <td className="cell">online</td>
          </>
        )}
      />,
    )
  }

  function headers(container: HTMLElement): HTMLElement[] {
    return Array.from(container.querySelectorAll<HTMLElement>('thead th'))
  }

  it('raises the strip and names the columns built from the quiet source', () => {
    const { controller } = makeController()
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
    const { controller } = makeController()
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
    const { controller } = makeController()
    window(controller, ['connections'])
    const { container } = renderSourced(controller, [
      SOURCED_COLUMNS[0]!,
      { ...SOURCED_COLUMNS[1]!, sortable: false },
    ])

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
    const { controller, sent } = makeController()
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
    const { controller } = makeController()
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
    const { controller } = makeController()
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
    const { controller } = makeController()
    window(controller, ['connections'])
    const { container } = renderSourced(controller)

    expect(
      container.querySelector('[data-id="hilos-table-stale-row-a"]'),
    ).not.toBeNull()
    expect(
      container.querySelector('[data-id="hilos-table-stale-row-b"]'),
    ).toBeNull()
  })

  it('stands the mark cell up on a quiet source with nothing waiting at all', () => {
    const { controller } = makeController()
    window(controller, ['connections'])
    const { container } = renderSourced(controller)

    expect(headers(container)).toHaveLength(SOURCED_COLUMNS.length + 1)
    expect(container.querySelector('thead th:last-child')?.textContent).toBe(
      'Row state and controls',
    )
    // Header and body read one condition, so the cell the header just made room
    // for is the one standing last in the row.
    const cells = container.querySelectorAll('[data-id="hilos-table-row-a"] td')
    expect(
      cells[cells.length - 1]?.querySelector(
        '[data-id="hilos-table-stale-row-a"]',
      ),
    ).not.toBeNull()
  })

  it('takes the strip, the snowflakes and the cell down when the source is current again', () => {
    const { controller } = makeController()
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
    expect(headers(container)).toHaveLength(SOURCED_COLUMNS.length)
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

    const empty = makeController(BULK_FRAME)
    empty.controller.ingestWindow([], 0, true, null, null, 10)
    const emptyTable = renderWithEdge(empty.controller).container
    expect(emptyTable.querySelector('tbody td')?.getAttribute('colspan')).toBe(
      String(COLUMNS.length + 1),
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
        columns={COLUMNS}
        row={(r) => <td>{r.name}</td>}
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
        columns={MARKED_COLUMNS}
        row={(r) => (
          <>
            <td />
            <td>{r.name}</td>
            <td />
            <td />
          </>
        )}
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
    const { controller } = makeController()
    window(controller)
    rowBar(controller, 'a')
    const { container } = renderMarked(controller)

    const cells = barCells(container)
    expect(cells.map((cell) => cell.getAttribute('colspan'))).toEqual([
      '1',
      '2',
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
    expect(cells).toHaveLength(1)
    expect(cells[0]?.getAttribute('colspan')).toBe('1')
    expect(cells[0]?.querySelector('[role="progressbar"]')).not.toBeNull()
  })

  it('adds the waiting cell to the bar row exactly as the header does', () => {
    const { controller } = makeController()
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
        columns={COLUMNS}
        row={(r) => <td>{r.name}</td>}
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
        columns={DETAIL_COLUMNS}
        searchable
        row={(r) => <td className="cell">{r.name}</td>}
        details={details}
      />,
    )
  }

  function find(container: HTMLElement, selector: string): HTMLElement | null {
    return container.querySelector<HTMLElement>(selector)
  }

  it('keeps a detail column out of the header and out of the width of a row', () => {
    const { controller } = makeController()
    window(controller)
    const { container } = renderDetailTable(controller)

    // One declared column left standing, plus the row-state cell the control lives in.
    expect(container.querySelectorAll('thead th')).toHaveLength(2)
    expect(find(container, 'thead')?.textContent).not.toContain('Error')
    expect(find(container, '[data-id="hilos-table-sort-lastError"]')).toBeNull()
  })

  it('offers the control only where a detail field was declared', () => {
    const { controller } = makeController()
    window(controller)

    expect(
      find(
        renderDetailTable(controller).container,
        '[data-id="hilos-table-expand-a"]',
      ),
    ).not.toBeNull()
    expect(
      find(
        renderTable(controller).container,
        '[data-id="hilos-table-expand-a"]',
      ),
    ).toBeNull()
  })

  it('opens the panel of one row and closes it again from the same control', () => {
    const { controller } = makeController()
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
    const { controller } = makeController()
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
    const { controller } = makeController()
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
    const { controller } = makeController()
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
    const { controller } = makeController()
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
    const { controller } = makeController()
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
        columns={STATE_COLUMNS}
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
    expect(tableRows[0]?.querySelectorAll('td')).toHaveLength(2)
    expect(
      container.querySelectorAll(
        '[data-id="hilos-table-cards"] [data-id="hilos-table-skeleton-row"]',
      ),
    ).toHaveLength(2)
    expect(
      container.querySelectorAll('.placeholder[aria-hidden="true"]'),
    ).toHaveLength(2 * 2)
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

  it('says nothing was found on a table that still draws its frame from props', () => {
    const { controller } = makeController()
    const { container } = render(
      <HilosViewportTable
        controller={controller}
        columns={COLUMNS}
        searchable
        row={(r) => <td className="cell">{r.name}</td>}
      />,
    )
    act(() => {
      controller.ingestWindow([], 0, true, null, null, 10)
      controller.setSearch('night')
      controller.ingestWindow([], 0, true, null, null, 10)
    })

    expect(
      container.querySelector('[data-id="hilos-table-no-matches-terms"]')
        ?.textContent,
    ).toBe('No rows match “night”')
  })
})
