import { useContext, useMemo } from 'react'
import { type ReadonlySignal } from '@hilos/core/base'
import {
  HilosBreadcrumb,
  HilosRouterContext,
  useSignal,
} from '@hilos/react/base'

import { currentCart, type CartLine } from '../../bootstrap/cart.js'
import { scopes } from '../../bootstrap/session.js'
import { ProductCartCount } from '../../catalog/ProductCartCount.js'
import { ProductViewerCount } from '../../catalog/ProductViewerCount.js'
import { kindText } from '../../catalog/catalog.js'
import {
  availabilityText,
  availabilityTone,
} from '../../catalog/productAvailability.js'
import { formatMoney } from '../../money/money.js'
import { PAGE_CATEGORY, PAGE_MAIN, PAGE_PRODUCT } from '../../pages/keys.js'
import { ProductCartAction } from './ProductCartAction.js'
import ProductSkeleton from './ProductSkeleton.js'
import {
  createProductCartCountSignal,
  createProductDetailsSignal,
  createProductViewerCountSignal,
} from './productPage.js'
import { type ProductDetails } from './types/ProductDetails.js'

interface ProductContentProps {
  readonly product: ProductDetails
  readonly cart?: ReadonlySignal<readonly CartLine[] | null>
  readonly otherViewerCount?: number
  readonly cartCount?: number
}

/** The storefront product with live availability, viewer and cart counts. */
export default function Product() {
  const router = useContext(HilosRouterContext)
  if (!router) throw new Error('Product requires a provided Hilos router.')

  const details = useMemo(
    () => createProductDetailsSignal(scopes, router.currentRoute),
    [router],
  )
  const viewers = useMemo(
    () => createProductViewerCountSignal(scopes, details),
    [details],
  )
  const carts = useMemo(
    () => createProductCartCountSignal(scopes, details),
    [details],
  )
  const product = useSignal(details)
  const otherViewerCount = useSignal(viewers)
  const cartCount = useSignal(carts)
  if (product === null) return <ProductSkeleton />

  return (
    <>
      <HilosBreadcrumb
        crumbs={[
          { page: PAGE_MAIN, label: 'Home', to: '/' },
          {
            page: PAGE_CATEGORY,
            label: product.categoryName,
            to: router.resolvePath(PAGE_CATEGORY, {
              slug: product.categorySlug,
            }),
          },
          { page: PAGE_PRODUCT, label: product.name },
        ]}
        className="text-break"
      />
      <ProductContent
        product={product}
        otherViewerCount={otherViewerCount}
        cartCount={cartCount}
      />
    </>
  )
}

/** The product details and controls for the current personal cart snapshot. */
export function ProductContent({
  product,
  cart = currentCart,
  otherViewerCount,
  cartCount,
}: ProductContentProps) {
  const lines = useSignal(cart)
  const unique = product.kind === 'unique'
  const inCart =
    unique && (lines?.some((line) => line.productId === product.id) ?? false)
  const kindTone = unique ? 'info' : 'secondary'
  const statusTone = inCart ? 'primary' : availabilityTone(product.availability)
  const statusIcon = inCart ? 'bi-cart-check' : 'bi-gem'
  const showCounts = product.availability !== 'out_of_stock'
  const showCartHint = cartCount !== undefined && cartCount > 0

  return (
    <div className="row g-4">
      <div className="col-md-5">
        <div className="ratio ratio-4x3 rounded overflow-hidden">
          <img
            src={product.pictureUrl}
            alt={product.name}
            className="object-fit-cover"
          />
        </div>
      </div>
      <div className="col-md-7">
        <h1 className="h4 mb-1 text-break">{product.name}</h1>
        <div className="d-flex flex-wrap align-items-center gap-2 mb-3">
          <span
            className={`badge rounded-pill bg-${kindTone}-subtle text-${kindTone}-emphasis border border-${kindTone}-subtle`}
          >
            <i
              className={`bi ${unique ? 'bi-gem' : 'bi-boxes'} me-1`}
              aria-hidden="true"
            />
            {kindText(product.kind)}
          </span>
          <span
            className={`badge rounded-pill bg-${statusTone}-subtle text-${statusTone}-emphasis border border-${statusTone}-subtle`}
            data-id="shop-product-availability"
          >
            {(inCart || product.availability === 'free') && (
              <i className={`bi ${statusIcon} me-1`} aria-hidden="true" />
            )}
            {inCart
              ? 'In your cart'
              : availabilityText(product.availability, product.units)}
          </span>
        </div>
        <div className="h4 mb-3" data-id="shop-product-price">
          {formatMoney(product.price)}
        </div>
        <p className="text-body-secondary text-break">{product.description}</p>
        <div className={showCounts ? 'border rounded p-2 mb-3' : undefined}>
          <div className="d-flex flex-wrap gap-3 small">
            <ProductViewerCount
              count={otherViewerCount}
              surface="shop"
              visible={showCounts}
            />
            <ProductCartCount cartCount={cartCount} visible={showCounts} />
          </div>
          {showCounts && unique && (
            <div
              className={`small text-body-secondary mt-1${showCartHint ? '' : ' invisible'}`}
              aria-hidden={showCartHint ? undefined : true}
              inert={!showCartHint}
            >
              A cart does not hold the bouquet: whoever pays first gets it.
            </div>
          )}
        </div>
        <ProductCartAction
          product={product}
          cartKnown={lines !== null}
          inCart={inCart}
        />
      </div>
    </div>
  )
}
