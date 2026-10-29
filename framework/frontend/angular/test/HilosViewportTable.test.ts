// The Angular view under the case names the Vue view and the React port run for it.
// The first group draws a table from its frame: the numbers a
// composite order puts on its headers (HIL-811), its rows' tint, the mark column,
// and the room of live messages above the rows (HIL-803, HIL-812). The
// next ones take the cells the host fills one marked template per column (HIL-815).
// The admin-page group stands the table inside the admin shell, the way the framework's
// admin pages draw it, where a table declaring no title is named by the page heading.
// The last two open a row and a card into the panel of fields that did not fit a
// column (HIL-816), whose values the host marks one template per field.
import { Component, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  HilosPages,
  TableViewportController,
  createSignal,
  bindTableViewport,
  ScopeManager,
  subscribeSignal,
  registerFocusReturnFallback,
} from '@hilos/core'
import type {
  ActionHandle,
  HilosConnection,
  TableGeometryCapture,
  HilosPageIdentity,
  HilosTableBulkAccepted,
  HilosRouter,
  HilosTableColumn,
  HilosTableFrame,
  HilosTableRowMessage,
  PageRouteMatch,
  TableViewportDescriptor,
} from '@hilos/core'

import { normalizeTableRow } from '../../core/src/state/normalizer.js'
import { HilosAdminPage } from '../src/HilosAdminPage.js'
import { HilosModal } from '../src/HilosModal.js'
import { HilosTableCell } from '../src/HilosTableCell.js'
import { HilosTableDetail } from '../src/HilosTableDetail.js'
import { HilosViewportTable } from '../src/HilosViewportTable.js'
import { HilosTableText } from '../src/base/index.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'
import {
  HILOS_TABLE_SELECTION_EDGE,
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

const FRAME: HilosTableFrame = {
  title: 'Backups',
  search: {},
  columns: COLUMNS,
}

/** A host drawing a table with declared columns and cells. */
@Component({
  selector: 'test-viewport-table-host',
  imports: [HilosTableCell, HilosViewportTable],
  template: `
    <hilos-viewport-table [controller]="controller">
      <ng-template hilosTableCell="name" let-row>{{ row.name }}</ng-template>
    </hilos-viewport-table>
  `,
})
class ViewportTableHost {
  controller!: TableViewportController<Row>
}

/** A host drawing a table with plain name cells. */
@Component({
  selector: 'test-plain-viewport-table-host',
  imports: [HilosTableCell, HilosViewportTable],
  template: `
    <hilos-viewport-table [controller]="controller">
      <ng-template hilosTableCell="name" let-row>
        <span class="cell">{{ row.name }}</span>
      </ng-template>
    </hilos-viewport-table>
  `,
})
class PlainTableHost {
  controller!: TableViewportController<Row>
}

/** A project's main text region beside a link that must stay outside its cap. */
@Component({
  imports: [HilosTableCell, HilosViewportTable, HilosTableText],
  template: `
    <hilos-viewport-table [controller]="controller">
      <ng-template hilosTableCell="name" let-row>
        <hilos-table-text [value]="row.name" />
        <a href="#person">Open person</a>
      </ng-template>
    </hilos-viewport-table>
  `,
})
class PartsTableHost {
  controller!: TableViewportController<Row>
}

/** A host filling every place a page has beside running work. */
@Component({
  selector: 'test-progress-table-host',
  imports: [HilosTableCell, HilosViewportTable],
  template: `
    <hilos-viewport-table [controller]="controller">
      <ng-template #tableProgress let-progress>
        <span data-id="page-progress-title"
          >{{ progress.detail['title'] }} — {{ progress.current }} of
          {{ progress.total }}</span
        >
      </ng-template>
      <ng-template #tableProgressAction let-progress>
        <button type="button" data-id="page-progress-stop">
          Stop {{ progress.progressKey }}
        </button>
      </ng-template>
      <ng-template #rowProgress let-progress let-rowKey="rowKey">
        <span [attr.data-id]="'page-row-note-' + rowKey">{{
          progress.detail['note']
        }}</span>
      </ng-template>
      <ng-template hilosTableCell="name" let-row>
        <span class="cell">{{ row.name }}</span>
      </ng-template>
    </hilos-viewport-table>
  `,
})
class ProgressTableHost {
  controller!: TableViewportController<Row>
}

// Columns that name where a row's bar goes: under the two content columns and not
// under the actions one, the way the mockup draws it.
const MARKED_COLUMNS: HilosTableColumn[] = [
  { key: 'mark', label: '' },
  { key: 'name', label: 'Name', progress: true },
  { key: 'kind', label: 'Kind', progress: true },
  { key: 'actions', label: '' },
]

/** A host drawing a table whose columns mark where a row's bar goes. */
@Component({
  selector: 'test-marked-table-host',
  imports: [HilosTableCell, HilosViewportTable],
  template: `
    <hilos-viewport-table [controller]="controller">
      <ng-template hilosTableCell="name" let-row>
        <span class="cell">{{ row.name }}</span>
      </ng-template>
    </hilos-viewport-table>
  `,
})
class MarkedTableHost {
  controller!: TableViewportController<Row>
}

/**
 * A page drawing a table inside the admin shell, the way the framework's
 * admin pages do: the table is handed its controller and its cells, and nothing else.
 */
@Component({
  selector: 'test-admin-page-table-host',
  imports: [HilosAdminPage, HilosTableCell, HilosViewportTable],
  template: `
    <hilos-admin-page [page]="page">
      <hilos-viewport-table [controller]="controller">
        <ng-template hilosTableCell="name" let-row>{{ row.name }}</ng-template>
      </hilos-viewport-table>
    </hilos-admin-page>
  `,
})
class AdminPageTableHost {
  controller!: TableViewportController<Row>
  page = HilosPages.I18N_LANGUAGE
}

// Two columns, one of them aligned the way a numeric column is: what the page used
// to write onto its own `<td>` and now declares once.
const CELL_COLUMNS: HilosTableColumn[] = [
  { key: 'name', label: 'Name' },
  {
    key: 'size',
    label: 'Size',
    headerClass: 'text-end',
    cellClass: 'text-end',
  },
]

/** A host filling each declared column's cell from a template marked with its key. */
@Component({
  selector: 'test-cells-table-host',
  imports: [HilosTableCell, HilosViewportTable],
  template: `
    <hilos-viewport-table [controller]="controller">
      <ng-template hilosTableCell="name" let-row>
        <span class="named">{{ row.name }}</span>
      </ng-template>
      <!-- A page may wrap a template in a block of its own; the table still finds it. -->
      @if (withSize) {
        <ng-template hilosTableCell="size">
          <span class="sized">1.2 GB</span>
        </ng-template>
      }
    </hilos-viewport-table>
  `,
})
class CellsTableHost {
  controller!: TableViewportController<Row>
  withSize = true
}

// One column of each place a card has, plus one the page keeps out of it: the whole
// projection read back through the markup the view writes.
const CARD_COLUMNS: HilosTableColumn[] = [
  { key: 'name', label: 'Name' },
  { key: 'kind', label: 'Kind', cellClass: 'text-end' },
  { key: 'state', label: 'State', card: 'badge' },
  { key: 'secret', label: 'Secret', card: 'hidden' },
  { key: 'actions', label: '' },
]

/**
 * A host filling every place of a card; `full` off leaves the name alone, and
 * `withEmpty` hands the table the page's own empty words.
 */
@Component({
  selector: 'test-cards-table-host',
  imports: [HilosTableCell, HilosViewportTable],
  template: `
    <hilos-viewport-table [controller]="controller">
      <ng-template hilosTableCell="name" let-row>
        <span class="named">{{ row.name }}</span>
      </ng-template>
      @if (full) {
        <ng-template hilosTableCell="kind">
          <span class="kind">full</span>
        </ng-template>
        <ng-template hilosTableCell="state">
          <span class="state-badge">ready</span>
        </ng-template>
        <ng-template hilosTableCell="secret">
          <span class="secret">1.2 GB</span>
        </ng-template>
        <ng-template hilosTableCell="actions">
          <button type="button" class="restore">Restore</button>
        </ng-template>
      }
      @if (withEmpty) {
        <ng-template #empty>
          <span class="none-yet">No backups yet</span>
        </ng-template>
      }
    </hilos-viewport-table>
  `,
})
class CardsTableHost {
  controller!: TableViewportController<Row>
  full = true
  withEmpty = false
}

interface WordsRow {
  operation: string
  title: string
  phrase: string
  bar: string
  message?: HilosTableRowMessage | null
}

/** A host filling cells of words columns with row message support. */
@Component({
  selector: 'test-row-message-table-host',
  imports: [HilosTableCell, HilosViewportTable],
  template: `
    <hilos-viewport-table [controller]="controller">
      <ng-template hilosTableCell="operation" let-row>
        <span class="op">{{ row.operation }}</span>
      </ng-template>
      <ng-template hilosTableCell="title" let-row>
        <span class="title">{{ row.title }}</span>
      </ng-template>
      <ng-template hilosTableCell="phrase" let-row>
        <span class="phrase">{{ row.phrase }}</span>
      </ng-template>
      <ng-template hilosTableCell="bar" let-row>
        <span class="bar">{{ row.bar }}</span>
      </ng-template>
    </hilos-viewport-table>
  `,
})
class RowMessageTableHost {
  controller!: TableViewportController<WordsRow>
}

/** The identity a leaf answers with: its heading, and nothing below it. */
const LEAF_IDENTITY: HilosPageIdentity = {
  label: 'Language',
  lead: 'A single language.',
  breadcrumb: [{ page: HilosPages.I18N_LANGUAGE, label: 'Language' }],
  children: [],
}

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.I18N_LANGUAGE,
      params: {},
      admin: true,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal<HilosPageIdentity | undefined>(LEAF_IDENTITY),
    dashboardSections: createSignal(undefined),
    resolvePath: (page) => `/hilos/${page}`,
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

// Two columns, the second drawn by no template, for the states of the body: a
// skeleton row counts a cell for each of them.
const STATE_COLUMNS: HilosTableColumn[] = [
  { key: 'name', label: 'Name', sortable: true },
  { key: 'kind', label: 'Kind' },
]

/** A host drawing a table over two columns, one of them marked. */
@Component({
  selector: 'test-states-table-host',
  imports: [HilosTableCell, HilosViewportTable],
  template: `
    <hilos-viewport-table [controller]="controller">
      <ng-template hilosTableCell="name" let-row>
        <span class="named">{{ row.name }}</span>
      </ng-template>
    </hilos-viewport-table>
  `,
})
class StatesTableHost {
  controller!: TableViewportController<Row>
}

function makeController(
  frame: HilosTableFrame = FRAME,
  options: {
    refused?: (row: Row) => boolean
    expandable?: (row: Row) => boolean
  } = {},
): TableViewportController<Row> {
  return new TableViewportController<Row>({
    resolve: (raw) => ({ name: String(raw.slots['name']) }),
    sendViewport: () => undefined,
    frame,
    ...(options.refused !== undefined ? { refused: options.refused } : {}),
    ...(options.expandable !== undefined
      ? { expandable: options.expandable }
      : {}),
  })
}

function mountInAdminPage(
  controller: TableViewportController<Row>,
): ComponentFixture<AdminPageTableHost> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })
  const fixture = TestBed.createComponent(AdminPageTableHost)
  fixture.componentInstance.controller = controller
  fixture.detectChanges()

  return fixture
}

function mountDeclared(
  controller: TableViewportController<Row>,
): ComponentFixture<ViewportTableHost> {
  const fixture = TestBed.createComponent(ViewportTableHost)
  fixture.componentInstance.controller = controller
  fixture.detectChanges()

  return fixture
}

function mountDeclaredWithEdge(
  controller: TableViewportController<Row>,
  edge: HilosTableSelectionEdge,
): ComponentFixture<ViewportTableHost> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_TABLE_SELECTION_EDGE, useValue: edge }],
  })

  return mountDeclared(controller)
}

function query(
  fixture: ComponentFixture<unknown>,
  selector: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(selector)
}

