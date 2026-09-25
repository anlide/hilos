// The Angular view under the case names the Vue view and the React port run for it.
// The first group draws a table whose page declared no frame: the numbers a
// composite order puts on its headers (HIL-811), its rows' tint, the mark column,
// and the room of live messages above the rows (HIL-803, HIL-812). The
// next ones take the branch on a declared frame (HIL-801, HIL-810), whose cells the
// host fills one marked template per column (HIL-815); a table on no frame still
// takes the `#row` template — either of which a component created directly could not
// be handed. The
// admin-page group stands the table inside the admin shell, the way the framework's
// admin pages draw it, where a table declaring no title is named by the page heading.
// The last two open a row and a card into the panel of fields that did not fit a
// column (HIL-816), whose values the host marks one template per field.
import { Component } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { HilosPages, TableViewportController, createSignal } from '@hilos/core'
import type {
  ActionHandle,
  HilosPageIdentity,
  HilosTableBulkAccepted,
  HilosRouter,
  HilosTableColumn,
  HilosTableFrame,
  PageRouteMatch,
  TableViewportDescriptor,
} from '@hilos/core'

import { HilosAdminPage } from '../src/HilosAdminPage.js'
import { HilosTableCell } from '../src/HilosTableCell.js'
import { HilosTableDetail } from '../src/HilosTableDetail.js'
import { HilosViewportTable } from '../src/HilosViewportTable.js'
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

/** A host drawing a table that also passes the props of the older branch. */
@Component({
  selector: 'test-viewport-table-host',
  imports: [HilosTableCell, HilosViewportTable],
  template: `
    <hilos-viewport-table
      [controller]="controller"
      [columns]="columns"
      label="Users"
      [searchable]="true"
    >
      <ng-template hilosTableCell="name" let-row>{{ row.name }}</ng-template>
    </hilos-viewport-table>
  `,
})
class ViewportTableHost {
  controller!: TableViewportController<Row>
  columns = COLUMNS
}

/** A host drawing a table whose page declared no frame, from its inputs alone. */
@Component({
  selector: 'test-plain-viewport-table-host',
  imports: [HilosViewportTable],
  template: `
    <hilos-viewport-table [controller]="controller" [columns]="columns">
      <ng-template #row let-row>
        <td class="cell">{{ row.name }}</td>
      </ng-template>
    </hilos-viewport-table>
  `,
})
class PlainTableHost {
  controller!: TableViewportController<Row>
  columns = COLUMNS
}

/** A host filling every place a page has beside running work. */
@Component({
  selector: 'test-progress-table-host',
  imports: [HilosViewportTable],
  template: `
    <hilos-viewport-table [controller]="controller" [columns]="columns">
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
      <ng-template #row let-row>
        <td>{{ row.name }}</td>
      </ng-template>
    </hilos-viewport-table>
  `,
})
class ProgressTableHost {
  controller!: TableViewportController<Row>
  columns = COLUMNS
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
  imports: [HilosViewportTable],
  template: `
    <hilos-viewport-table [controller]="controller" [columns]="columns">
      <ng-template #row let-row>
        <td></td>
        <td>{{ row.name }}</td>
        <td></td>
        <td></td>
      </ng-template>
    </hilos-viewport-table>
  `,
})
class MarkedTableHost {
  controller!: TableViewportController<Row>
  columns = MARKED_COLUMNS
}

/**
 * A page drawing a declared table inside the admin shell, the way the framework's
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

/**
 * A host whose table is declared while the page still passes a column list of the
 * props epoch, and fills only the column that list does not name.
 */
