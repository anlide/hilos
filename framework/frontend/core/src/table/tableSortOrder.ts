// The composite orders a table declares, turned into what the "Order" menu draws:
// the items, their words, and the number a participating column wears in the header
// (mockups/components/table section 7). Like tableCard.ts this file holds pure
// functions over a declaration plus their types — no window logic, which lives in the
// TableViewportController, and no markup, which belongs to each view package. The
// derivation sits in the core rather than in the three views because the words of an
// item are built from the declared column labels and the active item is decided by
// comparing orders: three views doing that each in its own way would be three
// different menus on one product.

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
 * One composite order a table declares: the key it is picked by, and the components
 * it runs in.
 *
 * The key is the same slug the backend's `sortOrders()` declares the order under, and
 * the menu item's `data-id` is built from it (table-subscription.md, the stable
 * selector registry). Deriving a key from the components instead would give one order
 * two names — one on each side of the wire.
 */
export interface HilosTableSortOrder {
  /** Slug of the order; the `data-id` of its menu item is built from it. */
  readonly key: string
  /** The components in the sequence they apply, the first one deciding. */
  readonly components: TableSortOrder
}

/** One item of the "Order" menu: what it is picked by, how it reads, whether it runs. */
export interface HilosTableOrderView {
  /** Key of the declared order, or {@link HILOS_TABLE_OPENING_ORDER_KEY}. */
  readonly key: string
  /** The item's words, e.g. `Kind ↑, then Date ↓`. */
  readonly label: string
  /** Whether this is the order the window runs in right now. */
  readonly active: boolean
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
 * The items of the "Order" menu: the way home first, then the declared orders in the
 * sequence the table declared them.
 *
 * A table that declared no composite order gets no items at all — a menu offering only
 * "the way it opened" offers no choice, and the bar draws nothing where there is
 * nothing to draw (Design D9). The first item stands for the order the table opened in
 * and is picked by {@link HILOS_TABLE_OPENING_ORDER_KEY}; it is a way home rather than
 * one more order, and a table that opened in no order of its own keeps it all the same,
 * because a reader who left for a composite order has no other way back.
 *
 * At most one item is active, and on an order that came from a header click none is:
 * that order is a state of the table which the menu does not offer, and lighting up the
 * nearest item would say the rows lie in an order they do not.
 *
 * @param declared The composite orders the table declares, in menu sequence.
 * @param opening The order the table opened in, or undefined when it opened in none.
 * @param current The order the window runs in, or undefined when it runs in none.
 * @param columns The columns as the page declared them.
 * @returns The menu items, or an empty list when there is no menu to draw.
 */
export function hilosTableOrderViews(
  declared: readonly HilosTableSortOrder[],
  opening: TableSortOrder | undefined,
  current: TableSortOrder | undefined,
  columns: readonly HilosTableColumn[],
): readonly HilosTableOrderView[] {
  if (declared.length === 0) {
    return []
  }

  return [
    {
      key: HILOS_TABLE_OPENING_ORDER_KEY,
      label: hilosTableOrderLabel(opening, columns),
      active: isSameOrder(opening, current),
    },
    ...declared.map(({ key, components }) => ({
      key,
      label: hilosTableOrderLabel(components, columns),
      active: isSameOrder(components, current),
    })),
  ]
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
