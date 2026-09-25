// HilosViewportTable — the thin Angular view over the SERVER-WINDOWED
// TableViewportController. Search, sort, and paging change the viewport
// descriptor and are sent to the backend (NO local filtering); live changes
// arrive as pending and are resolved with the Apply button. A removed row renders
// as a placeholder in its slot while the set still has rows elsewhere. It holds
// NO table logic (multiframework-core.md): the controller owns the descriptor,
// pending, and Apply. Body cells come from the page — one
// `<ng-template hilosTableCell="<column key>">` per declared column for a table whose
// page declared a frame, the whole row through `<ng-template #row let-row
// let-rowKey="rowKey">` for one whose page did not — plus one framework-owned cell at
// the end of the row carrying what waits on that row; the placeholder, header,
// paging, and the one room of live messages above the rows stay framework-owned.
// The controller arrives via
// input, carrying core signals, so the view mirrors them into Angular signals.
// (Distinct from HilosTable, the client-side view.) A table whose page DECLARED a
// frame draws the bar and the footer from that declaration instead
// (HilosTableBar, HilosTableFooter), and takes its columns, its name, and the words
// it says when empty from there too; a table whose page declared nothing is drawn
// from its inputs, the older of the two epochs, which the framework's log pages
// still take. A table whose page declared bulk operations also carries the
// framework's checkbox column, on whichever edge the installation provided — one
// choice for the whole application rather than an input of this table
// (mockups/components/table section 6). Below the md breakpoint a declared table is
// a list of cards instead, built from the same declared columns and filled by the
// same marked templates (mockups/components/table section 9). A field that did not
// fit a column of its own is declared `detail` and waits in a panel under the row:
// the framework owns the room, the order and the labels, while the page draws every
// value through `<ng-template hilosTableDetail="<column key>">`, exactly as it draws
// a cell (mockups/components/table section 4). A card opens into its own panel,
// inside its own body and off an id base of its own. The body is drawn from the
// state the core decides (HilosTableBody): rows, a skeleton of rows while a window
// change is late, or one of the four worded states drawn by HilosTableEmptyState —
// the page's own "nothing here yet", the framework's "Nothing found", and
// "List unavailable"
// (mockups/components/table section 10). Bootstrap classes only.
import { NgTemplateOutlet } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  contentChild,
  contentChildren,
  effect,
  inject,
  input,
  signal,
} from '@angular/core'
import type { TemplateRef, WritableSignal } from '@angular/core'
import {
  TABLE_DETAIL_COPY,
  TABLE_STALENESS_COPY,
  hilosTableDetailFields,
  hilosTableOrderPosition,
  hilosTablePlaceholder,
  hilosTableSortPositionLabel,
  hilosTableStaleColumns,
  hilosTableStaleSources,
  subscribeSignal,
} from '@hilos/core'
import type {
  HilosTableBody,
  HilosTableCard,
  HilosTableColumn,
  HilosTableProgress as HilosTableProgressState,
  HilosTableSelectionHeader,
  ReadonlySignal,
  TableSort,
  TableSortOrder,
  TableRemovalReason,
  TableViewportController,
  TableViewportRow,
} from '@hilos/core'

import { HilosTableBar } from './HilosTableBar.js'
import { HilosTableCell } from './HilosTableCell.js'
import type { HilosTableCellContext } from './HilosTableCell.js'
import { HilosTableDetail } from './HilosTableDetail.js'
import { HilosTableEmptyState } from './HilosTableEmptyState.js'
import { HilosTableFooter } from './HilosTableFooter.js'
import { HilosTableLive } from './HilosTableLive.js'
import { HilosTableProgress } from './HilosTableProgress.js'
import { HILOS_PAGE_HEADING_ID } from './hilosPageHeadingToken.js'
import { HILOS_TABLE_SELECTION_EDGE } from './hilosTableSelectionEdge.js'

// Distinct ids so two declared tables on one page never name themselves by the
// same title.
let viewportTableSeq = 0

/** The context a HilosViewportTable `#row` template receives. */
export interface ViewportTableRowContext<R> {
  /** The resolved row view-model (the template's implicit `let-row`). */
  $implicit: R
  /** The row's stable key. */
  rowKey: string
}

/** The context a HilosViewportTable `#tableProgress` or `#tableProgressAction` template receives. */
export interface ViewportTableProgressContext {
  /** The bar of the work running on the table (the template's implicit `let-progress`). */
  $implicit: HilosTableProgressState
}

/** The context a HilosViewportTable `#rowProgress` template receives. */
export interface ViewportTableRowProgressContext {
  /** The bar of the work running over one row (the template's implicit `let-progress`). */
  $implicit: HilosTableProgressState
  /** The key of the row the work runs over. */
  rowKey: string
}

/** One cell of a row bar's row: how many columns it spans, and whether the bar is under it. */
type ProgressCell = { span: number; covered: boolean }

/** The context a HilosViewportTable `#bulkUntouched` template receives. */
export interface BulkUntouchedContext {
  /** The key of the row a bulk run left untouched (the template's implicit `let-rowKey`). */
  $implicit: string
  /** Why the run left it alone, as the server said it. */
  reason: string
}