describe('HilosViewportTable', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it.each(['text', 'parts'] as const)(
    'stamps a project %s cell through the wrapper in both projections',
    async (heightContent) => {
      const controller = makeController({
        title: 'Declared text',
        rowHeight: { mode: 'lines', count: 2 },
        columns: [{ key: 'name', label: 'Name', heightContent }],
      })
      controller.ingestWindow(
        [{ rowKey: 'a', slots: { name: 'Alice' } }],
        1,
        true,
        null,
        null,
        10,
      )
      const fixture = TestBed.createComponent(
        heightContent === 'parts' ? PartsTableHost : PlainTableHost,
      )
      fixture.componentInstance.controller = controller
      fixture.detectChanges()
      await fixture.whenStable()
      fixture.detectChanges()
      const root = fixture.nativeElement as HTMLElement
      const regions = [
        ...root.querySelectorAll('hilos-table-value .hilos-table-text-lines-2'),
      ]
      expect(regions).toHaveLength(2)
      expect(regions.map((region) => region.textContent?.trim())).toEqual([
        'Alice',
        'Alice',
      ])
      if (heightContent === 'parts') {
        expect(root.querySelectorAll('a[href="#person"]')).toHaveLength(2)
        expect(regions.every((node) => node.querySelector('a') === null)).toBe(
          true,
        )
      }
      expect(new Set(regions.map((region) => region.id)).size).toBe(2)
      fixture.destroy()
      controller.dispose()
    },
  )

  function makePlainController(
    columns = COLUMNS,
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
      resolve: (raw) => ({ name: String(raw.slots['name']) }),
      sendViewport: (descriptor) => sent.push(descriptor),
      frame: { columns },
      ...(options.refused !== undefined ? { refused: options.refused } : {}),
      ...(options.expandable !== undefined
        ? { expandable: options.expandable }
        : {}),
    })

    return { controller, sent }
  }

  function mountTable(
    controller: TableViewportController<Row>,
  ): ComponentFixture<PlainTableHost> {
    const fixture = TestBed.createComponent(PlainTableHost)
    fixture.componentInstance.controller = controller
    fixture.detectChanges()

    return fixture
  }

  function all(
    fixture: ComponentFixture<unknown>,
    selector: string,
  ): Element[] {
    return Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll(selector),
    )
  }

  it('numbers the columns of a composite order and says the place in words', () => {
    const { controller } = makePlainController(TWO_COLUMNS)
    controller.setOrder([
      { field: 'name', direction: 'desc' },
      { field: 'id', direction: 'asc' },
    ])
    const fixture = mountTable(controller)

    expect(all(fixture, 'th sup').map((mark) => mark.textContent)).toEqual([
      '1',
      '2',
    ])
    // aria-sort names a direction and cannot say "second by importance", so the
    // place is spoken beside the number instead.
    expect(
      all(fixture, 'th button .visually-hidden').map((said) =>
        said.textContent?.trim(),
      ),
    ).toEqual(['Sort column 1 of 2', 'Sort column 2 of 2'])
  })

  it('numbers nothing under an order of one column, where the arrow says it all', () => {
    const { controller } = makePlainController(TWO_COLUMNS)
    controller.setSort('name')
    const fixture = mountTable(controller)

    expect(all(fixture, 'th sup')).toHaveLength(0)
  })

  it('highlights a row whose new value landed in place, with no waiting mark', () => {
    const { controller } = makePlainController()
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
    const fixture = mountTable(controller)

    expect(
      query(fixture, '[data-id="hilos-table-row-a"]')?.classList.contains(
        'table-success',
      ),
    ).toBe(true)
    expect(all(fixture, '[data-id^="hilos-table-pending-"]')).toHaveLength(0)
  })

  it('marks a waiting row amber and says in words what waits on it', () => {
    const { controller } = makePlainController()
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
    const fixture = mountTable(controller)

    expect(
      query(fixture, '[data-id="hilos-table-row-a"]')?.classList.contains(
        'table-warning',
      ),
    ).toBe(true)
    expect(
      query(
        fixture,
        '[data-id="hilos-table-pending-move-a"]',
      )?.textContent?.trim(),
    ).toBe('Will move')
    expect(
      query(fixture, '[data-id="hilos-table-row-b"]')?.classList.contains(
        'table-warning',
      ),
    ).toBe(true)
    expect(
      query(
        fixture,
        '[data-id="hilos-table-pending-remove-b"]',
      )?.textContent?.trim(),
    ).toBe('Will leave')
  })

  it('lets the waiting outrank the highlight on a row that is both', () => {
    const { controller } = makePlainController()
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
    const fixture = mountTable(controller)

    const row = query(fixture, '[data-id="hilos-table-row-a"]')
    expect(row?.classList.contains('table-warning')).toBe(true)
    expect(row?.classList.contains('table-success')).toBe(false)
  })

  it('marks a refused row red, leaves an unrefused row unpainted, and keeps other contextual classes away', () => {
    const { controller: plainController } = makePlainController()
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
    const plainFixture = mountTable(plainController)
    const plainRowA = query(plainFixture, '[data-id="hilos-table-row-a"]')
    const plainRowB = query(plainFixture, '[data-id="hilos-table-row-b"]')
    expect(plainRowA?.classList.contains('table-danger')).toBe(false)
    expect(plainRowA?.classList.contains('table-warning')).toBe(false)
    expect(plainRowA?.classList.contains('table-success')).toBe(false)
    expect(plainRowA?.classList.contains('table-active')).toBe(false)
    expect(plainRowB?.classList.contains('table-danger')).toBe(false)

    const { controller } = makePlainController(COLUMNS, {
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
    const fixture = mountTable(controller)

    const rowA = query(fixture, '[data-id="hilos-table-row-a"]')
    expect(rowA?.classList.contains('table-danger')).toBe(true)
    expect(rowA?.classList.contains('table-warning')).toBe(false)
    expect(rowA?.classList.contains('table-success')).toBe(false)
    expect(rowA?.classList.contains('table-active')).toBe(false)

    const rowB = query(fixture, '[data-id="hilos-table-row-b"]')
    expect(rowB?.classList.contains('table-danger')).toBe(false)
    expect(rowB?.classList.contains('table-warning')).toBe(false)
    expect(rowB?.classList.contains('table-success')).toBe(false)
    expect(rowB?.classList.contains('table-active')).toBe(false)
  })

  it('lets the waiting outrank the refusal on a row that is both', () => {
    const { controller } = makePlainController(COLUMNS, {
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
    const fixture = mountTable(controller)

    const row = query(fixture, '[data-id="hilos-table-row-a"]')
    expect(row?.classList.contains('table-warning')).toBe(true)
    expect(row?.classList.contains('table-danger')).toBe(false)
    expect(row?.classList.contains('table-success')).toBe(false)
  })

  it('lets a fresh highlight outrank the refusal on a row, returning to red once the highlight expires', () => {
    vi.useFakeTimers()
    const { controller } = makePlainController(COLUMNS, {
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
    const fixture = mountTable(controller)
    const row = query(fixture, '[data-id="hilos-table-row-a"]')
    expect(row?.classList.contains('table-danger')).toBe(true)
    expect(row?.classList.contains('table-success')).toBe(false)

    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'refused' } },
    })
    fixture.detectChanges()
    expect(row?.classList.contains('table-success')).toBe(true)
    expect(row?.classList.contains('table-danger')).toBe(false)
    expect(row?.classList.contains('table-warning')).toBe(false)

    vi.advanceTimersByTime(2000)
    fixture.detectChanges()
    expect(row?.classList.contains('table-danger')).toBe(true)
    expect(row?.classList.contains('table-success')).toBe(false)
    expect(row?.classList.contains('table-warning')).toBe(false)
  })

  it('clears red when a row refusal is cured, and does not paint a departed placeholder row red', () => {
    vi.useFakeTimers()
    const { controller } = makePlainController(COLUMNS, {
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
    const fixture = mountTable(controller)
    const row = query(fixture, '[data-id="hilos-table-row-a"]')
    expect(row?.classList.contains('table-danger')).toBe(true)

    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'cured' } },
    })
    vi.advanceTimersByTime(2000)
    fixture.detectChanges()
    expect(row?.classList.contains('table-danger')).toBe(false)
    expect(row?.classList.contains('table-success')).toBe(false)
    expect(row?.classList.contains('table-warning')).toBe(false)

    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()
    fixture.detectChanges()
    const placeholderRow = query(fixture, '[data-id="hilos-table-row-a"]')
    expect(placeholderRow?.classList.contains('table-danger')).toBe(false)
    expect(placeholderRow?.classList.contains('table-warning')).toBe(false)
    expect(placeholderRow?.classList.contains('table-success')).toBe(false)
  })

  it('keeps an expanded refused row red and an expanded unrefused row active', () => {
    const { controller } = makePlainController(COLUMNS, {
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
    const fixture = mountTable(controller)

    const rowA = query(fixture, '[data-id="hilos-table-row-a"]')
    expect(rowA?.classList.contains('table-danger')).toBe(true)
    expect(rowA?.classList.contains('table-active')).toBe(false)
    expect(rowA?.classList.contains('table-warning')).toBe(false)
    expect(rowA?.classList.contains('table-success')).toBe(false)

    const rowB = query(fixture, '[data-id="hilos-table-row-b"]')
    expect(rowB?.classList.contains('table-active')).toBe(true)
    expect(rowB?.classList.contains('table-danger')).toBe(false)
    expect(rowB?.classList.contains('table-warning')).toBe(false)
  })

  it('keeps the permanent state column after pending changes clear', () => {
    const { controller } = makePlainController()
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
    // An applied removal leaves a placeholder with its permanent state track.
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()
    const fixture = mountTable(controller)
    const placeholderSpan = () =>
      query(fixture, '[data-id="hilos-table-placeholder"]')?.getAttribute(
        'colspan',
      )

    expect(all(fixture, 'thead th')).toHaveLength(COLUMNS.length + 1)
    expect(placeholderSpan()).toBe(String(COLUMNS.length + 1))

    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'b',
      row: { rowKey: 'b', slots: { name: 'Bobby' } },
    })
    fixture.detectChanges()

    expect(all(fixture, 'thead th')).toHaveLength(COLUMNS.length + 1)
    expect(placeholderSpan()).toBe(String(COLUMNS.length + 1))
  })

  it('raises one strip for rows announced above or inside the window, and counts them together', () => {
    const { controller } = makePlainController()
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      1,
      true,
      null,
      null,
      10,
    )
    const fixture = mountTable(controller)
    const strip = () => query(fixture, '[data-id="hilos-table-announce"]')

    expect(strip()).toBeNull()

    // The strip names no place (HIL-1026): a row inside the window raises it as
    // one above does, and the two are one number.
    controller.ingestAnnounce('x', 'inside', 2, true)
    fixture.detectChanges()

    expect(strip()?.textContent).toContain('1 new row')
    expect(strip()?.textContent).not.toContain('above')

    controller.ingestAnnounce('y', 'above', 3, true)
    fixture.detectChanges()

    expect(strip()?.textContent).toContain('2 new rows')
    expect(strip()?.textContent).not.toContain('above')
  })

  it('asks for the window again when Show is pressed, and the strip goes', () => {
    const { controller, sent } = makePlainController()
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      1,
      true,
      null,
      null,
      10,
    )
    controller.ingestAnnounce('x', 'above', 2, true)
    const fixture = mountTable(controller)
    const asked = sent.length

    query(fixture, '[data-id="hilos-table-announce-show"]')?.click()
    fixture.detectChanges()

    expect(sent).toHaveLength(asked + 1)
    expect(query(fixture, '[data-id="hilos-table-announce"]')).toBeNull()
  })

  it('gives the one line to the waiting and keeps the new rows as an icon beside it', () => {
    const { controller } = makePlainController()
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
    const fixture = mountTable(controller)

    // One room, one line: the waiting holds it because its button would be hidden
    // otherwise, and the new rows keep speaking by their icon (Flow F4).
    expect(query(fixture, '[data-id="hilos-table-announce"]')).toBeNull()
    expect(query(fixture, '[data-id="hilos-table-apply"]')).not.toBeNull()
    expect(
      query(fixture, '[data-id="hilos-table-pending"]')?.textContent?.trim(),
    ).toBe('1')
    expect(
      query(fixture, '[data-id="hilos-table-live-rest"] .bi-arrow-down-circle'),
    ).not.toBeNull()
  })

  describe('marking a source that went quiet', () => {
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

    function headers(fixture: ComponentFixture<unknown>): HTMLElement[] {
      return all(fixture, 'thead th') as HTMLElement[]
    }

    it('raises the strip and names the columns built from the quiet source', () => {
      const { controller } = makePlainController(SOURCED_COLUMNS)
      window(controller, ['connections'])
      const fixture = mountTable(controller)

      expect(
        query(fixture, '[data-id="hilos-table-stale"]')?.textContent?.trim(),
      ).toBe(
        'Presence is not updating: the link to its source was lost. The other columns are live.',
      )
    })

    it('raises the strip in the generic wording when no column named the source', () => {
      const { controller } = makePlainController()
      window(controller, ['connections'])
      // COLUMNS declares no source at all, so there is nothing to name — and saying
      // nothing would leave yesterday's value looking like today's.
      const fixture = mountTable(controller)

      expect(
        query(fixture, '[data-id="hilos-table-stale"]')?.textContent?.trim(),
      ).toBe(
        'Some values here are not updating: the link to their source was lost. The other columns are live.',
      )
    })

    it('keeps the sort control on a quiet column and carries the warning inside it', () => {
      const { controller } = makePlainController(SOURCED_COLUMNS)
      window(controller, ['connections'])
      const fixture = mountTable(controller)

      const button = query(fixture, '[data-id="hilos-table-sort-presence"]')
      expect(button).not.toBeNull()
      expect(query(fixture, '[data-id="hilos-table-sort-name"]')).not.toBeNull()
      expect(
        button?.querySelector('[data-id="hilos-table-stale-column-presence"]'),
      ).not.toBeNull()
      expect(headers(fixture)[1]?.textContent).toContain(
        'Sorting by this column may be wrong',
      )
    })

    it('marks a quiet column that was never sortable without giving it a control', () => {
      const { controller } = makePlainController([
        SOURCED_COLUMNS[0]!,
        { ...SOURCED_COLUMNS[1]!, sortable: false },
      ])
      window(controller, ['connections'])
      const fixture = mountTable(controller)

      const header = headers(fixture)[1]
      expect(query(fixture, '[data-id="hilos-table-sort-presence"]')).toBeNull()
      expect(
        header?.querySelector('[data-id="hilos-table-stale-column-presence"]'),
      ).not.toBeNull()
      expect(header?.textContent).toContain("This column's source is lagging")
      expect(header?.textContent).not.toContain(
        'Sorting by this column may be wrong',
      )
    })

    it('sends a viewport when the name of a quiet column is clicked', () => {
      const { controller, sent } = makePlainController(SOURCED_COLUMNS)
      window(controller, ['connections'])
      const fixture = mountTable(controller)
      sent.length = 0
      query(fixture, '[data-id="hilos-table-sort-presence"]')?.click()

      expect(sent).toHaveLength(1)
    })

    it('numbers headers of a composite order whose first column is quiet and speaks both places', () => {
      const { controller } = makePlainController(SOURCED_COLUMNS)
      window(controller, ['connections'])
      controller.setOrder([
        { field: 'presence', direction: 'asc' },
        { field: 'name', direction: 'desc' },
      ])
      const fixture = mountTable(controller)

      expect(all(fixture, 'th sup').map((mark) => mark.textContent)).toEqual([
        '2',
        '1',
      ])
      expect(
        all(fixture, 'th sup + .visually-hidden').map(
          (mark) => mark.textContent,
        ),
      ).toEqual(['Sort column 2 of 2', 'Sort column 1 of 2'])
    })

    it('keeps the standing order over a column that went quiet readable', () => {
      const { controller } = makePlainController(SOURCED_COLUMNS)
      window(controller, [])
      const fixture = mountTable(controller)
      controller.setSort('presence')
      fixture.detectChanges()
      controller.ingestDelta({
        kind: 'row_stale',
        rowKey: 'a',
        staleSources: ['connections'],
      })
      fixture.detectChanges()

      // The rows lie in that order right now, and saying so is the truth; only
      // choosing or reversing it is gone.
      const header = headers(fixture)[1]
      expect(header?.getAttribute('aria-sort')).toBe('ascending')
      expect(header?.querySelector('.bi-arrow-up')).not.toBeNull()
    })

    it('marks exactly the rows whose own values are behind', () => {
      const { controller } = makePlainController(SOURCED_COLUMNS)
      window(controller, ['connections'])
      const fixture = mountTable(controller)

      expect(
        query(fixture, '[data-id="hilos-table-stale-row-a"]'),
      ).not.toBeNull()
      expect(query(fixture, '[data-id="hilos-table-stale-row-b"]')).toBeNull()
    })

    it('keeps the permanent state cell when a row source goes quiet', () => {
      const { controller } = makePlainController(SOURCED_COLUMNS)
      window(controller, ['connections'])
      const fixture = mountTable(controller)

      expect(headers(fixture)).toHaveLength(SOURCED_COLUMNS.length + 1)
      expect(
        query(
          fixture,
          'thead th:last-child > .visually-hidden',
        )?.textContent?.trim(),
      ).toBe('Row state and controls')
      // The state track was already present; its live snowflake now stands in it.
      const cells = all(fixture, '[data-id="hilos-table-row-a"] td')
      expect(
        cells[cells.length - 1]?.querySelector(
          '[data-id="hilos-table-stale-row-a"]',
        ),
      ).not.toBeNull()
    })

    it('clears live snowflakes while keeping the permanent state cell', () => {
      const { controller } = makePlainController(SOURCED_COLUMNS)
      window(controller, ['connections'])
      const fixture = mountTable(controller)
      controller.ingestDelta({
        kind: 'row_stale',
        rowKey: 'a',
        staleSources: [],
      })
      fixture.detectChanges()

      expect(query(fixture, '[data-id="hilos-table-stale"]')).toBeNull()
      expect(
        query(fixture, '[data-id="hilos-table-stale-column-presence"]'),
      ).toBeNull()
      expect(query(fixture, '[data-id="hilos-table-stale-row-a"]')).toBeNull()
      expect(
        query(fixture, '[data-id="hilos-table-sort-presence"]'),
      ).not.toBeNull()
      expect(headers(fixture)).toHaveLength(SOURCED_COLUMNS.length + 1)
    })
  })
})

