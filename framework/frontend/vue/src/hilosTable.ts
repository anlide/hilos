// The column declaration a HilosTable header renders from. It is view config —
// header text and which fields offer a sort control — never table logic, which
// lives in the core TableController (table-subscription.md, multiframework-core.md).

/** One column of a {@link HilosTable}: its key, header label, and sortability. */
export interface HilosTableColumn {
  /** Column id — the sort field passed to the controller and the header's data-id. */
  key: string
  /** Header text. */
  label: string
  /** Whether the header offers a sort control (default false). */
  sortable?: boolean
  /** Extra classes for the header cell, e.g. `text-end` for a numeric column. */
  headerClass?: string
}