/** The framework-owned table chrome over a headless TableViewportController. */
@Component({
  selector: 'hilos-viewport-table',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosTableBar,
    HilosTableEmptyState,
    HilosTableFooter,
    HilosTableLive,
    HilosTableProgress,
    NgTemplateOutlet,
  ],
  template: `
    <div [attr.data-id]="dataId()">
      @if (declaration()) {
        <hilos-table-bar
          [controller]="controller()"
          [titleId]="titleId"
          [autofocusSearch]="autofocusSearch()"
        />
      }

      <!-- The bar a table draws from inputs, kept while a page still passes them —
      the framework's log pages do. It goes with the inputs themselves. -->
      @if (!declaration() && searchable()) {
        <div class="mb-3">
          <input
            type="search"
            class="form-control"
            [placeholder]="searchPlaceholder()"
            [attr.aria-label]="searchPlaceholder()"
            [value]="search()"
            [attr.data-autofocus]="autofocusSearch() ? '' : null"
            data-id="hilos-table-search"
            (input)="onSearchInput($event)"
          />
        </div>
      }

      <!-- Everything live the table has to say — work running over the set, a
      source gone quiet, new rows the window cannot show, changes waiting for Apply —
      in one room that never changes height, outside both epochs of the frame: it
      speaks about what is happening to the rows, not about what the page
      declared. -->
      <hilos-table-live
        [controller]="controller()"
        [columns]="frameColumns()"
        [tableProgress]="tableProgress()"
        [tableProgressAction]="tableProgressAction()"
        [bulkUntouched]="bulkUntouched()"
      />

      <!-- A DECLARED table is a table on a wide screen and a list of cards on a
      narrow one, so its scroll wrapper goes with the table itself: there is
      nothing left to scroll sideways once the columns became lines of a card. A
      table still drawn from inputs has no cards to fall back on and keeps the
      wrapper at every width, scrollbar and all. -->
      <div
        class="table-responsive"
        [class.d-none]="declaration() !== null"
        [class.d-md-block]="declaration() !== null"
      >
        <table
          class="table table-striped table-hover align-middle mb-0"
          [attr.aria-labelledby]="declaration() ? nameId() : null"
        >
          <!-- A declared table already shows its name as a heading, and a hidden
          caption repeating it would name the table twice. -->
          @if (!declaration() && label(); as label) {
            <caption class="visually-hidden">
              {{
                label
              }}
            </caption>
          }
          <thead>
            <tr>
              <!-- The checkbox column stands on the edge the installation chose,
              outside the declared columns on either side of them: it belongs to
              the framework, and the page's columns are the page's. -->
              @if (selectionEnabled() && selectionEdge === 'start') {
                <th scope="col" class="hilos-table-selection-cell">
                  <input
                    class="form-check-input"
                    type="checkbox"
                    aria-label="Select all rows on this page"
                    data-id="hilos-table-select-page"
                    [checked]="selectionHeader() === 'all'"
                    [indeterminate]="selectionHeader() === 'some'"
                    (change)="onSelectPage($event)"
                  />
                </th>
              }
              @for (column of rowColumns(); track column.key) {
                <th
                  scope="col"
                  [class]="column.headerClass ?? ''"
                  [attr.aria-sort]="ariaSort(column)"
                >
                  @if (column.sortable) {
                    <button
                      type="button"
                      class="btn btn-link p-0 text-reset text-decoration-none d-inline-flex align-items-center gap-1"
                      [attr.data-id]="'hilos-table-sort-' + column.key"
                      (click)="controller().setSort(column.key)"
                    >
                      {{ column.label }}
                      <i
                        [class]="'bi ' + sortIcon(column.key)"
                        aria-hidden="true"
                      ></i>
                      @if (sortPosition(column.key); as place) {
                        <sup aria-hidden="true">{{ place }}</sup>
                        <span class="visually-hidden">{{
                          sortPositionLabel(place)
                        }}</span>
                      }
                      @if (staleColumnKeys().has(column.key)) {
                        <i
                          class="bi bi-snow"
                          [attr.data-id]="
                            'hilos-table-stale-column-' + column.key
                          "
                          aria-hidden="true"
                        ></i>
                        <span class="visually-hidden">{{
                          staleColumnText(column)
                        }}</span>
                      }
                    </button>
                  } @else if (staleColumnKeys().has(column.key)) {
                    <span class="d-inline-flex align-items-center gap-1">
                      {{ column.label }}
                      @if (sortComponent(column.key) !== undefined) {
                        <i
                          [class]="'bi ' + sortIcon(column.key)"
                          aria-hidden="true"
                        ></i>
                      }
                      <i
                        class="bi bi-snow"
                        [attr.data-id]="
                          'hilos-table-stale-column-' + column.key
                        "
                        aria-hidden="true"
                      ></i>
                      <span class="visually-hidden">{{
                        staleColumnText(column)
                      }}</span>
                    </span>
                  } @else {
                    {{ column.label }}
                  }
                </th>
              }
              @if (markColumn()) {
                <th scope="col" class="text-end">
                  <span class="visually-hidden">Row state and controls</span>
                </th>
              }
              @if (selectionEnabled() && selectionEdge === 'end') {
                <th scope="col" class="hilos-table-selection-cell">
                  <input
                    class="form-check-input"
                    type="checkbox"
                    aria-label="Select all rows on this page"
                    data-id="hilos-table-select-page"
                    [checked]="selectionHeader() === 'all'"
                    [indeterminate]="selectionHeader() === 'some'"
                    (change)="onSelectPage($event)"
                  />
                </th>
              }
            </tr>
          </thead>
          @if (body() === 'loading') {
            <!-- The skeleton stands in a body of its own while a window is late:
            a cell for every column standing in the row, the framework's
            included, so the columns keep their widths instead of collapsing into
            one cell and jolting the table sideways on every change (Flow F3).
            The bars say nothing to a screen reader; the hidden line says it in
            words. -->
            <tbody aria-busy="true" data-id="hilos-table-loading">
              @for (index of skeletonRowIndexes(); track index) {
                <tr data-id="hilos-table-skeleton-row">
                  @for (cell of skeletonCellIndexes(); track cell) {
                    <td class="placeholder-glow">
                      @if (index === 0 && cell === 0) {
                        <span class="visually-hidden" role="status"
                          >Loading…</span
                        >
                      }
                      <span
                        class="placeholder col-12"
                        aria-hidden="true"
                      ></span>
                    </td>
                  }
                </tr>
              }
            </tbody>
          } @else {
            <tbody>
              @for (view of rows(); track view.rowKey) {
                <tr
                  [attr.data-id]="'hilos-table-row-' + view.rowKey"
                  [class]="rowClass(view)"
                >
                  <!-- A row drawn as a placeholder carries no checkbox: there is
                nothing to mark in the trace of a row that left, and the core would
                not take its key anyway (Flow F1). Its own cell spans the whole row,
                so the column is simply not there for it. -->
                  @if (
                    selectionEnabled() &&
                    selectionEdge === 'start' &&
                    !view.placeholder
                  ) {
                    <td class="hilos-table-selection-cell">
                      <input
                        class="form-check-input"
                        type="checkbox"
                        aria-label="Select row"
                        [attr.data-id]="'hilos-table-select-' + view.rowKey"
                        [checked]="view.selected"
                        (change)="onSelectRow(view.rowKey, $event)"
                      />
                    </td>
                  }
                  @if (view.placeholder) {
                    <td
                      [attr.colspan]="bodyColspan()"
                      class="text-center text-muted fst-italic"
                      data-id="hilos-table-placeholder"
                    >
                      <i
                        class="bi {{ placeholder(view.removal).icon }} me-1"
                        aria-hidden="true"
                      ></i>
                      {{ placeholder(view.removal).text }}
                    </td>
                  } @else if (declaration()) {
                    <!-- What stands where a row's values do, one shape per epoch of
                  the frame. A DECLARED table hands the page one template per
                  column and writes the cell around it: that is what makes a cell
                  addressable by column at all. The cell stands even where the page
                  marked no template, or the row comes out narrower than its header
                  (Flow F3). -->
                    @for (column of rowColumns(); track column.key) {
                      <td [class]="column.cellClass ?? ''">
                        @if (cellTemplate(column.key); as cell) {
                          <ng-container
                            [ngTemplateOutlet]="cell"
                            [ngTemplateOutletContext]="{
                              $implicit: view.row,
                              rowKey: view.rowKey,
                            }"
                          />
                        }
                      </td>
                    }
                  } @else if (row(); as rowTemplate) {
                    <!-- A table still drawing its frame from inputs has no column to
                  address a cell by, so it keeps handing over the whole row. -->
                    <ng-container
                      [ngTemplateOutlet]="rowTemplate"
                      [ngTemplateOutletContext]="{
                        $implicit: view.row,
                        rowKey: view.rowKey,
                      }"
                    />
                  }
                  @if (markColumn() && !view.placeholder) {
                    <td class="text-end text-nowrap">
                      <!-- The freshness mark comes first and the waiting badge
                      after it: a row can both wait and stand on values that are
                      behind, and neither statement stands in for the other. -->
                      @if (view.staleSources.length > 0) {
                        <i
                          class="bi bi-snow me-1"
                          [attr.data-id]="
                            'hilos-table-stale-row-' + view.rowKey
                          "
                          aria-hidden="true"
                        ></i>
                        <span class="visually-hidden">{{ rowMark }}</span>
                      }
                      @if (view.pending === 'move') {
                        <span
                          class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                          [attr.data-id]="
                            'hilos-table-pending-move-' + view.rowKey
                          "
                        >
                          <i class="bi bi-arrows-move" aria-hidden="true"></i>
                          Will move
                        </span>
                      } @else if (view.pending === 'remove') {
                        <span
                          class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                          [attr.data-id]="
                            'hilos-table-pending-remove-' + view.rowKey
                          "
                        >
                          <i
                            class="bi bi-box-arrow-right"
                            aria-hidden="true"
                          ></i>
                          Will leave
                        </span>
                      }
                      <!-- The control comes last and stands at the very edge: the
                    badge STATES something about the row, while this one is the
                    only thing in the cell the reader acts on. -->
                      @if (detailFields().length > 0) {
                        <button
                          type="button"
                          class="btn btn-sm btn-outline-secondary ms-1"
                          [attr.data-id]="'hilos-table-expand-' + view.rowKey"
                          [attr.aria-expanded]="view.expanded"
                          [attr.aria-controls]="detailId(view.rowKey)"
                          (click)="
                            controller().expandRow(view.rowKey, !view.expanded)
                          "
                        >
                          <i
                            class="bi"
                            [class.bi-chevron-up]="view.expanded"
                            [class.bi-chevron-down]="!view.expanded"
                            aria-hidden="true"
                          ></i>
                          <span class="visually-hidden">{{
                            view.expanded ? detailCopy.hide : detailCopy.show
                          }}</span>
                        </button>
                      }
                    </td>
                  }
                  @if (
                    selectionEnabled() &&
                    selectionEdge === 'end' &&
                    !view.placeholder
                  ) {
                    <td class="hilos-table-selection-cell">
                      <input
                        class="form-check-input"
                        type="checkbox"
                        aria-label="Select row"
                        [attr.data-id]="'hilos-table-select-' + view.rowKey"
                        [checked]="view.selected"
                        (change)="onSelectRow(view.rowKey, $event)"
                      />
                    </td>
                  }
                </tr>

                <!-- Work running over this one row, drawn right under it and only
              while the row is on screen: a key absent from the window takes up
              nothing and comes back with its row. A row drawn as a placeholder
              gets no bar under it even if the key is still in the map — the core
              takes a removed row's bar down at once, so that is a race rather
              than a normal state, and the condition here is the same one that
              draws the placeholder above. -->
                @if (!view.placeholder && rowBar(view.rowKey); as bar) {
                  <tr
                    [attr.data-id]="'hilos-table-progress-row-' + view.rowKey"
                  >
                    @for (cell of progressCells(); track $index) {
                      <td [attr.colspan]="cell.span" class="pt-0">
                        @if (cell.covered) {
                          @if (rowProgress(); as caption) {
                            <div class="small text-body-secondary mb-1">
                              <ng-container
                                [ngTemplateOutlet]="caption"
                                [ngTemplateOutletContext]="{
                                  $implicit: bar,
                                  rowKey: view.rowKey,
                                }"
                              />
                            </div>
                          }
                          <hilos-table-progress
                            [progress]="bar"
                            label="Work on this row"
                          />
                        }
                      </td>
                    }
                  </tr>
                }

                <!-- The panel this row expands into, drawn after the row's own bar:
              the bar is a continuation of the row it belongs to, and what the
              reader opened themselves comes after what is happening to the record
              on its own. A placeholder never says it is expanded, and the second
              half of the condition keeps the fields from being handed one. -->
                @if (view.expanded && !view.placeholder) {
                  <tr
                    [id]="detailId(view.rowKey)"
                    class="table-active"
                    [attr.data-id]="'hilos-table-row-detail-' + view.rowKey"
                  >
                    <td [attr.colspan]="bodyColspan()" class="pt-0">
                      <dl class="row row-cols-1 row-cols-md-3 g-2 mb-0 small">
                        @for (field of detailFields(); track field.key) {
                          <div class="col">
                            <dt class="text-body-secondary fw-normal">
                              {{ field.label }}
                            </dt>
                            <dd class="mb-0 text-break">
                              <!-- A field the page declared but drew nothing into
                            shows the dash its cells show, rather than an empty
                            line that would read as "there is no value". -->
                              @if (detailTemplate(field.key); as detail) {
                                <ng-container
                                  [ngTemplateOutlet]="detail"
                                  [ngTemplateOutletContext]="{
                                    $implicit: view.row,
                                    rowKey: view.rowKey,
                                  }"
                                />
                              } @else {
                                {{ detailCopy.empty }}
                              }
                            </dd>
                          </div>
                        }
                      </dl>
                    </td>
                  </tr>
                }
              }
              @if (body() !== 'rows') {
                <tr>
                  <td [attr.colspan]="bodyColspan()">
                    <hilos-table-empty-state
                      [controller]="controller()"
                      [kind]="emptyKind()"
                      [fallback]="emptyFallback"
                    />
                  </td>
                </tr>
              }
            </tbody>
          }
        </table>
      </div>

      <!-- The same rows as cards, the shape a table takes on a narrow screen: the
      framework builds each card out of the very columns the page declared, so no
      project writes a second markup for its table (mockup section 9). Both
      branches stand in the document at once and Bootstrap's visibility utilities
      show exactly one of them — there is no width at which both are seen, and
      crossing the boundary re-renders nothing (Flow F11). The price is that a
      row's data-id is in the document twice, which is why a test on a narrow
      screen aims at a row THROUGH this container (table-subscription.md, the
      registry of selectors). The cards are a list and carry the accessible name
      of the table itself: the same name rather than a second one, and never both
      at once — the branch that is hidden leaves the accessibility tree with its
      display (Flow F9). The list holds the cards and nothing else; what the table
      says in words when it has no rows stands BESIDE it, a sentence not being an
      item of a list. -->
      @if (card(); as layout) {
        <div class="d-md-none" data-id="hilos-table-cards">
          @if (body() === 'rows') {
            <div role="list" [attr.aria-labelledby]="nameId()">
              @for (view of rows(); track view.rowKey) {
                <div
                  class="card mb-2"
                  [class]="cardClass(view)"
                  role="listitem"
                  [attr.data-id]="'hilos-table-card-' + view.rowKey"
                >
                  <!-- A removed row keeps its place as a card of one line,
                  exactly as it keeps it as a row of one cell, until an empty
                  set makes the whole window converge (Flow F4). -->
                  @if (view.placeholder) {
                    <div
                      class="card-body py-2 px-3 text-center text-body-secondary fst-italic small"
                      data-id="hilos-table-placeholder"
                    >
                      <i
                        class="bi {{ placeholder(view.removal).icon }} me-1"
                        aria-hidden="true"
                      ></i>
                      {{ placeholder(view.removal).text }}
                    </div>
                  } @else {
                    <div class="card-body py-2 px-3">
                      <div class="d-flex align-items-start gap-2 mb-1">
                        <!-- The mark sits on the edge the installation chose, the
                        same edge it sits on in the row: one product disagreeing
                        with itself between its table and its card is the very
                        thing that choice forbids. -->
                        @if (selectionEnabled() && selectionEdge === 'start') {
                          <input
                            class="form-check-input mt-1"
                            type="checkbox"
                            aria-label="Select row"
                            [attr.data-id]="'hilos-table-select-' + view.rowKey"
                            [checked]="view.selected"
                            (change)="onSelectRow(view.rowKey, $event)"
                          />
                        }
                        @if (cellFor(layout.title); as title) {
                          <span class="fw-medium">
                            <ng-container
                              [ngTemplateOutlet]="title"
                              [ngTemplateOutletContext]="{
                                $implicit: view.row,
                                rowKey: view.rowKey,
                              }"
                            />
                          </span>
                        }
                        <!-- The right of the head, in one group: what the PAGE
                        says about the row first, then what the FRAMEWORK says
                        about it. The page's badge is not pushed out by a
                        framework mark — on a wide screen the two stand in two
                        different cells, and a card is a second projection of the
                        same columns rather than a smaller set of facts (Flow
                        F6). -->
                        <span class="ms-auto d-flex align-items-center gap-1">
                          @if (cellFor(layout.badge); as badge) {
                            <ng-container
                              [ngTemplateOutlet]="badge"
                              [ngTemplateOutletContext]="{
                                $implicit: view.row,
                                rowKey: view.rowKey,
                              }"
                            />
                          }
                          @if (view.staleSources.length > 0) {
                            <i
                              class="bi bi-snow"
                              [attr.data-id]="
                                'hilos-table-stale-row-' + view.rowKey
                              "
                              aria-hidden="true"
                            ></i>
                            <span class="visually-hidden">{{ rowMark }}</span>
                          }
                          @if (view.pending === 'move') {
                            <span
                              class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                              [attr.data-id]="
                                'hilos-table-pending-move-' + view.rowKey
                              "
                            >
                              <i
                                class="bi bi-arrows-move"
                                aria-hidden="true"
                              ></i>
                              Will move
                            </span>
                          } @else if (view.pending === 'remove') {
                            <span
                              class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                              [attr.data-id]="
                                'hilos-table-pending-remove-' + view.rowKey
                              "
                            >
                              <i
                                class="bi bi-box-arrow-right"
                                aria-hidden="true"
                              ></i>
                              Will leave
                            </span>
                          }
                          <!-- The control comes after everything that merely
                          STATES something about the record, exactly as it does at
                          the end of a row: it is the one thing in the head the
                          reader acts on. -->
                          @if (detailFields().length > 0) {
                            <button
                              type="button"
                              class="btn btn-sm btn-outline-secondary"
                              [attr.data-id]="
                                'hilos-table-expand-' + view.rowKey
                              "
                              [attr.aria-expanded]="view.expanded"
                              [attr.aria-controls]="cardDetailId(view.rowKey)"
                              (click)="
                                controller().expandRow(
                                  view.rowKey,
                                  !view.expanded
                                )
                              "
                            >
                              <i
                                class="bi"
                                [class.bi-chevron-up]="view.expanded"
                                [class.bi-chevron-down]="!view.expanded"
                                aria-hidden="true"
                              ></i>
                              <span class="visually-hidden">{{
                                view.expanded
                                  ? detailCopy.hide
                                  : detailCopy.show
                              }}</span>
                            </button>
                          }
                          @if (selectionEnabled() && selectionEdge === 'end') {
                            <input
                              class="form-check-input mt-1"
                              type="checkbox"
                              aria-label="Select row"
                              [attr.data-id]="
                                'hilos-table-select-' + view.rowKey
                              "
                              [checked]="view.selected"
                              (change)="onSelectRow(view.rowKey, $event)"
                            />
                          }
                        </span>
                      </div>

                      <!-- What a column becomes on a narrow screen is a pair of a
                      label and a value, and a description list is the one markup
                      that says so to a screen reader (Flow F10). -->
                      @if (cardFields().length > 0) {
                        <dl class="row mb-2 small g-0">
                          @for (field of cardFields(); track field.column.key) {
                            <dt class="col-5 fw-normal text-body-secondary">
                              {{ field.column.label }}
                            </dt>
                            <dd class="col-7 mb-0">
                              <ng-container
                                [ngTemplateOutlet]="field.template"
                                [ngTemplateOutletContext]="{
                                  $implicit: view.row,
                                  rowKey: view.rowKey,
                                }"
                              />
                            </dd>
                          }
                        </dl>
                      }

                      <!-- What the reader opened, going on with the very pairs of
                      label and value the fields above are: in a row the panel
                      comes last of all, under the bar of the row's own work, but in
                      a card the controls and that bar are the bottom block and the
                      panel belongs with the body. -->
                      @if (view.expanded) {
                        <div
                          [id]="cardDetailId(view.rowKey)"
                          class="mb-2"
                          [attr.data-id]="
                            'hilos-table-row-detail-' + view.rowKey
                          "
                        >
                          <dl class="row mb-0 small g-0">
                            @for (field of detailFields(); track field.key) {
                              <dt class="col-5 fw-normal text-body-secondary">
                                {{ field.label }}
                              </dt>
                              <dd class="col-7 mb-0 text-break">
                                <!-- A field the page declared but drew nothing
                                into shows the dash its cells show, rather than an
                                empty line that would read as "there is no
                                value". -->
                                @if (detailTemplate(field.key); as detail) {
                                  <ng-container
                                    [ngTemplateOutlet]="detail"
                                    [ngTemplateOutletContext]="{
                                      $implicit: view.row,
                                      rowKey: view.rowKey,
                                    }"
                                  />
                                } @else {
                                  {{ detailCopy.empty }}
                                }
                              </dd>
                            }
                          </dl>
                        </div>
                      }

                      <!-- The controls of the row, full width at the foot of the
                      card. Which of them comes first is the markup the page hands
                      over, and the framework neither reorders them nor takes one
                      away (Flow F2). -->
                      @if (cellFor(layout.actions); as actions) {
                        <div class="d-grid gap-2">
                          <ng-container
                            [ngTemplateOutlet]="actions"
                            [ngTemplateOutletContext]="{
                              $implicit: view.row,
                              rowKey: view.rowKey,
                            }"
                          />
                        </div>
                      }

                      <!-- Work running over this one record, at the very bottom of
                      the card and across its whole width: which columns a bar
                      stretches under says nothing here, a card having no columns
                      standing in a row (Flow F7). -->
                      @if (rowBar(view.rowKey); as bar) {
                        <div
                          class="mt-2"
                          [attr.data-id]="
                            'hilos-table-progress-row-' + view.rowKey
                          "
                        >
                          @if (rowProgress(); as caption) {
                            <div class="small text-body-secondary mb-1">
                              <ng-container
                                [ngTemplateOutlet]="caption"
                                [ngTemplateOutletContext]="{
                                  $implicit: bar,
                                  rowKey: view.rowKey,
                                }"
                              />
                            </div>
                          }
                          <hilos-table-progress
                            [progress]="bar"
                            label="Work on this row"
                          />
                        </div>
                      }
                    </div>
                  }
                </div>
              }
            </div>
          } @else if (body() === 'loading') {
            <!-- The skeleton and the four states a table says in words live
            inside the table in the wide branch, so a narrow screen would hide
            them along with it and the phone would be left with a blank space
            where they are (Flow F12). A card of the skeleton is one bar, as the
            mockup draws it. -->
            <div aria-busy="true" data-id="hilos-table-loading">
              <span class="visually-hidden" role="status">Loading…</span>
              @for (index of skeletonRowIndexes(); track index) {
                <div
                  class="card mb-2"
                  aria-hidden="true"
                  data-id="hilos-table-skeleton-row"
                >
                  <div class="card-body py-2 px-3 placeholder-glow">
                    <span class="placeholder col-12"></span>
                  </div>
                </div>
              }
            </div>
          } @else {
            <hilos-table-empty-state
              [controller]="controller()"
              [kind]="emptyKind()"
              [fallback]="emptyFallback"
            />
          }
        </div>
      }

      @if (declaration()) {
        <hilos-table-footer [controller]="controller()" />
      }

      <!-- The footer a table draws from its own comparisons, for the same tables
      as the bar above. -->
      @if (!declaration() && paginated()) {
        <div class="d-flex justify-content-between align-items-center mt-3">
          <span class="text-muted small" data-id="hilos-table-count">
            {{ countLabel() }}
          </span>
          <div class="btn-group" role="group" aria-label="Pagination">
            <button
              type="button"
              class="btn btn-outline-secondary btn-sm"
              [disabled]="!hasPreviousPage()"
              data-id="hilos-table-prev"
              (click)="controller().prevPage()"
            >
              Previous
            </button>
            @if (pageCount() !== null) {
              <span
                class="btn btn-sm disabled"
                [attr.aria-label]="
                  'Page ' + (page() + 1) + ' of ' + pageCount()
                "
                data-id="hilos-table-page"
              >
                {{ page() + 1 }} / {{ pageCount() }}
              </span>
            }
            <button
              type="button"
              class="btn btn-outline-secondary btn-sm"
              [disabled]="!hasNextPage()"
              data-id="hilos-table-next"
              (click)="controller().nextPage()"
            >
              Next
            </button>
          </div>
        </div>
      }

      <!-- The page's own words for the "nothing here yet" tile when it declared no
      empty state: its #empty template, or else the emptyText input. Handed to the
      tile as a template, because the tile stands in both branches at once. -->
      <ng-template #emptyFallback>
        @if (empty(); as emptyTemplate) {
          <ng-container [ngTemplateOutlet]="emptyTemplate" />
        } @else {
          {{ emptyText() }}
        }
      </ng-template>
    </div>
  `,
})
export class HilosViewportTable<R> {
  /** The headless server-windowed controller driving rows, descriptor, and pending. */
  readonly controller = input.required<TableViewportController<R>>()
  /**
   * Column declarations for the header (labels and sort controls) of a table whose
   * page declared no frame; a declared table takes its columns from the declaration
   * and is not handed these.
   */
  readonly columns = input<HilosTableColumn[]>([])
  /** Accessible name for the table, rendered as a visually-hidden caption. */
  readonly label = input<string>()
  /** Show the search box above the table. */
  readonly searchable = input(false)
  /** Placeholder for the search box. */
  readonly searchPlaceholder = input('Search…')
  /** Whether the search box owns focus when this table opens inside a modal. */
  readonly autofocusSearch = input(false)
  /** Message shown when there are no rows and the page declared no empty state. */
  readonly emptyText = input('No rows.')
  /**
   * The `data-id` the table's root carries, for a page that draws more than one of them:
   * the default is the shared handle, and a second table on the same page names itself so
   * the two can be told apart from outside.
   */
  readonly dataId = input('hilos-viewport-table')

