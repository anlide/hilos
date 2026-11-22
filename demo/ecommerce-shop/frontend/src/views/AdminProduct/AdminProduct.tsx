// One product under the shared entity head: its live name, kind and whole
// description, then the picture and the card of category, price and stock.
// The view is made anew for every number in the address, so Back or Forward to
// another product never shows the previous card under the new number.
import { useContext, useMemo } from 'react'
import {
  HilosAdminPage,
  HilosRouterContext,
  usePageInstanceName,
  useSignal,
} from '@hilos/react/base'

import { scopes } from '../../bootstrap/session.js'
import { kindText } from '../../catalog/catalog.js'
import { formatMoney } from '../../money/money.js'
import { PAGE_ADMIN_PRODUCT } from '../../pages/keys.js'
import { stockParts } from './adminProduct.js'
import { createAdminProductSignals } from './adminProductPage.js'

/** One product's admin page, keyed by the product number in its address. */
export default function AdminProduct() {
  const router = useContext(HilosRouterContext)
  if (!router) throw new Error('AdminProduct requires a provided Hilos router.')
  const route = useSignal(router.currentRoute)
  return <ProductCard key={route.params.productId} />
}

function ProductCard() {
  const router = useContext(HilosRouterContext)!
  const signals = useMemo(
    () => createAdminProductSignals(router.currentRoute),
    [router],
  )
  usePageInstanceName(scopes, PAGE_ADMIN_PRODUCT, signals.name)
  const product = useSignal(signals.product)
  const categoryName = useSignal(signals.categoryName)
  const warehouseName = useSignal(signals.warehouseName)
  const stock = product ? stockParts(product, warehouseName) : null

  return (
    <HilosAdminPage
      page={PAGE_ADMIN_PRODUCT}
      headKind="entity"
      tabsLabel="Product pages"
      headBadges={
        product ? (
          product.kind === 'unique' ? (
            <span className="badge rounded-pill bg-info-subtle text-info-emphasis border border-info-subtle">
              <i className="bi bi-gem me-1" aria-hidden="true" />
              {kindText(product.kind)}
            </span>
          ) : (
            <span className="badge rounded-pill bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
              <i className="bi bi-boxes me-1" aria-hidden="true" />
              {kindText(product.kind)}
            </span>
          )
        ) : undefined
      }
      headDescription={
        product ? (
          <p className="text-body-secondary mb-0 text-break">
            {product.description}
          </p>
        ) : undefined
      }
    >
      {product && stock && (
        <div className="row g-3">
          <div className="col-lg-4">
            <img
              src={product.pictureUrl}
              alt=""
              className="img-fluid rounded"
            />
          </div>
          <div className="col-lg-8">
            <div className="card">
              <div className="card-body">
                <dl className="row small mb-0 g-0">
                  <dt className="col-4 fw-normal text-body-secondary">
                    Category
                  </dt>
                  <dd className="col-8">{categoryName ?? '—'}</dd>
                  <dt className="col-4 fw-normal text-body-secondary">Price</dt>
                  <dd className="col-8">{formatMoney(product.price)}</dd>
                  <dt className="col-4 fw-normal text-body-secondary">Stock</dt>
                  <dd className="col-8 mb-0">
                    {stock.lead}
                    {stock.strong !== null && <strong>{stock.strong}</strong>}
                    {stock.tail}
                  </dd>
                </dl>
              </div>
            </div>
          </div>
        </div>
      )}
    </HilosAdminPage>
  )
}
