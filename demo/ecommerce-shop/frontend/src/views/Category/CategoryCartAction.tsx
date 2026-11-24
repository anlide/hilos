import { useCallback, useRef } from 'react'
import {
  HilosLink,
  LoadingButton,
  useSignal,
  useTrackedAction,
} from '@hilos/react/base'

import { currentCart } from '../../bootstrap/cart.js'
import { sendCategoryAddToCart } from './categoryCartActions.js'
import { type CategoryProductRow } from './types/tables/CategoryProductRow.js'

interface CategoryCartActionProps {
  readonly row: CategoryProductRow
  readonly pending: boolean
  readonly trackAdd: (
    productId: number,
    submit: () => Promise<boolean>,
  ) => Promise<void>
}

/** The cart control for the current storefront row and personal snapshot. */
export function CategoryCartAction({
  row,
  pending,
  trackAdd,
}: CategoryCartActionProps) {
  const cart = useSignal(currentCart)
  const add = useTrackedAction()
  const available =
    Number.isSafeInteger(row.id) &&
    row.id > 0 &&
    row.units > 0 &&
    ((row.kind === 'unique' && row.availability === 'free') ||
      (row.kind === 'stock' &&
        (row.availability === 'low' || row.availability === 'in_stock')))
  const inCart =
    available &&
    row.kind === 'unique' &&
    cart?.some((line) => line.productId === row.id) === true
  const focusedProduct = useRef<number | null>(null)

  // Capture focus before React removes the Add control from this projection.
  const actionRow = useCallback(
    (element: HTMLDivElement | null) => {
      if (element === null) return

      const document = element.ownerDocument
      if (
        inCart &&
        focusedProduct.current === row.id &&
        document.activeElement === document.body
      ) {
        element.querySelector('a')?.focus()
      }
      focusedProduct.current = null

      return () => {
        focusedProduct.current =
          !inCart && document.activeElement === element.firstElementChild
            ? row.id
            : null
      }
    },
    [inCart, row.id],
  )

  return (
    <div key={inCart ? 'in-cart' : 'add-to-cart'} ref={actionRow}>
      {inCart ? (
        <HilosLink
          to="/cart"
          className="btn btn-sm btn-outline-primary"
          aria-label={`In cart · ${row.name}`}
          data-id="shop-category-go-to-cart"
        >
          In cart
        </HilosLink>
      ) : (
        <LoadingButton
          className={
            available ? 'btn-sm btn-primary' : 'btn-sm btn-outline-secondary'
          }
          aria-label={`Add to cart · ${row.name}`}
          data-id="shop-category-add-to-cart"
          loading={pending}
          disabled={cart === null || !available}
          onClick={() => {
            void trackAdd(row.id, () => add.run(sendCategoryAddToCart(row.id)))
          }}
        >
          {available ? (
            <i className="bi bi-cart-plus" aria-hidden="true" />
          ) : (
            'Add'
          )}
        </LoadingButton>
      )}
    </div>
  )
}
