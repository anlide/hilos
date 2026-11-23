/** The storefront product snapshot supplied in the initial and live page responses. */
export interface ProductDetails {
  readonly id: number
  readonly slug: string
  readonly name: string
  readonly kind: 'unique' | 'stock'
  readonly price: number
  readonly description: string
  readonly pictureUrl: string
  readonly units: number
  readonly availability: 'free' | 'in_stock' | 'low' | 'out_of_stock'
  readonly categoryName: string
  readonly categorySlug: string
}