  /** The icon and words for a removed-row placeholder. */
  protected placeholder(removal: TableRemovalReason | null): {
    readonly icon: string
    readonly text: string
  } {
    return hilosTablePlaceholder(removal)
  }

  /**
   * The cells of one row of a table whose page declared no frame:
   * `<ng-template #row let-row let-rowKey="rowKey">` filling the row with `<td>`s. A
   * declared table takes the marked cell templates instead and is not handed this.
   */
  protected readonly row =
    contentChild<TemplateRef<ViewportTableRowContext<R>>>('row')
  /**
   * The content of the cells of a declared table, one `<ng-template
   * hilosTableCell="<column key>">` per column. Collected through descendants,
   * because a page may wrap a template in an `@if` of its own.
   */
  protected readonly cells = contentChildren(HilosTableCell, {
    descendants: true,
  })
  // The marked templates by the key of their column — what a cell of a row and a
  // place of a card read to find their content.
  private readonly cellTemplates = computed(
    () =>
      new Map(
        this.cells().map((cell) => [cell.hilosTableCell(), cell.template]),
      ),
  )
  /**
   * The values of the fields of the panel a row expands into, one `<ng-template
   * hilosTableDetail="<column key>">` per field. Collected through descendants, as
   * the cells are, and read in both epochs of the frame.
   */
  protected readonly details = contentChildren(HilosTableDetail, {
    descendants: true,
  })
  // The marked templates by the key of their column — what a field of a panel reads
  // to find its value.
  private readonly detailTemplates = computed(
    () =>
      new Map(
        this.details().map((detail) => [
          detail.hilosTableDetail(),
          detail.template,
        ]),
      ),
  )
  protected readonly empty = contentChild<TemplateRef<unknown>>('empty')
  /**
   * The human name of one row a bulk run left untouched, for the report of the run:
   * `<ng-template #bulkUntouched let-rowKey let-reason="reason">`. The row's key is
   * printed where the page gives none.
   */
  protected readonly bulkUntouched =
    contentChild<TemplateRef<BulkUntouchedContext>>('bulkUntouched')
  /**
   * The project's own words about the work running on the table, drawn on the line
   * of the room of live messages above the rows: `<ng-template #tableProgress
   * let-progress>`.
   */
  protected readonly tableProgress =
    contentChild<TemplateRef<ViewportTableProgressContext>>('tableProgress')
  /**
   * The project's own control for that work, standing where a button stands:
   * `<ng-template #tableProgressAction let-progress>`.
   */
  protected readonly tableProgressAction = contentChild<
    TemplateRef<ViewportTableProgressContext>
  >('tableProgressAction')
  /**
   * The project's own words about the work running over one row, drawn above its
   * track: `<ng-template #rowProgress let-progress let-rowKey="rowKey">`. Where the
   * page gives none, the row of the bar carries the track alone.
   */
  protected readonly rowProgress =
    contentChild<TemplateRef<ViewportTableRowProgressContext>>('rowProgress')

