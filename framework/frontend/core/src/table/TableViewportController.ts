// The headless SERVER-WINDOWED table controller (table-subscription.md). Unlike
// the client-side TableController — which filters/sorts/paginates all delivered
// rows locally — here the window comes from the backend: the view's intents
// (search / sort / paginate) change the viewport descriptor and are sent to the
// server, which replies a table_window snapshot the controller displays.
//
// Changes to the SHOWN rows arrive as table_viewport_delta, and what the gate holds
// is POSITION and MEMBERSHIP, not the fields of a record: a value that left the row
// where it stood (row_updated) lands at once and the row is highlighted for two
// seconds, while a move (row_moved) and a removal (row_removed) accumulate as
// PENDING so the table never rearranges under the user's hands. The user resolves
// those with apply() — a moved row takes the slot the server named and a removed one
// becomes a placeholder in its slot (the layout never collapses, no row is pulled
// from the next page). Two live signals bypass the pending gate
// because they disrupt nothing: table_viewport_count updates the total/page count
// (navigation metadata), and table_viewport_append adds a row at the tail when the
// window is the last page with room. A third accumulates without waiting on
// apply(): table_viewport_announce is word of a created row the window cannot show,
// and it is counted per place rather than queued — there is no row to apply, and
// the only way to see it is show(), which asks for the window again. An explicit
// window change discards pending and announced alike, since the new window the
// server returns is authoritative. Work in progress (table_progress) neither waits
// on apply() nor accumulates: a bar goes up, is replaced or comes down as the frames
// say, and a window change leaves it standing — it does not belong to the window.
// The controller owns no rendering and no DOM.

import {
  type TableAnchor,
  type TableAnchorDirection,
  type TableViewportDescriptor,
} from '../connection/HilosConnection.js'
import { type TableRow } from '../state/TableRowsStore.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
  type WritableSignal,
} from '../state/signal.js'
import {
  type HilosTableBulkReport,
  type HilosTableBulkState,
} from './tableBulk.js'
import { hilosTableCard } from './tableCard.js'
import {
  HILOS_TABLE_FACET_OPTION_LIMIT,
  type HilosTableBody,
  type HilosTableFacetCount,
  type HilosTableFacets,
  type HilosTableFilterView,
  type HilosTableFooter,
  type HilosTableFrame,
  type HilosTableFrameState,
} from './tableFrame.js'
import {
  type HilosTableProgress,
  type HilosTableProgressFrame,
  type HilosTableProgressState,
} from './tableProgress.js'
import {
  type HilosTableSelectionHeader,
  type HilosTableSelectionState,
  type HilosTableSelectionTarget,
} from './tableSelection.js'
import {
  hilosTableOrderLabel,
  hilosTableOrderViews,
  type HilosTableOrderView,
  type HilosTableSortOrder,
} from './tableSortOrder.js'
import { hilosTableStaleSources } from './tableStaleness.js'
/** Sort direction for the active sort field. */
export type SortDirection = 'asc' | 'desc'

/** One component of the active order: which field, which direction. */
export interface TableSort {
  readonly field: string
  readonly direction: SortDirection
}

/**
 * The order a window runs in: its components in the sequence they apply, the
 * first one deciding. An order of one component is a click on a column header;
 * an order of more is one the table declared, picked whole.
 */
export type TableSortOrder = readonly TableSort[]

/** Generic filter-map key the search box writes; matches the backend `FILTER_KEY_SEARCH`. */
const SEARCH_FILTER_KEY = 'search'

/**
 * How long a row stays marked as just changed, in milliseconds.
 *
 * The mark lives here and not in the three view packages because it is one behavior — a
 * value landed, say so briefly — and three copies of a countdown drift into three
 * different tables. The views only paint what this signal says (HIL-803, HIL-812).
 */
const HIGHLIGHT_MS = 2000

/**
 * How long a window change may go unanswered before the body turns into the row
 * skeleton, in milliseconds.
 *
 * Until then the rows of the previous window stay on screen: an answer on a near machine
 * lands in tens of milliseconds, and a skeleton drawn on every press of the pager, of a
 * header, of a filter would be a flash rather than a state. The number was named by the
 * owner (HIL-808); it lives in one place so that it can be tuned in one place.
 */
const WINDOW_SKELETON_MS = 400

/**
 * The empty freshness list every current row and every placeholder shares.
 *
 * One frozen array rather than a fresh `[]` per row: the window is rebuilt on every
 * signal read, and a new empty array each time would make a row look changed to any
 * view comparing by identity.
 */
const NO_STALE_SOURCES: readonly string[] = []

/**
 * Whether two orders are the same state of the table — the same fields in the
 * same directions in the same sequence. An absent order (a table opened without
 * an initial one) is a state of its own, equal only to another absent order.
 *
 * Exported because the "Order" menu decides its active item by the very same
 * question, and a second answer to it would light up an item the sort cycle
 * would then step past (tableSortOrder.ts).
 *
 * @param one The state to compare.
 * @param other The state to compare it with.
 * @returns True when both run by the same components, or both are absent.
 */
export function isSameOrder(
  one: TableSortOrder | undefined,
  other: TableSortOrder | undefined,
): boolean {
  if (one === undefined || other === undefined) {
    return one === other
  }

  return (
    one.length === other.length &&
    one.every(
      (component, index) =>
        component.field === other[index]?.field &&
        component.direction === other[index]?.direction,
    )
  )
}

/**
 * Turn one arriving bar into the bar a view reads, working out the fraction.
 *
 * The division is done here and not in the three views, for the reason the core sums the
 * announced rows: three views dividing the same two numbers each in its own way are three
 * different bars on one screen. A missing or non-positive total leaves no fraction at all —
 * that is the indeterminate bar, not a zero one — and a `current` outside the total is clamped
 * rather than thrown away, a bar with a wrong number still having to say that work is running.
 *
 * @param frame The bar as it arrived, live or with the window.
 * @returns The bar as a view reads it.
 */
function toTableProgress(frame: HilosTableProgressFrame): HilosTableProgress {
  const total = frame.total ?? null

  return {
    progressKey: frame.progressKey,
    current: frame.current,
    total,
    fraction:
      total === null || total <= 0
        ? null
        : Math.min(1, Math.max(0, frame.current / total)),
    detail: frame.detail ?? {},
  }
}

/**
 * One live pending row change scoped to the connection's window, normalized from
 * a `table_viewport_delta` signal (the row already reduced to references):
 *
 * - `row_updated` — a shown row's content changed and its place did not; carries
 *   the new row, and applies at once because nothing moved;
 * - `row_moved` — an edit moves the shown row inside the window; carries the new
 *   row and the slot it lands in, absent when the table could not name one;
 * - `row_removed` — a shown row was deleted, left the set, or moved past an edge
 *   of the window; carries the reason;
 * - `row_stale` — which of a shown row's sources stopped being kept up to date;
 *   carries the new list and nothing else.
 *
 * Count and append changes are live, not pending, and arrive through their own
 * sink methods ({@link TableWindowSink.ingestCount} / `ingestAppend`).
 */
export type TableViewportDelta =
  | {
      readonly kind: 'row_updated'
      readonly rowKey: string
      readonly row: TableRow
      /** The backend tagged this receiver as the change's author: apply it now, resolving any queued pending. */
      readonly own?: boolean
    }
  | {
      readonly kind: 'row_moved'
      readonly rowKey: string
      readonly row: TableRow
      /** Zero-based slot the row lands in, absent when the table could not name one. */
      readonly position?: number
      /** The backend tagged this receiver as the change's author: apply it now, resolving any queued pending. */
      readonly own?: boolean
    }
  | {
      readonly kind: 'row_removed'
      readonly rowKey: string
      readonly reason: string
      /** The backend tagged this receiver as the change's author: apply it now, resolving any queued pending. */
      readonly own?: boolean
    }
  | {
      readonly kind: 'row_stale'
      readonly rowKey: string
      /** The row's slot keys that stopped being kept up to date; empty means current again. */
      readonly staleSources: readonly string[]
    }

/**
 * A delta that carries a change to a row rather than to its freshness — the three
 * kinds the own door applies at once, and the two the gate holds.
 */
type LiveViewportDelta = Extract<
  TableViewportDelta,
  { kind: 'row_updated' | 'row_moved' | 'row_removed' }
>

/** The place of an announced row against the window: the two a window cannot show. */
export type TableAnnouncePlacement = 'above' | 'inside'

/**
 * How many created rows this window has been told about and cannot show, by place.
 *
 * `total` is summed here rather than by each view because three views adding two
 * numbers is three chances to add them differently, and the strip that reads it is
 * drawn once per framework.
 */
export interface TableViewportAnnounced {
  readonly above: number
  readonly inside: number
  readonly total: number
}

/** A displayed row: its key, the resolved view-model, and whether it is a removed placeholder. */
export interface TableViewportRow<R> {
  readonly rowKey: string
  /** The resolved view-model, or `null` when the row is a removed placeholder. */
  readonly row: R | null
  /** True when an applied removal replaced the row with a placeholder in its slot. */
  readonly placeholder: boolean
  /** The kind of unapplied pending change waiting on this row, or null when none. */
  readonly pending: 'move' | 'remove' | null
  /** True for the couple of seconds after the row took a new value or its new slot. */
  readonly highlighted: boolean
  /**
   * True when this row is marked for a bulk action. The view draws the checkbox
   * off the same row it draws the cells off; there is no second source for
   * whether a row is marked, and a placeholder is never one.
   */
  readonly selected: boolean
  /**
   * True when the reader has opened this row's panel of details. Always a boolean
   * rather than an optional field, for the reason `selected` next to it is: the views
   * read it on every row they draw. A placeholder is never expanded — there are no
   * values under it to show.
   */
  readonly expanded: boolean
  /**
   * The row slots whose values stopped being kept up to date, empty when the row is
   * current. Always a list rather than an optional field: the views read it on every
   * row they draw, and an optional one would make each of them write `?? []` of its
   * own. A placeholder carries the empty list — it has no values whose freshness
   * could be spoken of.
   */
  readonly staleSources: readonly string[]
}

