import { useCallback, useId, useRef } from 'react'
import {
  HilosLink,
  LoadingButton,
  useSignal,
  useTrackedAction,
} from '@hilos/react/base'

import {
  pendingProductCartAdds,
  sendProductAddToCart,
} from './productCartActions.js'
import { type ProductDetails } from './types/ProductDetails.js'

interface ProductCartActionProps {
  readonly product: ProductDetails
  readonly cartKnown: boolean
  readonly inCart: boolean
}

interface ProductCartAddButtonProps {
  readonly productId: number
  readonly cartKnown: boolean
  readonly outOfStock: boolean
  readonly hintId?: string
}

function ProductCartAddButton({
  productId,
  cartKnown,
  outOfStock,
  hintId,
}: ProductCartAddButtonProps) {
  const add = useTrackedAction()
  const pending = useSignal(pendingProductCartAdds).has(productId)
  const label = outOfStock ? 'Out of stock' : 'Add to cart'

  function addToCart(): void {
    const handle = sendProductAddToCart(productId)
    if (handle !== null) void add.run(handle)
  }

  return (
    <LoadingButton
      type="button"
      className={outOfStock ? 'btn-secondary' : 'btn-primary'}
      data-id="shop-product-add-to-cart"
      loading={pending}
      disabled={!cartKnown || outOfStock}
      aria-label={label}
      aria-describedby={hintId}
      onClick={addToCart}
    >
      {!outOfStock && <i className="bi bi-cart-plus me-1" aria-hidden="true" />}
      {label}
    </LoadingButton>
  )
}

/** The product's cart control for the confirmed state. */
export function ProductCartAction({
  product,
  cartKnown,
  inCart,
}: ProductCartActionProps) {
  const outOfStock = product.kind === 'stock' && product.units === 0
  const hintId = useId()
  const showHint = product.kind === 'stock' && !outOfStock
  const focusedProduct = useRef<number | null>(null)

  // React detaches the keyed row's ref before removing its focused control.
  const actionRow = useCallback(
    (row: HTMLDivElement | null) => {
      if (row === null) return

      const document = row.ownerDocument
      if (
        focusedProduct.current === product.id &&
        document.activeElement === document.body
      ) {
        const button = row.querySelector('button')
        if (button === null) row.querySelector('a')?.focus()
        else if (!button.disabled) button.focus()
      }
      focusedProduct.current = null

      return () => {
        focusedProduct.current = row.contains(document.activeElement)
          ? product.id
          : null
      }
    },
    [product.id],
  )

  return (
    <>
      <div
        key={inCart ? 'in-cart' : 'add-to-cart'}
        ref={actionRow}
        className="d-flex flex-wrap align-items-center gap-2"
      >
        {inCart ? (
          <HilosLink
            to="/cart"
            className="btn btn-outline-primary"
            data-id="shop-product-go-to-cart"
          >
            <i className="bi bi-cart-check me-1" aria-hidden="true" />
            In your cart — go to cart
          </HilosLink>
        ) : (
          <ProductCartAddButton
            key={product.id}
            productId={product.id}
            cartKnown={cartKnown}
            outOfStock={outOfStock}
            hintId={showHint ? hintId : undefined}
          />
        )}
      </div>
      {showHint && (
        <div id={hintId} className="small text-body-secondary mt-2">
          Adds one. Change the quantity in the cart.
        </div>
      )}
    </>
  )
}