  // The declaration does not change over the life of a table, so it is read off
  // the controller rather than mirrored as a signal (tableFrame.ts,
  // HilosTableFrameState).
  protected readonly declaration = computed(
    () => this.controller().frame.declaration,
  )
  // The columns the table is drawn from: the declaration's own where there is one,
  // and the input for a table whose page declared nothing. The header and every cell
  // spanning the whole row count this one list, so they cannot disagree on its width.
  protected readonly frameColumns = computed<readonly HilosTableColumn[]>(
    () => this.declaration()?.columns ?? this.columns(),
  )
  // The fields that wait in a panel instead of taking a column of their own, and the
  // columns that are left standing in the row. Every place that measures or draws the
  // row itself — the header, the width of a full-row cell, the cells of a row bar —
  // counts the second list, while the panel is built from the first.
  protected readonly detailFields = computed(() =>
    hilosTableDetailFields(this.frameColumns()),
  )
  protected readonly rowColumns = computed(() =>
    this.frameColumns().filter((column) => column.detail !== true),
  )
  // The words of the control and of an empty field — the core's, for the template.
  protected readonly detailCopy = TABLE_DETAIL_COPY
  // Which declared column takes which place of the card a row is drawn as on a
  // narrow screen — the head, the badge beside it, the labelled lines, the
  // controls. The core derived it from the declaration (tableCard.ts) and the view
  // has no arithmetic of its own about it; like the declaration it follows from, it
  // is a constant over the life of a table and null exactly when that is.
  protected readonly card = computed<HilosTableCard | null>(
    () => this.controller().frame.card,
  )
  // The labelled lines of a card, each with the template that fills it: the fields
  // of the layout the page actually marked a template for. A card leaves out the
  // place of a column the page drew nothing into — a label with nothing under it
  // reads as a value lost rather than as an empty field (Flow F3).
  protected readonly cardFields = computed(() => {
    const templates = this.cellTemplates()

    return (this.card()?.fields ?? []).flatMap((column) => {
      const template = templates.get(column.key)

      return template === undefined ? [] : [{ column, template }]
    })
  })
  // The marks, and the one sign that this table has them: a page that declared bulk
  // operations. There is no second sign — a table drawing its frame from inputs has
  // no declaration, so `enabled` is already false for it (Flow F14).
  protected readonly selectionEnabled = computed(
    () => this.controller().selection.enabled,
  )
  protected readonly selectionHeader = signal<HilosTableSelectionHeader>('none')
  // Which edge the checkbox column sits on — one choice for the whole installation
  // and not an input of this table, because two tables of one product disagreeing
  // about it is the very thing the rule forbids (Design D5). A project that
  // provided nothing gets the left edge, where lists usually keep it.
  protected readonly selectionEdge =
    inject(HILOS_TABLE_SELECTION_EDGE, { optional: true }) ?? 'start'
  // Which sources went quiet anywhere in the shown window, and which declared columns
  // are built from them — the columns whose headers and row cells carry the mark. The
  // sentence about them is the room of live messages' own (HilosTableLive).
  protected readonly staleSources = computed(() =>
    hilosTableStaleSources(this.rows()),
  )
  protected readonly staleColumnKeys = computed(
    () =>
      new Set(
        hilosTableStaleColumns(this.frameColumns(), this.staleSources()).map(
          (column) => column.key,
        ),
      ),
  )
  // The hidden words of the snowflake on a row — the core's, for the template.
  protected readonly rowMark = TABLE_STALENESS_COPY.rowMark
  // The row-state cell stands while anything waits OR while a shown row's values are
  // behind OR while the table declared a field to expand into: it carries all three,
  // and a table with no pending change still needs it the moment a source goes quiet
  // or a reader is given something to open. Header cell and body cell read this ONE
  // condition, so the two cannot drift apart into a row wider than its header.
  protected readonly markColumn = computed(
    () =>
      this.pendingCount() > 0 ||
      this.staleSources().size > 0 ||
      this.detailFields().length > 0,
  )
  // Every cell that spans the whole row — the placeholder of a removed row, the panel
  // a row expands into, the skeleton and the worded states — counts the columns left
  // standing in the row plus the mark column while it stands plus the checkbox column
  // while the table has marks. This is the ONE place the width is worked out, and
  // everything that spans a row reads it rather than counting again.
  protected readonly bodyColspan = computed(
    () =>
      this.rowColumns().length +
      (this.markColumn() ? 1 : 0) +
      (this.selectionEnabled() ? 1 : 0),
  )
  protected readonly titleId = `hilos-table-title-${viewportTableSeq++}`
  // The base every expanded row's panel takes its id from — minted the same way the
  // title above is, and for the same reason: the control that points at a panel and
  // the panel itself are drawn in two places of one template.
  protected readonly detailBaseId = `hilos-table-detail-${viewportTableSeq++}`
  // The base the panel inside a CARD takes its id from — a second one, minted for the
  // same table. An id is unique in a document and both branches stand in it at once,
  // so a card borrowing the row's id would put that id in twice and break the tie
  // between control and panel on both.
  protected readonly cardDetailBaseId = `hilos-table-card-detail-${viewportTableSeq++}`
  // What names a declared table: its own title when it declared one, and otherwise
  // the heading of the page it stands on, which already names it. Null for a table
  // that declared neither and stands outside an admin page — it has no name to take.
  private readonly pageHeadingId = inject(HILOS_PAGE_HEADING_ID, {
    optional: true,
  })
  protected readonly nameId = computed(() =>
    this.declaration()?.title ? this.titleId : this.pageHeadingId,
  )
  protected readonly rows = signal<readonly TableViewportRow<R>[]>([])
  protected readonly search = signal('')
  protected readonly order = signal<TableSortOrder | undefined>(undefined)
  protected readonly page = signal(0)
  protected readonly pageCount = signal<number | null>(1)
  protected readonly totalCount = signal(0)
  protected readonly totalExact = signal(true)
  protected readonly hasNextPage = signal(false)
  protected readonly hasPreviousPage = signal(false)
  protected readonly pendingCount = signal(0)
  // Which state the body is in — rows, the skeleton, or one of the four worded
  // states. The core decides it (tableFrame.ts, HilosTableBody) so that the three
  // view layers cannot decide it three ways, and both branches read this one
  // answer.
  protected readonly body = signal<HilosTableBody>('loading')
  // Which worded state the tile draws. The four that carry words pass through as they
  // are; 'loading' never reaches the tile — the skeleton stands in its place — but the
  // type has to be narrowed somewhere, and doing it here keeps both branches of the
  // table reading one answer instead of each spelling the narrowing out again.
  protected readonly emptyKind = computed<
    'empty' | 'empty_filtered' | 'empty_page' | 'unavailable'
  >(() => {
    const body = this.body()

    return body === 'empty_filtered' ||
      body === 'empty_page' ||
      body === 'unavailable'
      ? body
      : 'empty'
  })
  protected readonly pageSize = signal(0)
  // As many skeleton rows as the window had rows, so the height of the table does
  // not jump while the next one is on its way; a window that had none — a reset out
  // of "Nothing found" — is waiting for a full one (Flow F2).
  protected readonly skeletonRows = computed(() =>
    this.rows().length > 0 ? this.rows().length : this.pageSize(),
  )
  // The skeleton's rows and the cells of each as lists of indexes, because @for
  // walks a collection and not a number. The cells count bodyColspan, the one
  // width every full-row cell reads.
  protected readonly skeletonRowIndexes = computed(() =>
    Array.from({ length: this.skeletonRows() }, (_unused, index) => index),
  )
  protected readonly skeletonCellIndexes = computed(() =>
    Array.from({ length: this.bodyColspan() }, (_unused, index) => index),
  )
  // The row bars this view draws itself. The table bar and the bulk bar are drawn by
  // the room of live messages above the rows (HilosTableLive).
  protected readonly rowProgressBars = signal<
    ReadonlyMap<string, HilosTableProgressState>
  >(new Map())
  // A table whose count stopped at its ceiling has no page count to compare against, and
  // the footer is what such a table still needs: it is the only place saying there is more.
  protected readonly paginated = signal<boolean>(true)
  // The total reads as "at least this many" when the count stopped at its ceiling, which is
  // what the trailing plus says.
  protected readonly countLabel = computed(() =>
    this.totalExact()
      ? `${this.totalCount()} total`
      : `${this.totalCount()}+ total`,
  )