/**
 * The counts beside a table's filter options as a `table_facet_counts` frame
 * carries them: filter by filter, `any` plus one count per option keyed by the
 * option value as text. A frame names only the filters whose counts moved.
 */
export type TableFacetCountsByFilter = Readonly<
  Record<
    string,
    {
      readonly any: HilosTableFacetCount
      readonly options: Readonly<Record<string, HilosTableFacetCount>>
    }
  >
>

/**
 * The sink the subscription wiring feeds a table's window snapshot and live
 * changes into — implemented by {@link TableViewportController} and held untyped
 * by the subscription wiring, since nothing on it depends on the row type `R`.
 * A pending row delta gates on apply(); a count update and a tail append are live.
 * It also answers the one question asked of a table rather than told to it — which
 * window it is holding — because the wiring is what registers the table with the
 * connection that reports its window on the next page subscribe.
 */
export interface TableWindowSink {
  ingestWindow(
    rows: readonly TableRow[],
    totalCount: number,
    totalExact: boolean,
    firstAnchor: TableAnchor | null,
    lastAnchor: TableAnchor | null,
    limit: number,
  ): void
  ingestSubscriptionWindow(
    rows: readonly TableRow[],
    totalCount: number,
    totalExact: boolean,
    firstAnchor: TableAnchor | null,
    lastAnchor: TableAnchor | null,
    limit: number,
    sort: TableSortOrder | undefined,
    progress: readonly HilosTableProgressFrame[],
  ): void
  ingestDelta(delta: TableViewportDelta): void
  ingestProgress(frame: HilosTableProgressFrame): void
  ingestBulkReport(report: HilosTableBulkReport): void
  descriptor(): TableViewportDescriptor | null
  ingestCount(totalCount: number, totalExact: boolean): void
  ingestFacetCounts(facets: TableFacetCountsByFilter): void
  ingestAppend(row: TableRow, totalCount: number, totalExact: boolean): void
  ingestOwnCreate(
    row: TableRow,
    position: number,
    totalCount: number,
    totalExact: boolean,
    requestId?: string | null,
  ): void
  ingestAnnounce(
    rowKey: string,
    placement: TableAnnouncePlacement,
    totalCount: number,
    totalExact: boolean,
  ): void
}

export interface TableViewportControllerOptions<R> {
  /**
   * Resolve one window row into its view-model. Called inside a computed signal,
   * so reading entity references through a scope selector makes the resolved rows
   * track entity updates reactively (the window rows already hold `EntityRef`s).
   */
  resolve: (row: TableRow) => R
  /**
   * Send the current viewport descriptor to the backend — typically
   * `HilosConnection.sendTableViewport` bound to this table's page and key. The
   * server answers with a `table_window` the controller ingests.
   */
  sendViewport: (descriptor: TableViewportDescriptor) => void
  /**
   * Send the options of this table's dropdown filters whose counts it wants —
   * typically `HilosConnection.sendTableFacets` bound to this table's page and
   * key. Called once the first window has landed, when there is anything to
   * count, and again whenever the options change after that; the server then
   * sends the counts by itself.
   *
   * SCAFFOLD: no table passes it yet — the counts are drawn in the filter bar,
   * and the pages move onto the declared frame in HIL-819.
   */
  sendFacets?: (facets: Readonly<Record<string, readonly unknown[]>>) => void
  /** Initial filter map; empty by default. */
  initialFilter?: Record<string, unknown>
  /**
   * Orders of more than one column this table declares, in the sequence the
   * "Order" menu offers them. The backend holds a composite order against the
   * very same list, so an order absent from here is one no window will be
   * served in.
   *
   * Every order carries the key it is picked by — the same slug the backend
   * declares it under, and the one the menu item's `data-id` is built from.
   * Declaring none leaves the table without a menu at all: one item, "the way it
   * opened", is no choice to offer.
   */
  declaredOrders?: readonly HilosTableSortOrder[]
  /**
   * What this table's page declares about its frame — title, search, filters,
   * main action, columns, bulk actions, empty state. Optional because a table
   * that declares nothing still has a frame state to read: the footer and the
   * body follow from the window, not from the declaration.
   *
   * SCAFFOLD: no table declares one yet, and no view reads it — the bar and the
   * footer drawn from it are HIL-801 (Vue) and HIL-810 (React, Angular), and the
   * five framework pages move onto it in HIL-819. Until then a view keeps taking
   * its columns, label, and empty text as props.
   */
  frame?: HilosTableFrame
}

export class TableViewportController<R> implements TableWindowSink {
  private readonly filterSignal: WritableSignal<Record<string, unknown>>

  /** Counts beside the options of the dropdown filters, by filter key, laid over one frame at a time. */
  private readonly facetCountsSignal = createSignal<
    Readonly<Record<string, HilosTableFacets>>
  >({})

  /** Option values of the dropdown filters short enough to count, by filter key: what is declared to the server. */
  private readonly facetOptions: ReadonlySignal<
    Readonly<Record<string, readonly unknown[]>>
  >

  private readonly orderSignal: WritableSignal<TableSortOrder | undefined>

  private readonly pageSignal = createSignal(0)

  /** Place the window is asked from, or null for the edge {@link anchorDirection} points away from. */
  private anchor: TableAnchor | null = null

  /** Side of the anchor the window is asked from. */
  private anchorDirection: TableAnchorDirection = 'after'

  /** Page a jump asks for, or null while the window is paged by anchor. */
  private pageIndex: number | null = null

  /** Place the first row of the delivered window sits at, or null while it is empty. */
  private firstAnchor: TableAnchor | null = null

  /** Place the last row of the delivered window sits at, or null while it is empty. */
  private lastAnchor: TableAnchor | null = null

  private readonly windowSignal = createSignal<readonly TableRow[]>([])

  private readonly totalCountSignal = createSignal(0)

  /** Whether the total is the size of the set rather than the ceiling the count stopped at. */
  private readonly totalExactSignal = createSignal(true)

  /** False until the first window arrives — lets the view tell "loading" from "empty". */
  private readonly loadedSignal = createSignal(false)

  private readonly placeholderKeysSignal = createSignal<ReadonlySet<string>>(
    new Set(),
  )

  /**
   * The row keys the reader marked, held RAW — keys that left the window are not
   * swept out of it, and nothing here is ever shown directly.
   *
   * What is shown as marked is this set intersected with the live rows of the
   * window, which is what makes every "a row that left drops out of the marks"
   * case fall out of one rule instead of five: a live removal, an applied pending
   * removal, an own echo and a row pushed past the edge by an own insert all end
   * the same way, and the counter falls by itself because it counts that same
   * intersection.
   */
  private readonly selectedKeysSignal = createSignal<ReadonlySet<string>>(
    new Set(),
  )

  /**
   * The row keys whose panel of details the reader opened, held the same way the
   * marks next door are and narrowed at the same three places.
   *
   * It lives here rather than in the view because the view has no event for a window
   * changing — it only ever sees a new list of rows — and three views each guessing
   * when to close the panels would be three different rules. Here there is one:
   * whatever changes the window closes them, and nothing else does.
   *
   * There is no ceiling on how many rows are open at once: a reader opens two records
   * precisely to read them side by side.
   */
  private readonly expandedKeysSignal = createSignal<ReadonlySet<string>>(
    new Set(),
  )

  /**
   * Whether the choice is the filter CONDITION rather than a list of keys — the
   * second kind of selection, entered by its own button and never by paging.
   */
  private readonly allByFilterSignal = createSignal(false)

  private readonly pendingCountSignal = createSignal(0)

  /** Counts of the rows announced to this window, by place; rebuilt from the two sets below. */
  private readonly announcedSignal = createSignal<TableViewportAnnounced>({
    above: 0,
    inside: 0,
    total: 0,
  })

  /** The bar of work running on the table as a whole, or null when none is. */
  private readonly tableProgressSignal =
    createSignal<HilosTableProgress | null>(null)

  /** The bar of a bulk action running over the marked rows, or null when none is. */
  private readonly bulkProgressSignal = createSignal<HilosTableProgress | null>(
    null,
  )

  /** The bars of work running over single rows, by row key. */
  private readonly rowProgressSignal = createSignal<
    ReadonlyMap<string, HilosTableProgress>
  >(new Map())

  /** How the last bulk run on this table ended, or null while none has. */
  private readonly bulkReportSignal = createSignal<HilosTableBulkReport | null>(
    null,
  )

  /** Request id of the last own-create ingested, or null when it was not tracked. */
  private readonly ownCreateRequestIdSignal = createSignal<string | null>(null)

  private readonly searchSignal: ReadonlySignal<string>

  /**
   * Window size, as the last window that arrived said it was.
   *
   * The size is declared by the table on the backend and travels on every window, so the
   * controller reads it rather than holding an opinion of its own: two declarations of one
   * quantity is how a footer comes to count rows a window never had. It is a signal because
   * the page count and the footer are computed from it.
   */
  private readonly pageSizeSignal = createSignal(1)

  /**
   * The order the table opened in, as the first window that arrived said it was.
   *
   * "Home" for the sort cycle and for a reset — which used to be a client-side option and is
   * now whatever the backend declared. Read off the first window rather than off every one:
   * later windows carry the order the reader chose, and coming home to that would leave no
   * way home at all.
   */
  private openingOrder: TableSortOrder | undefined = undefined

  /** Whether {@link openingOrder} has been read off a window yet. */
  private openingOrderKnown = false

  /**
   * Pending moves by row key — the new row and the slot it lands in, applied on apply().
   *
   * A move with no slot carries the row alone: the server could name no place, so apply()
   * puts the values where the row already stands rather than at an index nobody computed.
   */
  private readonly pendingMoves = new Map<
    string,
    { readonly row: TableRow; readonly position?: number }
  >()

