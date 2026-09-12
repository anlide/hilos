// What a page DECLARES about the bar above its table and the footer below it —
// its title, whether it searches, which filters it offers, its main action, its
// columns, its bulk operations, and what it says when it is empty. The framework
// owns the whole bar and the whole footer; the page owns the content of a cell
// and nothing around it (table-subscription.md, mockups/components/table
// section 2). Like hilosTableColumn.ts this file holds declaration types only —
// no window logic, which lives in the TableViewportController.

import { type ActionHandle } from '../connection/actionLifecycle.js'
import { type ReadonlySignal } from '../state/signal.js'
import { type HilosTableColumn } from './hilosTableColumn.js'
import { type HilosTableBulkAccepted } from './tableBulk.js'
import { type HilosTableCard } from './tableCard.js'
import { type HilosTableSelectionTarget } from './tableSelection.js'
import { type HilosTableOrderView } from './tableSortOrder.js'

/**
 * The search box of a table: present means the table searches, absent means it
 * does not. Search is an ordinary filter on the wire (the `search` key of the
 * filter map) and is declared apart from the other filters only because the bar
 * draws it apart from them — a field on the left rather than one of the row of
 * filter controls.
 */
export interface HilosTableSearch {
  /** Placeholder text of the search field. */
  readonly placeholder?: string
}

/** One option of a `select` filter: the value it writes and how it reads. */
export interface HilosTableFilterOption {
  /** Value written into the filter map when this option is picked. */
  readonly value: unknown
  /** Option text. */
  readonly label: string
}

/**
 * One filter a page declares for its table, in one of the three shapes the bar
 * draws: a dropdown, a date range, a toggle. There is no free builder of shapes,
 * for the reason there is none for composite orders — the framework owns the
 * place in the bar and the look, the page owns which filters are there.
 *
 * Every shape names the filter-map key(s) it writes, because that map is what
 * travels to the backend and becomes the query condition; no filter is ever
 * applied on the client.
 */
export type HilosTableFilter =
  | {
      readonly kind: 'select'
      /** Filter-map key this dropdown writes. */
      readonly key: string
      /** Control label in the bar. */
      readonly label: string
      /** Text of the "no choice" option; the view defaults it to "Any". */
      readonly anyLabel?: string
      /**
       * The options to offer, read fresh inside a computed signal: a list that
       * arrives in the page scope later — the channels of a delivery log — is
       * picked up on its own rather than frozen at declaration time.
       */
      readonly options: () => readonly HilosTableFilterOption[]
    }
  | {
      readonly kind: 'date_range'
      /** Filter-map key the lower bound is written to. */
      readonly fromKey: string
      /** Filter-map key the upper bound is written to. */
      readonly toKey: string
      /** Control label in the bar. */
      readonly label: string
    }
  | {
      readonly kind: 'toggle'
      /** Filter-map key this toggle writes. */
      readonly key: string
      /** Control label in the bar. */
      readonly label: string
      /** Value written into the filter map while the toggle is on; off clears the key. */
      readonly on: unknown
    }

/**
 * The main action of a table — the one button at the right of the bar, and the
 * same one the empty state offers. It is a declaration of a button and not a
 * mutation: the press opens the page's own modal, and how the work goes is shown
 * by the table's progress bar, neither of which the frame holds.
 */
export interface HilosTableMainAction {
  /** Button text. */
  readonly label: string
  /** What the press does — typically opening the page's modal. */
  readonly press: () => void
}

/**
 * One operation offered for the marked rows: its key, its label, whether it reads
 * as destructive, and what it runs.
 *
 * SCAFFOLD: no table declares a bulk operation until HIL-819.
 */
export interface HilosTableBulkAction {
  /** Operation id, unique within the table. */
  readonly key: string
  /** Menu text. */
  readonly label: string
  /** Whether the operation reads as destructive (default false). */
  readonly danger?: boolean
  /**
   * Send the operation over what is marked; the run is watched through the
   * table's bulk bar and report.
   */
  readonly run: (
    target: HilosTableSelectionTarget,
  ) => ActionHandle<HilosTableBulkAccepted>
}

/**
 * What the table says when the set it shows is empty and nothing is filtering
 * it — the one empty state that belongs to the project. "Nothing found", the row
 * skeleton, and a page that refuses altogether are the framework's own and are
 * not declared here.
 */
export interface HilosTableEmpty {
  /** Headline, e.g. "Nothing here yet". */
  readonly title: string
  /** Line under it, e.g. "Your first backup will show up here". */
  readonly hint?: string
}

/**
 * Everything a page declares about its table's frame. A page declares; the view
 * draws. Growing this declaration is how the whole product gets one bar rather
 * than one bar per page.
 */
