import { PRODUCT_COUNT_VARIANTS } from './productCountVariants.js'

interface ProductCartCountProps {
  readonly cartCount: number | undefined
  readonly visible?: boolean
}

function cartCountLabel(cartCount: number) {
  if (cartCount === 0) return 'In nobody’s cart yet'

  return (
    <>
      In <strong className="font-monospace">{cartCount}</strong>{' '}
      {cartCount === 1 ? 'cart' : 'carts'}
    </>
  )
}

function CartIcon() {
  return (
    <i className="bi bi-cart me-1 text-body-secondary" aria-hidden="true" />
  )
}

/** An independent live cart count shared by the storefront and Overview. */
export function ProductCartCount({
  cartCount,
  visible = true,
}: ProductCartCountProps) {
  const label =
    visible && cartCount !== undefined ? cartCountLabel(cartCount) : null

  return (
    <>
      <span
        className="visually-hidden"
        data-id="product-cart-count-status"
        role="status"
        aria-live="polite"
      >
        {label}
      </span>
      {visible && (
        <span className="hilos-stack mw-100 text-break">
          {PRODUCT_COUNT_VARIANTS.map((variant) => (
            <span key={variant} className="invisible" aria-hidden="true" inert>
              <CartIcon />
              {cartCountLabel(variant)}
            </span>
          ))}
          <span data-id="product-cart-count" aria-hidden="true">
            {label !== null && (
              <>
                <CartIcon />
                {label}
              </>
            )}
          </span>
        </span>
      )}
    </>
  )
}