@Component({
  selector: 'test-stale-columns-table-host',
  imports: [HilosTableCell, HilosViewportTable],
  template: `
    <hilos-viewport-table [controller]="controller" [columns]="columns">
      <ng-template hilosTableCell="size">
        <span class="sized">1.2 GB</span>
      </ng-template>
    </hilos-viewport-table>
  `,
})
class StaleColumnsTableHost {
  controller!: TableViewportController<Row>
  columns: HilosTableColumn[] = [{ key: 'name', label: 'Name' }]
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

/** A host drawing a declared table over two columns, one of them marked. */
@Component({
  selector: 'test-states-table-host',
  imports: [HilosTableCell, HilosViewportTable],
  template: `
    <hilos-viewport-table [controller]="controller" [columns]="columns">
      <ng-template hilosTableCell="name" let-row>
        <span class="named">{{ row.name }}</span>
      </ng-template>
    </hilos-viewport-table>
  `,
})
class StatesTableHost {
  controller!: TableViewportController<Row>
  columns = STATE_COLUMNS
}

function makeController(
  frame: HilosTableFrame = FRAME,
): TableViewportController<Row> {
  return new TableViewportController<Row>({
    resolve: (raw) => ({ name: String(raw.slots['name']) }),
    sendViewport: () => undefined,
    frame,
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

function query(
  fixture: ComponentFixture<unknown>,
  selector: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(selector)
}

describe('HilosViewportTable', () => {
  function makePlainController(): {
    controller: TableViewportController<Row>
    sent: TableViewportDescriptor[]
  } {
    const sent: TableViewportDescriptor[] = []
    const controller = new TableViewportController<Row>({
      resolve: (raw) => ({ name: String(raw.slots['name']) }),
      sendViewport: (descriptor) => sent.push(descriptor),
    })

    return { controller, sent }
  }

  function mountTable(
    controller: TableViewportController<Row>,
    columns: HilosTableColumn[] = COLUMNS,
  ): ComponentFixture<PlainTableHost> {
    const fixture = TestBed.createComponent(PlainTableHost)
    fixture.componentInstance.controller = controller
    fixture.componentInstance.columns = columns
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
    const { controller } = makePlainController()
    controller.setOrder([
      { field: 'name', direction: 'desc' },
      { field: 'id', direction: 'asc' },
    ])
    const fixture = mountTable(controller, TWO_COLUMNS)

    expect(all(fixture, 'th sup').map((mark) => mark.textContent)).toEqual([
      '1',
      '2',
    ])
    // aria-sort names a direction and cannot say "second by importance", so the
    // place is spoken beside the number instead.
    expect(
      all(fixture, 'th .visually-hidden').map((said) =>
        said.textContent?.trim(),
      ),
    ).toEqual(['Sort column 1 of 2', 'Sort column 2 of 2'])
  })

  it('numbers nothing under an order of one column, where the arrow says it all', () => {
    const { controller } = makePlainController()
    controller.setSort('name')
    const fixture = mountTable(controller, TWO_COLUMNS)

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

  it('grows the mark column with the waiting, header and body at once', () => {
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
    // An applied removal leaves a placeholder behind and nothing waiting, which is
    // the state where the mark column must not stand.
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

    expect(all(fixture, 'thead th')).toHaveLength(COLUMNS.length)
    expect(placeholderSpan()).toBe(String(COLUMNS.length))

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
      const { controller } = makePlainController()
      window(controller, ['connections'])
      const fixture = mountTable(controller, SOURCED_COLUMNS)

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
      const { controller } = makePlainController()
      window(controller, ['connections'])
      const fixture = mountTable(controller, SOURCED_COLUMNS)

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
      const { controller } = makePlainController()
      window(controller, ['connections'])
      const fixture = mountTable(controller, [
        SOURCED_COLUMNS[0]!,
        { ...SOURCED_COLUMNS[1]!, sortable: false },
      ])

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
      const { controller, sent } = makePlainController()
      window(controller, ['connections'])
      const fixture = mountTable(controller, SOURCED_COLUMNS)
      sent.length = 0
      query(fixture, '[data-id="hilos-table-sort-presence"]')?.click()

      expect(sent).toHaveLength(1)
    })

    it('numbers headers of a composite order whose first column is quiet and speaks both places', () => {
      const { controller } = makePlainController()
      window(controller, ['connections'])
      controller.setOrder([
        { field: 'presence', direction: 'asc' },
        { field: 'name', direction: 'desc' },
      ])
      const fixture = mountTable(controller, SOURCED_COLUMNS)

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
      const { controller } = makePlainController()
      window(controller, [])
      const fixture = mountTable(controller, SOURCED_COLUMNS)
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
      const { controller } = makePlainController()
      window(controller, ['connections'])
      const fixture = mountTable(controller, SOURCED_COLUMNS)

      expect(
        query(fixture, '[data-id="hilos-table-stale-row-a"]'),
      ).not.toBeNull()
      expect(query(fixture, '[data-id="hilos-table-stale-row-b"]')).toBeNull()
    })

    it('stands the mark cell up on a quiet source with nothing waiting at all', () => {
      const { controller } = makePlainController()
      window(controller, ['connections'])
      const fixture = mountTable(controller, SOURCED_COLUMNS)

      expect(headers(fixture)).toHaveLength(SOURCED_COLUMNS.length + 1)
      expect(query(fixture, 'thead th:last-child')?.textContent?.trim()).toBe(
        'Row state and controls',
      )
      // Header and body read one condition, so the cell the header just made room
      // for is the one standing last in the row.
      const cells = all(fixture, '[data-id="hilos-table-row-a"] td')
      expect(
        cells[cells.length - 1]?.querySelector(
          '[data-id="hilos-table-stale-row-a"]',
        ),
      ).not.toBeNull()
    })

    it('takes the strip, the snowflakes and the cell down when the source is current again', () => {
      const { controller } = makePlainController()
      window(controller, ['connections'])
      const fixture = mountTable(controller, SOURCED_COLUMNS)
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
      expect(headers(fixture)).toHaveLength(SOURCED_COLUMNS.length)
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

  it('draws the declared footer instead of the one built from props', () => {
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
    expect(query(fixture, '[data-id="hilos-table-page"]')).toBeNull()
  })

  it('leaves the props-driven bar out and keeps Apply in the waiting strip', () => {
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

    // One search box, the declared one; and the strips speak in both epochs of the
    // frame, because they are about the rows and not about what the page declared.
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

describe('HilosViewportTable on an admin page', () => {
  /** A declared table with no title of its own — the one table of such a page. */
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

  it('draws the header and the empty words from the declaration when handed no columns', () => {
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
    expect(cells).toHaveLength(CELL_COLUMNS.length)
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

  it('reads the columns off the declaration rather than off the prop', () => {
    const controller = makeController(CELL_FRAME)
    window(controller)
    const fixture = TestBed.createComponent(StaleColumnsTableHost)
    fixture.componentInstance.controller = controller
    fixture.detectChanges()

    const root = fixture.nativeElement as HTMLElement
    expect(root.querySelectorAll('thead th')).toHaveLength(CELL_COLUMNS.length)
    expect(root.querySelector('thead')?.textContent).toContain('Size')
    expect(root.querySelector('.sized')).not.toBeNull()
  })

  it('keeps handing the whole row over while the page passes columns as a prop', () => {
    const controller = new TableViewportController<Row>({
      resolve: (raw) => ({ name: String(raw.slots['name']) }),
      sendViewport: () => undefined,
    })
    window(controller)
    const fixture = TestBed.createComponent(PlainTableHost)
    fixture.componentInstance.controller = controller
    fixture.detectChanges()

    expect(
      query(fixture, '[data-id="hilos-table-row-a"] td.cell')?.textContent,
    ).toBe('Alice')
  })
})

describe('HilosViewportTable drawing a row as a card', () => {
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
      { key: 'delete', label: 'Delete', danger: true, run: neverRun },
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

  it('draws no cards and keeps the table at every width without a declaration', () => {
    const controller = new TableViewportController<Row>({
      resolve: (raw) => ({ name: String(raw.slots['name']) }),
      sendViewport: () => undefined,
    })
    window(controller)
    const fixture = TestBed.createComponent(PlainTableHost)
    fixture.componentInstance.controller = controller
    fixture.detectChanges()

    expect(query(fixture, '[data-id="hilos-table-cards"]')).toBeNull()
    expect(
      query(fixture, '.table-responsive')?.classList.contains('d-none'),
    ).toBe(false)
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
    expect(card.querySelector('.d-grid .restore')).not.toBeNull()
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
    ).toHaveLength(CARD_COLUMNS.length)
    expect(cardOf(fixture, 'a').querySelectorAll('dt')).toHaveLength(0)
    expect(cardOf(fixture, 'a').querySelector('.d-grid')).toBeNull()
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

  it('draws the empty tile instead of placeholders after convergence', () => {
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
    ).toHaveLength(0)
    expect(
      root(fixture).querySelectorAll('[data-id="hilos-table-empty"]'),
    ).toHaveLength(2)
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

  it('stands the framework marks beside the page badge, not instead of it', () => {
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

    const group = cardOf(fixture, 'a').querySelector('.ms-auto')
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
    expect(marks.slice(0, 4)).toEqual([
      'state-badge',
      'hilos-table-stale-row-a',
      'visually-hidden',
      'hilos-table-pending-move-a',
    ])
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
        .querySelector('.ms-auto input')
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
})

describe('HilosViewportTable with a selection column', () => {
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
    expect(all(fixture, 'thead th')).toHaveLength(COLUMNS.length)
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
    ).toBe(String(COLUMNS.length + 1))

    const empty = makeController(BULK_FRAME)
    empty.ingestWindow([], 0, true, null, null, 10)
    const emptyTable = mountWithEdge(empty)
    expect(query(emptyTable, 'tbody td')?.getAttribute('colspan')).toBe(
      String(COLUMNS.length + 1),
    )
  })
})

// A controller for a table whose page declared no frame: the default of
// makeController would hand it one.
function makeUndeclaredController(): TableViewportController<Row> {
  return new TableViewportController<Row>({
    resolve: (raw) => ({ name: String(raw.slots['name']) }),
    sendViewport: () => undefined,
  })
}

describe('HilosViewportTable drawing work in progress', () => {
  it('hands the whole bar, detail and all, to the places beside the track', () => {
    const controller = makeUndeclaredController()
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
  function plainController(): TableViewportController<Row> {
    const controller = makeUndeclaredController()
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
    const controller = plainController()
    rowBar(controller, 'a')
    const fixture = mount(MarkedTableHost, controller)

    const cells = barCells(fixture)
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
    const controller = plainController()
    rowBar(controller, 'a')
    const fixture = mount(PlainTableHost, controller)

    const cells = barCells(fixture)
    expect(cells).toHaveLength(1)
    expect(cells[0]?.getAttribute('colspan')).toBe('1')
    expect(cells[0]?.querySelector('[role="progressbar"]')).not.toBeNull()
  })

  it('adds the waiting cell to the bar row exactly as the header does', () => {
    const controller = plainController()
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
    // The header grew by the waiting column, and the bar row grew with it: the
    // two are one sum, so the row can never be wider than its header.
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
 * A host drawing that table from its inputs; `detail` picks what the page marks for
 * the field — the reason, the row and its key, or nothing at all.
 */
@Component({
  selector: 'test-detail-table-host',
  imports: [HilosTableDetail, HilosViewportTable],
  template: `
    <hilos-viewport-table
      [controller]="controller"
      [columns]="columns"
      [searchable]="true"
    >
      <ng-template #row let-row>
        <td class="cell">{{ row.name }}</td>
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
  columns = DETAIL_COLUMNS
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
    expect(blocks[3]?.classList.contains('d-grid')).toBe(true)
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
    const controller = makeUndeclaredController()
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
    const plain = TestBed.createComponent(PlainTableHost)
    plain.componentInstance.controller = controller
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

  function makeStates(frame?: HilosTableFrame): {
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
    expect(tableRows[0]?.querySelectorAll('td')).toHaveLength(2)
    expect(
      all(
        fixture,
        '[data-id="hilos-table-cards"] [data-id="hilos-table-skeleton-row"]',
      ),
    ).toHaveLength(2)
    expect(all(fixture, '.placeholder[aria-hidden="true"]')).toHaveLength(2 * 2)
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

  it('says nothing was found on a table that still draws its frame from props', () => {
    const { controller } = makeStates()
    const fixture = TestBed.createComponent(ViewportTableHost)
    fixture.componentInstance.controller = controller
    fixture.detectChanges()
    controller.ingestWindow([], 0, true, null, null, 10)
    controller.setSearch('night')
    controller.ingestWindow([], 0, true, null, null, 10)
    fixture.detectChanges()

    expect(
      all(
        fixture,
        '[data-id="hilos-table-no-matches-terms"]',
      )[0]?.textContent?.trim(),
    ).toBe('No rows match “night”')
  })
})
