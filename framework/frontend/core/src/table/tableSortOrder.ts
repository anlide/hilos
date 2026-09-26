// The composite orders a table declares, turned into what the "Order" menu draws:
// the items, their words, and the number a participating column wears in the header
// (mockups/components/table section 7). Like tableCard.ts this file holds pure
// functions over a declaration plus their types — no window logic, which lives in the
// TableViewportController, and no markup, which belongs to each view package. The
// derivation sits in the core rather than in the three views because the words of an
// item are built from the declared column labels and the active item is decided by
// comparing orders: three views doing that each in its own way would be three
// different menus on one product. Below md, where there is no header to click, the
// menu carries every sortable column in both directions as well.

import { type HilosTableColumn } from './hilosTableColumn.js'
import { isSameOrder, type TableSortOrder } from './TableViewportController.js'

/**
 * The reserved key of the menu's first item — the order the table opened in.
 *
 * That order is the backend's own and is declared nowhere on the frontend, so it has
 * no key of its own to be picked by; it gets this one, the way the row controls get
 * `HILOS_TABLE_ACTIONS_KEY` (hilosTableColumn.ts) — the only other virtual key in the
 * SDK.
 */
export const HILOS_TABLE_OPENING_ORDER_KEY = 'opening'

/**
 * What the key of a mirror item ends in: the key of its original plus this.
 *
 * The backend declares no key for a mirror — it holds a composite order against the
 * declared orders and their mirrors alike, and the order itself is what travels — so
 * the item's key is derived here, from the one name the order already has. One order,
 * one name, on both sides of the wire; the suffix only tells the two items apart.
 */
export const HILOS_TABLE_MIRROR_ORDER_SUFFIX = '-mirror'

/**
 * One order the menu offers: the key it is picked by, and the components it runs in.
 *
 * The key of a composite order is the same slug the backend's `sortOrders()` declares
 * the order under, and the menu item's `data-id` is built from it (table-subscription.md,
 * the stable selector registry). Deriving a key from the components instead would give
 * one order two names — one on each side of the wire. An order of one column has no
 * slug on either side: its key is the column's and its direction
 * ({@link hilosTableColumnOrders}).
 */
export interface HilosTableSortOrder {
  /** Slug of the order; the `data-id` of its menu item is built from it. */
  readonly key: string
  /** The components in the sequence they apply, the first one deciding. */
  readonly components: TableSortOrder
}

/** One item of the "Order" menu: what it is picked by, how it reads, whether it runs. */
export interface HilosTableOrderView {
  /** Key of the offered order, or {@link HILOS_TABLE_OPENING_ORDER_KEY}. */
  readonly key: string
  /** The item's words, e.g. `Kind ↑, then Date ↓`. */
  readonly label: string
  /** Whether this is the order the window runs in right now. */
  readonly active: boolean
  /** Whether this order runs over a column whose source is lagging. */
  readonly stale: boolean
  /**
   * Whether the item is offered only below md, where no header can be clicked: the
   * order of one column, or the way home of a table that declared no composite
   * order. Above md the header gives both, so the menu there carries only the
   * composite orders and the way home from them.
   */
  readonly narrowOnly: boolean
}

/**
 * The words the menu carries.
 *
 * Here rather than in each view package for the reason `RT_STALENESS_COPY` is: the
 * three shells cannot then drift into three different menus. A page declares none of
 * this — an order reads by its columns' own labels, so renaming a column renames every
 * item it appears in.
 */
export const TABLE_ORDER_COPY = {
  /** Face of the menu button, `${menu}: ${label}`; also its accessible name. */
  menu: 'Order',
  /** First item's words when the table opened in no order of its own. */
  defaultOrder: 'Default order',
  /** Mark of an ascending component. */
  ascending: '↑',
  /** Mark of a descending component. */
  descending: '↓',
  /** What stands between two components of one order. */
  separator: ', then ',
} as const

