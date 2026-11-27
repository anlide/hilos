import {
  createSignal,
  registerFocusReturnFallback,
  subscribeSignal,
} from '@hilos/core/base'
import {
  HilosLink,
  LoadingButton,
  useSignal,
  useTrackedAction,
} from '@hilos/react/base'
import {
  Component,
  Fragment,
  createRef,
  useLayoutEffect,
  useMemo,
  useRef,
} from 'react'

import { connection } from '../../bootstrap/connection.js'
import { currentUserId, scopes } from '../../bootstrap/session.js'
import { createCartPageSignal } from '../../cart/cartPageSnapshot.js'
import { availabilityText } from '../../catalog/productAvailability.js'
import { formatDeduction, formatMoney } from '../../money/money.js'
import { PAGE_CART } from '../../pages/keys.js'
import {
  cartIdentity,
  type CartLine,
  type CartSnapshot,
  type CartVoucher,
} from '../../types/Cart.js'
import { CartQuantityControls } from './CartQuantityControls.js'
import { CartQuantityEditModal } from './CartQuantityEditModal.js'
import { CartRemoveButton } from './CartRemoveButton.js'
import CartSkeleton from './CartSkeleton.js'
import { CartVoucherApplyModal } from './CartVoucherApplyModal.js'
import { sendCartRemoveVoucher, type CartOwnerKind } from './cartActions.js'
import { createCartQuantityEdit } from './cartQuantityEdit.js'

const cartPage = createCartPageSignal(
  scopes,
  currentUserId,
  connection,
  PAGE_CART,
)

/** Read the current owner's accepted cart while voucher controls are available. */
function readVoucherCart(): CartSnapshot | null {
  const snapshot = cartPage.get()
  if (
    snapshot === null ||
    snapshot.userId !== currentUserId.get() ||
    snapshot.lines.length === 0
  ) {
    return null
  }

  return snapshot
}

/** A saved selection at the current price and availability. */
function CartItem({
  line,
  ownerKind,
  onEdit,
}: {
  line: CartLine
  ownerKind: CartOwnerKind
  onEdit: () => void
}) {
  const unavailable = line.availableQuantity === 0
  const limited = line.availableQuantity < line.quantity
  const atLimit =
    line.kind === 'stock' && line.availableQuantity === line.quantity

  return (
    <li
      className={`list-group-item d-flex flex-wrap align-items-center gap-3 py-3${unavailable ? ' bg-body-tertiary text-body-secondary' : ''}`}
      data-id="shop-cart-line"
      data-line-id={line.lineId}
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
          <CartQuantityControls
            line={line}
            ownerKind={ownerKind}
            onEdit={onEdit}
          />
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
      <CartRemoveButton
        lineId={line.lineId}
        ownerKind={ownerKind}
        name={line.name}
      />
    </li>
  )
}

