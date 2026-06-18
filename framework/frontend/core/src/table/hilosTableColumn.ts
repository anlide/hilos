// The column declaration a HilosTable header renders from. It is framework-
// agnostic view config — header text and which fields offer a sort control —
// never table logic, which lives in the TableController (table-subscription.md,
// multiframework-core.md). It lives in the core so every view layer's HilosTable
// shares one column type.

/** One column of a HilosTable: its key, header label, and sortability. */
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