describe('HilosViewportTable with a declared frame', () => {
  it('draws the declared bar and names the table by its visible title', () => {
    const fixture = mountDeclared(makeController())

    const title = query(fixture, '[data-id="hilos-table-title"]')
    expect(title?.textContent?.trim()).toBe('Backups')
    expect(query(fixture, 'table')?.getAttribute('aria-labelledby')).toBe(
      title?.id,
    )
    expect(query(fixture, 'caption')).toBeNull()
  })

  it('draws the declared footer with the delivered row range', () => {
    const controller = makeController()
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      128,
      true,
      null,
      null,
      20,
    )
    const fixture = mountDeclared(controller)

    expect(
      query(fixture, '[data-id="hilos-table-count"]')?.textContent?.trim(),
    ).toBe('1 – 1 of 128')
  })

  it('keeps the retained zero footer, clears Apply, and hides it on a fresh empty snapshot', () => {
    const controller = makeController()
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      1,
      true,
      null,
      null,
      20,
    )
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.ingestCount(0, true)
    const fixture = mountDeclared(controller)

    expect(query(fixture, '[data-id="hilos-table-apply"]')).not.toBeNull()
    controller.apply()
    fixture.detectChanges()

    expect(controller.frame.body.get()).toBe('rows')
    expect(
      query(fixture, '[data-id="hilos-table-count"]')?.textContent?.trim(),
    ).toBe('0 – 0 of 0')
    expect(query(fixture, '[data-id="hilos-table-apply"]')).toBeNull()
    expect(
      (fixture.nativeElement as HTMLElement).querySelectorAll(
        '[data-id="hilos-table-placeholder"]',
      ),
    ).toHaveLength(2)
    expect(query(fixture, '[data-id="hilos-table-empty"]')).toBeNull()

    controller.ingestWindow([], 0, true, null, null, 20)
    fixture.detectChanges()
    expect(controller.frame.body.get()).toBe('empty')
    expect(query(fixture, '[data-id="hilos-table-count"]')).toBeNull()
    expect(query(fixture, '[data-id="hilos-table-placeholder"]')).toBeNull()
    fixture.destroy()
    controller.dispose()
  })

  it('draws one search bar and keeps Apply in the waiting strip', () => {
    const controller = makeController()
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
    const fixture = mountDeclared(controller)

    // The declared search bar and the waiting strip each stand once.
    expect(
      (fixture.nativeElement as HTMLElement).querySelectorAll(
        '[data-id="hilos-table-search"]',
      ),
    ).toHaveLength(1)
    expect(query(fixture, '[data-id="hilos-table-apply"]')).not.toBeNull()
    expect(
      query(fixture, '[data-id="hilos-table-pending"]')?.textContent?.trim(),
    ).toBe('1')
  })
})

describe('HilosViewportTable permanent state room', () => {
  type Pending = 'move' | 'remove' | null
  type Detail = 'absent' | 'collapsed' | 'expanded'
  const cases = [false, true].flatMap((stale) =>
    ([null, 'move', 'remove'] as const).flatMap((pending) =>
      (['absent', 'collapsed', 'expanded'] as const).map((detail) => ({
        stale,
        pending,
        detail,
      })),
    ),
  )

  for (const edge of ['start', 'end'] as const) {
    it.each(cases)(
      `${edge}: stale=$stale pending=$pending detail=$detail`,
      ({
        stale,
        pending,
        detail,
      }: {
        stale: boolean
        pending: Pending
        detail: Detail
      }) => {
        const columns: HilosTableColumn[] = [
          { key: 'name', label: 'Name', source: 'people', progress: true },
        ]
        if (detail !== 'absent') {
          columns.push({ key: 'note', label: 'Note', detail: true })
        }
        const frame: HilosTableFrame = {
          title: 'People',
          columns,
          bulkActions: [
            {
              key: 'archive',
              label: 'Archive',
              refusalTitle: "Couldn't archive",
              run: () => {
                throw new Error('The declaration is only read here')
              },
            },
          ],
        }
        const controller = makeController(frame)
        controller.ingestWindow(
          [{ rowKey: 'a', slots: { name: 'Alice' } }],
          1,
          true,
          null,
          null,
          10,
        )
        if (stale) {
          controller.ingestDelta({
            kind: 'row_stale',
            rowKey: 'a',
            staleSources: ['people'],
          })
        }
        if (detail === 'expanded') {
          controller.expandRow('a', true)
        }
        if (pending === 'move') {
          controller.ingestDelta({
            kind: 'row_moved',
            rowKey: 'a',
            row: { rowKey: 'a', slots: { name: 'Alice' } },
            position: 0,
          })
        } else if (pending === 'remove') {
          controller.ingestDelta({
            kind: 'row_removed',
            rowKey: 'a',
            reason: 'deleted',
          })
        }
        const fixture = mountDeclaredWithEdge(controller, edge)
        const root = fixture.nativeElement as HTMLElement
        const holders = root.querySelectorAll('.hilos-table-marks.invisible')

        expect(root.querySelectorAll('thead th')).toHaveLength(3)
        expect(root.querySelectorAll('colgroup col')).toHaveLength(3)
        expect(
          root.querySelectorAll('[data-id="hilos-table-row-a"] td'),
        ).toHaveLength(3)
        expect(holders).toHaveLength(3)
        for (const holder of holders) {
          expect(holder.getAttribute('aria-hidden')).toBe('true')
          expect(holder.hasAttribute('inert')).toBe(true)
          expect(holder.querySelectorAll('.bi-snow')).toHaveLength(1)
          expect(
            Array.from(holder.querySelectorAll('.badge')).map((badge) =>
              badge.textContent?.trim(),
            ),
          ).toEqual(['Will move', 'Will leave'])
          expect(holder.querySelectorAll('.btn')).toHaveLength(
            detail === 'absent' ? 0 : 1,
          )
          expect(
            holder.querySelector(
              '[id], [data-id], [data-autofocus], button, input, [tabindex], a',
            ),
          ).toBeNull()
        }
        expect(
          root.querySelectorAll('[data-id="hilos-table-expand-a"]'),
        ).toHaveLength(detail === 'absent' ? 0 : 2)
        expect(
          root.querySelectorAll('[data-id="hilos-table-stale-column-name"]'),
        ).toHaveLength(stale ? 1 : 0)
        expect(
          root.querySelectorAll(
            'th .bi-snow.invisible, th .hilos-table-marks.invisible .bi-snow',
          ),
        ).toHaveLength(2)

        if (pending === 'remove') {
          controller.apply()
          fixture.detectChanges()
          const placeholders = root.querySelectorAll(
            '[data-id="hilos-table-placeholder"]',
          )
          expect(placeholders).toHaveLength(2)
          for (const placeholder of placeholders) {
            expect(
              placeholder.querySelector('button, input, [id], [tabindex]'),
            ).toBeNull()
          }
          expect(
            root
              .querySelector('td[data-id="hilos-table-placeholder"]')
              ?.getAttribute('colspan'),
          ).toBe('3')
          expect(
            root.querySelectorAll(
              '[data-id="hilos-table-expand-a"], [data-id="hilos-table-select-a"], [data-id="hilos-table-row-detail-a"]',
            ),
          ).toHaveLength(0)
        }

        fixture.destroy()
        controller.dispose()
      },
    )
  }
})

describe('HilosViewportTable on an admin page', () => {
  /** A table with no title of its own — the one table of such a page. */
  const UNTITLED: HilosTableFrame = {
    search: {},
    columns: COLUMNS,
    empty: { title: 'No languages yet.' },
  }

  it('names a declared table that has no title of its own by the page heading', () => {
    const fixture = mountInAdminPage(makeController(UNTITLED))

    const heading = query(fixture, '[data-id="hilos-admin-title"]')
    expect(heading?.id).toBeTruthy()
    expect(query(fixture, 'table')?.getAttribute('aria-labelledby')).toBe(
      heading?.id,
    )
    // One name over one table: the table draws no heading of its own repeating it.
    expect(query(fixture, '[data-id="hilos-table-title"]')).toBeNull()
  })

  it('draws the header and the empty words from the declaration', () => {
    const controller = makeController(UNTITLED)
    controller.ingestWindow([], 0, true, null, null, 20)
    const fixture = mountInAdminPage(controller)

    expect(
      query(fixture, '[data-id="hilos-table-sort-name"]')?.textContent?.trim(),
    ).toBe('Name')
    expect(query(fixture, 'tbody')?.textContent?.trim()).toBe(
      'No languages yet.',
    )
  })
})

describe('HilosViewportTable drawing the cells of a declared table', () => {
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

  function mountCells(withSize = true): ComponentFixture<CellsTableHost> {
    const controller = makeController(CELL_FRAME)
    window(controller)
    const fixture = TestBed.createComponent(CellsTableHost)
    fixture.componentInstance.controller = controller
    fixture.componentInstance.withSize = withSize
    fixture.detectChanges()

    return fixture
  }

  function rowCells(fixture: ComponentFixture<unknown>): HTMLElement[] {
    return Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll<HTMLElement>(
        '[data-id="hilos-table-row-a"] td',
      ),
    )
  }

  it('draws one cell per declared column and fills it from its own template', () => {
    const fixture = mountCells()

    const cells = rowCells(fixture)
    expect(cells).toHaveLength(CELL_COLUMNS.length + 1)
    expect(cells[0]?.querySelector('.named')?.textContent).toBe('Alice')
    // Found although the page wrapped it in a block of its own.
    expect(cells[1]?.querySelector('.sized')?.textContent).toBe('1.2 GB')
  })

  it('puts the declared cell class on the body cell and nowhere else', () => {
    const fixture = mountCells()

    const cells = rowCells(fixture)
    expect(cells[0]?.classList.contains('text-end')).toBe(false)
    expect(cells[1]?.classList.contains('text-end')).toBe(true)
  })

  it('leaves the cell standing where the page marked no template', () => {
    const fixture = mountCells(false)

    const cells = rowCells(fixture)
    expect(cells).toHaveLength(
      (fixture.nativeElement as HTMLElement).querySelectorAll('thead th')
        .length,
    )
    expect(cells[1]?.textContent?.trim()).toBe('')
  })

  it('uses the same declared columns for the header and cells', () => {
    const controller = makeController(CELL_FRAME)
    window(controller)
    const fixture = TestBed.createComponent(CellsTableHost)
    fixture.componentInstance.controller = controller
    fixture.detectChanges()

    const root = fixture.nativeElement as HTMLElement
    expect(root.querySelectorAll('thead th')).toHaveLength(
      CELL_COLUMNS.length + 1,
    )
    expect(root.querySelector('thead')?.textContent).toContain('Size')
    expect(root.querySelector('.sized')).not.toBeNull()
  })
})

