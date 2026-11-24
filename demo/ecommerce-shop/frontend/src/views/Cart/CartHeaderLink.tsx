import { type ReadonlySignal } from '@hilos/core/base'
import { HilosLink, useSignal } from '@hilos/react/base'

import { currentCart, type CartLine } from '../../bootstrap/cart.js'

export interface CartHeaderLinkProps {
  /** The current cart lines signal, defaults to {@link currentCart}. */
  cart?: ReadonlySignal<readonly CartLine[] | null>
}

/**
 * Header link leading to the shopping cart, with an item counter badge.
 *
 * @param props The cart signal to mirror.
 */
export function CartHeaderLink({ cart = currentCart }: CartHeaderLinkProps) {
  const lines = useSignal(cart)
  const totalCount =
    lines === null ? null : lines.reduce((acc, line) => acc + line.quantity, 0)

  const accessibleName =
    totalCount === null
      ? 'Cart'
      : totalCount === 1
        ? 'Cart, 1 item'
        : `Cart, ${totalCount} items`

  return (
    <HilosLink
      to="/cart"
      aria-label={accessibleName}
      className="nav-link position-relative d-inline-flex align-items-center p-0 fs-5"
      data-id="nav-cart"
    >
      <i className="bi bi-cart3" aria-hidden="true" />
      {totalCount !== null && (
        <span
          className="badge rounded-pill bg-danger position-absolute top-0 start-100 translate-middle"
          data-id="cart-badge"
        >
          {totalCount}
        </span>
      )}
    </HilosLink>
  )
}

export default CartHeaderLink