/**
 * The words an order reads by: every component's column label with its direction,
 * in the sequence they apply.
 *
 * A component whose column the page did not declare reads by its own field key rather
 * than being dropped: an item showing half of an order would name an order nobody can
 * get. No order at all — the table opened in the backend's own — reads by
 * `TABLE_ORDER_COPY.defaultOrder`, which is also what an empty order reads by: both
 * say the same thing about the window.
 *
 * @param order The order to word, or undefined when there is none.
 * @param columns The columns as the page declared them.
 * @returns The words of the order.
 */
export function hilosTableOrderLabel(
  order: TableSortOrder | undefined,
  columns: readonly HilosTableColumn[],
): string {
  if (order === undefined || order.length === 0) {
    return TABLE_ORDER_COPY.defaultOrder
  }

  return order
    .map((component) => {
      const column = columns.find(({ key }) => key === component.field)
      const mark =
        component.direction === 'asc'
          ? TABLE_ORDER_COPY.ascending
          : TABLE_ORDER_COPY.descending

      return `${column?.label ?? component.field} ${mark}`
    })
    .join(TABLE_ORDER_COPY.separator)
}

/**
 * The orders of one column the menu offers: every sortable column, in the sequence
 * the columns are declared, ascending and then descending.
 *
 * These are the orders a click on a header gives, less the third state of its cycle —
 * the way home, which the menu names by an item of its own. Below md there is no header
 * to click, and the menu is the only way to take them (mockups/components/table section
 * 9, D-131). Each is picked by the column's key and the direction it runs in, the same
 * word the order carries on the wire.
 *
 * @param columns The columns as the page declared them.
 * @returns Two orders for every sortable column, or an empty list for none.
 */
export function hilosTableColumnOrders(
  columns: readonly HilosTableColumn[],
): readonly HilosTableSortOrder[] {
  return columns
    .filter(({ sortable }) => sortable === true)
    .flatMap(({ key }) =>
      (['asc', 'desc'] as const).map((direction) => ({
        key: `${key}-${direction}`,
        components: [{ field: key, direction }],
      })),
    )
}

/**
 * The orders the menu offers: every declared order, each followed by its mirror.
 *
 * The mirror runs by the same fields in the same sequence with every direction turned
 * — the declared order read backwards — and it is offered because it costs nothing on
 * either side: the backend's index under an order serves its mirror by being read
 * backwards, which a window going back asks of it already, and the backend's gate takes
 * the mirror of a declared order as it takes the order itself. So no table declares a
 * mirror; the one rule "one key, both directions" that a header click gives a single
 * column holds for a composite order too. A mirror the table declared itself is not
 * offered a second time — it already stands where the table put it, and a menu naming
 * one order twice would be the framework's doing.
 *
 * @param declared The composite orders the table declares, in menu sequence.
 * @returns The declared orders with a mirror after each, or an empty list for none.
 */
export function hilosTableOfferedOrders(
  declared: readonly HilosTableSortOrder[],
): readonly HilosTableSortOrder[] {
  return declared.flatMap((order) => {
    const mirror: HilosTableSortOrder = {
      key: `${order.key}${HILOS_TABLE_MIRROR_ORDER_SUFFIX}`,
      components: order.components.map(({ field, direction }) => ({
        field,
        direction: direction === 'asc' ? 'desc' : 'asc',
      })),
    }
    const declaredItself = declared.some(({ components }) =>
      isSameOrder(components, mirror.components),
    )

    return declaredItself ? [order] : [order, mirror]
  })
}