describe('HilosViewportTable drawing a row as a card', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  /** The sender of the declared operation, which this block never presses. */
  function neverRun(): ActionHandle<HilosTableBulkAccepted> {
    throw new Error('the declaration is only read here')
  }

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

  function mountCards(
    controller: TableViewportController<Row>,
    options: {
      edge?: HilosTableSelectionEdge
      full?: boolean
      withEmpty?: boolean
    } = {},
  ): ComponentFixture<CardsTableHost> {
    if (options.edge !== undefined) {
      TestBed.configureTestingModule({
        providers: [
          { provide: HILOS_TABLE_SELECTION_EDGE, useValue: options.edge },
        ],
      })
    }
    const fixture = TestBed.createComponent(CardsTableHost)
    fixture.componentInstance.controller = controller
    fixture.componentInstance.full = options.full ?? true
    fixture.componentInstance.withEmpty = options.withEmpty ?? false
    fixture.detectChanges()

    return fixture
  }

  function root(fixture: ComponentFixture<unknown>): HTMLElement {
    return fixture.nativeElement as HTMLElement
  }

  function cardOf(
    fixture: ComponentFixture<unknown>,
    rowKey: string,
  ): HTMLElement {
    return query(
      fixture,
      `[data-id="hilos-table-card-${rowKey}"]`,
    ) as HTMLElement
  }

  it('stands the cards beside the table and shows exactly one of the two', () => {
    const controller = makeController(CARD_FRAME)
    window(controller)
    const fixture = mountCards(controller)

    const cards = query(fixture, '[data-id="hilos-table-cards"]') as HTMLElement
    expect(cards.classList.contains('d-md-none')).toBe(true)
    expect(
      cards.querySelectorAll('[data-id^="hilos-table-card-"]'),
    ).toHaveLength(2)

    const wide = query(fixture, '.table-responsive') as HTMLElement
    expect(wide.classList.contains('d-none')).toBe(true)
    expect(wide.classList.contains('d-md-block')).toBe(true)
    // Nothing to scroll sideways once the columns became lines of a card.
    expect(cards.querySelectorAll('.table-responsive')).toHaveLength(0)
  })

  it('lays the card out the way the core projected it', () => {
    const controller = makeController(CARD_FRAME)
    window(controller)
    const fixture = mountCards(controller)
    const card = cardOf(fixture, 'a')

    expect(card.querySelector('.named')?.textContent).toBe('Alice')
    // The title and the badge are drawn bare; only fields carry a label.
    expect(card.textContent).not.toContain('Name')
    expect(card.querySelector('.state-badge')).not.toBeNull()
    expect(card.textContent).not.toContain('State')
    expect(
      Array.from(card.querySelectorAll('dt')).map((label) =>
        label.textContent?.trim(),
      ),
    ).toEqual(['Kind'])
    expect(card.querySelector('dd .kind')).not.toBeNull()
    expect(card.querySelector('.hilos-button-row .restore')).not.toBeNull()
    // A column the page kept out of the card is nowhere in it, though it still
    // stands in the row.
    expect(card.querySelector('.secret')).toBeNull()
    expect(
      query(fixture, '[data-id="hilos-table-row-a"] .secret'),
    ).not.toBeNull()
  })

  it('fills a cell of the row and a line of the card from one template', () => {
    const controller = makeController(CARD_FRAME)
    window(controller)
    const fixture = mountCards(controller)

    expect(query(fixture, '[data-id="hilos-table-row-a"] .kind')).not.toBeNull()
    expect(cardOf(fixture, 'a').querySelector('.kind')).not.toBeNull()
  })

  it('leaves out the card line of a column the page marked no template for', () => {
    const controller = makeController(CARD_FRAME)
    window(controller)
    const fixture = mountCards(controller, { full: false })

    // The row keeps every cell, or it comes out narrower than its header; the
    // card keeps no label with nothing under it.
    expect(
      root(fixture).querySelectorAll('[data-id="hilos-table-row-a"] td'),
    ).toHaveLength(CARD_COLUMNS.length + 1)
    expect(cardOf(fixture, 'a').querySelectorAll('dt')).toHaveLength(0)
    expect(cardOf(fixture, 'a').querySelector('.hilos-button-row')).toBeNull()
  })

  it('puts the declared cell class on the row cell and not on the card line', () => {
    const controller = makeController(CARD_FRAME)
    window(controller)
    const fixture = mountCards(controller)

    const cells = root(fixture).querySelectorAll(
      '[data-id="hilos-table-row-a"] td',
    )
    expect(cells[1]?.classList.contains('text-end')).toBe(true)
    expect(
      cardOf(fixture, 'a').querySelector('dd')?.classList.contains('text-end'),
    ).toBe(false)
  })

  it('wraps actions cell content in an inline-flex gap-1 container on wide rows', () => {
    const controller = makeController(CARD_FRAME)
    window(controller)
    const fixture = mountCards(controller)

    const row = query(fixture, '[data-id="hilos-table-row-a"]')
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
      const controller = makeController(CARD_FRAME)
      window(controller)
      controller.ingestDelta({ kind: 'row_removed', rowKey: 'a', reason })
      controller.apply()
      const fixture = mountCards(controller)

      const row = query(
        fixture,
        '[data-id="hilos-table-row-a"] [data-id="hilos-table-placeholder"]',
      )
      const card = cardOf(fixture, 'a').querySelector(
        '[data-id="hilos-table-placeholder"]',
      )
      expect(row?.textContent?.trim()).toBe(text)
      expect(row?.querySelector('i')?.classList.contains(icon)).toBe(true)
      expect(card?.textContent?.trim()).toBe(text)
      expect(card?.querySelector('i')?.classList.contains(icon)).toBe(true)
      expect(
        root(fixture).querySelectorAll('[data-id^="hilos-table-card-"]'),
      ).toHaveLength(2)
      fixture.destroy()
    }
  })

  it('retains placeholders without an empty tile when the count reaches zero', () => {
    const controller = makeController(CARD_FRAME)
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
    const fixture = mountCards(controller)

    expect(
      root(fixture).querySelectorAll('[data-id="hilos-table-placeholder"]'),
    ).toHaveLength(4)
    expect(
      root(fixture).querySelectorAll('[data-id="hilos-table-empty"]'),
    ).toHaveLength(0)
  })

  it('tints a card amber while a change waits and green after one landed', () => {
    const controller = makeController(CARD_FRAME)
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
    const fixture = mountCards(controller)

    expect(cardOf(fixture, 'a').classList.contains('border-warning')).toBe(true)
    expect(cardOf(fixture, 'a').classList.contains('card')).toBe(true)
    expect(cardOf(fixture, 'b').classList.contains('border-success')).toBe(true)
  })

  it('lets the waiting outrank the highlight on a card that is both', () => {
    const controller = makeController(CARD_FRAME)
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
    const fixture = mountCards(controller)
    const card = cardOf(fixture, 'a')

    expect(card.classList.contains('border-warning')).toBe(true)
    expect(card.classList.contains('border-success')).toBe(false)
  })

  it('marks a refused card red, leaves an unrefused card unpainted, and keeps other border classes away', () => {
    const plainController = makeController(CARD_FRAME)
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
    const plainFixture = mountCards(plainController)
    const plainCardA = cardOf(plainFixture, 'a')
    const plainCardB = cardOf(plainFixture, 'b')
    expect(plainCardA.classList.contains('border-danger')).toBe(false)
    expect(plainCardA.classList.contains('border-warning')).toBe(false)
    expect(plainCardA.classList.contains('border-success')).toBe(false)
    expect(plainCardB.classList.contains('border-danger')).toBe(false)

    const controller = makeController(CARD_FRAME, {
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
    const fixture = mountCards(controller)

    const cardA = cardOf(fixture, 'a')
    expect(cardA.classList.contains('border-danger')).toBe(true)
    expect(cardA.classList.contains('border-warning')).toBe(false)
    expect(cardA.classList.contains('border-success')).toBe(false)

    const cardB = cardOf(fixture, 'b')
    expect(cardB.classList.contains('border-danger')).toBe(false)
    expect(cardB.classList.contains('border-warning')).toBe(false)
    expect(cardB.classList.contains('border-success')).toBe(false)
  })

  it('lets the waiting outrank the refusal on a card that is both', () => {
    const controller = makeController(CARD_FRAME, {
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
    const fixture = mountCards(controller)
    const card = cardOf(fixture, 'a')

    expect(card.classList.contains('border-warning')).toBe(true)
    expect(card.classList.contains('border-danger')).toBe(false)
    expect(card.classList.contains('border-success')).toBe(false)
  })

  it('lets a fresh highlight outrank the refusal on a card, returning to red once the highlight expires', () => {
    vi.useFakeTimers()
    const controller = makeController(CARD_FRAME, {
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
    const fixture = mountCards(controller)
    const card = cardOf(fixture, 'a')
    expect(card.classList.contains('border-danger')).toBe(true)

    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'refused' } },
    })
    fixture.detectChanges()
    expect(card.classList.contains('border-success')).toBe(true)
    expect(card.classList.contains('border-danger')).toBe(false)
    expect(card.classList.contains('border-warning')).toBe(false)

    vi.advanceTimersByTime(2000)
    fixture.detectChanges()
    expect(card.classList.contains('border-danger')).toBe(true)
    expect(card.classList.contains('border-success')).toBe(false)
    expect(card.classList.contains('border-warning')).toBe(false)
  })

  it('clears red when a card refusal is cured, and does not paint a departed placeholder card red', () => {
    vi.useFakeTimers()
    const controller = makeController(CARD_FRAME, {
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
    const fixture = mountCards(controller)
    const card = cardOf(fixture, 'a')
    expect(card.classList.contains('border-danger')).toBe(true)

    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'cured' } },
    })
    vi.advanceTimersByTime(2000)
    fixture.detectChanges()
    expect(card.classList.contains('border-danger')).toBe(false)
    expect(card.classList.contains('border-success')).toBe(false)
    expect(card.classList.contains('border-warning')).toBe(false)

    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()
    fixture.detectChanges()
    const placeholderCard = cardOf(fixture, 'a')
    expect(placeholderCard.classList.contains('border-danger')).toBe(false)
    expect(placeholderCard.classList.contains('border-warning')).toBe(false)
    expect(placeholderCard.classList.contains('border-success')).toBe(false)
  })

  it('keeps an expanded refused card border-danger without grey, and an expanded unrefused card borderless', () => {
    const controller = makeController(CARD_FRAME, {
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
    const fixture = mountCards(controller)

    const cardA = cardOf(fixture, 'a')
    expect(cardA.classList.contains('border-danger')).toBe(true)
    expect(cardA.classList.contains('border-secondary')).toBe(false)
    expect(cardA.classList.contains('border-active')).toBe(false)
    expect(cardA.classList.contains('border-warning')).toBe(false)
    expect(cardA.classList.contains('border-success')).toBe(false)

    const cardB = cardOf(fixture, 'b')
    expect(cardB.classList.contains('border-danger')).toBe(false)
    expect(cardB.classList.contains('border-secondary')).toBe(false)
    expect(cardB.classList.contains('border-active')).toBe(false)
    expect(cardB.classList.contains('border-warning')).toBe(false)
    expect(cardB.classList.contains('border-success')).toBe(false)
  })

  it('reserves a framework card-mark row beside the page badge', () => {
    const controller = makeController(CARD_FRAME)
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
    const fixture = mountCards(controller)

    const card = cardOf(fixture, 'a')
    const badge = card.querySelector('.ms-auto')
    const marks = card.querySelector('.hilos-table-card-marks')
    expect(badge?.querySelector('.state-badge')).not.toBeNull()
    expect(
      badge?.querySelector('[data-id="hilos-table-stale-row-a"]'),
    ).toBeNull()
    expect(
      marks?.querySelector('[data-id="hilos-table-stale-row-a"]'),
    ).not.toBeNull()
    expect(
      marks?.querySelector('[data-id="hilos-table-pending-move-a"]'),
    ).not.toBeNull()
    expect(badge?.nextElementSibling).toBe(marks)
  })

  it('puts the bar of a running job at the foot of the card', () => {
    const controller = makeController(CARD_FRAME)
    window(controller)
    controller.ingestProgress({
      scope: 'row',
      progressKey: 'pack-a',
      rowKey: 'a',
      current: 34,
      total: 110,
    })
    const fixture = mountCards(controller)

    const card = cardOf(fixture, 'a')
    const bar = card.querySelector('[data-id="hilos-table-progress-row-a"]')
    expect(bar).not.toBeNull()
    expect(bar?.querySelector('[role="progressbar"]')).not.toBeNull()
    expect(card.querySelector('.card-body')?.lastElementChild).toBe(bar)

    // A row shown as a placeholder gets no bar, on a card as in a row.
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()
    fixture.detectChanges()

    expect(
      cardOf(fixture, 'a').querySelector(
        '[data-id="hilos-table-progress-row-a"]',
      ),
    ).toBeNull()
  })

  it('says loading and then the page own empty words in both branches', () => {
    const controller = makeController(CARD_FRAME)
    const fixture = mountCards(controller, { withEmpty: true })

    expect(
      root(fixture).querySelectorAll('[data-id="hilos-table-loading"]'),
    ).toHaveLength(2)

    controller.ingestWindow([], 0, true, null, null, 10)
    fixture.detectChanges()

    expect(
      root(fixture).querySelectorAll('[data-id="hilos-table-loading"]'),
    ).toHaveLength(0)
    expect(root(fixture).querySelectorAll('.none-yet')).toHaveLength(2)
  })

  it('names the list of cards with the heading the table is named by', () => {
    const controller = makeController(CARD_FRAME)
    window(controller)
    const fixture = mountCards(controller)

    const list = query(
      fixture,
      '[data-id="hilos-table-cards"] [role="list"]',
    ) as HTMLElement
    const titleId = query(fixture, '[data-id="hilos-table-title"]')?.id
    expect(list.getAttribute('aria-labelledby')).toBe(titleId)
    expect(query(fixture, 'table')?.getAttribute('aria-labelledby')).toBe(
      titleId,
    )
    expect(cardOf(fixture, 'a').getAttribute('role')).toBe('listitem')
    // The list owns cards and nothing else.
    expect(list.children).toHaveLength(2)
  })

  it('keeps the words of an empty table beside the list and not inside it', () => {
    const controller = makeController(CARD_FRAME)
    const fixture = mountCards(controller)

    expect(
      query(
        fixture,
        '[data-id="hilos-table-cards"] [role="list"] [data-id="hilos-table-loading"]',
      ),
    ).toBeNull()
    expect(
      query(
        fixture,
        '[data-id="hilos-table-cards"] [data-id="hilos-table-loading"]',
      ),
    ).not.toBeNull()

    controller.ingestWindow([], 0, true, null, null, 10)
    fixture.detectChanges()

    expect(
      query(fixture, '[data-id="hilos-table-cards"] [role="list"]'),
    ).toBeNull()
  })

  it('carries the row mark in the head of the card, on the edge the app chose', () => {
    const controller = makeController(BULK_CARD_FRAME)
    window(controller)
    const left = mountCards(controller)

    const box = cardOf(left, 'a').querySelector<HTMLInputElement>(
      '[data-id="hilos-table-select-a"]',
    )
    expect(box).not.toBeNull()
    // On the left edge the mark stands before the title, not in the group of
    // marks pushed to the right.
    expect(cardOf(left, 'a').querySelector('.ms-auto input')).toBeNull()

    // The card box tells the core the state it is now in, as the row box does.
    box?.click()
    expect(controller.selection.count.get()).toBe(1)
    left.destroy()
    TestBed.resetTestingModule()

    const right = mountCards(controller, { edge: 'end' })
    expect(
      cardOf(right, 'a')
        .querySelector('.hilos-table-card-marks + input')
        ?.getAttribute('data-id'),
    ).toBe('hilos-table-select-a')
  })

  it('gives a card shown as a placeholder no mark to make', () => {
    const controller = makeController(BULK_CARD_FRAME)
    window(controller)
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()
    const fixture = mountCards(controller)

    expect(
      cardOf(fixture, 'a').querySelector('[data-id="hilos-table-select-a"]'),
    ).toBeNull()
    expect(
      cardOf(fixture, 'b').querySelector('[data-id="hilos-table-select-b"]'),
    ).not.toBeNull()
  })

  it('projects row message across wide merged tail and narrow card phrase', () => {
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

    const fixture = TestBed.createComponent(RowMessageTableHost)
    fixture.componentInstance.controller = controller
    fixture.detectChanges()

    const normalWideRow = query(
      fixture,
      '[data-id="hilos-table-row-default"]',
    ) as HTMLElement
    const normalWideCells = normalWideRow.querySelectorAll('td')
    expect(normalWideCells).toHaveLength(5)
    expect(
      normalWideRow.querySelector('[data-id="hilos-table-row-message"]'),
    ).toBeNull()
    expect(normalWideCells[0]?.textContent?.trim()).toBe('default')
    expect(normalWideCells[1]?.textContent?.trim()).toBe(
      'Maintenance in progress',
    )
    expect(normalWideCells[2]?.textContent?.trim()).toBe('Briefly unavailable')
    expect(normalWideCells[3]?.textContent?.trim()).toBe(
      'Verification in progress',
    )
    expect(normalWideCells[0]?.getAttribute('colspan')).toBeNull()
    expect(normalWideCells[1]?.getAttribute('colspan')).toBeNull()
    expect(normalWideCells[2]?.getAttribute('colspan')).toBeNull()
    expect(normalWideCells[3]?.getAttribute('colspan')).toBeNull()

    const messageWideRow = query(
      fixture,
      '[data-id="hilos-table-row-restore"]',
    ) as HTMLElement
    const messageWideCells = messageWideRow.querySelectorAll('td')
    expect(messageWideCells).toHaveLength(3)
    expect(messageWideCells[0]?.textContent?.trim()).toBe('restore')
    expect(messageWideCells[0]?.getAttribute('colspan')).toBeNull()

    const wideMessageCell = messageWideCells[1]!
    expect(wideMessageCell.getAttribute('colspan')).toBe('3')
    expect(wideMessageCell.classList.contains('text-body-secondary')).toBe(true)
    expect(wideMessageCell.classList.contains('fst-italic')).toBe(true)
    expect(wideMessageCell.getAttribute('data-id')).toBe(
      'hilos-table-row-message',
    )
    expect(wideMessageCell.textContent?.trim()).toBe(
      'No own entry — uses default',
    )

    const normalCard = query(
      fixture,
      '[data-id="hilos-table-card-default"]',
    ) as HTMLElement
    expect(normalCard.querySelector('.op')?.textContent?.trim()).toBe('default')
    expect(
      Array.from(normalCard.querySelectorAll('dt')).map((dt) =>
        dt.textContent?.trim(),
      ),
    ).toEqual(['Title', 'Phrase', 'Bar'])
    expect(normalCard.querySelectorAll('dd')).toHaveLength(3)
    expect(
      normalCard.querySelector('[data-id="hilos-table-row-message"]'),
    ).toBeNull()

    const messageCard = query(
      fixture,
      '[data-id="hilos-table-card-restore"]',
    ) as HTMLElement
    expect(messageCard.querySelector('.op')?.textContent?.trim()).toBe(
      'restore',
    )
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
    expect(cardMessage.textContent?.trim()).toBe('No own entry — uses default')

    controller.dispose()
  })
})

