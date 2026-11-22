/** One product as shown in the category storefront table and tiles. */
export interface CategoryProductRow {
  readonly id: number
  readonly slug: string
  readonly name: string
  readonly pictureUrl: string
  readonly pictureAlt: string
  readonly kind: string
  readonly price: number
  readonly units: number
  readonly availability: string
}
