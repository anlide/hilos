// The warehouses admin table row view-model: one warehouse as the admin table
// renders it. The backend warehouses table carries the whole row as an inline
// record in the `warehouse` slot; the selector that reads it
// (adminWarehousesPage.ts) lives with the page (data-model.md — table-row
// view-models live in types/tables/).

/** One row of the warehouses admin table. */
export interface WarehouseRow {
  /** Warehouse id; also the table row key. */
  readonly id: number
  /** Warehouse name. */
  readonly name: string
  /** Warehouse address. */
  readonly address: string
  /** Different products the warehouse keeps. */
  readonly productCount: number
  /** Units of those products in all. */
  readonly unitCount: number
}
