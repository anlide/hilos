import { HilosLink, useSignal } from '@hilos/react/base'

import { connection } from '../../bootstrap/connection.js'
import { currentUserId, scopes } from '../../bootstrap/session.js'
import { availabilityText } from '../../catalog/productAvailability.js'
import { formatMoney } from '../../money/money.js'
import CartSkeleton from './CartSkeleton.js'
import { createCartPageSignal } from './cartPage.js'
import { type CartLine } from './types/Cart.js'

const cartPage = createCartPageSignal(scopes, currentUserId, connection)

/** A saved selection at the current price and availability. */
function CartItem({ line }: { line: CartLine }) {
  const unavailable = line.availableQuantity === 0
  const limited = line.availableQuantity < line.quantity

  return (
    <li
      className={`list-group-item d-flex flex-wrap align-items-center gap-3 py-3${unavailable ? ' bg-body-tertiary text-body-secondary' : ''}`}
      data-id="shop-cart-line"
    >
      <img
        src={line.pictureUrl}
        alt=""
        width={72}
        height={54}
        className="rounded object-fit-cover"
      />
      <div className="flex-grow-1 text-break">
        <HilosLink
          to={`/product/${encodeURIComponent(line.slug)}`}
          className="fw-medium"
          data-id="shop-cart-product-link"
        >
          {line.name}
        </HilosLink>
        <div className="small text-body-secondary">
          {formatMoney(line.price)}
          {line.kind === 'stock' && ' each'}
        </div>
      </div>
      <div className="d-flex flex-wrap align-items-center gap-2 small">
        <span>
          {line.kind === 'unique'
            ? '1 · one of a kind'
            : `Quantity: ${line.quantity}`}
        </span>
        {limited && (
          <span
            className={`badge rounded-pill ${unavailable ? 'bg-secondary-subtle text-secondary-emphasis' : 'bg-warning-subtle text-warning-emphasis'}`}
          >
            {availabilityText(
              unavailable ? 'out_of_stock' : 'low',
              line.availableQuantity,
            )}
          </span>
        )}
      </div>
      <span
        className="ms-auto fw-semibold text-nowrap"
        data-id="shop-cart-line-total"
      >
        {formatMoney(line.lineTotal)}
      </span>
    </li>
  )
}

/** The current owner's public storefront cart. */
export default function Cart() {
  const cart = useSignal(cartPage)
  if (cart === null) {
    return (
      <>
        <span className="visually-hidden" role="status">
          Loading cart…
        </span>
        <CartSkeleton />
      </>
    )
  }

  const itemsTotal = formatMoney(cart.itemsTotal)

  return (
    <>
      <h1 className="h4 mb-3">Cart</h1>
      {cart.lines.length === 0 ? (
        <div className="text-center py-5" data-id="shop-cart-empty">
          <i
            className="bi bi-cart fs-2 text-body-secondary mb-2 d-block"
            aria-hidden="true"
          />
          <h2 className="h6 mb-1">Your cart is empty</h2>
          <p className="small text-body-secondary mb-3">
            Add something from the shop. It stays here
            {cart.userId === null && ' for this browser session'}.
          </p>
          <HilosLink
            to="/"
            className="btn btn-sm btn-primary"
            data-id="shop-cart-go-shopping"
          >
            Go shopping
          </HilosLink>
        </div>
      ) : (
        <div className="row g-4">
          <div className="col-lg-8">
            <ul className="list-group list-group-flush" role="list">
              {cart.lines.map((line) => (
                <CartItem key={line.productId} line={line} />
              ))}
            </ul>
          </div>
          <div className="col-lg-4">
            <div className="card">
              <dl className="card-body mb-0">
                <div className="d-flex justify-content-between gap-2 small mb-1">
                  <dt className="fw-normal">Items</dt>
                  <dd className="mb-0">{itemsTotal}</dd>
                </div>
                <div className="d-flex justify-content-between gap-2 fw-semibold">
                  <dt>Total</dt>
                  <dd className="mb-0" data-id="shop-cart-total">
                    {itemsTotal}
                  </dd>
                </div>
              </dl>
            </div>
          </div>
        </div>
      )}
    </>
  )
}
