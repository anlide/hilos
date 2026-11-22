// The Overview body and editor for the product in the address, under the
// shared product head.
import { useContext, useLayoutEffect, useMemo } from 'react'
import {
  computedSignal,
  hilosAdminAccess,
  hilosTakeoverViewOnly,
} from '@hilos/core/base'
import { HilosRouterContext, useSignal } from '@hilos/react/base'

import { AdminProductHead } from '../../catalog/AdminProductHead.js'
import { formatMoney } from '../../money/money.js'
import { PAGE_ADMIN_PRODUCT } from '../../pages/keys.js'
import { stockParts } from './adminProduct.js'
import { createAdminProductEdit } from './adminProductEdit.js'
import { createAdminProductSignals } from './adminProductPage.js'
import { ProductEditModal } from './ProductEditModal.js'

const writable = computedSignal(
  () => hilosAdminAccess.get() === 'full' && !hilosTakeoverViewOnly.get(),
)

/** One product's admin page, keyed by the product number in its address. */
export default function AdminProduct() {
  const router = useContext(HilosRouterContext)
  if (!router) throw new Error('AdminProduct requires a provided Hilos router.')
  const route = useSignal(router.currentRoute)
  return <ProductCard key={route.params.productId} />
}

function ProductCard() {
  const router = useContext(HilosRouterContext)!
  const signals = useMemo(() => createAdminProductSignals(router), [router])
  const edit = useMemo(
    () =>
      createAdminProductEdit({
        card: signals.editCard,
        awaitingFreshCard: signals.awaitingFreshCard,
        categories: signals.categories,
        writable,
      }),
    [signals],
  )
  useLayoutEffect(() => {
    const stopFreshness = signals.watchFreshness()
    edit.start()
    return () => {
      edit.dispose()
      stopFreshness()
    }
  }, [edit, signals])

  const product = useSignal(signals.product)
  const categoryName = useSignal(signals.categoryName)
  const warehouseName = useSignal(signals.warehouseName)
  const canOpenEdit = useSignal(edit.canOpen)
  const editOpened = useSignal(edit.opened)
  const stock = product ? stockParts(product, warehouseName) : null

  return (
    <AdminProductHead
      page={PAGE_ADMIN_PRODUCT}
      product={product}
      name={signals.name}
      preserveModalDraftsOnDelete={edit.opened.get}
      modals={
        editOpened ? (
          <ProductEditModal edit={edit} categories={signals.categories} />
        ) : null
      }
    >
      {product && stock && (
        <>
          <div className="d-flex flex-wrap justify-content-end gap-2 mb-3">
            <button
              type="button"
              className="btn btn-sm btn-primary"
              disabled={!canOpenEdit}
              data-id="admin-product-edit-open"
              onClick={() => edit.open()}
            >
              Edit
            </button>
          </div>
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
                    <dt className="col-4 fw-normal text-body-secondary">
                      Price
                    </dt>
                    <dd className="col-8">{formatMoney(product.price)}</dd>
                    <dt className="col-4 fw-normal text-body-secondary">
                      Stock
                    </dt>
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
        </>
      )}
    </AdminProductHead>
  )
}