describe('HilosViewportTable with a selection column', () => {
  /**
   * The sender of the declared operation, which this file never presses: the column
   * is what it is about, and the panel that presses is tested next door.
   */
  function neverRun(): ActionHandle<HilosTableBulkAccepted> {
    throw new Error('the declaration is only read here')
  }

  // The table of a page that declared one bulk operation, and therefore has a
  // checkbox selection column in addition to the permanent state column.
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

  function mountWithEdge(
    controller: TableViewportController<Row>,
    edge?: HilosTableSelectionEdge,
  ): ComponentFixture<ViewportTableHost> {
    if (edge !== undefined) {
      TestBed.configureTestingModule({
        providers: [{ provide: HILOS_TABLE_SELECTION_EDGE, useValue: edge }],
      })
    }

    return mountDeclared(controller)
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

  function input(
    fixture: ComponentFixture<unknown>,
    id: string,
  ): HTMLInputElement | null {
    return query(fixture, `[data-id="${id}"]`) as HTMLInputElement | null
  }

  function all(
    fixture: ComponentFixture<unknown>,
    selector: string,
  ): Element[] {
    return Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll(selector),
    )
  }

  it('draws no checkbox column for a table that declared no bulk operations', () => {
    const controller = makeController(PLAIN_FRAME)
    window(controller)
    const fixture = mountWithEdge(controller)

    expect(input(fixture, 'hilos-table-select-page')).toBeNull()
    expect(input(fixture, 'hilos-table-select-a')).toBeNull()
    expect(all(fixture, 'thead th')).toHaveLength(COLUMNS.length + 1)
  })

  it('puts the column first by default and last where the app asked for the end', () => {
    const controller = makeController(BULK_FRAME)
    window(controller)

    const left = mountWithEdge(controller)
    expect(
      all(left, 'thead th')[0]?.classList.contains(
        'hilos-table-selection-cell',
      ),
    ).toBe(true)
    const leftCells = all(left, '[data-id="hilos-table-row-a"] td')
    expect(leftCells[0]?.querySelector('input')?.dataset['id']).toBe(
      'hilos-table-select-a',
    )

    TestBed.resetTestingModule()
    const right = mountWithEdge(controller, 'end')
    const headers = all(right, 'thead th')
    expect(
      headers[headers.length - 1]?.classList.contains(
        'hilos-table-selection-cell',
      ),
    ).toBe(true)
    const rightCells = all(right, '[data-id="hilos-table-row-a"] td')
    expect(
      rightCells[rightCells.length - 1]?.querySelector('input')?.dataset['id'],
    ).toBe('hilos-table-select-a')
  })

  it('tells the core the state each checkbox is now in', () => {
    const controller = makeController(BULK_FRAME)
    window(controller)
    const fixture = mountWithEdge(controller)
    const press = (id: string) => {
      input(fixture, id)?.click()
      fixture.detectChanges()
    }

    press('hilos-table-select-a')
    expect(controller.selection.count.get()).toBe(1)
    press('hilos-table-select-a')
    expect(controller.selection.count.get()).toBe(0)

    press('hilos-table-select-page')
    expect(controller.selection.count.get()).toBe(2)
    press('hilos-table-select-page')
    expect(controller.selection.count.get()).toBe(0)
  })

  it('shows the header checkbox empty, half-marked and full', () => {
    const controller = makeController(BULK_FRAME)
    window(controller)
    const fixture = mountWithEdge(controller)
    const header = () =>
      input(fixture, 'hilos-table-select-page') as HTMLInputElement

    expect(header().checked).toBe(false)
    expect(header().indeterminate).toBe(false)

    controller.selectRow('a', true)
    fixture.detectChanges()
    expect(header().checked).toBe(false)
    expect(header().indeterminate).toBe(true)

    controller.selectRow('b', true)
    fixture.detectChanges()
    expect(header().checked).toBe(true)
    expect(header().indeterminate).toBe(false)
  })

  it('gives a row shown as a placeholder no checkbox', () => {
    const controller = makeController(BULK_FRAME)
    window(controller)
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()
    const fixture = mountWithEdge(controller)

    expect(query(fixture, '[data-id="hilos-table-placeholder"]')).not.toBeNull()
    expect(input(fixture, 'hilos-table-select-a')).toBeNull()
    expect(input(fixture, 'hilos-table-select-b')).not.toBeNull()
  })

  it('counts the checkbox column into every cell that spans the row', () => {
    const controller = makeController(BULK_FRAME)
    window(controller)
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()
    const fixture = mountWithEdge(controller)

    expect(
      query(fixture, '[data-id="hilos-table-placeholder"]')?.getAttribute(
        'colspan',
      ),
    ).toBe(String(COLUMNS.length + 2))

    const empty = makeController(BULK_FRAME)
    empty.ingestWindow([], 0, true, null, null, 10)
    const emptyTable = mountWithEdge(empty)
    expect(query(emptyTable, 'tbody td')?.getAttribute('colspan')).toBe(
      String(COLUMNS.length + 2),
    )
  })

  it.each(['start', 'end'] as const)(
    'keeps the row-progress tracks aligned with selection at the %s edge',
    (edge) => {
      const controller = makeController({
        title: 'People',
        columns: MARKED_COLUMNS,
        bulkActions: BULK_FRAME.bulkActions,
      })
      window(controller)
      controller.ingestProgress({
        scope: 'row',
        progressKey: 'archive-a',
        rowKey: 'a',
        current: 1,
        total: 4,
      })
      const fixture = mountWithEdge(controller, edge)
      const cells = Array.from(
        (fixture.nativeElement as HTMLElement).querySelectorAll(
          '[data-id="hilos-table-progress-row-a"] td',
        ),
      )

      expect(
        (fixture.nativeElement as HTMLElement).querySelectorAll('colgroup col'),
      ).toHaveLength(6)
      expect(cells.map((cell) => cell.getAttribute('colspan'))).toEqual(
        edge === 'start'
          ? ['1', '1', '2', '1', '1']
          : ['1', '2', '1', '1', '1'],
      )
      expect(
        cells.reduce(
          (total, cell) => total + Number(cell.getAttribute('colspan')),
          0,
        ),
      ).toBe(6)
      fixture.destroy()
      controller.dispose()
    },
  )
})

describe('HilosViewportTable drawing work in progress', () => {
  it('hands the whole bar, detail and all, to the places beside the track', () => {
    const controller = new TableViewportController<Row>({
      resolve: (raw) => ({ name: String(raw.slots['name']) }),
      sendViewport: () => undefined,
      frame: { columns: COLUMNS },
    })
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
    const fixture = TestBed.createComponent(ProgressTableHost)
    fixture.componentInstance.controller = controller
    fixture.detectChanges()

    const line = query(fixture, '[data-id="hilos-table-progress"]')
    expect(
      line
        ?.querySelector('[data-id="page-progress-title"]')
        ?.textContent?.replace(/\s+/g, ' ')
        .trim(),
    ).toBe('Nightly check — 34 of 120')
    expect(
      line
        ?.querySelector('[data-id="page-progress-stop"]')
        ?.textContent?.trim(),
    ).toBe('Stop nightly')
  })
})

describe('HilosViewportTable drawing work over one row', () => {
  function plainController(
    columns: HilosTableColumn[] = COLUMNS,
  ): TableViewportController<Row> {
    const controller = new TableViewportController<Row>({
      resolve: (raw) => ({ name: String(raw.slots['name']) }),
      sendViewport: () => undefined,
      frame: { columns },
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

    return controller
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

  function mount<H extends { controller: TableViewportController<Row> }>(
    host: new () => H,
    controller: TableViewportController<Row>,
  ): ComponentFixture<H> {
    const fixture = TestBed.createComponent(host)
    fixture.componentInstance.controller = controller
    fixture.detectChanges()

    return fixture
  }

  function barCells(fixture: ComponentFixture<unknown>): HTMLElement[] {
    return Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll<HTMLElement>(
        '[data-id="hilos-table-progress-row-a"] td',
      ),
    )
  }

  it('draws the bar in a row of its own right after the row it belongs to', () => {
    const controller = plainController()
    rowBar(controller, 'a')
    const fixture = mount(PlainTableHost, controller)

    const ids = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll('tbody tr'),
    ).map((node) => node.getAttribute('data-id'))
    expect(ids).toEqual([
      'hilos-table-row-a',
      'hilos-table-progress-row-a',
      'hilos-table-row-b',
    ])
  })

  it('stretches the bar under the marked columns and leaves the rest empty', () => {
    const controller = plainController(MARKED_COLUMNS)
    rowBar(controller, 'a')
    const fixture = mount(MarkedTableHost, controller)

    const cells = barCells(fixture)
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
    const controller = plainController()
    rowBar(controller, 'a')
    const fixture = mount(PlainTableHost, controller)

    const cells = barCells(fixture)
    expect(cells).toHaveLength(2)
    expect(cells[0]?.getAttribute('colspan')).toBe('1')
    expect(cells[1]?.getAttribute('colspan')).toBe('1')
    expect(cells[0]?.querySelector('[role="progressbar"]')).not.toBeNull()
  })

  it('adds the waiting cell to the bar row exactly as the header does', () => {
    const controller = plainController(MARKED_COLUMNS)
    rowBar(controller, 'a')
    const fixture = mount(MarkedTableHost, controller)
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'b',
      reason: 'deleted',
    })
    fixture.detectChanges()

    const spans = barCells(fixture).map((cell) =>
      Number(cell.getAttribute('colspan')),
    )
    // The state column remains represented in the bar row, which has the same
    // total span as the header either side of a pending change.
    expect(spans.reduce((total, span) => total + span, 0)).toBe(
      (fixture.nativeElement as HTMLElement).querySelectorAll('thead th')
        .length,
    )
    expect(spans).toEqual([1, 2, 1, 1])
  })

  it('hands the whole bar to the row-progress place', () => {
    const controller = plainController()
    rowBar(controller, 'a')
    const fixture = mount(ProgressTableHost, controller)

    expect(
      query(fixture, '[data-id="page-row-note-a"]')?.textContent?.trim(),
    ).toBe('packed 3.4 GB of 11 GB')
  })

  it('leaves no caption line under a row where the page filled no place', () => {
    const controller = plainController()
    rowBar(controller, 'a')
    const fixture = mount(PlainTableHost, controller)

    const row = query(
      fixture,
      '[data-id="hilos-table-progress-row-a"]',
    ) as HTMLElement
    expect(row.querySelectorAll('div.small')).toHaveLength(0)
    expect(row.querySelector('[role="progressbar"]')).not.toBeNull()
  })

  it('draws no bar for a key outside the current window', () => {
    const controller = plainController()
    rowBar(controller, 'z')
    const fixture = mount(PlainTableHost, controller)

    expect(
      (fixture.nativeElement as HTMLElement).querySelectorAll(
        '[data-id^="hilos-table-progress-row-"]',
      ),
    ).toHaveLength(0)
  })

  it('draws no bar under a row shown as a placeholder', () => {
    const controller = plainController()
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
    const fixture = mount(PlainTableHost, controller)

    expect(query(fixture, '[data-id="hilos-table-placeholder"]')).not.toBeNull()
    expect(query(fixture, '[data-id="hilos-table-progress-row-a"]')).toBeNull()
  })

  it('draws no row under any row for a bar over the whole table', () => {
    const controller = plainController()
    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 34,
      total: 120,
    })
    const fixture = mount(PlainTableHost, controller)

    expect(
      (fixture.nativeElement as HTMLElement).querySelectorAll(
        '[data-id^="hilos-table-progress-row-"]',
      ),
    ).toHaveLength(0)
  })
})

// A table with one field that did not fit a column of its own: the row shows the
// name, and the reason waits in the panel under it.
const DETAIL_COLUMNS: HilosTableColumn[] = [
  { key: 'name', label: 'Name', sortable: true },
  { key: 'lastError', label: 'Error', detail: true },
]

/**
 * A host drawing that table; `detail` picks what the page marks for
 * the field — the reason, the row and its key, or nothing at all.
 */
@Component({
  selector: 'test-detail-table-host',
  imports: [HilosTableCell, HilosTableDetail, HilosViewportTable],
  template: `
    <hilos-viewport-table [controller]="controller">
      <ng-template hilosTableCell="name" let-row>
        <span class="cell">{{ row.name }}</span>
      </ng-template>
      @if (detail === 'reason') {
        <ng-template hilosTableDetail="lastError">
          <span class="reason">Mailbox full</span>
        </ng-template>
      } @else if (detail === 'keyed') {
        <ng-template hilosTableDetail="lastError" let-row let-rowKey="rowKey">
          <span class="reason">{{ row.name }} {{ rowKey }}</span>
        </ng-template>
      }
    </hilos-viewport-table>
  `,
})
class DetailTableHost {
  controller!: TableViewportController<Row>
  detail: 'reason' | 'keyed' | 'none' = 'reason'
}

// The same table as a declared frame with a head, a line and controls: on a narrow
// screen the field is what the card opens into.
const CARD_DETAIL_FRAME: HilosTableFrame = {
  title: 'Backups',
  columns: [
    { key: 'name', label: 'Name' },
    { key: 'kind', label: 'Kind' },
    { key: 'lastError', label: 'Error', detail: true },
    { key: 'actions', label: '' },
  ],
}

/** A host filling every place of such a card; `withDetail` off marks no field. */
@Component({
  selector: 'test-detail-cards-host',
  imports: [HilosTableCell, HilosTableDetail, HilosViewportTable],
  template: `
    <hilos-viewport-table [controller]="controller">
      <ng-template hilosTableCell="name">
        <span class="named">Alice</span>
      </ng-template>
      <ng-template hilosTableCell="kind">
        <span class="kind">full</span>
      </ng-template>
      <ng-template hilosTableCell="actions">
        <button type="button" class="restore">Restore</button>
      </ng-template>
      @if (withDetail) {
        <ng-template hilosTableDetail="lastError">
          <span class="reason">Mailbox full</span>
        </ng-template>
      }
    </hilos-viewport-table>
  `,
})
class DetailCardsHost {
  controller!: TableViewportController<Row>
  withDetail = true
}

