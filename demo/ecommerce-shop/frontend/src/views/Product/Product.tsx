import { useContext, useMemo } from 'react'
import { type ReadonlySignal } from '@hilos/core/base'
import {
  HilosBreadcrumb,
  HilosRouterContext,
  useSignal,
} from '@hilos/react/base'

import { currentCart, type CartLine } from '../../bootstrap/cart.js'
import { scopes } from '../../bootstrap/session.js'
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
  createProductDetailsSignal,
  createProductViewerCountSignal,
} from './productPage.js'
import { type ProductDetails } from './types/ProductDetails.js'

interface ProductContentProps {
  readonly product: ProductDetails
  readonly cart?: ReadonlySignal<readonly CartLine[] | null>
  readonly otherViewerCount?: number
}

/** The public storefront product, live availability, and other viewers. */
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
  const product = useSignal(details)
  const otherViewerCount = useSignal(viewers)
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
      <ProductContent product={product} otherViewerCount={otherViewerCount} />
    </>
  )
}

/** The product details and controls for the current personal cart snapshot. */
export function ProductContent({
  product,
  cart = currentCart,
  otherViewerCount,
}: ProductContentProps) {
  const lines = useSignal(cart)
  const unique = product.kind === 'unique'
  const inCart =
    unique && (lines?.some((line) => line.productId === product.id) ?? false)
  const kindTone = unique ? 'info' : 'secondary'
  const statusTone = inCart ? 'primary' : availabilityTone(product.availability)
  const statusIcon = inCart ? 'bi-cart-check' : 'bi-gem'

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
        <ProductViewerCount
          count={otherViewerCount}
          surface="shop"
          visible={product.availability !== 'out_of_stock'}
        />
        <ProductCartAction
          product={product}
          cartKnown={lines !== null}
          inCart={inCart}
        />
      </div>
    </div>
  )
}
