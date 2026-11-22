/** One product as its own admin page shows it. */
export interface AdminProduct {
  /** Product id; also the number in the page's address. */
  readonly productId: number
  /** Product name. */
  readonly name: string
  /** Whether this is a unique piece or a stock item. */
  readonly kind: string
  /** Price of one unit, in kopecks. */
  readonly price: number
  /** Id of the category the product belongs to. */
  readonly categoryId: number
  /** Product description, whole. */
  readonly description: string
  /** Address of the large product picture. */
  readonly pictureUrl: string
  /** Stored file of the product picture. */
  readonly pictureFileId: number
  /** Opaque version of the saved catalog fields. */
  readonly editVersion: string
  /** What the warehouses hold of the product. */
  readonly stock: AdminProductStock
}

/** The product's stock total across the warehouses. */
export interface AdminProductStock {
  /** Units held in all warehouses. */
  readonly units: number
  /** Warehouses holding at least one unit. */
  readonly warehouses: number
  /** Warehouse keeping a placed unique piece, or null when it has none. */
  readonly warehouseId: number | null
}