  /** Pending removals by row key (value is the reason) — become placeholders on apply(). */
  private readonly pendingRemoved = new Map<string, string>()

  /**
   * Keys of the rows announced above this window, and of those announced inside it.
   *
   * Keys rather than a running number, because the same row can be announced twice — the
   * frame is sent per foreign write and nothing recalls one — and a person told twice about
   * one row would be told wrong. A key lands in one set only: the place it was first
   * announced at is the place it keeps until a window arrives and settles everything.
   */
  private readonly announcedAbove = new Set<string>()

  private readonly announcedInside = new Set<string>()

  /** Per-row pending kind ('move' | 'remove') driving the row marking; rebuilt on every pending change. */
  private readonly pendingKindSignal = createSignal<
    ReadonlyMap<string, 'move' | 'remove'>
  >(new Map())

  /** Row keys marked as just changed; the views paint them and the timers below clear them. */
  private readonly highlightedKeysSignal = createSignal<ReadonlySet<string>>(
    new Set(),
  )

  /** The running countdown of each highlighted row, so a re-highlight restarts one timer, not two. */
  private readonly highlightTimers = new Map<
    string,
    ReturnType<typeof setTimeout>
  >()

  /**
   * Whether a window change has gone unanswered for longer than {@link WINDOW_SKELETON_MS} —
   * what turns the body into the skeleton after the first window has arrived.
   *
   * {@link loadedSignal} cannot say it: it goes true once and never back, and the first
   * window arrives with the page itself (HIL-642), so "no window yet" is a state a reader
   * practically never sees. The skeleton is for a window CHANGE, and this is its sign.
   */
  private readonly windowPendingSignal = createSignal(false)

  /** The countdown to {@link windowPendingSignal}, or null while no window change is unanswered. */
  private windowSkeletonTimer: ReturnType<typeof setTimeout> | null = null

  /** The displayed rows resolved to view-models — what the view renders. */
  readonly rows: ReadonlySignal<readonly TableViewportRow<R>[]>

  /** Total rows matching the filter, as the last applied window/change reported. */
  readonly totalCount: ReadonlySignal<number>

  /**
   * Whether {@link totalCount} is the size of the set rather than the ceiling it stopped at.
   *
   * The backend counts a windowed query only up to a ceiling, so past it the total is that
   * ceiling and reads as "at least this many". Everything a reader gets from the total —
   * the page count, the numbered pages, the end of the set — follows from this too.
   */
  readonly totalExact: ReadonlySignal<boolean>

  /** Number of pages under the window size; at least 1, and null while the total is not exact. */
  readonly pageCount: ReadonlySignal<number | null>

  /**
   * Whether there is a page after this one to go to.
   *
   * With an exact total this is the page number against the page count. Without one there
   * is no last page to compare against, so the answer is read off the window itself: a
   * window filled to its size has rows behind it, and a short one is the end of the set.
   */
  readonly hasNextPage: ReadonlySignal<boolean>

  /** Count of accumulated pending changes (the badge); 0 when there is nothing to apply. */
  readonly pendingCount: ReadonlySignal<number>

  /**
   * Counts of the created rows this window has been told about and cannot show.
   *
   * The two places are kept apart because they are two different things to say — a row
   * above the window is one an earlier page now holds, a row inside it is one between rows
   * on the screen — and what the strip says about each is the view's to decide.
   */
  readonly announced: ReadonlySignal<TableViewportAnnounced>

  /** False until the first window has been ingested — the view shows "loading" rather than "empty". */
  readonly loaded: ReadonlySignal<boolean>

  /** The readable frame state, built once from the declaration and the window signals. */
  private readonly frameState: HilosTableFrameState

  /** The readable selection state, built once over the window signals the same way. */
  private readonly selectionState: HilosTableSelectionState

  /** The readable progress state: the three signals above, under the names a view reads. */
  private readonly progressState: HilosTableProgressState

  /** The readable bulk state: the report signal above, under the name a view reads. */
  private readonly bulkState: HilosTableBulkState

  constructor(private readonly options: TableViewportControllerOptions<R>) {
    this.filterSignal = createSignal<Record<string, unknown>>({
      ...(options.initialFilter ?? {}),
    })
    this.orderSignal = createSignal<TableSortOrder | undefined>(undefined)
    this.searchSignal = computedSignal(() => {
      const value = this.filterSignal.get()[SEARCH_FILTER_KEY]

      return typeof value === 'string' ? value : ''
    })
    this.rows = computedSignal(() => {
      const placeholders = this.placeholderKeysSignal.get()
      const pendingKinds = this.pendingKindSignal.get()
      const highlighted = this.highlightedKeysSignal.get()
      const selectedKeys = this.selectedKeysSignal.get()
      const expandedKeys = this.expandedKeysSignal.get()
      const allByFilter = this.allByFilterSignal.get()

      return this.windowSignal.get().map((raw) => {
        const placeholder = placeholders.has(raw.rowKey)

        return {
          rowKey: raw.rowKey,
          row: placeholder ? null : options.resolve(raw),
          placeholder,
          pending: placeholder ? null : (pendingKinds.get(raw.rowKey) ?? null),
          highlighted: !placeholder && highlighted.has(raw.rowKey),
          selected:
            !placeholder && (allByFilter || selectedKeys.has(raw.rowKey)),
          expanded: !placeholder && expandedKeys.has(raw.rowKey),
          staleSources: placeholder
            ? NO_STALE_SOURCES
            : (raw.staleSources ?? NO_STALE_SOURCES),
        }
      })
    })
    this.totalCount = this.totalCountSignal
    this.totalExact = this.totalExactSignal
    this.pageCount = computedSignal(() =>
      this.totalExactSignal.get()
        ? Math.max(
            1,
            Math.ceil(this.totalCountSignal.get() / this.pageSizeSignal.get()),
          )
        : null,
    )
    this.hasNextPage = computedSignal(() => {
      const pageCount = this.pageCount.get()

      return pageCount === null
        ? this.windowSignal.get().length >= this.pageSizeSignal.get()
        : this.pageSignal.get() < pageCount - 1
    })
    this.pendingCount = this.pendingCountSignal
    this.announced = this.announcedSignal
    this.loaded = this.loadedSignal
    const declaration = options.frame ?? null
    const filterViews = computedSignal<readonly HilosTableFilterView[]>(() => {
      const filter = this.filterSignal.get()
      const counts = this.facetCountsSignal.get()

      return (declaration?.filters ?? []).map((declared) => {
        if (declared.kind === 'date_range') {
          const from = filter[declared.fromKey]
          const to = filter[declared.toKey]

          return {
            filter: declared,
            value: { from, to },
            active: from !== undefined || to !== undefined,
            facets: null,
          }
        }

        return {
          filter: declared,
          value: filter[declared.key],
          active: filter[declared.key] !== undefined,
          facets:
            declared.kind === 'select' ? (counts[declared.key] ?? null) : null,
        }
      })
    })
    const activeFilterCount = computedSignal(
      () => filterViews.get().filter((view) => view.active).length,
    )
    this.facetOptions = computedSignal(() => {
      const declared: Record<string, readonly unknown[]> = {}
      for (const filter of declaration?.filters ?? []) {
        if (filter.kind !== 'select') {
          continue
        }
        const offered = filter.options()
        if (offered.length <= HILOS_TABLE_FACET_OPTION_LIMIT) {
          declared[filter.key] = offered.map((option) => option.value)
        }
      }

      return declared
    })
    const sendFacets = options.sendFacets
    if (sendFacets !== undefined) {
      // Declared once the first window has landed and not before: that window came over a
      // connected socket and the server holds it, while a declaration sent at construction
      // would be dropped by a socket that is still connecting, and nothing would send it
      // again. The flag goes true once and never back, so this fires once.
      subscribeSignal(this.loadedSignal, () => {
        const declared = this.facetOptions.get()
        if (Object.keys(declared).length > 0) {
          sendFacets(declared)
        }
      })
      // After that every change of the options is declared, the empty declaration
      // included, which is how the server drops a list. A change before the first window
      // needs no frame of its own: the options are read fresh when that window lands.
      subscribeSignal(this.facetOptions, (declared) => {
        if (this.loadedSignal.get()) {
          sendFacets(declared)
        }
      })
    }
    this.frameState = {
      declaration,
      card: declaration ? hilosTableCard(declaration.columns) : null,
      filters: filterViews,
      activeFilterCount,
      orders: computedSignal<readonly HilosTableOrderView[]>(() =>
        hilosTableOrderViews(
          this.orders,
          this.openingOrder,
          this.orderSignal.get(),
          declaration?.columns ?? [],
          hilosTableStaleSources(this.rows.get()),
        ),
      ),
      orderLabel: computedSignal(() =>
        hilosTableOrderLabel(
          this.orderSignal.get(),
          declaration?.columns ?? [],
        ),
      ),
      footer: computedSignal<HilosTableFooter>(() => {
        const shown = this.windowSignal.get().length
        const page = this.pageSignal.get()
        const firstRow = shown === 0 ? 0 : page * this.pageSizeSignal.get() + 1

        return {
          firstRow,
          lastRow: shown === 0 ? 0 : firstRow + shown - 1,
          totalCount: this.totalCountSignal.get(),
          totalExact: this.totalExactSignal.get(),
          page,
          pageCount: this.pageCount.get(),
          hasPreviousPage: page > 0,
          hasNextPage: this.hasNextPage.get(),
        }
      }),
      body: computedSignal<HilosTableBody>(() => {
        if (this.windowPendingSignal.get() || !this.loadedSignal.get()) {
          return 'loading'
        }
        if (this.windowSignal.get().length > 0) {
          return 'rows'
        }

        return this.searchSignal.get() !== '' || activeFilterCount.get() > 0
          ? 'empty_filtered'
          : 'empty'
      }),
    }
    // A table has marks exactly when its page declared bulk operations: one sign,
    // the one that already exists. A second one ("this table is selectable") would
    // drift from it and give a table with no operations a column leading nowhere.
    const selectionEnabled = (declaration?.bulkActions?.length ?? 0) > 0
    const selectedRows = computedSignal(() =>
      this.rows.get().filter((row) => row.selected),
    )
    this.selectionState = {
      enabled: selectionEnabled,
      target: computedSignal<HilosTableSelectionTarget | null>(() => {
        if (!selectionEnabled) {
          return null
        }
        if (this.allByFilterSignal.get()) {
          return { kind: 'filter', filter: { ...this.filterSignal.get() } }
        }
        const rowKeys = selectedRows.get().map((row) => row.rowKey)

        return rowKeys.length === 0 ? null : { kind: 'rows', rowKeys }
      }),
      count: computedSignal(() => selectedRows.get().length),
      header: computedSignal<HilosTableSelectionHeader>(() => {
        const live = this.rows.get().filter((row) => !row.placeholder).length
        const marked = selectedRows.get().length
        if (live === 0 || marked === 0) {
          return 'none'
        }

        return marked === live ? 'all' : 'some'
      }),
    }
    // Handed over as they are, with no computed in between: unlike the frame and the
    // selection, a bar is not derived from the window — it IS what arrived, and the
    // arithmetic on it was done when it was taken in.
    this.progressState = {
      table: this.tableProgressSignal,
      bulk: this.bulkProgressSignal,
      rows: this.rowProgressSignal,
    }
    this.bulkState = { report: this.bulkReportSignal }
  }

