// The categories admin page selectors: the page-scoped categories table rows fed
// into a server-windowed TableViewportController, each raw row resolved into its
// CategoryRow view-model. The backend categories table carries the whole row,
// counts included, in the `category` slot. The slot bears an `id`, so binding
// the window files it in the page scope as the `category` entity and leaves a
// reference in the row: the row is read through that reference. The view reads
// the controller, never a raw store.
import {
  HILOS_TABLE_ACTIONS_KEY,
  TableViewportController,
  bindTableViewport,
  type HilosTableColumnOf,
  type HilosTableFrame,
  type TableRow,
} from '@hilos/core/base'

import { connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'
import {
  slotFields,
  type EntityFieldsResolver,
} from '../../entityFields/entityFields.js'
import { PAGE_ADMIN_CATEGORIES } from '../../pages/keys.js'
import { type CategoryRow } from './types/tables/CategoryRow.js'

// Wire keys: the categories table (EcommerceShopTableContext::categories) and its
// one row slot (CategoriesTable::ROW_SLOT), a CategoryTableRow record that is
// also the slot's entity type in the page scope.
const CATEGORIES_TABLE = 'categories'
const CATEGORY_SLOT = 'category'

/** Row payload key of the category id. */
const CATEGORY_ID_FIELD = 'id'

/** Row payload key of the category description. */
const CATEGORY_DESCRIPTION_FIELD = 'description'

/** Row payload key of the category place in the catalog. */
const CATEGORY_POSITION_FIELD = 'position'

/** Row payload key of whether no category comes before it in the catalog. */
export const CATEGORY_FIRST_FIELD = 'first'

/** Row payload key of the category picture address. */
export const CATEGORY_PICTURE_FIELD = 'picture'

/** Row payload key of the category picture's registry id. */
const CATEGORY_PICTURE_FILE_ID_FIELD = 'pictureFileId'

/** Row payload key of the edit preview's address. */
const CATEGORY_PICTURE_PREVIEW_FIELD = 'picturePreview'

/** Row payload key of the category name. */
export const CATEGORY_NAME_FIELD = 'name'

/** Row payload key of the number of products the category holds. */
export const CATEGORY_PRODUCT_COUNT_FIELD = 'productCount'

const fieldsOf: EntityFieldsResolver = (ref) =>
  scopes.entitySignal(ref).get()?.fields

/** Read a record field as a string, or the empty string when it is not one. */
function readString(
  record: Readonly<Record<string, unknown>> | undefined,
  field: string,
): string {
  const value = record?.[field]

  return typeof value === 'string' ? value : ''
}

/** Read a record field as a number, or zero when it is not one. */
function readNumber(
  record: Readonly<Record<string, unknown>> | undefined,
  field: string,
): number {
  const value = record?.[field]

  return typeof value === 'number' ? value : 0
}

/** Read a record field as a boolean, or false when it is not one. */
function readBoolean(
  record: Readonly<Record<string, unknown>> | undefined,
  field: string,
): boolean {
  const value = record?.[field]

  return typeof value === 'boolean' ? value : false
}

/**
 * Resolve one raw categories-table row into its view-model: the fields of the
 * referenced category entity, the id falling back to the row key.
 *
 * @param row The raw table row from the page-scoped table store.
 * @param fieldsOf The resolver of a slot's reference to its fields.
 */
export function resolveCategoryRow(
  row: TableRow,
  fieldsOf: EntityFieldsResolver,
): CategoryRow {
  const category = slotFields(row.slots[CATEGORY_SLOT], fieldsOf)
  const id = category[CATEGORY_ID_FIELD]

  return {
    id: typeof id === 'number' ? id : Number(row.rowKey),
    name: readString(category, CATEGORY_NAME_FIELD),
    description: readString(category, CATEGORY_DESCRIPTION_FIELD),
    position: readNumber(category, CATEGORY_POSITION_FIELD),
    picture: readString(category, CATEGORY_PICTURE_FIELD),
    pictureFileId: readNumber(category, CATEGORY_PICTURE_FILE_ID_FIELD),
    picturePreview: readString(category, CATEGORY_PICTURE_PREVIEW_FIELD),
    productCount: readNumber(category, CATEGORY_PRODUCT_COUNT_FIELD),
    first: readBoolean(category, CATEGORY_FIRST_FIELD),
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
// count reads to the right, as numbers do. Actions sit in the trailing column,
// and the pencil is also shown on narrow cards.
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
  {
    key: HILOS_TABLE_ACTIONS_KEY,
    label: '',
    headerClass: 'text-end',
    cellClass: 'text-end text-nowrap',
    reads: [
      CATEGORY_NAME_FIELD,
      CATEGORY_DESCRIPTION_FIELD,
      CATEGORY_PICTURE_FILE_ID_FIELD,
      CATEGORY_PICTURE_PREVIEW_FIELD,
      CATEGORY_FIRST_FIELD,
    ],
  },
]

/**
 * The categories table frame. No title: the section head names the table. The
 * main action opens the Add category dialog, which the view owns.
 *
 * @param addCategory Open the Add category dialog.
 */
function categoriesFrame(addCategory: () => void): HilosTableFrame {
  return {
    search: { placeholder: 'Search categories' },
    columns: CATEGORIES_COLUMNS,
    mainAction: { label: 'Add category', press: addCategory },
    empty: {
      title: 'No categories yet',
      hint: 'Add one before adding products: every product lives in a category.',
    },
  }
}

/**
 * The server-windowed controller for the categories admin table: search and
 * paging change the viewport descriptor sent over the connection, and the
 * backend replies a window plus live deltas scoped to the table's (page,
 * tableKey) address. Rows resolve through {@link resolveCategoryRow}.
 *
 * @param addCategory Open the Add category dialog — the table's main action.
 */
export function createCategoriesTable(
  addCategory: () => void,
): CategoriesTable {
  const controller = new TableViewportController<CategoryRow>({
    resolve: (row) => resolveCategoryRow(row, fieldsOf),
    frame: categoriesFrame(addCategory),
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
      // The slot's own key is its entity type: no entity-type override to declare.
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
