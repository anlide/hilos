// The Overview body and editor for the product in the address, under the
// shared product head.
import { useCallback, useContext, useLayoutEffect, useMemo } from 'react'
import {
  computedSignal,
  hilosAdminAccess,
  hilosTakeoverViewOnly,
} from '@hilos/core/base'
import { HilosLink, HilosRouterContext, useSignal } from '@hilos/react/base'

import { AdminProductHead } from '../../catalog/AdminProductHead.js'
import { ProductCartCount } from '../../catalog/ProductCartCount.js'
import { ProductViewerCount } from '../../catalog/ProductViewerCount.js'
import { formatMoney } from '../../money/money.js'
import { PAGE_ADMIN_PRODUCT, PAGE_PRODUCT } from '../../pages/keys.js'
import { stockParts } from './adminProduct.js'
import { createAdminProductEdit } from './adminProductEdit.js'
import { createAdminProductSignals } from './adminProductPage.js'
import { createAdminProductWarehouseEdit } from './adminProductWarehouseEdit.js'
import { AdminProductWarehouseModal } from './AdminProductWarehouseModal.js'
import { ProductEditModal } from './ProductEditModal.js'

const writable = computedSignal(
  () => hilosAdminAccess.get() === 'full' && !hilosTakeoverViewOnly.get(),
)

/** One product's admin page, keyed by the product number in its address. */
export default function AdminProduct() {
  const router = useContext(HilosRouterContext)
  if (!router) throw new Error('AdminProduct requires a provided Hilos router.')
  const route = useSignal(router.currentRoute)
  const { productId } = route.params
  return <ProductCard key={productId} productId={Number(productId)} />
}

function ProductCard({ productId }: { productId: number }) {
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
  const warehouseEdit = useMemo(
    () =>
      createAdminProductWarehouseEdit({
        productId,
        card: signals.editCard,
        warehouses: signals.warehouses,
        writable,
      }),
    [productId, signals],
  )
  const preserveModalDraftsOnDelete = useCallback(
    () => edit.opened.get() || warehouseEdit.opened.get(),
    [edit, warehouseEdit],
  )
  useLayoutEffect(() => {
    const stopFreshness = signals.watchFreshness()
    edit.start()
    warehouseEdit.start()
    return () => {
      warehouseEdit.dispose()
      edit.dispose()
      stopFreshness()
    }
  }, [edit, signals, warehouseEdit])

  const product = useSignal(signals.product)
  const cartCount = useSignal(signals.cartCount)
  const categoryName = useSignal(signals.categoryName)
  const warehouseName = useSignal(signals.warehouseName)
  const canOpenEdit = useSignal(edit.canOpen)
  const editOpened = useSignal(edit.opened)
  const canOpenWarehouse = useSignal(warehouseEdit.canOpen)
  const warehouseOpened = useSignal(warehouseEdit.opened)
  const stock = product ? stockParts(product, warehouseName) : null
  const shopPath =
    product && product.slug !== ''
      ? router.resolvePath(PAGE_PRODUCT, { slug: product.slug })
      : undefined
  const showShopLink =
    Boolean(shopPath) &&
    product !== null &&
    product.slug !== '' &&
    (product.kind !== 'unique' || product.stock.units > 0)

  return (
    <AdminProductHead
      page={PAGE_ADMIN_PRODUCT}
      product={product}
      name={signals.name}
      preserveModalDraftsOnDelete={preserveModalDraftsOnDelete}
      modals={
        <>
          {editOpened && (
            <ProductEditModal edit={edit} categories={signals.categories} />
          )}
          {warehouseOpened && (
            <AdminProductWarehouseModal
              edit={warehouseEdit}
              warehouses={signals.warehouses}
            />
          )}
        </>
      }
    >
      {product && stock && (
        <>
          <div className="d-flex flex-wrap justify-content-end gap-2 mb-3">
            {showShopLink && shopPath && (
              <HilosLink
                to={shopPath}
                className="btn btn-sm btn-outline-secondary"
              >
                <i
                  className="bi bi-box-arrow-up-right me-1"
                  aria-hidden="true"
                />
                See in the shop
              </HilosLink>
            )}
            {product.kind === 'unique' && (
              <button
                type="button"
                className="btn btn-sm btn-outline-secondary"
                disabled={!canOpenWarehouse}
                data-id="admin-product-warehouse-open"
                onClick={() => warehouseEdit.open()}
              >
                {product.stock.warehouseId === null
                  ? 'Put in a warehouse'
                  : 'Move to another warehouse'}
              </button>
            )}
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
                    <dd className="col-8">
                      {stock.lead}
                      {stock.strong !== null && <strong>{stock.strong}</strong>}
                      {stock.tail}
                    </dd>
                    <dt className="col-4 fw-normal text-body-secondary">
                      On the shop front
                    </dt>
                    <dd
                      className="col-8 mb-0"
                      data-id="admin-product-shop-front"
                    >
                      <div className="d-flex flex-wrap gap-3">
                        <ProductViewerCount
                          count={product.viewerCount}
                          surface="admin"
                        />
                        <ProductCartCount cartCount={cartCount} />
                      </div>
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
