/** One product as the administration table renders it. */
export interface ProductRow {
  /** Product id; also the table row key. */
  readonly id: number
  /** Product name. */
  readonly name: string
  /** Address of the product picture. */
  readonly pictureUrl: string
  /** Id of the category the product belongs to. */
  readonly categoryId: number
  /** Name of the category the product belongs to. */
  readonly categoryName: string
  /** Whether this is a unique piece or a stock item. */
  readonly kind: string
  /** Price of one unit, in kopecks. */
  readonly price: number
  /** Total units held in warehouses. */
  readonly units: number
  /** Warehouse holding the unique piece, or null when it has none. */
  readonly warehouseName: string | null
  /** Availability of the product. */
  readonly availability: string
  /** Last change as epoch milliseconds. */
  readonly changedAt: number
}