/** The selected voucher: what it takes off, or a muted line saying it is switched off. */
function CartVoucherRow({
  voucher,
  owner,
  pageSubscriptionProof,
}: {
  voucher: CartVoucher
  owner: string
  pageSubscriptionProof?: string
}) {
  const remove = useTrackedAction()
  const submitting = useRef(false)

  async function submit(): Promise<void> {
    if (submitting.current) return

    const snapshot = readVoucherCart()
    if (
      snapshot === null ||
      cartIdentity(snapshot) !== owner ||
      snapshot.voucher?.id !== voucher.id ||
      pageSubscriptionProof === undefined ||
      snapshot.pageSubscriptionProof !== pageSubscriptionProof
    ) {
      return
    }

    // Dispatch sends synchronously, before the tracked runner accepts a handle.
    submitting.current = true
    try {
      await remove.run(
        sendCartRemoveVoucher(
          snapshot.voucher.id,
          snapshot.userId === null ? 'guest' : 'account',
          pageSubscriptionProof,
        ),
      )
    } finally {
      submitting.current = false
    }
  }

  return (
    <div
      className={`d-flex justify-content-between gap-2 small mb-1${voucher.active ? '' : ' text-body-secondary'}`}
      data-id="shop-cart-voucher"
    >
      <dt className="fw-normal">
        Voucher <span className="font-monospace">{voucher.code}</span>
        <LoadingButton
          type="button"
          className="btn-link btn-sm p-1 ms-1 text-body-secondary"
          aria-label="Remove voucher"
          data-id="cart-voucher-remove"
          loading={remove.busy}
          onClick={() => void submit()}
        >
          <i className="bi bi-x-circle" aria-hidden="true" />
        </LoadingButton>
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

interface CartRowFocus {
  readonly row: Element
  readonly neighbors: readonly Element[]
}

type CartFocus =
  | CartRowFocus
  | { readonly voucherRemove: HTMLButtonElement }
  | { readonly checkout: HTMLAnchorElement }

interface CartContentsProps {
  readonly cart: CartSnapshot
  readonly onEdit: (lineId: number) => void
  readonly onApplyVoucher: () => void
}

/** Render the accepted snapshot and return focus when it removes a focused row. */
class CartContents extends Component<CartContentsProps> {
  private readonly list = createRef<HTMLUListElement>()
  private readonly empty = createRef<HTMLDivElement>()
  private readonly summary = createRef<HTMLDListElement>()
  private readonly applyVoucher = createRef<HTMLButtonElement>()
  private readonly checkoutActions = createRef<HTMLDivElement>()

  getSnapshotBeforeUpdate(): CartFocus | null {
    const voucherRemove =
      this.summary.current?.querySelector<HTMLButtonElement>(
        '[data-id="cart-voucher-remove"]',
      )
    if (
      voucherRemove &&
      voucherRemove.ownerDocument.activeElement === voucherRemove
    ) {
      return { voucherRemove }
    }

    const checkout =
      this.checkoutActions.current?.querySelector<HTMLAnchorElement>(
        '[data-id="shop-cart-checkout"]',
      )
    if (checkout && checkout.ownerDocument.activeElement === checkout) {
      return { checkout }
    }

    const list = this.list.current
    if (!list) return null

    // Read focus while the old DOM still exists, before React removes a row.
    const active = list.ownerDocument.activeElement
    if (!list.contains(active)) return null

    const rows = Array.from(list.children)
    const focusedIndex = rows.findIndex((row) => row.contains(active))
    const row = rows[focusedIndex]
    if (!row) return null

    return {
      row,
      neighbors: [
        ...rows.slice(focusedIndex + 1),
        ...rows.slice(0, focusedIndex).reverse(),
      ],
    }
  }

  componentDidUpdate(
    _previous: Readonly<CartContentsProps>,
    _state: unknown,
    focus: CartFocus | null,
  ) {
    if (!focus) return

    const fallback = this.empty.current?.querySelector<HTMLAnchorElement>(
      '[data-id="shop-cart-go-shopping"]',
    )
    if ('voucherRemove' in focus) {
      const { voucherRemove } = focus
      if (voucherRemove.isConnected) return

      const { activeElement, body } = voucherRemove.ownerDocument
      if (activeElement?.isConnected && activeElement !== body) return

      const target = this.applyVoucher.current ?? fallback
      target?.focus()
      return
    }

    if ('checkout' in focus) {
      const { checkout } = focus
      if (checkout.isConnected) return

      const { activeElement, body } = checkout.ownerDocument
      if (activeElement?.isConnected && activeElement !== body) return

      fallback?.focus()
      return
    }

    if (focus.row.isConnected) return
    const row = focus.neighbors.find((neighbor) => neighbor.isConnected)
    const remove = (row ?? this.list.current)?.querySelector<HTMLButtonElement>(
      '[data-id="shop-cart-remove-line"]',
    )
    const target = remove ?? fallback
    target?.focus()
  }

  render() {
    const { cart, onEdit, onApplyVoucher } = this.props
    const ownerKind = cart.userId === null ? 'guest' : 'account'
    const owner = cartIdentity(cart)

    return (
      <>
        <h1 className="h4 mb-3">Cart</h1>
        {cart.lines.length === 0 ? (
          <div
            ref={this.empty}
            className="text-center py-5"
            data-id="shop-cart-empty"
          >
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
              <ul
                ref={this.list}
                className="list-group list-group-flush"
                role="list"
              >
                {cart.lines.map((line) => (
                  <CartItem
                    key={`${owner}:${line.lineId}`}
                    line={line}
                    ownerKind={ownerKind}
                    onEdit={() => onEdit(line.lineId)}
                  />
                ))}
              </ul>
            </div>
            <div className="col-lg-4">
              <div className="card">
                <div className="card-body">
                  <dl ref={this.summary} className="mb-3">
                    <div className="d-flex justify-content-between gap-2 small mb-1">
                      <dt className="fw-normal">Items</dt>
                      <dd className="mb-0">{formatMoney(cart.itemsTotal)}</dd>
                    </div>
                    {cart.voucher !== null && (
                      <CartVoucherRow
                        key={owner}
                        voucher={cart.voucher}
                        owner={owner}
                        pageSubscriptionProof={cart.pageSubscriptionProof}
                      />
                    )}
                    <div className="d-flex justify-content-between gap-2 small mb-1">
                      <dt className="fw-normal">Delivery</dt>
                      <dd className="mb-0">to a parcel locker, free</dd>
                    </div>
                    <div className="d-flex justify-content-between gap-2 fw-semibold">
                      <dt>Total</dt>
                      <dd className="mb-0" data-id="shop-cart-total">
                        {formatMoney(cart.total)}
                      </dd>
                    </div>
                  </dl>
                  <div ref={this.checkoutActions} className="d-grid gap-2">
                    <HilosLink
                      to="/checkout"
                      className="btn btn-primary"
                      data-id="shop-cart-checkout"
                    >
                      Checkout
                    </HilosLink>
                    <button
                      ref={this.applyVoucher}
                      key={owner}
                      type="button"
                      className="btn btn-sm btn-outline-secondary"
                      data-id="cart-voucher-apply-open"
                      onClick={(event) => {
                        event.currentTarget.focus()
                        onApplyVoucher()
                      }}
                    >
                      {cart.voucher === null
                        ? 'Apply voucher'
                        : 'Apply another voucher'}
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        )}
      </>
    )
  }
}

interface CartVoucherOpening {
  readonly id: number
  readonly owner: string
}

/** The current owner's public storefront cart. */
export default function Cart() {
  const cart = useSignal(cartPage)
  const edit = useMemo(
    () => createCartQuantityEdit(cartPage, currentUserId, connection),
    [],
  )
  const opened = useSignal(edit.opened)
  const voucherOpening = useMemo(
    () => createSignal<CartVoucherOpening | null>(null),
    [],
  )
  const voucher = useSignal(voucherOpening)
  const nextVoucherId = useRef(0)
  const surface = useRef<HTMLElement>(null)

  useLayoutEffect(edit.start, [edit])
  useLayoutEffect(() => {
    const close = () => voucherOpening.set(null)
    const stopCart = subscribeSignal(cartPage, (snapshot) => {
      const opening = voucherOpening.get()
      if (
        opening !== null &&
        snapshot !== null &&
        (snapshot.lines.length === 0 ||
          cartIdentity(snapshot) !== opening.owner)
      ) {
        close()
      }
    })
    const stopOwner = subscribeSignal(currentUserId, close)
    const stopRotation = connection.on('sessionRotate', close)

    return () => {
      stopCart()
      stopOwner()
      stopRotation()
    }
  }, [voucherOpening])
  useLayoutEffect(() => {
    const root = surface.current
    if (!root) return

    const quantityButton = '[data-id="shop-cart-quantity"]'
    return registerFocusReturnFallback(
      root,
      () => root,
      (opener) => {
        if (!opener.matches(quantityButton)) return null
        const snapshot = cartPage.get()
        const lineId =
          opener.closest<HTMLElement>('[data-line-id]')?.dataset.lineId
        if (snapshot === null || lineId === undefined) return null
        const owner = cartIdentity(snapshot)

        return () => {
          const current = cartPage.get()
          if (current === null || cartIdentity(current) !== owner) return null

          return root.querySelector<HTMLButtonElement>(
            `[data-line-id="${lineId}"] ${quantityButton}`,
          )
        }
      },
    )
  }, [])

  function openVoucher(): void {
    const snapshot = readVoucherCart()
    if (voucherOpening.get() !== null || snapshot === null) return

    voucherOpening.set({
      id: ++nextVoucherId.current,
      owner: cartIdentity(snapshot),
    })
  }

  function voucherOwnerKind(opening: CartVoucherOpening): CartOwnerKind | null {
    const snapshot = readVoucherCart()
    if (
      voucherOpening.get() !== opening ||
      snapshot === null ||
      cartIdentity(snapshot) !== opening.owner
    ) {
      return null
    }

    return snapshot.userId === null ? 'guest' : 'account'
  }

  return (
    <>
      <section ref={surface} aria-label="Cart" tabIndex={-1}>
        {cart === null ? (
          <>
            <span className="visually-hidden" role="status">
              Loading cart…
            </span>
            <CartSkeleton />
          </>
        ) : (
          <CartContents
            cart={cart}
            onEdit={edit.open}
            onApplyVoucher={openVoucher}
          />
        )}
      </section>
      {opened && <CartQuantityEditModal edit={edit} />}
      {/* Release modal focus after the cart has removed an obsolete opener. */}
      <Fragment>
        {voucher !== null && (
          <CartVoucherApplyModal
            key={voucher.id}
            readOwnerKind={() => voucherOwnerKind(voucher)}
            onClose={() => {
              if (voucherOpening.get() === voucher) voucherOpening.set(null)
            }}
          />
        )}
      </Fragment>
    </>
  )
}
