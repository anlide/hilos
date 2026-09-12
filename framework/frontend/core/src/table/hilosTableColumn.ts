// The column declaration a HilosViewportTable header renders from. It is framework-
// agnostic view config — header text, which fields offer a sort control, which
// place the column takes in the card a row projects to on a narrow screen, which
// source its values are built from, which columns a row's bar of running work
// stretches under, and which of them wait in the panel the row expands into — never
// table logic, which lives in the TableViewportController (table-subscription.md,
// multiframework-core.md). It lives in the core so every view layer's
// HilosViewportTable shares one column type.

/**
 * The one virtual column key in the SDK: it renders the row controls and belongs to
 * no row field. Both the column type and the card projection read it, so it is named
 * here rather than spelled out at either site.
 */
export const HILOS_TABLE_ACTIONS_KEY = 'actions'

/**
 * Where a column goes in the card a row projects to on a narrow screen
 * (mockups/components/table section 9):
 *
 * - `title` — the head of the card, drawn without a label;
 * - `badge` — the right of that head, drawn without a label; never assigned by
 *   default, because which column carries a state is known to the page alone;
 * - `field` — a labelled line in the body of the card;
 * - `hidden` — the column is not in the card at all.
 */
export type HilosTableCardSlot = 'title' | 'badge' | 'field' | 'hidden'

/** One column of a HilosViewportTable: its key, header label, and sortability. */
export interface HilosTableColumn {
  /**
   * Column id — the sort field passed to the controller and the header's data-id.
   * A sortable column's key travels to the backend as the sort field name, so it
   * must match the wire key of the row, not just its view-model field name.
   */
  key: string
  /** Header text. */
  label: string
  /** Whether the header offers a sort control (default false). */
  sortable?: boolean
  /** Extra classes for the header cell, e.g. `text-end` for a numeric column. */
  headerClass?: string
  /**
   * Where this column goes in the card a row projects to on a narrow screen.
   * Absent means the default layout follows from the declaration order — the first
   * column becomes the title and the rest become fields. The wide screen never
   * reads this: a column kept out of the card keeps its place in the row.
   */
  card?: HilosTableCardSlot
  /**
   * Which row slot this column's values are built from — the same key the row's
   * `staleSources` names when that source stops being kept up to date. Declaring it
   * is what lets the framework say in words which columns froze and take the sort
   * control off them (tableStaleness.ts); a column that declares no source is never
   * counted stale, because the framework does not know what it is made of.
   */
  source?: string
  /**
   * Whether the row's progress bar stretches under this column; no column marked
   * means it stretches under the whole row (mockups/components/table section 5).
   */
  progress?: boolean
  /**
   * Whether this column lives ONLY in the panel a row expands into: it is in no
   * header, takes no width in the row, and stays out of the card's main set on a
   * narrow screen (tableDetail.ts, mockups/components/table section 4). Absent
   * means an ordinary column. Removing the marked column's cell from the row slot
   * is the page's own duty — the framework cannot see the markup a page writes.
   */
  detail?: boolean
}

/**
 * The same column with its key checked against the row the page renders: a typo or
 * a renamed view-model field fails compilation instead of rendering an empty column
 * in three frameworks at once. A page that owns a row type declares its columns as
 * `HilosTableColumnOf<ItsRow>[]`; a page with no row type of its own keeps the plain
 * {@link HilosTableColumn} and stays valid unchanged.
 *
 * {@link HILOS_TABLE_ACTIONS_KEY} is the one virtual key in the SDK: it renders row
 * controls and belongs to no row field.
 *
 * It is a narrowing of the column rather than a type parameter on it because
 * `keyof TRow` makes the parameter contravariant — a `HilosTableColumn<ItsRow>[]`
 * would then not be accepted by HilosViewportTable, which takes the plain column
 * list and only ever reads the key.
 */
export type HilosTableColumnOf<TRow> = Omit<HilosTableColumn, 'key'> & {
  /** Column id, restricted to the row's own fields plus the virtual actions key. */
  key: (keyof TRow & string) | typeof HILOS_TABLE_ACTIONS_KEY
}
