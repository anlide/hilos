import { Component, createRef, useId } from 'react'
import { HilosBreadcrumb, HilosLink, useSignal } from '@hilos/react/base'

import { connection } from '../../bootstrap/connection.js'
import { currentUserId, scopes } from '../../bootstrap/session.js'
import { createCartPageSignal } from '../../cart/cartPageSnapshot.js'
import { availabilityText } from '../../catalog/productAvailability.js'
import { formatDeduction, formatMoney } from '../../money/money.js'
import { PAGE_CART, PAGE_CHECKOUT } from '../../pages/keys.js'
import type { CartLine, CartSnapshot } from '../../types/Cart.js'
import CheckoutSkeleton from './CheckoutSkeleton.js'

const checkoutPage = createCartPageSignal(
  scopes,
  currentUserId,
  connection,
  PAGE_CHECKOUT,
)

function CheckoutLine({ line }: { line: CartLine }) {
  const unavailable = line.availableQuantity === 0
  const limited = line.availableQuantity < line.quantity

  return (
    <li
      className={`d-flex flex-wrap align-items-center gap-2 small mb-1${unavailable ? ' bg-body-tertiary text-body-secondary' : ''}`}
      data-id="shop-checkout-line"
    >
      <span className="text-break">
        {line.name}
        {line.quantity > 1 && ` × ${line.quantity}`}
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
      <span className="ms-auto text-nowrap">{formatMoney(line.lineTotal)}</span>
    </li>
  )
}

function CheckoutOrder({ cart }: { cart: CartSnapshot }) {
  const { lines, voucher, total } = cart

  return (
    <div className="card">
      <div className="card-body">
        <h2 className="h6 mb-3">Your order</h2>
        <ul className="list-unstyled mb-2" role="list">
          {lines.map((line) => (
            <CheckoutLine key={line.lineId} line={line} />
          ))}
        </ul>
        <dl className="mb-0">
          {voucher !== null && (
            <div
              className={`d-flex justify-content-between gap-2 small mb-1${voucher.active ? '' : ' text-body-secondary'}`}
              data-id="shop-checkout-voucher"
            >
              <dt className="fw-normal text-break">
                Voucher <span className="font-monospace">{voucher.code}</span>
                {!voucher.active && ' · Inactive'}
              </dt>
              {voucher.active ? (
                <dd className="mb-0 text-success-emphasis text-nowrap">
                  {formatDeduction(voucher.discount)}
                </dd>
              ) : (
                <dd className="mb-0">—</dd>
              )}
            </div>
          )}
          <div className="d-flex justify-content-between gap-2 border-top mt-2 pt-2 fw-semibold">
            <dt>Total</dt>
            <dd className="mb-0" data-id="shop-checkout-total">
              {formatMoney(total)}
            </dd>
          </div>
        </dl>
      </div>
    </div>
  )
}

/** The account's live cart and delivery summary before payment. */
export default function Checkout() {
  const cart = useSignal(checkoutPage)
  const paymentHintId = useId()
  if (cart === null) return <CheckoutSkeleton />

  return <CheckoutContents cart={cart} paymentHintId={paymentHintId} />
}

interface CheckoutContentsProps {
  readonly cart: CartSnapshot
  readonly paymentHintId: string
}

/** Keep keyboard focus on the action when a live snapshot replaces it. */
class CheckoutContents extends Component<CheckoutContentsProps> {
  private readonly actions = createRef<HTMLDivElement>()

  getSnapshotBeforeUpdate(): HTMLAnchorElement | null {
    // Read focus before React removes the old branch and its link.
    const action = this.actions.current?.querySelector('a')
    return action && action.ownerDocument.activeElement === action
      ? action
      : null
  }

  componentDidUpdate(
    _previous: Readonly<CheckoutContentsProps>,
    _state: unknown,
    action: HTMLAnchorElement | null,
  ) {
    if (!action || action.isConnected) return

    const { activeElement, body } = action.ownerDocument
    if (activeElement?.isConnected && activeElement !== body) return

    this.actions.current?.querySelector('a')?.focus()
  }

  render() {
    const { cart, paymentHintId } = this.props

    const backToCart = (
      <HilosLink
        to="/cart"
        className="btn btn-outline-secondary"
        data-id="shop-checkout-back-to-cart"
      >
        Back to cart
      </HilosLink>
    )

    return (
      <>
        <HilosBreadcrumb
          crumbs={[
            { page: PAGE_CART, label: 'Cart', to: '/cart' },
            { page: PAGE_CHECKOUT, label: 'Checkout' },
          ]}
        />
        <h1 className="h4 mb-3">Checkout</h1>
        {cart.lines.length === 0 ? (
          <div
            ref={this.actions}
            className="text-center py-5"
            data-id="shop-checkout-empty"
          >
            <h2 className="h6 mb-1">Your cart is empty</h2>
            <p className="small text-body-secondary mb-3">
              Add something from the shop. It stays here.
            </p>
            <HilosLink
              to="/"
              className="btn btn-sm btn-primary"
              data-id="shop-checkout-go-shopping"
            >
              Go shopping
            </HilosLink>
          </div>
        ) : cart.lines.every((line) => line.availableQuantity === 0) ? (
          <div
            ref={this.actions}
            className="text-center py-5"
            data-id="shop-checkout-unavailable"
          >
            <h2 className="h6 mb-3">
              Nothing in your cart can be bought right now
            </h2>
            {backToCart}
          </div>
        ) : (
          <div className="row g-4">
            <div className="col-lg-7">
              <div className="alert alert-info small">
                This is a demo shop — no money is charged.
              </div>
              <div className="card mb-3">
                <div className="card-body">
                  <h2 className="h6 mb-2">Delivery</h2>
                  <p className="small mb-0">
                    To a parcel locker, free. The demo picks the locker for you.
                  </p>
                </div>
              </div>
              <div ref={this.actions} className="d-flex flex-wrap gap-2">
                {backToCart}
                <button
                  type="button"
                  className="btn btn-primary"
                  disabled
                  aria-describedby={paymentHintId}
                  data-id="shop-checkout-pay"
                >
                  Pay {formatMoney(cart.total)}
                </button>
              </div>
              <p
                id={paymentHintId}
                className="small text-body-secondary mt-2 mb-0"
              >
                Paying is not connected yet — it comes with the next step of the
                demo.
              </p>
            </div>
            <div className="col-lg-5">
              <CheckoutOrder cart={cart} />
            </div>
          </div>
        )}
      </>
    )
  }
}