function ingestTwo(controller: TableViewportController<Row>): void {
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

function press(fixture: ComponentFixture<unknown>, selector: string): void {
  query(fixture, selector)?.click()
  fixture.detectChanges()
}

describe('HilosViewportTable expanding a card', () => {
  function mountDetailCards(
    controller: TableViewportController<Row>,
    withDetail = true,
  ): ComponentFixture<DetailCardsHost> {
    const fixture = TestBed.createComponent(DetailCardsHost)
    fixture.componentInstance.controller = controller
    fixture.componentInstance.withDetail = withDetail
    fixture.detectChanges()

    return fixture
  }

  const CARD_A = '[data-id="hilos-table-card-a"]'
  const CARD_A_CONTROL = `${CARD_A} [data-id="hilos-table-expand-a"]`
  const CARD_A_PANEL = `${CARD_A} [data-id="hilos-table-row-detail-a"]`

  it('opens one card from the control in its head and closes it again', () => {
    const controller = makeController(CARD_DETAIL_FRAME)
    ingestTwo(controller)
    const fixture = mountDetailCards(controller)
    const control = query(fixture, CARD_A_CONTROL)

    expect(control?.getAttribute('aria-expanded')).toBe('false')
    expect(control?.textContent?.trim()).toBe('Show details')
    expect(query(fixture, CARD_A_PANEL)).toBeNull()

    press(fixture, CARD_A_CONTROL)

    const panel = query(fixture, CARD_A_PANEL)
    expect(panel?.textContent).toContain('Error')
    expect(panel?.querySelector('.reason')?.textContent).toBe('Mailbox full')
    expect(
      query(
        fixture,
        '[data-id="hilos-table-card-b"] [data-id="hilos-table-row-detail-b"]',
      ),
    ).toBeNull()

    press(fixture, CARD_A_CONTROL)

    expect(query(fixture, CARD_A_PANEL)).toBeNull()
  })

  it('stands the panel after the fields and before the controls', () => {
    const controller = makeController(CARD_DETAIL_FRAME)
    ingestTwo(controller)
    const fixture = mountDetailCards(controller)
    press(fixture, CARD_A_CONTROL)

    const blocks = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll(
        `${CARD_A} .card-body > *`,
      ),
    )
    expect(blocks[1]?.tagName).toBe('DL')
    expect(blocks[2]?.getAttribute('data-id')).toBe('hilos-table-row-detail-a')
    expect(blocks[3]?.classList.contains('hilos-button-row')).toBe(true)
  })

  it('gives the card panel an id of its own, apart from the row panel', () => {
    const controller = makeController(CARD_DETAIL_FRAME)
    ingestTwo(controller)
    const fixture = mountDetailCards(controller)
    press(fixture, CARD_A_CONTROL)

    const rowPanel = query(fixture, 'tr[data-id="hilos-table-row-detail-a"]')
    const cardControl = query(fixture, CARD_A_CONTROL)
    const cardPanel = query(fixture, CARD_A_PANEL)

    expect(cardPanel?.id).toBeTruthy()
    expect(cardPanel?.id).not.toBe(rowPanel?.id)
    expect(cardControl?.getAttribute('aria-controls')).toBe(cardPanel?.id)
    expect(cardControl?.getAttribute('aria-expanded')).toBe('true')
  })

  it('offers no control on a card of a table that declared no such field', () => {
    const controller = makeController({
      title: 'Backups',
      columns: [
        { key: 'name', label: 'Name' },
        { key: 'actions', label: '' },
      ],
    })
    ingestTwo(controller)
    const fixture = mountDetailCards(controller, false)

    expect(query(fixture, CARD_A_CONTROL)).toBeNull()
  })

  it('stands a dash in a card field the page drew nothing into', () => {
    const controller = makeController(CARD_DETAIL_FRAME)
    ingestTwo(controller)
    const fixture = mountDetailCards(controller, false)
    press(fixture, CARD_A_CONTROL)

    expect(query(fixture, `${CARD_A_PANEL} dd`)?.textContent?.trim()).toBe('—')
  })
})

describe('HilosViewportTable expanding a row', () => {
  function mountDetailTable(
    controller: TableViewportController<Row>,
    detail: DetailTableHost['detail'] = 'reason',
  ): ComponentFixture<DetailTableHost> {
    const fixture = TestBed.createComponent(DetailTableHost)
    fixture.componentInstance.controller = controller
    fixture.componentInstance.detail = detail
    fixture.detectChanges()

    return fixture
  }

  function detailController(): TableViewportController<Row> {
    const controller = new TableViewportController<Row>({
      resolve: (raw) => ({ name: String(raw.slots['name']) }),
      sendViewport: () => undefined,
      frame: { search: {}, columns: DETAIL_COLUMNS },
    })
    ingestTwo(controller)

    return controller
  }

  it('keeps a detail column out of the header and out of the width of a row', () => {
    const fixture = mountDetailTable(detailController())

    // One declared column left standing, plus the row-state cell the control lives in.
    expect(
      (fixture.nativeElement as HTMLElement).querySelectorAll('thead th'),
    ).toHaveLength(2)
    expect(query(fixture, 'thead')?.textContent).not.toContain('Error')
    expect(query(fixture, '[data-id="hilos-table-sort-lastError"]')).toBeNull()
  })

  it('offers the control only where a detail field was declared', () => {
    const controller = detailController()
    const plainController = new TableViewportController<Row>({
      resolve: (raw) => ({ name: String(raw.slots['name']) }),
      sendViewport: () => undefined,
      frame: { columns: COLUMNS },
    })
    ingestTwo(plainController)
    const plain = TestBed.createComponent(PlainTableHost)
    plain.componentInstance.controller = plainController
    plain.detectChanges()

    expect(
      query(mountDetailTable(controller), '[data-id="hilos-table-expand-a"]'),
    ).not.toBeNull()
    expect(query(plain, '[data-id="hilos-table-expand-a"]')).toBeNull()
  })

  it('opens the panel of one row and closes it again from the same control', () => {
    const fixture = mountDetailTable(detailController())
    const control = query(fixture, '[data-id="hilos-table-expand-a"]')

    expect(control?.getAttribute('aria-expanded')).toBe('false')
    expect(control?.textContent?.trim()).toBe('Show details')
    expect(query(fixture, '[data-id="hilos-table-row-detail-a"]')).toBeNull()

    press(fixture, '[data-id="hilos-table-expand-a"]')

    const panel = query(fixture, '[data-id="hilos-table-row-detail-a"]')
    expect(panel).not.toBeNull()
    expect(panel?.textContent).toContain('Error')
    expect(panel?.querySelector('.reason')?.textContent).toBe('Mailbox full')
    expect(
      query(fixture, '[data-id="hilos-table-expand-a"]')?.textContent?.trim(),
    ).toBe('Hide details')
    expect(query(fixture, '[data-id="hilos-table-row-detail-b"]')).toBeNull()

    press(fixture, '[data-id="hilos-table-expand-a"]')

    expect(query(fixture, '[data-id="hilos-table-row-detail-a"]')).toBeNull()
  })

  it('points the control at its own panel and hides the chevron from the reader', () => {
    const fixture = mountDetailTable(detailController())
    press(fixture, '[data-id="hilos-table-expand-a"]')

    const control = query(fixture, '[data-id="hilos-table-expand-a"]')
    const panel = query(fixture, '[data-id="hilos-table-row-detail-a"]')
    expect(control?.getAttribute('aria-expanded')).toBe('true')
    expect(control?.getAttribute('aria-controls')).toBe(panel?.id)
    expect(panel?.id).toBeTruthy()
    expect(control?.querySelector('i')?.getAttribute('aria-hidden')).toBe(
      'true',
    )
    expect(
      control?.querySelector('i')?.classList.contains('bi-chevron-up'),
    ).toBe(true)
  })

  it('spans the panel across the whole row, tinted as the row it hangs under', () => {
    const fixture = mountDetailTable(detailController())
    press(fixture, '[data-id="hilos-table-expand-a"]')

    const panel = query(fixture, '[data-id="hilos-table-row-detail-a"]')
    expect(panel?.classList.contains('table-active')).toBe(true)
    expect(panel?.querySelector('td')?.getAttribute('colspan')).toBe('2')
    expect(
      query(fixture, '[data-id="hilos-table-row-a"]')?.classList.contains(
        'table-active',
      ),
    ).toBe(true)
  })

  it('stands a dash in a field the page declared but drew nothing into', () => {
    const fixture = mountDetailTable(detailController(), 'none')
    press(fixture, '[data-id="hilos-table-expand-a"]')

    expect(
      query(
        fixture,
        '[data-id="hilos-table-row-detail-a"] dd',
      )?.textContent?.trim(),
    ).toBe('—')
  })

  it('closes every panel when the window changes', () => {
    const fixture = mountDetailTable(detailController())
    press(fixture, '[data-id="hilos-table-expand-a"]')
    press(fixture, '[data-id="hilos-table-expand-b"]')

    const search = query(
      fixture,
      '[data-id="hilos-table-search"]',
    ) as HTMLInputElement
    search.value = 'failed'
    search.dispatchEvent(new Event('input'))
    fixture.detectChanges()

    expect(query(fixture, '[data-id="hilos-table-row-detail-a"]')).toBeNull()
    expect(query(fixture, '[data-id="hilos-table-row-detail-b"]')).toBeNull()
  })

  it('hands a detail field the row and its key, as a cell is handed them', () => {
    const fixture = mountDetailTable(detailController(), 'keyed')
    press(fixture, '[data-id="hilos-table-expand-b"]')

    expect(
      query(
        fixture,
        '[data-id="hilos-table-row-detail-b"] .reason',
      )?.textContent?.trim(),
    ).toBe('Bob b')
  })
})

describe('HilosViewportTable drawing the states of the body', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  const STATE_FRAME: HilosTableFrame = {
    title: 'Backups',
    search: {},
    columns: STATE_COLUMNS,
    empty: { title: 'Nothing here yet' },
  }

  function makeStates(frame: HilosTableFrame = STATE_FRAME): {
    controller: TableViewportController<Row>
    sent: TableViewportDescriptor[]
  } {
    const sent: TableViewportDescriptor[] = []
    const controller = new TableViewportController<Row>({
      resolve: (raw) => ({ name: String(raw.slots['name']) }),
      sendViewport: (descriptor) => sent.push(descriptor),
      frame,
    })

    return { controller, sent }
  }

  function mountStates(
    controller: TableViewportController<Row>,
  ): ComponentFixture<StatesTableHost> {
    const fixture = TestBed.createComponent(StatesTableHost)
    fixture.componentInstance.controller = controller
    fixture.detectChanges()

    return fixture
  }

  function all(
    fixture: ComponentFixture<unknown>,
    selector: string,
  ): Element[] {
    return Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll(selector),
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
    const { controller } = makeStates(STATE_FRAME)
    twoRows(controller)
    const fixture = mountStates(controller)

    controller.setSort('name')
    vi.advanceTimersByTime(399)
    fixture.detectChanges()
    expect(all(fixture, '[data-id="hilos-table-loading"]')).toHaveLength(0)
    expect(all(fixture, '.named')).toHaveLength(4)

    vi.advanceTimersByTime(1)
    fixture.detectChanges()

    // Both branches say it, each in its own shape: rows of cells in the table,
    // one bar per card in the list.
    const loading = all(fixture, '[data-id="hilos-table-loading"]')
    expect(loading).toHaveLength(2)
    expect(loading[0]?.getAttribute('aria-busy')).toBe('true')
    const tableRows = all(
      fixture,
      'tbody[data-id="hilos-table-loading"] [data-id="hilos-table-skeleton-row"]',
    )
    expect(tableRows).toHaveLength(2)
    // A cell for every column standing in the row, so the widths hold.
    expect(tableRows[0]?.querySelectorAll('td')).toHaveLength(3)
    expect(
      all(
        fixture,
        '[data-id="hilos-table-cards"] [data-id="hilos-table-skeleton-row"]',
      ),
    ).toHaveLength(2)
    expect(all(fixture, '.placeholder[aria-hidden="true"]')).toHaveLength(6)
    expect(
      all(fixture, '[role="status"]').filter(
        (node) => node.textContent?.trim() === 'Loading…',
      ),
    ).toHaveLength(2)
    expect(all(fixture, '.named')).toHaveLength(0)
  })

  it('counts the skeleton by the window size when the window before was empty', () => {
    vi.useFakeTimers()
    const { controller } = makeStates(STATE_FRAME)
    controller.ingestWindow([], 0, true, null, null, 3)
    const fixture = mountStates(controller)

    controller.setSearch('x')
    vi.advanceTimersByTime(400)
    fixture.detectChanges()

    expect(
      all(
        fixture,
        'tbody[data-id="hilos-table-loading"] [data-id="hilos-table-skeleton-row"]',
      ),
    ).toHaveLength(3)
  })

  it('says nothing was found in both branches and resets out of it', () => {
    const { controller, sent } = makeStates(STATE_FRAME)
    twoRows(controller)
    const fixture = mountStates(controller)

    controller.setSearch('night')
    controller.ingestWindow([], 0, true, null, null, 25)
    fixture.detectChanges()

    expect(all(fixture, '[data-id="hilos-table-no-matches"]')).toHaveLength(2)
    expect(
      all(
        fixture,
        '[data-id="hilos-table-no-matches-terms"]',
      )[1]?.textContent?.trim(),
    ).toBe('No rows match “night”')
    expect(all(fixture, '[data-id="hilos-table-empty"]')).toHaveLength(0)
    ;(
      all(fixture, '[data-id="hilos-table-no-matches-reset"]')[1] as HTMLElement
    ).click()
    expect(sent.at(-1)?.filter).toEqual({})
  })

  it('draws the declared empty state in both branches when nothing filters the set', () => {
    const { controller } = makeStates(STATE_FRAME)
    const fixture = mountStates(controller)

    controller.ingestWindow([], 0, true, null, null, 25)
    fixture.detectChanges()

    expect(all(fixture, '[data-id="hilos-table-empty-title"]')).toHaveLength(2)
    expect(all(fixture, '[data-id="hilos-table-no-matches"]')).toHaveLength(0)
  })

  it('draws List unavailable in both branches when the window was refused, with no footer', () => {
    const { controller } = makeStates(STATE_FRAME)
    twoRows(controller)
    const fixture = mountStates(controller)

    controller.ingestRefusal('internal_error')
    fixture.detectChanges()

    expect(all(fixture, '[data-id="hilos-table-unavailable"]')).toHaveLength(2)
    expect(
      all(fixture, '[data-id="hilos-table-unavailable"]')[0]?.getAttribute(
        'role',
      ),
    ).toBe('status')
    expect(
      all(
        fixture,
        '[data-id="hilos-table-unavailable-title"]',
      )[0]?.textContent?.trim(),
    ).toBe('List unavailable')
    expect(
      all(
        fixture,
        '[data-id="hilos-table-unavailable-hint"]',
      )[0]?.textContent?.trim(),
    ).toBe(
      'The rows of this list could not be fetched. The rest of the page still works.',
    )
    expect(all(fixture, '[data-id="hilos-table-count"]')).toHaveLength(0)
  })
})

describe('HilosViewportTable rows with nothing to expand and wide fields', () => {
  // A names table in miniature: Bob has no corrections, so his row has nothing to open,
  // and the corrections are a list that takes the panel's whole width.
  const WIDE_FRAME: HilosTableFrame = {
    title: 'Names',
    columns: [
      { key: 'name', label: 'Name' },
      { key: 'kind', label: 'Kind' },
      { key: 'lastError', label: 'Error', detail: true },
      {
        key: 'corrections',
        label: 'Locale corrections',
        detail: true,
        detailWide: true,
      },
      { key: 'actions', label: '' },
    ],
  }

  function mountWide(): {
    controller: TableViewportController<Row>
    fixture: ComponentFixture<DetailCardsHost>
  } {
    const controller = new TableViewportController<Row>({
      resolve: (raw) => ({ name: String(raw.slots['name']) }),
      sendViewport: () => undefined,
      frame: WIDE_FRAME,
      expandable: (row) => row.name !== 'Bob',
    })
    ingestTwo(controller)
    const fixture = TestBed.createComponent(DetailCardsHost)
    fixture.componentInstance.controller = controller
    fixture.detectChanges()

    return { controller, fixture }
  }

  function all(
    fixture: ComponentFixture<unknown>,
    selector: string,
  ): HTMLElement[] {
    return [
      ...(fixture.nativeElement as HTMLElement).querySelectorAll<HTMLElement>(
        selector,
      ),
    ]
  }

  it('draws no control, in the row or in the card, on a row with nothing to open', () => {
    const { fixture } = mountWide()

    expect(all(fixture, '[data-id="hilos-table-expand-a"]')).toHaveLength(2)
    expect(query(fixture, '[data-id="hilos-table-expand-b"]')).toBeNull()
  })

  it('gives a wide field the whole width of the panel and of the card', () => {
    const { fixture } = mountWide()

    press(fixture, 'tr [data-id="hilos-table-expand-a"]')

    const fields = all(
      fixture,
      'tr[data-id="hilos-table-row-detail-a"] dl > div',
    )
    expect(fields).toHaveLength(2)
    expect(fields[0]?.classList.contains('col-md-12')).toBe(false)
    expect(fields[1]?.classList.contains('col-md-12')).toBe(true)

    const card =
      '[data-id="hilos-table-card-a"] [data-id="hilos-table-row-detail-a"]'
    const labels = all(fixture, `${card} dt`)
    expect(labels[0]?.classList.contains('col-5')).toBe(true)
    expect(labels[1]?.classList.contains('col-12')).toBe(true)
    expect(all(fixture, `${card} dd`)[1]?.classList.contains('col-12')).toBe(
      true,
    )
  })

  it('closes the panel of a row that lost what it had to show', () => {
    const { controller, fixture } = mountWide()
    controller.expandRow('a', true)
    fixture.detectChanges()

    expect(
      query(fixture, 'tr[data-id="hilos-table-row-detail-a"]'),
    ).not.toBeNull()

    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'Bob' } },
    })
    fixture.detectChanges()

    expect(query(fixture, '[data-id="hilos-table-row-detail-a"]')).toBeNull()
    expect(query(fixture, '[data-id="hilos-table-expand-a"]')).toBeNull()
  })
})