  // The cells of a row bar's row, in the order the columns are declared: runs of
  // marked columns merge into one covered cell, runs of unmarked ones into one
  // empty cell, and the waiting cell is added exactly while it stands over the
  // ordinary rows. No column marked means one covered cell across the whole row.
  // The checkbox column takes an empty cell of its own on whichever edge it sits.
  //
  // The spans add up to bodyColspan by construction, which is what keeps the row
  // from growing wider than its header the moment a waiting change appears.
  protected readonly progressCells = computed<readonly ProgressCell[]>(() => {
    const columns = this.rowColumns()
    const cells: ProgressCell[] = []
    for (const column of columns) {
      const covered = column.progress === true
      const last = cells[cells.length - 1]
      if (last !== undefined && last.covered === covered) {
        last.span += 1
      } else {
        cells.push({ span: 1, covered })
      }
    }
    if (!columns.some((column) => column.progress === true)) {
      cells.splice(0, cells.length, { span: columns.length, covered: true })
    }
    if (this.markColumn()) {
      cells.push({ span: 1, covered: false })
    }
    if (this.selectionEnabled()) {
      const selectionCell: ProgressCell = { span: 1, covered: false }
      if (this.selectionEdge === 'start') {
        cells.unshift(selectionCell)
      } else {
        cells.push(selectionCell)
      }
    }

    return cells
  })