  /** The current search query (empty string when unset). */
  get search(): ReadonlySignal<string> {
    return this.searchSignal
  }

  /** The active order, or `undefined` when unsorted. */
  get order(): ReadonlySignal<TableSortOrder | undefined> {
    return this.orderSignal
  }

  /**
   * The orders of more than one column this table declares, in menu sequence —
   * each with the key it is picked by. What the menu DRAWS is
   * {@link frameState}'s `orders`; this is the declaration itself, which a view
   * reads to answer a pick with the components behind the key.
   */
  get orders(): readonly HilosTableSortOrder[] {
    return this.options.declaredOrders ?? []
  }

  /**
   * The frame state a view renders the bar and the footer from: what the page
   * declared, and the parts that follow from the window. A table that declared
   * no frame still has one to read — `declaration` is then null.
   *
   * SCAFFOLD: read by the bar and the footer, which are HIL-801 (Vue) and
   * HIL-810 (React, Angular); its `card` is read by the card a row projects to on
   * a narrow screen, which is HIL-806 (Vue) and HIL-815 (React, Angular).
   */
  get frame(): HilosTableFrameState {
    return this.frameState
  }

  /**
   * The marks a view draws its selection panel and its checkboxes from, and a
   * bulk action reads to learn what it runs over. A table whose page declared no
   * bulk operations still has this to read — `enabled` is then false, the four
   * inputs stay silent, and the state reads empty.
   *
   * SCAFFOLD: the Vue panel, its checkbox column and the send over what is marked
   * read it; React and Angular follow in HIL-810 and HIL-813, and no table declares
   * bulk operations until HIL-819.
   */
  get selection(): HilosTableSelectionState {
    return this.selectionState
  }

  /**
   * The work running on this table, in the three places it can be drawn.
   *
   * A bar DOES NOT BELONG TO THE WINDOW: paging, filtering and re-sorting leave every one of
   * these standing, unlike the marks, because the work goes on whichever page is being looked
   * at. A row bar whose key is not in the current window is simply not drawn, and it comes
   * back with its row without the server having to say anything again. The one thing that
   * replaces the lot is the snapshot a subscription answer carries, which is the server naming
   * the whole truth at once.
   *
   * SCAFFOLD: read by the bars themselves, which are HIL-805 (Vue) and HIL-814 (React,
   * Angular). The backup's run moves onto this channel in HIL-820.
   */
  get progress(): HilosTableProgressState {
    return this.progressState
  }

  /**
   * How the last bulk run on this table ended: how many rows it changed, and every
   * row it did not, by name.
   *
   * It is NOT the reply to the action. The reply said the run was accepted — a run
   * over a condition outlives the client's action timeout — and this is what arrived
   * when the work was actually over.
   *
   * Like a bar, it does not belong to the window: paging, filtering and re-sorting
   * leave it standing, because it is about the work and not about what is on screen.
   * It stands until the next run on this table begins, which is the one thing that
   * clears it.
   *
   * SCAFFOLD: the Vue selection panel reads it; React and Angular follow in
   * HIL-813, and no table declares bulk operations until HIL-819.
   */
  get bulk(): HilosTableBulkState {
    return this.bulkState
  }

  /** The current zero-based page index. */
  get page(): ReadonlySignal<number> {
    return this.pageSignal
  }

  /**
   * The window size, as the last window that arrived said it was.
   *
   * What a view counts the skeleton rows by when the previous window was empty — a reader
   * who reset the filters out of "Nothing found" is waiting for a full window, and a
   * skeleton of zero rows would say nothing is coming.
   */
  get pageSize(): ReadonlySignal<number> {
    return this.pageSizeSignal
  }

  /**
   * The action behind the last of this receiver's own creates to land in the
   * window, or null when none has or the write was not tracked. A surface reads it
   * to recognize the result of a press it is still showing as in flight.
   */
  get ownCreateRequestId(): ReadonlySignal<string | null> {
    return this.ownCreateRequestIdSignal
  }

  /**
   * Ask the backend for this table's window again, unchanged.
   *
   * Not how a table opens — the first window arrives with the page's own answer since
   * HIL-642 — but how a page refreshes one that nothing else would refresh: an action whose
   * result the table cannot learn about live, such as a retry on the delivery journal, which
   * has no deltas of its own. A table on a live source never needs this.
   */
  refresh(): void {
    this.send()
  }

  /**
   * Show what the window has been told about but cannot show: ask for the window again.
   *
   * The press behind the announcement strip, and deliberately nothing of its own — this is
   * the same window change a filter, an order or a page turn makes, at the same address. So
   * the reader keeps the place it was standing at, and what comes back is exactly what a
   * reload would have given: pending, placeholders, highlights, marks and the announcements
   * themselves all go, and the server's answer is the whole truth again.
   */
  show(): void {
    this.changeWindow()
  }

  /**
   * Set the search filter and return to the first page, discard pending (the new
   * window is authoritative), then request the new window. An empty query drops
   * the search filter entirely.
   *
   * @param query The raw search text.
   */
  setSearch(query: string): void {
    const filter = { ...this.filterSignal.get() }
    if (query.trim() === '') {
      delete filter[SEARCH_FILTER_KEY]
    } else {
      filter[SEARCH_FILTER_KEY] = query
    }
    this.filterSignal.set(filter)
    this.resetAddress()
    this.changeWindow()
  }

  /**
   * Set one domain filter-map entry and return to the first page, discard pending
   * (the new window is authoritative), then request the new window. A null /
   * undefined / empty-string value clears the entry, so a "no filter" option maps
   * to dropping the key rather than sending an empty value the backend must
   * special-case. The free-text search box has its own {@link setSearch}; this
   * drives the domain filters a page renders as its own controls (channel, status,
   * period, …), which ride the same open filter map to the backend query. It is
   * the one-entry case of {@link setFilters}, so the clearing rule lives in one
   * place.
   *
   * @param key The filter-map key (matches the backend TableQueryDTO filter key).
   * @param value The new value, or null/undefined/'' to clear the key.
   */
  setFilter(key: string, value: unknown): void {
    this.setFilters({ [key]: value })
  }

  /**
   * Set SEVERAL domain filter-map entries in one window change, then behave
   * exactly as {@link setFilter} does — the clearing rule is the same and lives
   * here, and the entries not named are left as they are.
   *
   * One control over two keys is what needs this: a date range writes both of
   * its bounds, and setting them one at a time would send two windows and show a
   * window filtered by a start with no end in between.
   *
   * @param values The filter-map entries to write; a null/undefined/'' value clears its key.
   */
  setFilters(values: Record<string, unknown>): void {
    const filter = { ...this.filterSignal.get() }
    for (const [key, value] of Object.entries(values)) {
      if (value === null || value === undefined || value === '') {
        delete filter[key]
      } else {
        filter[key] = value
      }
    }
    this.filterSignal.set(filter)
    this.resetAddress()
    this.changeWindow()
  }

  /**
   * Return the filters to the ones the table opened with and request that
   * window — what the "Reset filters" control of the "Nothing found" state does.
   *
   * The table opens with `initialFilter`, and that is what a reset comes back
   * to rather than an empty map: a route preset arrives that way, so resetting
   * to empty would turn the log of one channel into the log of all of them. The
   * search box is cleared along with the filters, being an entry of the very
   * same map — and "Nothing found" names the query and the filters together.
   */
  resetFilters(): void {
    this.filterSignal.set({ ...(this.options.initialFilter ?? {}) })
    this.resetAddress()
    this.changeWindow()
  }

  /**
   * Sort by a field — a cycle of three states on the clicked field: ascending,
   * descending, then the order the table opened with (no initial order means the
   * backend's own order). Which state comes next is read from the state on
   * display, not from a click count: a state already on display is skipped, so
   * the column the table opened sorted by has no dead click, and clicking any
   * other column starts its cycle at ascending. Return to the first page, then
   * request the new window.
   *
   * A click always leaves an order of one column, so it is also how a composite
   * order is left: the mockup gives the header that job, and a click that added
   * a column to the order would be the free builder the backend refuses to serve.
   *
   * @param field The field key to sort by.
   */
  setSort(field: string): void {
    const current = this.orderSignal.get()
    const cycle: readonly (TableSortOrder | undefined)[] = [
      [{ field, direction: 'asc' }],
      [{ field, direction: 'desc' }],
      this.openingOrder,
    ]
    const shown = cycle.findIndex((state) => isSameOrder(state, current))
    const next = cycle.findIndex(
      (state, position) => position > shown && !isSameOrder(state, current),
    )
    const state = next < 0 ? cycle[0] : cycle[next]
    if (isSameOrder(state, this.openingOrder)) {
      // The cycle came home, and coming home is one operation however it was
      // asked for — through the last click of the cycle here, or through the
      // reset a page offers on its own.
      this.resetOrder()

      return
    }
    this.orderSignal.set(state)
    this.resetAddress()
    this.changeWindow()
  }