@Component({
  imports: [HilosTableCell, HilosTableDetail, HilosViewportTable],
  template: `
    @if (controller(); as current) {
      <hilos-viewport-table [controller]="current">
        <ng-template hilosTableCell="name" let-row
          ><span class="named">{{ row.name }}</span></ng-template
        >
        <ng-template hilosTableCell="kind"
          ><span class="kind">full</span></ng-template
        >
        <ng-template hilosTableCell="actions"
          ><button type="button" class="restore">Restore</button></ng-template
        >
        <ng-template hilosTableDetail="lastError" let-row
          ><span class="reason">{{ row.name }} detail</span></ng-template
        >
        <ng-template #rowProgress>Private work payload</ng-template>
      </hilos-viewport-table>
    }
  `,
})
class GeometryTableHost {
  readonly controller = signal<TableViewportController<Row> | null>(null)
}

/** The real table and modal share only the opening intent and modal model. */
@Component({
  imports: [HilosTableCell, HilosTableDetail, HilosViewportTable, HilosModal],
  template: `
    @if (controller(); as current) {
      <hilos-viewport-table [controller]="current">
        <ng-template hilosTableCell="name" let-row>
          @if (part() === 'main') {
            <fieldset>
              <button
                type="button"
                data-id="open-person"
                data-hilos-focus-return="person-open"
                (click)="open.set(true)"
              >
                Inspect {{ row.name }}
              </button>
            </fieldset>
          }
        </ng-template>
        <ng-template #rowProgress>
          @if (part() === 'work') {
            <button
              type="button"
              data-id="open-person"
              data-hilos-focus-return="person-open"
              (click)="open.set(true)"
            >
              Inspect work
            </button>
          }
        </ng-template>
        <ng-template hilosTableDetail="lastError">
          @if (part() === 'detail') {
            <button
              type="button"
              data-id="open-person"
              data-hilos-focus-return="person-open"
              (click)="open.set(true)"
            >
              Inspect detail
            </button>
          }
        </ng-template>
      </hilos-viewport-table>
    }
    <hilos-modal [(open)]="open" title="Inspect person" initialFocus="dialog">
      <ng-template #modalActions let-requestClose="requestClose">
        <button type="button" data-id="detail-close" (click)="requestClose()">
          Close
        </button>
      </ng-template>
    </hilos-modal>
    <button type="button" data-id="unrelated">Other control</button>
  `,
})
class ModalGeometryTableHost extends GeometryTableHost {
  readonly open = signal(false)
  readonly part = signal<'main' | 'work' | 'detail'>('main')
}