/**
 * The items of the "Order" menu: the way home first, then the offered orders in the
 * sequence they are offered — every sortable column in both directions, then each
 * declared order followed by its mirror.
 *
 * The menu is empty only for a table with neither a sortable column nor a composite
 * order — a menu offering only "the way it opened" offers no choice, and the bar draws
 * nothing where there is nothing to draw (Design D9). The first item stands for the
 * order the table opened in and is picked by {@link HILOS_TABLE_OPENING_ORDER_KEY}; it
 * is a way home rather than one more order, and a table that opened in no order of its
 * own keeps it all the same, because a reader who left for another order has no other
 * way back. An order of one column that is the opening order itself is not listed a
 * second time: the way home already names it.
 *
 * The items of one column are marked {@link HilosTableOrderView.narrowOnly}, and so is
 * the way home of a table that declared no composite order: above md the header gives
 * them, and the menu there carries only the composite orders and the way home from them.
 *
 * At most one item is active. On an order that came from a header click it is that
 * column's item — offered below md, hidden above it, so there the part of the menu on
 * display carries no mark: lighting up the nearest item would say the rows lie in an
 * order they do not.
 *
 * @param offered The orders the menu offers after the way home — the columns in both
 *   directions, then each declared order followed by its mirror.
 * @param opening The order the table opened in, or undefined when it opened in none.
 * @param current The order the window runs in, or undefined when it runs in none.
 * @param columns The columns as the page declared them.
 * @param staleSources The sources that froze in the window.
 * @returns The menu items, or an empty list when there is no menu to draw.
 */
export function hilosTableOrderViews(
  offered: readonly HilosTableSortOrder[],
  opening: TableSortOrder | undefined,
  current: TableSortOrder | undefined,
  columns: readonly HilosTableColumn[],
  staleSources: ReadonlySet<string>,
): readonly HilosTableOrderView[] {
  if (offered.length === 0) {
    return []
  }

  const isOrderStale = (order: TableSortOrder | undefined): boolean => {
    if (order === undefined || order.length === 0 || staleSources.size === 0) {
      return false
    }
    return order.some((component) => {
      const column = columns.find(({ key }) => key === component.field)
      return column?.source !== undefined && staleSources.has(column.source)
    })
  }

  return [
    {
      key: HILOS_TABLE_OPENING_ORDER_KEY,
      label: hilosTableOrderLabel(opening, columns),
      active: isSameOrder(opening, current),
      stale: isOrderStale(opening),
      narrowOnly: !offered.some(({ components }) => components.length > 1),
    },
    ...offered
      .filter(
        ({ components }) =>
          components.length > 1 || !isSameOrder(components, opening),
      )
      .map(({ key, components }) => ({
        key,
        label: hilosTableOrderLabel(components, columns),
        active: isSameOrder(components, current),
        stale: isOrderStale(components),
        narrowOnly: components.length === 1,
      })),
  ]
}

/**
 * Whether the whole menu is offered only below md: it has items, and every one of them
 * is {@link HilosTableOrderView.narrowOnly} — a table with sortable columns and no
 * composite order. One rule for the three views: the menu and a bar holding nothing
 * else both hide above md by it.
 *
 * @param views The menu items, as {@link hilosTableOrderViews} lists them.
 * @returns Whether no item of the menu is on display above md.
 */
export function hilosTableOrderMenuNarrowOnly(
  views: readonly HilosTableOrderView[],
): boolean {
  return views.length > 0 && views.every(({ narrowOnly }) => narrowOnly)
}

/**
 * Which place a column takes in the order the window runs in, 1-based, or null when it
 * takes none.
 *
 * An order of one column has no places to tell apart, so a column of one reads as null
 * too: the header there already says everything with its arrow, and a number beside it
 * would take room in the tightest part of the screen for nothing (Flow F12).
 *
 * @param order The order the window runs in, or undefined when it runs in none.
 * @param key The column's key.
 * @returns The 1-based place, or null.
 */
export function hilosTableOrderPosition(
  order: TableSortOrder | undefined,
  key: string,
): number | null {
  if (order === undefined || order.length < 2) {
    return null
  }

  const place = order.findIndex((component) => component.field === key)

  return place < 0 ? null : place + 1
}

/**
 * What a screen reader hears beside the number a header wears.
 *
 * `aria-sort` names a direction and has no way to say "second by importance", so the
 * place is spoken in words instead of in an attribute nothing would read (Flow F14).
 *
 * @param position The column's 1-based place in the order.
 * @param total How many columns the order runs by.
 * @returns The sentence to hide beside the number.
 */
export function hilosTableSortPositionLabel(
  position: number,
  total: number,
): string {
  return `Sort column ${position} of ${total}`
}
