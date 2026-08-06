// The column declaration a HilosViewportTable header renders from. It is framework-
// agnostic view config — header text and which fields offer a sort control —
// never table logic, which lives in the TableViewportController (table-subscription.md,
// multiframework-core.md). It lives in the core so every view layer's
// HilosViewportTable shares one column type.

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
}

/**
 * The same column with its key checked against the row the page renders: a typo or
 * a renamed view-model field fails compilation instead of rendering an empty column
 * in three frameworks at once. A page that owns a row type declares its columns as
 * `HilosTableColumnOf<ItsRow>[]`; a page with no row type of its own keeps the plain
 * {@link HilosTableColumn} and stays valid unchanged.
 *
 * `actions` is the one virtual key in the SDK: it renders row controls and belongs
 * to no row field.
 *
 * It is a narrowing of the column rather than a type parameter on it because
 * `keyof TRow` makes the parameter contravariant — a `HilosTableColumn<ItsRow>[]`
 * would then not be accepted by HilosViewportTable, which takes the plain column
 * list and only ever reads the key.
 */
export type HilosTableColumnOf<TRow> = Omit<HilosTableColumn, 'key'> & {
  /** Column id, restricted to the row's own fields plus the virtual `actions`. */
  key: (keyof TRow & string) | 'actions'
}