  constructor() {
    // The controller arrives via input (not at construction) and carries core
    // signals, so mirror them into the Angular signals above once it is bound;
    // the cleanup drops the subscriptions if the controller is replaced.
    effect((onCleanup) => {
      const controller = this.controller()
      const bind = <T>(
        source: ReadonlySignal<T>,
        target: WritableSignal<T>,
      ): (() => void) => {
        target.set(source.get())

        return subscribeSignal(source, (value) => target.set(value))
      }
      const subscriptions = [
        bind(controller.rows, this.rows),
        bind(controller.search, this.search),
        bind(controller.order, this.order),
        bind(controller.page, this.page),
        bind(controller.pageCount, this.pageCount),
        bind(controller.paginated, this.paginated),
        bind(controller.totalCount, this.totalCount),
        bind(controller.totalExact, this.totalExact),
        bind(controller.hasNextPage, this.hasNextPage),
        bind(controller.hasPreviousPage, this.hasPreviousPage),
        bind(controller.pendingCount, this.pendingCount),
        bind(controller.frame.body, this.body),
        bind(controller.pageSize, this.pageSize),
        bind(controller.selection.header, this.selectionHeader),
        bind(controller.progress.rows, this.rowProgressBars),
      ]
      onCleanup(() => {
        for (const unsubscribe of subscriptions) {
          unsubscribe()
        }
      })
    })
  }

