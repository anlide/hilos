// The branch of the Angular view on a declared frame, under the case names the Vue
// view and the React port run for it (HIL-801, HIL-810). The host fills the `#row`
// template, which a component created directly could not be handed. The second
// group stands the table inside the admin shell, the way the framework's admin
// pages draw it, where a table declaring no title is named by the page heading.
import { Component } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'
import { HilosPages, TableViewportController, createSignal } from '@hilos/core'
import type {
  ActionHandle,
  HilosPageIdentity,
  HilosTableBulkAccepted,
  HilosRouter,
  HilosTableColumn,
  HilosTableFrame,
  PageRouteMatch,
} from '@hilos/core'

import { HilosAdminPage } from '../src/HilosAdminPage.js'
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

const FRAME: HilosTableFrame = {
  title: 'Backups',
  search: {},
  columns: COLUMNS,
}

/** A host drawing a table that also passes the props of the older branch. */
@Component({
  selector: 'test-viewport-table-host',
  imports: [HilosViewportTable],
  template: `
    <hilos-viewport-table
      [controller]="controller"
      [columns]="columns"
      label="Users"
      [searchable]="true"
    >
      <ng-template #row let-row>
        <td>{{ row.name }}</td>
      </ng-template>
    </hilos-viewport-table>
  `,
})
class ViewportTableHost {
  controller!: TableViewportController<Row>
  columns = COLUMNS
}

/**
 * A page drawing a declared table inside the admin shell, the way the framework's
 * admin pages do: the table is handed its controller and its row, and nothing else.
 */
@Component({
  selector: 'test-admin-page-table-host',
  imports: [HilosAdminPage, HilosViewportTable],
  template: `
    <hilos-admin-page [page]="page">
      <hilos-viewport-table [controller]="controller">
        <ng-template #row let-row>
          <td>{{ row.name }}</td>
        </ng-template>
      </hilos-viewport-table>
    </hilos-admin-page>
  `,
})
class AdminPageTableHost {
  controller!: TableViewportController<Row>
  page = HilosPages.I18N_LANGUAGE
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

  // Apply stays: the room of live messages that carries it in Vue is not ported yet,
  // and the framework pages on this view take their pending changes through it.
  it('leaves the props-driven search out but keeps its Apply button', () => {
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

    expect(query(fixture, '[data-id="hilos-table-apply"]')).not.toBeNull()
    expect(
      (fixture.nativeElement as HTMLElement).querySelectorAll(
        '[data-id="hilos-table-search"]',
      ),
    ).toHaveLength(1)
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
