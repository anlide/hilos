/** One warehouse's stock of the product addressed by the page. */
export interface ProductStockRow {
  readonly warehouseId: number
  readonly warehouseName: string
  readonly units: number
}