  /**
   * Run the window in one of the orders the table declares. The order is taken
   * whole — the reader picks it from the list rather than assembling it — and the
   * backend holds it against that same list before any of it reaches a query.
   * Return to the first page, then request the new window.
   *
   * SCAFFOLD: called by the "Order" menu, which is HIL-802 and HIL-811.
   *
   * @param order The order to run the window in.
   */
  setOrder(order: TableSortOrder): void {
    this.orderSignal.set(order)
    this.resetAddress()
    this.changeWindow()
  }

  /**
   * Return to the order the table opened with — for a table without an initial
   * one that is no order at all, the backend's own — return to the first page,
   * then request the new window. The last click of a column's cycle arrives
   * here; a page is free to call it from a reset control of its own.
   */
  resetOrder(): void {
    this.orderSignal.set(this.openingOrder)
    this.resetAddress()
    this.changeWindow()
  }

  /**
   * Jump to a zero-based page, clamped into `[0, pageCount - 1]`, then request that
   * page's window. This is the one address an anchor cannot express: page seven has no
   * anchor until somebody has shown it, so the server is asked for it by number and
   * counts to it from whichever end of the set is nearer.
   *
   * Use {@link nextPage} and {@link prevPage} for the neighbours — their boundary is
   * already in hand, and asking for them by number would count rows for nothing.
   *
   * Does nothing while the total is not exact: a page number is a place counted from an
   * end of the set, and without the size of the set there is no such place to jump to.
   *
   * @param page The requested zero-based page index.
   */
  setPage(page: number): void {
    const pageCount = this.pageCount.get()
    if (pageCount === null) {
      return
    }
    const target = Math.min(Math.max(0, page), Math.max(0, pageCount - 1))
    this.pageSignal.set(target)
    this.anchor = null
    this.anchorDirection = 'after'
    this.pageIndex = target
    this.changeWindow()
  }

  /**
   * Go to the next page — the rows after the window's last one — then request it. Does
   * nothing on the last page, where there is nothing after the window to ask for, and
   * nothing on an empty window: with no boundary in hand there is nothing to page from,
   * and moving the number anyway would show one page while asking for another.
   *
   * Whether there is a next page is {@link hasNextPage}, which is also what the view
   * disables the control by — one rule, read in one place.
   */
  nextPage(): void {
    if (this.lastAnchor === null || !this.hasNextPage.get()) {
      return
    }
    this.anchor = this.lastAnchor
    this.anchorDirection = 'after'
    this.pageIndex = null
    this.pageSignal.set(this.pageSignal.get() + 1)
    this.changeWindow()
  }

  /**
   * Go to the previous page — the rows before the window's first one — then request it.
   * Does nothing on the first page, where there is nothing before the window to ask for,
   * and nothing on an empty window, for the reason {@link nextPage} gives.
   */
  prevPage(): void {
    if (this.firstAnchor === null || this.pageSignal.get() <= 0) {
      return
    }
    this.anchor = this.firstAnchor
    this.anchorDirection = 'before'
    this.pageIndex = null
    this.pageSignal.set(this.pageSignal.get() - 1)
    this.changeWindow()
  }

  /**
   * Ingest a window snapshot from the backend (`table_window`): replace the
   * displayed rows and the total count, and drop any leftover pending, placeholders
   * and marks — the fresh window is authoritative. Called by the subscription
   * wiring; the rows are already normalized to references.
   *
   * The marks are put out here and not only where a window is asked for, because a
   * window also arrives where nobody asked: a tab coming back after a broken socket
   * gets its windows served back to it. A countdown started before that break would
   * be pointing at a row of the window that replaced the one it was started for.
   *
   * A window is also the only thing that makes an inexact total exact again: it is the one
   * moment the set is read, so a window that stopped at the ceiling last time may well come
   * back with a number this time, and the other way round.
   *
   * @param rows The window's rows, in display order.
   * @param totalCount Total rows matching the filter.
   * @param totalExact Whether that total is the size of the set rather than the ceiling it stopped at.
   * @param firstAnchor Place the first row sits at, or null when the window is empty.
   * @param lastAnchor Place the last row sits at, or null when the window is empty.
   * @param limit How many rows the window carries — the size the backend served it at.
   */
  ingestWindow(
    rows: readonly TableRow[],
    totalCount: number,
    totalExact: boolean,
    firstAnchor: TableAnchor | null,
    lastAnchor: TableAnchor | null,
    limit: number,
  ): void {
    this.windowSignal.set(rows.slice())
    this.totalCountSignal.set(Math.max(0, totalCount))
    this.totalExactSignal.set(totalExact)
    this.firstAnchor = firstAnchor
    this.lastAnchor = lastAnchor
    this.pageSizeSignal.set(Math.max(1, Math.trunc(limit)))
    this.placeholderKeysSignal.set(new Set())
    this.loadedSignal.set(true)
    this.clearWindowPending()
    this.clearHighlights()
    this.clearPending()
    this.clearAnnounced()
    // A window also arrives where nobody changed one — a refresh a page asked for,
    // a re-subscribe after a broken socket — and there the marks stay: the raw keys
    // are narrowed to the rows that came, and the condition is untouched, being about
    // the filter, which did not move. A window CHANGE has already cleared both.
    const arrived = new Set(rows.map((row) => row.rowKey))
    this.selectedKeysSignal.set(
      new Set(
        [...this.selectedKeysSignal.get()].filter((key) => arrived.has(key)),
      ),
    )
    this.expandedKeysSignal.set(
      new Set(
        [...this.expandedKeysSignal.get()].filter((key) => arrived.has(key)),
      ),
    )
  }

  /**
   * Ingest the window that arrived with the page's own subscription answer (HIL-642).
   *
   * The same window as any other, and one thing besides: it says which order it ran in. This
   * is the one frame that has to — a reply to a request echoes back an order the reader
   * chose, while a cold entry runs in the order the table declares on the backend, and
   * nothing on this side would otherwise know what that is. The first one to arrive also
   * settles where a reset of the order goes home to.
   *
   * It also carries the work running on the table, and that list REPLACES what has been
   * collected, an empty one clearing it. This is the only cure for a bar left standing by a
   * socket that broke mid-run: while there was no connection the end could not arrive, and
   * resubscribing is the moment the server names the truth in full. A window that merely
   * changes ({@link ingestWindow}) leaves the bars alone — a window has nothing to say about
   * them.
   *
   * @param rows The window's rows, in display order.
   * @param totalCount Total rows matching the filter.
   * @param totalExact Whether that total is the size of the set rather than the ceiling it stopped at.
   * @param firstAnchor Place the first row sits at, or null when the window is empty.
   * @param lastAnchor Place the last row sits at, or null when the window is empty.
   * @param limit How many rows the window carries — the size the backend served it at.
   * @param sort The order the window ran in, or undefined when it ran in none.
   * @param progress The work running on the table, as the answer names it in full.
   */
  ingestSubscriptionWindow(
    rows: readonly TableRow[],
    totalCount: number,
    totalExact: boolean,
    firstAnchor: TableAnchor | null,
    lastAnchor: TableAnchor | null,
    limit: number,
    sort: TableSortOrder | undefined,
    progress: readonly HilosTableProgressFrame[],
  ): void {
    this.replaceProgress(progress)
    if (!this.openingOrderKnown) {
      // Where the sort cycle and a reset come home to: the order the table opened in, which
      // is the backend's declaration and never a later choice of the reader's.
      //
      // Taken BEFORE the order itself is published: this is a plain field, not a signal, so
      // nothing recomputes when it lands, and the menu built off the order would otherwise
      // word its way home from the moment before the table knew where home was.
      this.openingOrder = sort
      this.openingOrderKnown = true
    }
    this.orderSignal.set(sort)
    this.ingestWindow(
      rows,
      totalCount,
      totalExact,
      firstAnchor,
      lastAnchor,
      limit,
    )
  }

  /**
   * Ingest the counts beside the options of this table's dropdown filters
   * (`table_facet_counts`), laid over the ones held filter by filter: a frame names
   * only the filters whose counts moved, and changing one filter moves the counts of
   * every other filter while its own stay where they were. Like the total, counts
   * describe the set rather than a row, so they are never gated as pending.
   *
   * @param facets The counts the frame carried, by filter key.
   */
  ingestFacetCounts(facets: TableFacetCountsByFilter): void {
    const held = { ...this.facetCountsSignal.get() }
    for (const [filterKey, facet] of Object.entries(facets)) {
      held[filterKey] = {
        any: { count: facet.any.count, exact: facet.any.exact },
        options: new Map(
          Object.entries(facet.options).map(([option, count]) => [
            option,
            { count: count.count, exact: count.exact },
          ]),
        ),
      }
    }
    this.facetCountsSignal.set(held)
  }

  /**
   * Ingest a live count update (`table_viewport_count`): set the total — and thus
   * the page count — at once. The count is navigation metadata, not row content,
   * so it is never gated as pending; the pager reflects the real total at once,
   * even while a removed row still shows as a placeholder.
   *
   * @param totalCount Total rows matching the filter.
   * @param totalExact Whether that total is the size of the set rather than the ceiling it stopped at.
   */
  ingestCount(totalCount: number, totalExact: boolean): void {
    this.totalCountSignal.set(Math.max(0, totalCount))
    this.totalExactSignal.set(totalExact)
  }