// The real Angular render/capture seam, with numeric rectangles. Native CSS and
// the installed Sass/FESM delivery are proved by the separate browser fixture.
describe('HilosViewportTable wired geometry', () => {
  const releases: (() => void)[] = []
  const modalRoots: HTMLElement[] = []
  afterEach(() => {
    releases.splice(0).forEach((release) => release())
    modalRoots.length = 0
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
  })

  function observeGeometry(controller: TableViewportController<Row>) {
    const source = controller.geometry
    const captures = vi.fn<(capture: TableGeometryCapture) => void>()
    const register = vi.fn((capture: Parameters<typeof source.register>[0]) =>
      source.register((request) => {
        const result = capture(request)
        captures(result)
        return result
      }),
    )
    Object.defineProperty(controller, 'geometry', { value: { register } })
    return { register, captures }
  }

  function geometryFixture(
    host = GeometryTableHost,
    frame = CARD_DETAIL_FRAME,
  ) {
    const measured: string[] = []
    let detailHeight = 60.75
    let mainHeight = 40.25
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(
      function (this: HTMLElement) {
        const id = this.getAttribute('data-id') ?? ''
        measured.push(id)
        if (
          this.matches(
            '.hilos-table-reason-twins, .hilos-table-record-space > span',
          )
        )
          return new DOMRect(0, 0, 200, 22)
        if (this.matches('.hilos-table-replacement-row'))
          return new DOMRect(0, 0, 200, 23)
        if (this.tagName === 'TH') {
          const index = (this as HTMLTableCellElement).cellIndex
          return new DOMRect(index * 100.25, 0, 100.25, 20)
        }
        if (id.startsWith('hilos-table-row-detail-'))
          return new DOMRect(0, 78.75, 200.5, detailHeight)
        if (id.startsWith('hilos-table-progress-row-'))
          return new DOMRect(0, 60.25, 200.5, 18.5)
        if (id.startsWith('hilos-table-row-'))
          return new DOMRect(0, 20, 200.5, mainHeight)
        if (id.startsWith('hilos-table-card-'))
          return new DOMRect(0, 200, 200.5, 210.5)
        return new DOMRect(0, 0, 200.5, 500)
      },
    )
    const resolve = vi.fn(
      (raw: { slots: Readonly<Record<string, unknown>> }) => ({
        name: String(raw.slots['name']),
      }),
    )
    const controller = new TableViewportController<Row>({
      resolve,
      sendViewport: () => {},
      frame,
    })
    ingestTwo(controller)
    const { register, captures } = observeGeometry(controller)
    const fixture = TestBed.createComponent(host)
    fixture.componentInstance.controller.set(controller)
    fixture.detectChanges()
    TestBed.tick()
    return {
      fixture,
      controller,
      resolve,
      register,
      captures,
      measured,
      main: (height: number) => {
        mainHeight = height
      },
      detail: (height: number) => {
        detailHeight = height
      },
    }
  }

  function modalFixture({
    projection = 'wide',
    search = true,
    marked = true,
    part = 'main',
  }: {
    projection?: 'wide' | 'card'
    search?: boolean
    marked?: boolean
    part?: 'main' | 'work' | 'detail'
  } = {}) {
    const frame = {
      title: 'People',
      columns: part === 'detail' ? CARD_DETAIL_FRAME.columns : COLUMNS,
      ...(search ? { search: {} } : {}),
    }
    const f = geometryFixture(ModalGeometryTableHost, frame)
    // TestBed's renderer removes earlier fixture roots on each createComponent.
    // Independent hosts in one case must both stay in the real document.
    for (const root of modalRoots)
      if (!root.isConnected) document.body.append(root)
    modalRoots.push(f.fixture.nativeElement)
    const host = f.fixture.componentInstance as ModalGeometryTableHost
    host.part.set(part)
    function redraw() {
      f.fixture.detectChanges()
      TestBed.tick()
    }
    function preparePart() {
      if (part === 'detail') f.controller.expandRow('a', true)
      if (part === 'work')
        f.controller.ingestProgress({
          scope: 'row',
          rowKey: 'a',
          progressKey: 'inspect-a',
          current: 1,
          total: 2,
        })
    }
    preparePart()
    redraw()
    function show(next: 'wide' | 'card') {
      query(f.fixture, '.table-responsive')!.hidden = next !== 'wide'
      query(f.fixture, '[data-id="hilos-table-cards"]')!.hidden =
        next !== 'card'
    }
    show(projection)
    function control() {
      const prefix =
        projection === 'card'
          ? 'card'
          : part === 'detail'
            ? 'row-detail'
            : part === 'work'
              ? 'progress-row'
              : 'row'
      return query(
        f.fixture,
        `[data-id="hilos-table-${prefix}-a"] [data-id="open-person"]`,
      )!
    }
    const opener = control()
    if (!marked) opener.removeAttribute('data-hilos-focus-return')
    const other = query(f.fixture, '[data-id="unrelated"]')!
    const modal = () => query(f.fixture, '[data-id="modal"]')!
    return {
      ...f,
      opener,
      other,
      control,
      modal,
      show,
      search: () => query(f.fixture, '[data-id="hilos-table-search"]')!,
      root: () => query(f.fixture, '[data-id="hilos-viewport-table"]')!,
      activate() {
        opener.focus()
        opener.click()
        redraw()
        expect(document.activeElement).toBe(modal())
      },
      close(close: 'escape' | 'footer' = 'escape') {
        if (close === 'escape')
          modal().dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
          )
        else query(f.fixture, '[data-id="detail-close"]')!.click()
        redraw()
        expect(modal()).toBeNull()
      },
      hide() {
        host.open.set(false)
        redraw()
      },
      snapshot(name = 'Alice') {
        f.controller.ingestWindow(
          [{ rowKey: 'a', slots: { name } }],
          1,
          true,
          null,
          null,
          10,
        )
        preparePart()
        redraw()
      },
      remove(placeholder = false) {
        if (placeholder)
          f.controller.ingestDelta({
            kind: 'row_removed',
            rowKey: 'a',
            reason: 'deleted',
            own: true,
          })
        else f.controller.ingestWindow([], 0, true, null, null, 10)
        redraw()
      },
      replaceController() {
        const next = makeController(frame)
        ingestTwo(next)
        next.ingestWindow(
          [{ rowKey: 'a', slots: { name: 'Other controller' } }],
          1,
          true,
          null,
          null,
          10,
        )
        releases.push(() => next.dispose())
        host.controller.set(next)
        redraw()
      },
      unmountTable() {
        host.controller.set(null)
        redraw()
      },
      offerOuter() {
        releases.push(
          registerFocusReturnFallback(f.fixture.nativeElement, () => other),
        )
      },
    }
  }

  describe('modal focus lifetime', () => {
    it.each(['wide', 'card'] as const)(
      'keeps the available original %s opener after a live update',
      (projection) => {
        const f = modalFixture({ projection })
        f.activate()
        f.controller.ingestDelta({
          kind: 'row_updated',
          rowKey: 'a',
          row: { rowKey: 'a', slots: { name: 'Updated' } },
        })
        f.fixture.detectChanges()
        expect(f.opener.isConnected).toBe(true)
        f.close()
        expect(document.activeElement).toBe(f.opener)
      },
    )

    describe.each(['wide', 'card'] as const)('%s projection', (projection) => {
      it.each(['work', 'detail'] as const)(
        'returns to the fresh marked control in the %s part',
        (part) => {
          const f = modalFixture({ projection, part })
          const occurrence = f.controller.rows.get()[0]!.occurrence
          f.activate()
          const modal = f.modal()
          f.snapshot()
          const fresh = f.control()
          expect(f.controller.rows.get()[0]!.occurrence).not.toBe(occurrence)
          expect(f.opener.isConnected).toBe(false)
          expect(fresh).not.toBe(f.opener)
          expect(fresh.isConnected).toBe(true)
          expect(document.activeElement).toBe(modal)
          f.close()
          expect(document.activeElement).toBe(fresh)
        },
      )

      it.each(['escape', 'footer'] as const)(
        'returns to the fresh marked logical control after %s',
        (close) => {
          const f = modalFixture({ projection })
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
          f.close(close)
          expect(document.activeElement).toBe(fresh)
        },
      )

      it.each(['same key', 'reused key'])(
        'returns an unmarked data-id-only %s control to search',
        (change) => {
          const f = modalFixture({ projection, marked: false })
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
        'empty marker',
        'changed marker',
        'duplicate marker',
        'hidden control',
        'disabled control',
        'inert control',
        'disabled fieldset',
        'hidden projection',
        'foreign parts',
        'foreign table',
      ] as const)('returns to search for %s', (change) => {
        const f = modalFixture({ projection })
        const foreign =
          change === 'foreign table' ? modalFixture({ projection }) : null
        f.activate()
        const modal = f.modal()
        if (change === 'removed' || change === 'placeholder')
          f.remove(change === 'placeholder')
        else {
          f.snapshot()
          const control = f.control()
          if (change === 'missing marker' || change === 'foreign table')
            control.removeAttribute('data-hilos-focus-return')
          if (change === 'empty marker')
            control.setAttribute('data-hilos-focus-return', '')
          if (change === 'changed marker')
            control.setAttribute('data-hilos-focus-return', 'another-action')
          if (change === 'duplicate marker')
            control.parentElement!.append(control.cloneNode(true))
          if (change === 'hidden control') control.hidden = true
          if (change === 'disabled control')
            control.setAttribute('disabled', '')
          if (change === 'inert control') control.setAttribute('inert', '')
          if (change === 'disabled fieldset')
            control.closest('fieldset')!.disabled = true
          if (change === 'hidden projection')
            f.show(projection === 'wide' ? 'card' : 'wide')
        }
        const row =
          change === 'foreign parts'
            ? f
                .control()
                .closest<HTMLElement>(projection === 'wide' ? 'tr' : '.card')!
            : null
        const parent = row?.parentElement
        if (row) document.body.append(row)
        try {
          expect(f.opener.isConnected).toBe(false)
          expect(document.activeElement).toBe(modal)
          f.close()
          expect(document.activeElement).toBe(f.search())
          if (foreign)
            expect(document.activeElement).not.toBe(foreign.control())
        } finally {
          if (row) parent!.append(row)
        }
      })
    })

    it.each(['absent', 'hidden'])(
      'returns to the root with %s search',
      (search) => {
        const f = modalFixture({ search: search === 'hidden' })
        f.activate()
        if (search === 'hidden') f.search().hidden = true
        f.remove(true)
        expect(document.activeElement).toBe(f.modal())
        f.close()
        expect(document.activeElement).toBe(f.root())
        expect(f.root().tabIndex).toBe(-1)
      },
    )

    it.each(['controller', 'unmount'] as const)(
      'invalidates the captured offer after %s',
      (change) => {
        const f = modalFixture()
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

    it('keeps an explicit focus choice outside the dialog', () => {
      const f = modalFixture()
      f.activate()
      f.other.focus()
      f.snapshot()
      expect(document.activeElement).toBe(f.other)
      f.close()
      expect(document.activeElement).toBe(f.other)
    })

    it('honors a nearer registered offer before the table replacement', () => {
      const f = modalFixture()
      releases.push(
        registerFocusReturnFallback(
          query(f.fixture, '.table-responsive')!,
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
      const lower = modalFixture()
      const upper = modalFixture()
      lower.activate()
      upper.activate()
      const modal = upper.modal()
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

  it.each(['deleted', 'left_set', 'moved_out'] as const)(
    'first replacement retains the complete footprint for %s and removes payload from both mounted branches',
    (reason) => {
      const f = geometryFixture()
      f.controller.expandRow('a', true)
      f.controller.ingestProgress({
        scope: 'row',
        rowKey: 'a',
        progressKey: 'pack-a',
        current: 1,
        total: 2,
        detail: {},
      })
      f.fixture.detectChanges()
      TestBed.tick()
      // Change the DOM's dimensions without an observer/render. The ingress capture
      // must see this final old layout, before any payload is destroyed.
      f.detail(90.5)
      f.controller.ingestDelta({
        kind: 'row_removed',
        rowKey: 'a',
        reason,
        own: true,
      })
      f.fixture.detectChanges()
      const wide = query(f.fixture, 'tr[data-id="hilos-table-row-a"]')!
      const card = query(f.fixture, '[data-id="hilos-table-card-a"]')!
      expect(
        wide.querySelector('.hilos-table-record-space')?.getAttribute('style'),
      ).toContain('--hilos-table-record-px: 148.25')
      expect(
        card.querySelector('.hilos-table-record-space')?.getAttribute('style'),
      ).toContain('--hilos-table-record-px: 210.5')
      for (const node of [wide, card]) {
        expect(node.textContent).toContain(
          reason === 'deleted'
            ? 'Removed'
            : reason === 'left_set'
              ? 'No longer'
              : 'Moved',
        )
        expect(
          node.querySelector(
            'button, input, .named, .kind, .reason, .restore, [role="progressbar"]',
          ),
        ).toBeNull()
      }
      expect(
        query(f.fixture, '[data-id="hilos-table-row-detail-a"]'),
      ).toBeNull()
      expect(
        query(f.fixture, '[data-id="hilos-table-progress-row-a"]'),
      ).toBeNull()
      // The final tombstone is data-free and subsequent renders never resolve it.
      expect(
        f.controller.rows.get().find((view) => view.rowKey === 'a')?.row,
      ).toBeNull()
      f.resolve.mockClear()
      f.fixture.detectChanges()
      TestBed.tick()
      expect(f.resolve).not.toHaveBeenCalled()
      expect(
        query(f.fixture, 'table')?.classList.contains('hilos-table-fixed'),
      ).toBe(true)
      expect(query(f.fixture, 'col')?.getAttribute('style')).toContain(
        '--hilos-table-track-px',
      )
    },
  )

  it('renders an empty numeric work reserve before Apply and folds it into one replacement', () => {
    const f = geometryFixture()
    f.controller.ingestProgress({
      scope: 'row',
      rowKey: 'a',
      progressKey: 'pack-a',
      current: 1,
      total: 2,
      detail: {},
    })
    f.fixture.detectChanges()
    TestBed.tick()
    f.controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    f.fixture.detectChanges()
    const reserves = [
      ...(f.fixture.nativeElement as HTMLElement).querySelectorAll(
        '.hilos-table-work-reserve',
      ),
    ]
    expect(reserves).toHaveLength(2)
    for (const reserve of reserves) {
      expect(reserve.textContent?.trim()).toBe('')
      expect(
        reserve.querySelector('button, input, [role="progressbar"]'),
      ).toBeNull()
    }
    f.controller.apply()
    f.fixture.detectChanges()
    expect(
      (f.fixture.nativeElement as HTMLElement).querySelectorAll(
        '.hilos-table-work-reserve',
      ),
    ).toHaveLength(0)
    expect(
      (f.fixture.nativeElement as HTMLElement).querySelectorAll(
        '[data-id="hilos-table-placeholder"]',
      ),
    ).toHaveLength(2)
  })

  it('uses occurrence identity when the same key returns, and disposes a replaced controller registration', () => {
    const f = geometryFixture()
    const oldRow = query(f.fixture, 'tr[data-id="hilos-table-row-a"]')!
    const oldCard = query(f.fixture, '[data-id="hilos-table-card-a"]')!
    f.controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Replacement' } }],
      1,
      true,
      null,
      null,
      10,
    )
    f.fixture.detectChanges()
    expect(query(f.fixture, 'tr[data-id="hilos-table-row-a"]')).not.toBe(oldRow)
    expect(query(f.fixture, '[data-id="hilos-table-card-a"]')).not.toBe(oldCard)
    const oldRegistration = f.register.mock.results[0]!.value as ReturnType<
      TableViewportController<Row>['geometry']['register']
    >
    const other = makeController(CARD_DETAIL_FRAME)
    ingestTwo(other)
    const { register: nextRegister } = observeGeometry(other)
    f.fixture.componentInstance.controller.set(other)
    f.fixture.detectChanges()
    TestBed.tick()
    expect(nextRegister).toHaveBeenCalledTimes(1)
    expect(oldRegistration.record('a', 'wide')).toBeNull()
    f.measured.length = 0
    f.controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
      own: true,
    })
    expect(f.measured).toHaveLength(0)
    const registration = nextRegister.mock.results[0]!.value as ReturnType<
      TableViewportController<Row>['geometry']['register']
    >
    f.fixture.destroy()
    expect(registration.record('a', 'wide')).toBeNull()
  })

  it('keeps an unchanged after-render measurement quiet', () => {
    const f = geometryFixture()
    const registration = f.register.mock.results[0]!.value as ReturnType<
      TableViewportController<Row>['geometry']['register']
    >
    const revision = registration.revision.get()
    f.fixture.detectChanges()
    TestBed.tick()
    expect(registration.revision.get()).toBe(revision)
  })
  it('captures the real binder boundary before normalization publishes to the entity store', () => {
    const f = geometryFixture()
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
      f.controller,
    )
    f.captures.mockClear()
    const stop = subscribeSignal(
      scope.entities.signal({ type: 'user', id: 7 }),
      () => {
        expect(f.captures).toHaveBeenCalledTimes(1)
        expect(f.captures.mock.calls[0]![0].records[0]?.extent?.main).toBe(
          40.25,
        )
        f.main(1)
      },
    )
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
    })
    expect(f.captures).toHaveBeenCalledTimes(1)
    expect(JSON.stringify(f.captures.mock.calls[0])).not.toMatch(
      /slots|Alice|Bob|Private|HTMLElement/,
    )
    stop()
    unbind()
    scopes.dropPage()
  })

  it.each(['future_reason', undefined])(
    'keeps geometry and the shared fallback for wire removal reason %s',
    (reason) => {
      const f = geometryFixture()
      const scopes = new ScopeManager()
      scopes.openPage('geometry')
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
        f.controller,
      )
      listeners.get('tableViewportDelta')!({
        data: {
          page: 'geometry',
          tableKey: 'people',
          kind: 'row_removed',
          rowKey: 'a',
          reason,
          own: true,
        },
      })
      f.fixture.detectChanges()
      const root = f.fixture.nativeElement as HTMLElement
      const placeholders = root.querySelectorAll(
        '[data-id="hilos-table-placeholder"]',
      )
      expect(placeholders).toHaveLength(2)
      for (const placeholder of placeholders) {
        expect(placeholder.textContent?.trim()).toBe('Removed')
        expect(placeholder.querySelector('.bi-dash-circle')).not.toBeNull()
        expect(placeholder.querySelector('button, input, .named')).toBeNull()
      }
      expect(
        query(
          f.fixture,
          'tr[data-id="hilos-table-row-a"] .hilos-table-record-space',
        )?.getAttribute('style'),
      ).toContain('--hilos-table-record-px')
      unbind()
      scopes.dropPage()
    },
  )

  it.each(['before', 'between', 'after'] as const)(
    'holds batch/last-row geometry and the zero footer when count arrives %s departure',
    (timing) => {
      const f = geometryFixture()
      f.captures.mockClear()
      f.measured.length = 0
      const stop = subscribeSignal(f.controller.rows, () => {
        expect(f.measured).toEqual(
          expect.arrayContaining([
            'hilos-table-row-a',
            'hilos-table-row-b',
            'hilos-table-card-a',
            'hilos-table-card-b',
          ]),
        )
      })
      if (timing === 'before') f.controller.ingestCount(0, true)
      f.controller.withPresentationUpdate(
        { kind: 'rows', rowKeys: ['a', 'b'] },
        () => {
          f.controller.ingestDelta({
            kind: 'row_removed',
            rowKey: 'a',
            reason: 'deleted',
            own: true,
          })
          if (timing === 'between') f.controller.ingestCount(0, true)
          f.controller.ingestDelta({
            kind: 'row_removed',
            rowKey: 'b',
            reason: 'left_set',
            own: true,
          })
        },
      )
      stop()
      if (timing === 'after') f.controller.ingestCount(0, true)
      expect(f.captures).toHaveBeenCalledTimes(1)
      f.fixture.detectChanges()
      const root = f.fixture.nativeElement as HTMLElement
      expect(
        root.querySelectorAll('[data-id="hilos-table-placeholder"]'),
      ).toHaveLength(4)
      expect(
        query(f.fixture, '[data-id="hilos-table-count"]')?.textContent?.trim(),
      ).toBe('0 – 0 of 0')
      expect(
        root.querySelector(
          'button.restore, input[data-id^="hilos-table-select-"], .named, .reason, [role="progressbar"]',
        ),
      ).toBeNull()
      expect(root.textContent).not.toMatch(/Alice|Bob|Private/)
    },
  )

  it.each(['append', 'own-create'] as const)(
    'treats a reused key from %s as a fresh appearance with the retained track latch',
    (mode) => {
      const f = geometryFixture()
      f.controller.ingestDelta({
        kind: 'row_removed',
        rowKey: 'a',
        reason: 'deleted',
        own: true,
      })
      f.fixture.detectChanges()
      const old = query(f.fixture, 'tr[data-id="hilos-table-row-a"]')
      const latch = (
        f.register.mock.results[0]!.value as ReturnType<
          TableViewportController<Row>['geometry']['register']
        >
      ).tracks()
      const row = { rowKey: 'a', slots: { name: 'Fresh' } }
      if (mode === 'append') f.controller.ingestAppend(row, 2, true, null, null)
      else
        f.controller.ingestOwnCreate(
          row,
          0,
          2,
          true,
          null,
          null,
          'create-request',
        )
      f.fixture.detectChanges()
      expect(query(f.fixture, 'tr[data-id="hilos-table-row-a"]')).not.toBe(old)
      expect(
        query(f.fixture, 'tr[data-id="hilos-table-row-a"] .named')?.textContent,
      ).toBe('Fresh')
      expect(query(f.fixture, '[data-id="hilos-table-placeholder"]')).toBeNull()
      expect(
        (
          f.register.mock.results[0]!.value as ReturnType<
            TableViewportController<Row>['geometry']['register']
          >
        ).tracks(),
      ).toBe(latch)
    },
  )

  it.each(['snapshot', 'refusal', 'reset', 'reconnect', 'remount'] as const)(
    'does not carry departed allocations into a %s lifetime',
    (mode) => {
      const f = geometryFixture()
      f.controller.ingestDelta({
        kind: 'row_removed',
        rowKey: 'a',
        reason: 'deleted',
        own: true,
      })
      f.fixture.detectChanges()
      const old = query(f.fixture, 'tr[data-id="hilos-table-row-a"]')
      if (mode === 'refusal') {
        f.controller.ingestRefusal('unavailable')
        f.fixture.detectChanges()
        expect(
          query(f.fixture, '[data-id="hilos-table-placeholder"]'),
        ).toBeNull()
      }
      if (mode === 'reset') f.controller.refresh()
      if (mode === 'reconnect') {
        f.controller.ingestFrozen(123)
        f.controller.resetWindowRequests()
      }
      if (mode === 'remount') {
        f.fixture.componentInstance.controller.set(null)
        f.fixture.detectChanges()
        f.fixture.componentInstance.controller.set(f.controller)
      }
      f.controller.ingestWindow(
        [{ rowKey: 'a', slots: { name: 'New snapshot' } }],
        1,
        true,
        null,
        null,
        10,
      )
      f.fixture.detectChanges()
      expect(query(f.fixture, 'tr[data-id="hilos-table-row-a"]')).not.toBe(old)
      expect(query(f.fixture, '.hilos-table-record-space')).toBeNull()
      expect(
        query(f.fixture, 'tr[data-id="hilos-table-row-a"] .named')?.textContent,
      ).toBe('New snapshot')
    },
  )
  it('retires an edge-evicted target and preserves occurrence through an Apply move', () => {
    const f = geometryFixture()
    f.controller.ingestWindow(
      [
        { rowKey: 'a', slots: { name: 'Alice' } },
        { rowKey: 'b', slots: { name: 'Bob' } },
      ],
      2,
      true,
      null,
      null,
      2,
    )
    f.fixture.detectChanges()
    const edge = query(f.fixture, 'tr[data-id="hilos-table-row-b"]')!
    f.controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'b',
      reason: 'deleted',
    })
    f.controller.ingestOwnCreate(
      { rowKey: 'c', slots: { name: 'Created' } },
      0,
      3,
      true,
      null,
      null,
      'request-c',
    )
    f.fixture.detectChanges()
    expect(edge.isConnected).toBe(false)
    expect(query(f.fixture, '[data-id="hilos-table-row-b"]')).toBeNull()
    expect(
      (
        f.register.mock.results[0]!.value as ReturnType<
          TableViewportController<Row>['geometry']['register']
        >
      ).record('b', 'wide'),
    ).toBeNull()
    const row = query(f.fixture, 'tr[data-id="hilos-table-row-a"]')!
    f.controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'Moved' } },
      position: 0,
    })
    f.controller.apply()
    f.fixture.detectChanges()
    expect(query(f.fixture, 'tr[data-id="hilos-table-row-a"]')).toBe(row)
    expect(row.querySelector('.named')?.textContent).toBe('Moved')
    expect(row.querySelector('.hilos-table-record-space')).toBeNull()
  })

  it.each(['before', 'after'] as const)(
    'keeps an early work reserve when progress ends %s the removal notice',
    (timing) => {
      const f = geometryFixture()
      const work = (ended: boolean) =>
        f.controller.ingestProgress({
          scope: 'row',
          rowKey: 'a',
          progressKey: 'pack-a',
          current: ended ? 2 : 1,
          total: 2,
          ended,
        })
      work(false)
      f.fixture.detectChanges()
      TestBed.tick()
      if (timing === 'before') {
        work(true)
        f.fixture.detectChanges()
      }
      f.controller.ingestDelta({
        kind: 'row_removed',
        rowKey: 'a',
        reason: 'deleted',
      })
      f.fixture.detectChanges()
      if (timing === 'after') work(true)
      expect(
        (f.fixture.nativeElement as HTMLElement).querySelectorAll(
          '.hilos-table-work-reserve',
        ),
      ).toHaveLength(timing === 'after' ? 2 : 0)
      f.controller.apply()
      f.fixture.detectChanges()
      expect(query(f.fixture, '.hilos-table-work-reserve')).toBeNull()
      expect(
        query(
          f.fixture,
          'tr[data-id="hilos-table-row-a"] .hilos-table-record-space',
        ),
      ).not.toBeNull()
    },
  )
})