  // A row's tint, resolved in the order the mockup resolves it (section 4): amber
  // while a pending change waits on the row — a move and a removal alike — and green
  // for the couple of seconds after a value landed. Waiting outranks the highlight,
  // because it is the one of the two the reader still has to act on. Red belongs to a
  // refused write, not to waiting. Grey comes last of the three: amber and green
  // speak of something that happened to the row and the reader has yet to take in,
  // while grey says only that the reader opened this one themselves. Bootstrap's
  // contextual row classes carry their own dark-mode variants, so they adapt to the
  // active theme with no custom styles. An untinted row gets the empty string, which
  // is what the [class] binding takes.
  protected rowClass(view: TableViewportRow<R>): string {
    if (view.pending !== null) {
      return 'table-warning'
    }
    if (view.highlighted) {
      return 'table-success'
    }

    return view.expanded ? 'table-active' : ''
  }

  // The arrow a header carries: every column the order runs by gets one, because
  // an order of two columns is sorted by both of them and a single arrow would
  // name one of the two as the whole answer.
  protected sortComponent(key: string): TableSort | undefined {
    return this.order()?.find((component) => component.field === key)
  }

  // The template that fills one place of a card, or undefined where the layout has no
  // column for the place or the page marked no template for it. A place the page
  // drew nothing into is not drawn in the card at all — the row is the other way
  // round, its cell always stands, and that is the one place the two branches part
  // company (Flow F3).
  protected cellFor(
    column: HilosTableColumn | null,
  ): TemplateRef<HilosTableCellContext<unknown>> | undefined {
    return column === null ? undefined : this.cellTemplate(column.key)
  }