  /**
   * Ingest word of a created row this window cannot show
   * (`table_viewport_announce`): count it under its place and take the counts.
   *
   * The counts are taken the way {@link ingestCount} takes them, because that is what
   * they are — the announcement carries the same total shift the count would have. What
   * is new is the key: a row already announced under either place is counted once, so a
   * repeat of the frame moves nothing.
   *
   * Nothing is shown by this. The row has no body here and never enters the window; the
   * only way to see it is {@link show}, which asks the server for the window again.
   *
   * @param rowKey Key of the created row the window cannot show.
   * @param placement Where the row falls against the window — above it or inside it.
   * @param totalCount Total rows matching the filter.
   * @param totalExact Whether that total is the size of the set rather than the ceiling it stopped at.
   */
  ingestAnnounce(
    rowKey: string,
    placement: TableAnnouncePlacement,
    totalCount: number,
    totalExact: boolean,
  ): void {
    this.ingestCount(totalCount, totalExact)
    if (this.announcedAbove.has(rowKey) || this.announcedInside.has(rowKey)) {
      return
    }
    if (placement === 'above') {
      this.announcedAbove.add(rowKey)
    } else {
      this.announcedInside.add(rowKey)
    }
    this.refreshAnnouncedSignal()
  }

  /**
   * Ingest one bar of work (`table_progress`): put it up, move it, or take it down.
   *
   * A place holds one bar, and the place is the pair (scope, row key). A frame naming a
   * different `progressKey` REPLACES what stands there — another run has started — while one
   * that says `ended` takes the bar down only when the key matches the bar standing. That
   * asymmetry is the whole of it: without it a late end of the previous run would clear the
   * bar of the run that has just begun, and a bar would vanish over live work — the same break
   * this channel exists to fix, only mirrored.
   *
   * An `ended` for a place that holds nothing does nothing. There is no bar to take down, and
   * minting an empty one to clear would put a flicker on the screen for a run that is over.
   *
   * @param frame The bar as it arrived.
   */
  ingestProgress(frame: HilosTableProgressFrame): void {
    if (frame.scope === 'row') {
      const { rowKey } = frame
      if (rowKey === undefined) {
        // Not a second rule: a row bar without its row is already dropped where frames are
        // taken in (bindTableViewport.ts). This is what tells the type system so.
        return
      }
      if (frame.ended === true) {
        if (
          this.rowProgressSignal.get().get(rowKey)?.progressKey ===
          frame.progressKey
        ) {
          this.dropRowProgress(rowKey)
        }

        return
      }
      this.rowProgressSignal.set(
        new Map(this.rowProgressSignal.get()).set(
          rowKey,
          toTableProgress(frame),
        ),
      )

      return
    }

    const place: WritableSignal<HilosTableProgress | null> =
      frame.scope === 'table'
        ? this.tableProgressSignal
        : this.bulkProgressSignal
    if (frame.ended === true) {
      if (place.get()?.progressKey === frame.progressKey) {
        place.set(null)
      }

      return
    }
    if (
      frame.scope === 'bulk' &&
      this.bulkReportSignal.get()?.progressKey !== frame.progressKey
    ) {
      // A new run is what clears the report of the last one, and nothing else does.
      // Clearing it when the bar of the SAME run moves would erase a report that
      // arrived first, which is the order the server sends the two in.
      this.bulkReportSignal.set(null)
    }
    place.set(toTableProgress(frame))
  }

  /**
   * Ingest the report a bulk run ended with (`table_bulk_report`).
   *
   * It replaces whatever report was standing, because one table shows the outcome of
   * the run that just ended and not a history of them. It is NOT matched against the
   * bar: the report arrives BEFORE the bar comes down, deliberately, so that the panel
   * is never left with neither, and a report checked against a bar that is still up
   * would be the same ordering read backwards.
   *
   * @param report The outcome as it arrived.
   */
  ingestBulkReport(report: HilosTableBulkReport): void {
    this.bulkReportSignal.set(report)
  }

  /**
   * Ingest a live tail append (`table_viewport_append`): add the new row at the
   * END of the window and set the total. Sent only when this window is the last
   * page with room, so the row fits without pushing any shown row out — there is
   * nothing to gate and it applies at once. The row is already normalized to refs.
   *
   * @param row The new row to append, in reference form.
   * @param totalCount Total rows matching the filter.
   * @param totalExact Whether that total is the size of the set rather than the ceiling it stopped at.
   */
  ingestAppend(row: TableRow, totalCount: number, totalExact: boolean): void {
    this.windowSignal.set([...this.windowSignal.get(), row])
    this.totalCountSignal.set(Math.max(0, totalCount))
    this.totalExactSignal.set(totalExact)
  }

  /**
   * Ingest the receiver's own new row (`table_viewport_own_create`): insert it at
   * the index the server computed and set the total. Sent only to the connection
   * that created the row, and only when the row lands inside this window, so it
   * applies at once — the author is looking at the result of its own press and has
   * nothing to gate against.
   *
   * The window keeps its size: an insert pushes the last row past the end, exactly
   * as a reload would, and that row leaves with whatever was queued against it -
   * a pending change nobody can apply any more, or a placeholder standing where a
   * row used to be. The row is already normalized to refs.
   *
   * The arriving key is cleared of whatever the window still holds under it, the
   * same way {@link applyOwnDelta} clears an own update. A create may reuse the key
   * of a row deleted in this very window: the server has forgotten that row and
   * sends the create, while here its tombstone is still standing — placeholders
   * live until the next window — and without this the key would sit in the window
   * twice, the fresh row rendering as the removed one.
   *
   * `requestId` names the action behind the row and is kept on
   * {@link ownCreateRequestId}, so a surface can tell which of its own presses this
   * row answers — an ack only ever said "accepted", and for a backup the record
   * arrives minutes later. The window itself does not read it.
   *
   * @param row The new row to insert, in reference form.
   * @param position Zero-based index the row takes in the window.
   * @param totalCount Total rows matching the filter.
   * @param totalExact Whether that total is the size of the set rather than the ceiling it stopped at.
   * @param requestId Request id of the action that created the row, when it was tracked.
   */
  ingestOwnCreate(
    row: TableRow,
    position: number,
    totalCount: number,
    totalExact: boolean,
    requestId?: string | null,
  ): void {
    this.ownCreateRequestIdSignal.set(requestId ?? null)
    const rows = this.windowSignal
      .get()
      .filter((shown) => shown.rowKey !== row.rowKey)
    rows.splice(
      Math.min(Math.max(0, Math.trunc(position)), rows.length),
      0,
      row,
    )
    const evicted = rows.splice(this.pageSizeSignal.get())
    this.windowSignal.set(rows)
    this.totalCountSignal.set(Math.max(0, totalCount))
    this.totalExactSignal.set(totalExact)

    const placeholders = new Set(this.placeholderKeysSignal.get())
    // The mark and the open panel go with everything else held under these keys, and
    // for the same reason: the row pushed past the edge is gone, and a key coming back
    // as a fresh row must not arrive already marked or already open — nobody touched
    // THIS row.
    const selected = new Set(this.selectedKeysSignal.get())
    const expanded = new Set(this.expandedKeysSignal.get())
    for (const gone of [...evicted, row]) {
      this.pendingMoves.delete(gone.rowKey)
      this.pendingRemoved.delete(gone.rowKey)
      placeholders.delete(gone.rowKey)
      selected.delete(gone.rowKey)
      expanded.delete(gone.rowKey)
    }
    this.placeholderKeysSignal.set(placeholders)
    this.selectedKeysSignal.set(selected)
    this.expandedKeysSignal.set(expanded)
    this.refreshPendingSignals()
  }

  /**
   * Take one live row delta: apply it now, or accumulate it as pending.
   *
   * What the gate holds is the POSITION and the MEMBERSHIP of the rows, so the kind
   * decides. A `row_updated` is a value that left the row where it stood: it lands at
   * once, highlighted, resolving anything queued for that row, and raises no badge —
   * a badge whose Apply changes nothing on the screen is the defect this door closes.
   * A `row_moved` and a `row_removed` wait, because both of them move the list under
   * a reader who is aiming at it.
   *
   * A delta is kept only when its row is in the current window (anchored by row-id).
   * A backend-tagged own-change is the ONE exception to the gate: the server marks the
   * delta `own` for the connection that authored it, and it applies immediately via
   * {@link applyOwnDelta}. There used to be a second — a backend could declare an
   * ordinary mutation live to step around the gate, for the sake of a row reporting work
   * in progress. That row is a bar of its own now, and the door went with it (HIL-820).
   *
   * A freshness delta is taken before any of those doors and passes through none of
   * them: it changes no value, so there is nothing for the gate to hold, and nothing
   * queued for the row may be resolved by it.
   *
   * @param delta The normalized viewport delta.
   */
  ingestDelta(delta: TableViewportDelta): void {
    if (delta.kind === 'row_stale') {
      this.applyRowStaleness(delta.rowKey, delta.staleSources)

      return
    }
    if (delta.own === true) {
      this.applyOwnDelta(delta)

      return
    }
    switch (delta.kind) {
      case 'row_updated':
        this.applyRowValues(delta.rowKey, delta.row)

        return
      case 'row_moved':
        if (this.isInWindow(delta.rowKey)) {
          this.pendingRemoved.delete(delta.rowKey)
          this.pendingMoves.set(delta.rowKey, {
            row: delta.row,
            position: delta.position,
          })
        }
        break
      case 'row_removed':
        // The bar goes at once, whatever the gate decides about the row: the row is gone on
        // the server, so nobody will cover it with a bar again, and the end of its work may
        // never arrive at all — leaving the bar hanging under a placeholder with nothing left
        // to take it down.
        this.dropRowProgress(delta.rowKey)
        if (this.isInWindow(delta.rowKey)) {
          this.pendingMoves.delete(delta.rowKey)
          this.pendingRemoved.set(delta.rowKey, delta.reason)
        }
        break
    }
    this.refreshPendingSignals()
  }

