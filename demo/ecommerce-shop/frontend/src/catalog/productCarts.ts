/** Page data carrying the aggregate number of carts containing a product. */
export const PRODUCT_CARTS_DATA = 'productCarts'

/**
 * Read the cart count addressed to the current product.
 *
 * @param raw The inline cart count record from the page response.
 * @param productId The current card's product ID.
 * @param slug The current card's slug, required on the storefront.
 */
export function resolveProductCartCount(
  raw: unknown,
  productId: number,
  slug?: string,
): number | undefined {
  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) {
    return undefined
  }

  const record = raw as Readonly<Record<string, unknown>>
  const { cartCount } = record
  return Number.isInteger(productId) &&
    productId > 0 &&
    record.productId === productId &&
    (slug === undefined || record.slug === slug) &&
    typeof cartCount === 'number' &&
    Number.isInteger(cartCount) &&
    cartCount >= 0
    ? cartCount
    : undefined
}