  // A card's tint, resolved in the order the row's is: amber while a change waits
  // on the record, green for the couple of seconds after a value landed, and
  // nothing otherwise. It is worn as a border rather than a fill — Bootstrap's
  // contextual row classes are built for the cells of a table (Flow F5).
  protected cardClass(view: TableViewportRow<R>): string {
    if (view.pending !== null) {
      return 'border-warning'
    }

    return view.highlighted ? 'border-success' : ''
  }

  // The template the page marked for one column's cell, or undefined where it marked
  // none.
  protected cellTemplate(
    key: string,
  ): TemplateRef<HilosTableCellContext<unknown>> | undefined {
    return this.cellTemplates().get(key)
  }

  // The template the page marked for one field of a panel, or undefined where it
  // marked none — which is what stands a dash in the field.
  protected detailTemplate(
    key: string,
  ): TemplateRef<HilosTableCellContext<unknown>> | undefined {
    return this.detailTemplates().get(key)
  }

  // The id of one row's panel, which the control above it points at through
  // aria-controls. One base for the whole table and the row key after it: the keys
  // are unique within a window, and two tables on one page mint two bases.
  protected detailId(rowKey: string): string {
    return `${this.detailBaseId}-${rowKey}`
  }

  /** The same for the panel inside the card of that row, off its own base. */
  protected cardDetailId(rowKey: string): string {
    return `${this.cardDetailBaseId}-${rowKey}`
  }

  // The bar running over one row, or undefined when none is. Read out of the map
  // by key rather than off the row's own projection, which is the tie the channel
  // exists to cut (tableProgress.ts): the bar outlives the window the row sits in.
  protected rowBar(rowKey: string): HilosTableProgressState | undefined {
    return this.rowProgressBars().get(rowKey)
  }

  protected sortIcon(key: string): string {
    const component = this.sortComponent(key)
    if (component === undefined) {
      return 'bi-arrow-down-up text-muted'
    }

    return component.direction === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down'
  }

  // The place a column takes in a composite order — null under an order of one
  // column, where the arrow already says everything. The arithmetic is the core's:
  // a view that counted the places itself would be a second answer to the question
  // the menu answers (tableSortOrder.ts).
  protected sortPosition(key: string): number | null {
    return hilosTableOrderPosition(this.order(), key)
  }

  protected sortPositionLabel(place: number): string {
    return hilosTableSortPositionLabel(place, this.order()?.length ?? 0)
  }

  // A sortable header reports its current sort state to assistive tech through
  // aria-sort: a sortable-but-unsorted column reports 'none', the active column
  // its direction, and a non-sortable column nothing at all.
  protected ariaSort(
    column: HilosTableColumn,
  ): 'ascending' | 'descending' | 'none' | undefined {
    if (!column.sortable) {
      return undefined
    }
    const component = this.sortComponent(column.key)
    if (component === undefined) {
      return 'none'
    }

    return component.direction === 'asc' ? 'ascending' : 'descending'
  }

  // The words a frozen header carries for a screen reader: the warning the sort
  // control carries, or — on a column that was never sortable — the plain statement
  // that its source is behind. The control stays and the warning rides inside it,
  // keeping HIL-809's argument about disabled controls as the reason the button is
  // not greyed: a disabled button drops out of the focus order, and a warning
  // hung on it would never be read to the one reader who needs it most.
  protected staleColumnText(column: HilosTableColumn): string {
    return column.sortable === true
      ? TABLE_STALENESS_COPY.sortWarning
      : TABLE_STALENESS_COPY.columnMark
  }

  protected onSearchInput(event: Event): void {
    this.controller().setSearch((event.target as HTMLInputElement).value)
  }

  // The checkbox tells the core the state it is now IN rather than asking it to
  // toggle: the state of a checkbox is what the reader sees, and a toggle sent from
  // a box the browser has already flipped is a second answer to one question
  // (Flow F1).
  protected onSelectRow(rowKey: string, event: Event): void {
    this.controller().selectRow(
      rowKey,
      (event.target as HTMLInputElement).checked,
    )
  }

  // One input for both directions: the header box goes to "all" out of none and out
  // of the half-marked state alike, and clears the window only from "all" — which is
  // what the core does with the state the box is now in (Flow F2).
  protected onSelectPage(event: Event): void {
    this.controller().selectWindow((event.target as HTMLInputElement).checked)
  }
}