  /**
   * Put one shown row's new values in its slot and mark it as just changed.
   *
   * Everything queued for that row goes with them: the values that just landed are the
   * later word, and holding an older move behind the gate would move the row on the next
   * Apply to a slot computed for a row that no longer exists.
   *
   * @param rowKey The shown row whose values landed.
   * @param row The row as the server rebuilt it.
   */
  private applyRowValues(rowKey: string, row: TableRow): void {
    if (!this.isInWindow(rowKey)) {
      return
    }
    this.windowSignal.set(
      this.windowSignal
        .get()
        .map((shown) => (shown.rowKey === rowKey ? row : shown)),
    )
    this.pendingMoves.delete(rowKey)
    this.pendingRemoved.delete(rowKey)
    this.highlight(rowKey)
    this.refreshPendingSignals()
  }

  /**
   * Replace one shown row's list of sources that are no longer being kept up to date.
   *
   * The row's VALUES are left exactly as they are, and nothing queued for the row is
   * resolved or dropped: this says nothing about content, and a reader who has not pressed
   * Apply has not accepted anything by learning that a source went quiet. Holding the mark
   * behind that gate was the alternative, and it is the outcome the ticket exists to
   * prevent — until the press, a frozen number would go on looking fresh.
   *
   * A queued update for the same row is re-stamped with the new list for exactly that
   * reason. The mark belongs to the row and not to one copy of it, and the queued copy was
   * built when the server sent it — pressing Apply would otherwise put a row carrying the
   * freshness of that earlier moment on screen, silently taking the mark off values that
   * are still frozen.
   *
   * A row outside the window is ignored, the way a content delta for one is.
   *
   * @param rowKey The shown row whose freshness moved.
   * @param staleSources The row's slot keys that are no longer current; empty clears the mark.
   */
  private applyRowStaleness(
    rowKey: string,
    staleSources: readonly string[],
  ): void {
    if (!this.isInWindow(rowKey)) {
      return
    }
    this.windowSignal.set(
      this.windowSignal
        .get()
        .map((row) => (row.rowKey === rowKey ? { ...row, staleSources } : row)),
    )
    const queued = this.pendingMoves.get(rowKey)
    if (queued) {
      this.pendingMoves.set(rowKey, {
        ...queued,
        row: { ...queued.row, staleSources },
      })
    }
  }

  /**
   * Apply a backend-tagged own-change at once, resolving everything accumulated
   * for that row: this echo is the authoritative latest, so any pending update /
   * removal already queued for the same row is dropped, and the row is updated in
   * place (or replaced by a placeholder).
   *
   * The author's own move takes its new slot as it lands, which is what the reader is
   * looking at: they pressed the button, and the row standing still where the sort no
   * longer puts it would be the surprise, not the movement.
   *
   * @param delta The own-change echo (row_updated, row_moved or row_removed).
   */
  private applyOwnDelta(delta: LiveViewportDelta): void {
    if (!this.isInWindow(delta.rowKey)) {
      return
    }
    this.pendingMoves.delete(delta.rowKey)
    this.pendingRemoved.delete(delta.rowKey)
    const placeholders = new Set(this.placeholderKeysSignal.get())
    if (delta.kind === 'row_updated' || delta.kind === 'row_moved') {
      this.windowSignal.set(
        this.placedWindow(delta.rowKey, delta.row, this.slotOf(delta)),
      )
      this.highlight(delta.rowKey)
      placeholders.delete(delta.rowKey)
    } else {
      placeholders.add(delta.rowKey)
    }
    this.placeholderKeysSignal.set(placeholders)
    this.refreshPendingSignals()
  }

  /**
   * Apply accumulated pending changes to the displayed rows in place: updates
   * replace their row and removals become placeholders in their slot (the layout
   * is not collapsed and no row is pulled from another page). The window itself
   * does not move; the count is already live and not part of pending.
   */
  apply(): void {
    if (this.pendingCountSignal.get() === 0) {
      return
    }

    if (this.pendingRemoved.size > 0) {
      const placeholders = new Set(this.placeholderKeysSignal.get())
      for (const rowKey of this.pendingRemoved.keys()) {
        placeholders.add(rowKey)
      }
      this.placeholderKeysSignal.set(placeholders)
    }

    // Slots first, movement after: the placeholders of this same press are still standing
    // in the window, so a slot the server named counts them exactly as the reader sees them.
    // The moves themselves run by ascending slot, so each one lands against a window the
    // earlier ones have already settled.
    const moves = [...this.pendingMoves.entries()].sort(
      ([, one], [, other]) =>
        (one.position ?? Number.MAX_SAFE_INTEGER) -
        (other.position ?? Number.MAX_SAFE_INTEGER),
    )
    for (const [rowKey, move] of moves) {
      this.windowSignal.set(this.placedWindow(rowKey, move.row, move.position))
      this.highlight(rowKey)
    }

    this.clearPending()
  }

  /**
   * Apply all pending changes, then resolve the row's current view-model — used
   * when opening an edit / delete dialog so it reflects the latest committed
   * state. Returns null when the row is now a placeholder (its removal was
   * applied) or has left the window, so the dialog can decline to open.
   *
   * @param rowKey The row whose fresh view-model the dialog needs.
   */
  applyAndResolve(rowKey: string): R | null {
    this.apply()
    if (this.placeholderKeysSignal.get().has(rowKey)) {
      return null
    }
    const raw = this.windowSignal.get().find((row) => row.rowKey === rowKey)

    return raw ? this.options.resolve(raw) : null
  }

  /**
   * Mark one row or take the mark off it — the one input behind a row checkbox,
   * called with the state the box is in rather than with "toggle": the view holds
   * a real checkbox and knows that state, while two inputs would disagree on a
   * click that lands on the row and on the keyboard.
   *
   * A key that is not a LIVE row of the window is refused: a placeholder is the
   * trace of a row that left, and there is nothing in it to mark.
   *
   * Taking a box off while the choice is the CONDITION leaves the condition: what
   * remains is the window named row by row, minus this one. It is not "everything
   * matching the filter except this row", because a condition carries no
   * exceptions and the request has no way to say one; and it is not "clear
   * everything", which would be a dead click after which the reader starts over.
   * The counter says the number of the page at once, so the state itself tells the
   * reader what it has become.
   *
   * Silent on a table that declared no bulk operations, the way {@link setPage}
   * is silent while the total is a ceiling — an input with no meaning does
   * nothing rather than throwing.
   *
   * @param rowKey The row the checkbox belongs to.
   * @param selected The state the checkbox is now in.
   */
  selectRow(rowKey: string, selected: boolean): void {
    if (!this.selectionState.enabled || !this.isLiveRow(rowKey)) {
      return
    }
    if (this.allByFilterSignal.get()) {
      if (selected) {
        // The row is in the choice already: the condition covers everything.
        return
      }
      this.allByFilterSignal.set(false)
      this.selectedKeysSignal.set(
        new Set(this.liveRowKeys().filter((key) => key !== rowKey)),
      )

      return
    }
    const keys = new Set(this.selectedKeysSignal.get())
    if (selected) {
      keys.add(rowKey)
    } else {
      keys.delete(rowKey)
    }
    this.selectedKeysSignal.set(keys)
  }

  /**
   * Open or close the panel of details under one row — the control at the end of it,
   * one input for both directions.
   *
   * It takes the state the row is going TO rather than toggling: the view already
   * knows the state the row is in, it draws it off `expanded` on that very row, and a
   * toggle here would be a second source for the same truth.
   *
   * A key that is not a LIVE row of the window is refused, the way a mark is: a
   * placeholder is the trace of a row that left, and there are no values under it to
   * unfold. Unlike a mark it is guarded by nothing else: which fields a panel holds
   * follows from the declared columns, which live in the view, and a table that
   * declared none simply never draws a control to press.
   *
   * @param rowKey The row the control belongs to.
   * @param expanded Whether the panel is now open.
   */
  expandRow(rowKey: string, expanded: boolean): void {
    if (!this.isLiveRow(rowKey)) {
      return
    }
    const keys = new Set(this.expandedKeysSignal.get())
    if (expanded) {
      keys.add(rowKey)
    } else {
      keys.delete(rowKey)
    }
    this.expandedKeysSignal.set(keys)
  }

  /**
   * Mark every live row of the window, or take the marks off them — the header
   * checkbox, one input for both directions. It takes the rows of the current
   * window and nothing else: an action over rows that are not on screen is "all
   * matching the filter", and that has a button of its own.
   *
   * Silent on a table that declared no bulk operations.
   *
   * @param selected Whether the window's rows are being marked or unmarked.
   */
  selectWindow(selected: boolean): void {
    if (!this.selectionState.enabled) {
      return
    }
    this.allByFilterSignal.set(false)
    this.selectedKeysSignal.set(
      selected ? new Set(this.liveRowKeys()) : new Set(),
    )
  }

  /**
   * Choose everything the filter matches — the second kind of selection, entered
   * by its own button. It is a CONDITION and not a list of keys: over a large set
   * there is no exact row count, so a list would have to promise a number nobody
   * counted, and the request carries one or the other and never both.
   *
   * The keys marked one by one are forgotten as it is entered: the choice is now
   * a different thing, and keeping a list underneath it would be two answers to
   * one question. Every live row of the window shows as marked, because "all"
   * includes the visible ones and three views should not each decide that.
   *
   * Silent on a table that declared no bulk operations.
   */
  selectAllByFilter(): void {
    if (!this.selectionState.enabled) {
      return
    }
    this.allByFilterSignal.set(true)
    this.selectedKeysSignal.set(new Set())
  }

  /**
   * Take off every mark, of either kind — the "Clear" button of the selection
   * panel, which is one button on the mockup and answers for both the list of
   * keys and the condition.
   *
   * The one input of the four that needs no guard: on a table with no bulk
   * operations there is nothing marked, and clearing it changes nothing.
   */
  clearSelection(): void {
    this.allByFilterSignal.set(false)
    this.selectedKeysSignal.set(new Set())
  }

