import { HilosLink, useSignal } from '@hilos/react/base'

import { connection } from '../../bootstrap/connection.js'
import { currentUserId, scopes } from '../../bootstrap/session.js'
import { availabilityText } from '../../catalog/productAvailability.js'
import { formatDeduction, formatMoney } from '../../money/money.js'
import { CartQuantityControls } from './CartQuantityControls.js'
import CartSkeleton from './CartSkeleton.js'
import { type CartOwnerKind } from './cartActions.js'
import { createCartPageSignal } from './cartPage.js'
import { type CartLine, type CartVoucher } from './types/Cart.js'

const cartPage = createCartPageSignal(scopes, currentUserId, connection)

/** A saved selection at the current price and availability. */
function CartItem({
  line,
  ownerKind,
}: {
  line: CartLine
  ownerKind: CartOwnerKind
}) {
  const unavailable = line.availableQuantity === 0
  const limited = line.availableQuantity < line.quantity
  const atLimit =
    line.kind === 'stock' && line.availableQuantity === line.quantity

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
        {line.kind === 'unique' ? (
          <span>1 · one of a kind</span>
        ) : (
          <CartQuantityControls line={line} ownerKind={ownerKind} />
        )}
        {(limited || atLimit) && (
          <span
            className={`badge rounded-pill ${limited && !unavailable ? 'bg-warning-subtle text-warning-emphasis' : 'bg-secondary-subtle text-secondary-emphasis'}`}
          >
            {atLimit
              ? `${line.availableQuantity} in stock`
              : availabilityText(
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

/** The selected voucher: what it takes off, or a muted line saying it is switched off. */
function CartVoucherRow({ voucher }: { voucher: CartVoucher }) {
  return (
    <div
      className={`d-flex justify-content-between gap-2 small mb-1${voucher.active ? '' : ' text-body-secondary'}`}
      data-id="shop-cart-voucher"
    >
      <dt className="fw-normal">
        Voucher <span className="font-monospace">{voucher.code}</span>
        {!voucher.active && ' · Inactive'}
      </dt>
      {voucher.active ? (
        <dd className="mb-0 text-success-emphasis">
          {formatDeduction(voucher.discount)}
        </dd>
      ) : (
        <dd className="mb-0">—</dd>
      )}
    </div>
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

  const ownerKind = cart.userId === null ? 'guest' : 'account'

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
                <CartItem
                  key={`${ownerKind}:${line.lineId}`}
                  line={line}
                  ownerKind={ownerKind}
                />
              ))}
            </ul>
          </div>
          <div className="col-lg-4">
            <div className="card">
              <dl className="card-body mb-0">
                <div className="d-flex justify-content-between gap-2 small mb-1">
                  <dt className="fw-normal">Items</dt>
                  <dd className="mb-0">{formatMoney(cart.itemsTotal)}</dd>
                </div>
                {cart.voucher !== null && (
                  <CartVoucherRow voucher={cart.voucher} />
                )}
                <div className="d-flex justify-content-between gap-2 fw-semibold">
                  <dt>Total</dt>
                  <dd className="mb-0" data-id="shop-cart-total">
                    {formatMoney(cart.total)}
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