export interface HilosTableFrame {
  /** Table title in the bar. */
  readonly title: string
  /** Line under the title. */
  readonly subtitle?: string
  /** The search box; absent means this table does not search. */
  readonly search?: HilosTableSearch
  /** The filters offered in the bar, in the order they are drawn. */
  readonly filters?: readonly HilosTableFilter[]
  /** The one button at the right of the bar, offered again by the empty state. */
  readonly mainAction?: HilosTableMainAction
  /** The columns of the table, in display order. */
  readonly columns: readonly HilosTableColumn[]
  /** The operations offered for the marked rows. */
  readonly bulkActions?: readonly HilosTableBulkAction[]
  /** What the table says when it is empty and nothing is filtering it. */
  readonly empty?: HilosTableEmpty
}

/**
 * One declared filter together with the value it currently holds — what a
 * control in the bar renders itself from.
 */
export interface HilosTableFilterView {
  /** The filter as the page declared it. */
  readonly filter: HilosTableFilter
  /**
   * The value in the filter map, or undefined when the filter holds none. A
   * `date_range` always reads as `{ from, to }`, each bound being the value of
   * its own key or undefined — the control is one, and it is drawn whole.
   */
  readonly value: unknown
  /** Whether the filter holds a value — what the "2 filters" badge counts. */
  readonly active: boolean
}

/**
 * What the footer of a table says: which rows of the set are on screen, how many
 * there are, and where the pager can go. The framework owns all of it — there is
 * nothing here for a page to declare — and the core hands over the numbers,
 * never the sentence built from them, which belongs to the view and its
 * language.
 */
export interface HilosTableFooter {
  /** 1-based number of the first row on screen; 0 while the window is empty. */
  readonly firstRow: number
  /** 1-based number of the last row on screen; 0 while the window is empty. */
  readonly lastRow: number
  /** Rows matching the filter, or the ceiling the count stopped at. */
  readonly totalCount: number
  /** Whether that total is the size of the set rather than the ceiling it stopped at. */
  readonly totalExact: boolean
  /** Current zero-based page index. */
  readonly page: number
  /** Number of pages, or null while the total is not exact and there is no last page. */
  readonly pageCount: number | null
  /** Whether there is a page before this one to go back to. */
  readonly hasPreviousPage: boolean
  /** Whether there is a page after this one to go to. */
  readonly hasNextPage: boolean
}

/**
 * Which of its states the body of a table is in — decided in the core so that
 * three view layers cannot decide it three ways:
 *
 * - `loading` — a window change has gone unanswered for longer than the
 *   controller's skeleton threshold (400 ms), or no window has arrived yet; the
 *   view draws the row skeleton. Until the threshold the rows of the previous
 *   window stay, so a quick answer draws no skeleton at all;
 * - `empty` — the set is empty and nothing is filtering it, so the page's own
 *   empty text and its main action are what to offer;
 * - `empty_filtered` — the set is empty under a search or a filter, so what to
 *   offer is "Nothing found" and a way to reset;
 * - `rows` — there are rows to draw.
 *
 * A page that refuses altogether is not one of these: that is the page's own
 * refusal (HilosRouter.pageError), not a state of its table.
 */
export type HilosTableBody = 'loading' | 'empty' | 'empty_filtered' | 'rows'

/**
 * The readable frame state a {@link HilosTableFrame} declaration turns into —
 * what a view renders the bar and the footer from.
 *
 * The unchanging parts of the declaration (title, subtitle, columns, main
 * action, bulk actions, empty state, search) are read off `declaration` and are
 * not wrapped in signals: they do not change over the life of a table, and a
 * signal would only make the view subscribe to a constant.
 */
export interface HilosTableFrameState {
  /** What the page declared, or null when it declared no frame. */
  readonly declaration: HilosTableFrame | null
  /**
   * The card a row projects to on a narrow screen, derived from the declared
   * columns. Null exactly when `declaration` is — and not wrapped in a signal for
   * the same reason the columns are not: it follows from the declaration alone.
   */
  readonly card: HilosTableCard | null
  /** The declared filters with their current values, in declaration order. */
  readonly filters: ReadonlySignal<readonly HilosTableFilterView[]>
  /**
   * How many declared filters hold a value — the badge in the bar. Search is not
   * one of them: it has its own field, and it is counted by neither the badge
   * nor this.
   */
  readonly activeFilterCount: ReadonlySignal<number>
  /**
   * The items of the "Order" menu: the way home first, then the orders the table
   * declared. Empty exactly when it declared none — and then there is no menu in
   * the bar at all, a single item being no choice to offer.
   */
  readonly orders: ReadonlySignal<readonly HilosTableOrderView[]>
  /**
   * The words of the order the window runs in, for the face of the menu button —
   * whoever set that order, a menu pick or a click on a header.
   *
   * Not the words of the active item: after a header click the window runs in an
   * order the menu does not offer, so there is no active item and this still has
   * something true to say.
   */
  readonly orderLabel: ReadonlySignal<string>
  /** What the footer says: the range on screen, the total, and where the pager can go. */
  readonly footer: ReadonlySignal<HilosTableFooter>
  /** Which state the body of the table is in. */
  readonly body: ReadonlySignal<HilosTableBody>
}