  /**
   * Close every open panel — one line of {@link changeWindow} and called from
   * nowhere else. The reader opened those panels over the rows that were on the
   * screen; a search, a filter, an order or a page turn puts other rows there, and
   * an expansion that outlived its window would be an opinion about records the
   * reader never opened.
   */
  private clearExpanded(): void {
    this.expandedKeysSignal.set(new Set())
  }

  private isInWindow(rowKey: string): boolean {
    return this.windowSignal.get().some((row) => row.rowKey === rowKey)
  }

  /** Whether the key is a row of the window that is still standing — not a placeholder. */
  private isLiveRow(rowKey: string): boolean {
    return (
      this.isInWindow(rowKey) && !this.placeholderKeysSignal.get().has(rowKey)
    )
  }

  /** The keys of the window's live rows, in window order. */
  private liveRowKeys(): readonly string[] {
    const placeholders = this.placeholderKeysSignal.get()

    return this.windowSignal
      .get()
      .map((row) => row.rowKey)
      .filter((rowKey) => !placeholders.has(rowKey))
  }

  /**
   * The slot a live or own delta lands in: the one it named, or none for a plain value.
   *
   * @param delta The delta being applied at once.
   * @returns The slot the row takes, or undefined to leave it where it stands.
   */
  private slotOf(delta: LiveViewportDelta): number | undefined {
    return delta.kind === 'row_moved' ? delta.position : undefined
  }

  /**
   * The window with one row rewritten, and moved to a slot when one was named.
   *
   * A slot outside the window is pulled to its nearest edge rather than refused: the
   * number is the row's place at the moment the frame was raised, and the window is
   * authoritative only when it changes — clamping keeps the row visible where it was
   * heading, and the next window settles the rest.
   *
   * @param rowKey The shown row to rewrite.
   * @param row The row as the server rebuilt it.
   * @param position The slot it lands in, or undefined to leave it where it stands.
   * @returns The rows of the window after the change.
   */
  private placedWindow(
    rowKey: string,
    row: TableRow,
    position: number | undefined,
  ): readonly TableRow[] {
    const rows = this.windowSignal
      .get()
      .map((shown) => (shown.rowKey === rowKey ? row : shown))
    if (position === undefined) {
      return rows
    }

    const from = rows.findIndex((shown) => shown.rowKey === rowKey)
    if (from < 0) {
      return rows
    }
    const moved = rows.slice()
    moved.splice(from, 1)
    moved.splice(
      Math.min(Math.max(0, Math.trunc(position)), moved.length),
      0,
      row,
    )

    return moved
  }

  /**
   * Mark one row as just changed, and start the countdown that unmarks it.
   *
   * A row marked again restarts its own countdown instead of collecting a second one:
   * two timers on one row would take the mark off while the newer change is still fresh.
   *
   * @param rowKey The row that just took a value or a slot.
   */
  private highlight(rowKey: string): void {
    const running = this.highlightTimers.get(rowKey)
    if (running !== undefined) {
      clearTimeout(running)
    }
    const highlighted = new Set(this.highlightedKeysSignal.get())
    highlighted.add(rowKey)
    this.highlightedKeysSignal.set(highlighted)
    this.highlightTimers.set(
      rowKey,
      setTimeout(() => {
        this.highlightTimers.delete(rowKey)
        const left = new Set(this.highlightedKeysSignal.get())
        if (left.delete(rowKey)) {
          this.highlightedKeysSignal.set(left)
        }
      }, HIGHLIGHT_MS),
    )
  }

  /** Take every mark off and stop its countdown — what a new window makes meaningless. */
  private clearHighlights(): void {
    for (const timer of this.highlightTimers.values()) {
      clearTimeout(timer)
    }
    this.highlightTimers.clear()
    if (this.highlightedKeysSignal.get().size > 0) {
      this.highlightedKeysSignal.set(new Set())
    }
  }

  /** Stop waiting for a window: stop the countdown to the skeleton and take the skeleton down. */
  private clearWindowPending(): void {
    if (this.windowSkeletonTimer !== null) {
      clearTimeout(this.windowSkeletonTimer)
      this.windowSkeletonTimer = null
    }
    this.windowPendingSignal.set(false)
  }

  /**
   * Discard pending, placeholders, marks, the selection and the open panels, then
   * request the new window and start the countdown to the skeleton.
   *
   * The one place a selection or an expansion is cleared, and every input that
   * changes a window goes through here — search, filters, their reset, sort, a
   * declared order, its reset, a page jump and the two neighbours — so the rule
   * cannot be forgotten by whoever adds the next one.
   *
   * The bars of running work are deliberately NOT in that list, and their absence is a
   * decision rather than an oversight: work goes on whichever page is being looked at, so
   * turning a page is no reason to stop showing it. A row bar off the new window is not
   * drawn and comes back with its row.
   */
  private changeWindow(): void {
    this.placeholderKeysSignal.set(new Set())
    this.clearHighlights()
    this.clearPending()
    this.clearAnnounced()
    this.clearSelection()
    this.clearExpanded()
    this.send()
    // The rows of the previous window stay until the answer is late; only then does the
    // body say so. A second change restarts the countdown rather than stacking a second one.
    if (this.windowSkeletonTimer !== null) {
      clearTimeout(this.windowSkeletonTimer)
    }
    this.windowSkeletonTimer = setTimeout(() => {
      this.windowSkeletonTimer = null
      this.windowPendingSignal.set(true)
    }, WINDOW_SKELETON_MS)
  }

  /**
   * Forget everything announced: a window has arrived, and it holds the truth.
   *
   * Called from both places a window comes from, because the two are not the same event.
   * A window CHANGE is a press, and the announcements go with the press; a window that
   * simply ARRIVES — a refresh the page asked for, a re-subscribe after a broken socket —
   * was announced to nobody, and leaving its counts standing would leave a strip on the
   * screen naming rows the reader is already looking at.
   */
  private clearAnnounced(): void {
    this.announcedAbove.clear()
    this.announcedInside.clear()
    this.refreshAnnouncedSignal()
  }

  /**
   * Put up exactly the bars a subscription answer named, and take down every other.
   *
   * @param frames The work the server says is running on this table, in full.
   */
  private replaceProgress(frames: readonly HilosTableProgressFrame[]): void {
    const rows = new Map<string, HilosTableProgress>()
    let table: HilosTableProgress | null = null
    let bulk: HilosTableProgress | null = null
    for (const frame of frames) {
      if (frame.scope === 'row') {
        if (frame.rowKey !== undefined) {
          rows.set(frame.rowKey, toTableProgress(frame))
        }
        continue
      }
      if (frame.scope === 'table') {
        table = toTableProgress(frame)
        continue
      }
      bulk = toTableProgress(frame)
    }
    this.rowProgressSignal.set(rows)
    this.tableProgressSignal.set(table)
    this.bulkProgressSignal.set(bulk)
  }

  /**
   * Take down the bar of one row, if it had one.
   *
   * @param rowKey Key of the row whose bar goes.
   */
  private dropRowProgress(rowKey: string): void {
    if (!this.rowProgressSignal.get().has(rowKey)) {
      return
    }
    const rows = new Map(this.rowProgressSignal.get())
    rows.delete(rowKey)
    this.rowProgressSignal.set(rows)
  }

  private refreshAnnouncedSignal(): void {
    this.announcedSignal.set({
      above: this.announcedAbove.size,
      inside: this.announcedInside.size,
      total: this.announcedAbove.size + this.announcedInside.size,
    })
  }

  private clearPending(): void {
    this.pendingMoves.clear()
    this.pendingRemoved.clear()
    this.refreshPendingSignals()
  }

  private refreshPendingSignals(): void {
    this.pendingCountSignal.set(
      this.pendingMoves.size + this.pendingRemoved.size,
    )
    const pendingKinds = new Map<string, 'move' | 'remove'>()
    for (const rowKey of this.pendingMoves.keys()) {
      pendingKinds.set(rowKey, 'move')
    }
    for (const rowKey of this.pendingRemoved.keys()) {
      pendingKinds.set(rowKey, 'remove')
    }
    this.pendingKindSignal.set(pendingKinds)
  }

  /**
   * The window this table is holding, or null while it is holding none.
   *
   * Asked when a page subscribes: a tab coming back after a broken socket is the only side
   * that still remembers what was on the screen, so it reports its windows in that frame and
   * the backend serves them back. A controller that has never received a window has nothing
   * to report — it does not know its own size or order until one arrives — and says so; that
   * is the cold entry, and the table's own declaration answers for it.
   *
   * A table that declares its options for counting reports them with the window, so the
   * counts come back with it; the window frames it sends while mounted carry none.
   *
   * @return The current descriptor, or null while no window has arrived.
   */
  descriptor(): TableViewportDescriptor | null {
    if (!this.loadedSignal.get()) {
      return null
    }
    const facets = this.facetOptions.get()

    return this.options.sendFacets !== undefined &&
      Object.keys(facets).length > 0
      ? { ...this.currentDescriptor(), facets }
      : this.currentDescriptor()
  }

  /** The viewport descriptor for the current filter, order, size and address. */
  private currentDescriptor(): TableViewportDescriptor {
    const order = this.orderSignal.get()

    return {
      filter: { ...this.filterSignal.get() },
      sort:
        order === undefined
          ? null
          : order.map((component) => ({
              field: component.field,
              direction: component.direction,
            })),
      limit: this.pageSizeSignal.get(),
      anchor: this.anchor,
      anchorDirection: this.anchorDirection,
      pageIndex: this.pageIndex,
    }
  }

  /**
   * Send the window back to the start of the set, which is what a new filter or sort asks
   * for: the anchors in hand belong to an ordering that no longer exists.
   */
  private resetAddress(): void {
    this.anchor = null
    this.anchorDirection = 'after'
    this.pageIndex = null
    this.pageSignal.set(0)
  }

  private send(): void {
    this.options.sendViewport(this.currentDescriptor())
  }
}
