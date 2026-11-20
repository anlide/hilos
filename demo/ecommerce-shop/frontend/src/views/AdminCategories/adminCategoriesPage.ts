// The categories admin page selectors: the page-scoped categories table rows fed
// into a server-windowed TableViewportController, each raw row resolved into its
// CategoryRow view-model. The backend categories table carries the whole row as
// an inline record in the `category` slot — the product count is recounted on
// the server, so the slot is a record, not an entity reference. The view reads
// the controller, never a raw store.
import {
  TableViewportController,
  bindTableViewport,
  type HilosTableColumnOf,
  type HilosTableFrame,
  type TableRow,
} from '@hilos/core/base'

import { connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'
import { PAGE_ADMIN_CATEGORIES } from '../../pages/keys.js'
import { type CategoryRow } from './types/tables/CategoryRow.js'

// Wire keys: the categories table (EcommerceShopTableContext::categories) and its
// one row slot (CategoriesTable::ROW_SLOT), an inline CategoryTableRow record.
const CATEGORIES_TABLE = 'categories'
const CATEGORY_SLOT = 'category'

/** Row payload key of the category id. */
const CATEGORY_ID_FIELD = 'id'

/** Row payload key of the category description. */
const CATEGORY_DESCRIPTION_FIELD = 'description'

/** Row payload key of the category place in the catalog. */
const CATEGORY_POSITION_FIELD = 'position'

/** Row payload key of the category picture address. */
export const CATEGORY_PICTURE_FIELD = 'picture'

/** Row payload key of the category name. */
export const CATEGORY_NAME_FIELD = 'name'

/** Row payload key of the number of products the category holds. */
export const CATEGORY_PRODUCT_COUNT_FIELD = 'productCount'

/** Read a row slot as an inline record, or undefined when it is not one. */
function recordSlot(slot: unknown): Record<string, unknown> | undefined {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : undefined
}

/** Read a record field as a string, or the empty string when it is not one. */
function readString(
  record: Record<string, unknown> | undefined,
  field: string,
): string {
  const value = record?.[field]

  return typeof value === 'string' ? value : ''
}

/** Read a record field as a number, or zero when it is not one. */
function readNumber(
  record: Record<string, unknown> | undefined,
  field: string,
): number {
  const value = record?.[field]

  return typeof value === 'number' ? value : 0
}

/**
 * Resolve one raw categories-table row into its view-model: the fields of the
 * inline `category` record, the id falling back to the row key.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveCategoryRow(row: TableRow): CategoryRow {
  const category = recordSlot(row.slots[CATEGORY_SLOT])
  const id = category?.[CATEGORY_ID_FIELD]

  return {
    id: typeof id === 'number' ? id : Number(row.rowKey),
    name: readString(category, CATEGORY_NAME_FIELD),
    description: readString(category, CATEGORY_DESCRIPTION_FIELD),
    position: readNumber(category, CATEGORY_POSITION_FIELD),
    picture: readString(category, CATEGORY_PICTURE_FIELD),
    productCount: readNumber(category, CATEGORY_PRODUCT_COUNT_FIELD),
  }
}

/** The categories table handle the admin view drives: controller plus mount lifecycle. */
export interface CategoriesTable {
  /** The server-windowed controller the view renders rows from. */
  readonly controller: TableViewportController<CategoryRow>
  /** Bind the table to the connection and request the first window — call on mount. */
  start(): void
  /** Unbind from the connection — call on unmount. */
  dispose(): void
}

// Nothing sorts: the one order is the place in the catalog. The name cell also
// draws the description, so it reads that field too — an edit of the description
// must reach the window. The picture has no label and no card of its own; the
// count reads to the right, as numbers do.
const CATEGORIES_COLUMNS: HilosTableColumnOf<CategoryRow>[] = [
  { key: CATEGORY_PICTURE_FIELD, label: '', card: 'hidden' },
  {
    key: CATEGORY_NAME_FIELD,
    label: 'Category',
    card: 'title',
    reads: [CATEGORY_DESCRIPTION_FIELD],
  },
  {
    key: CATEGORY_PRODUCT_COUNT_FIELD,
    label: 'Products',
    headerClass: 'text-end',
    cellClass: 'text-end',
  },
]

// No title: the section head names the table. No main action yet: adding a
// category is a leaf of its own.
const CATEGORIES_FRAME: HilosTableFrame = {
  search: { placeholder: 'Search categories' },
  columns: CATEGORIES_COLUMNS,
  empty: {
    title: 'No categories yet',
    hint: 'Add one before adding products: every product lives in a category.',
  },
}

/**
 * The server-windowed controller for the categories admin table: search and
 * paging change the viewport descriptor sent over the connection, and the
 * backend replies a window plus live deltas scoped to the table's (page,
 * tableKey) address. Rows resolve through {@link resolveCategoryRow}.
 */
export function createCategoriesTable(): CategoriesTable {
  const controller = new TableViewportController<CategoryRow>({
    resolve: resolveCategoryRow,
    frame: CATEGORIES_FRAME,
    sendViewport: (descriptor) =>
      connection.sendTableViewport(
        PAGE_ADMIN_CATEGORIES,
        CATEGORIES_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      connection.sendTableRendered(
        PAGE_ADMIN_CATEGORIES,
        CATEGORIES_TABLE,
        rendered,
      ),
    sendFocus: (rowKey) =>
      connection.sendTableRowFocus(
        PAGE_ADMIN_CATEGORIES,
        CATEGORIES_TABLE,
        rowKey,
      ),
  })
  const teardown: Array<() => void> = []

  return {
    controller,
    start() {
      // The slot is an inline record, not an entity: no entity types to normalize.
      teardown.push(
        bindTableViewport(
          connection,
          scopes,
          { page: PAGE_ADMIN_CATEGORIES, tableKey: CATEGORIES_TABLE },
          controller,
        ),
      )
    },
    dispose() {
      // A row still in focus on the server is let go — an empty focus key —
      // before the bindings are dropped.
      controller.releaseFocus()
      for (const off of teardown.splice(0)) {
        off()
      }
    },
  }
}
