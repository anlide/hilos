// Products under their shared administration section head. The SDK owns the
// search, filters, paging and narrow-screen cards; this view supplies the cells.
// A product's name and its Open link lead to that product's own page.
import { useContext, useLayoutEffect, useState } from 'react'
import {
  HILOS_TABLE_ACTIONS_KEY,
  formatMoment,
  hilosTimeZone,
} from '@hilos/core/base'
import {
  HilosAdminPage,
  HilosLink,
  HilosRouterContext,
  HilosViewportTable,
  useSignal,
} from '@hilos/react/base'

import { kindText } from '../../catalog/catalog.js'
import { formatMoney } from '../../money/money.js'
import { PAGE_ADMIN_PRODUCT, PAGE_ADMIN_PRODUCTS } from '../../pages/keys.js'
import {
  PRODUCT_AVAILABILITY_FIELD,
  PRODUCT_CATEGORY_NAME_FIELD,
  PRODUCT_CHANGED_AT_FIELD,
  PRODUCT_KIND_FIELD,
  PRODUCT_NAME_FIELD,
  PRODUCT_PRICE_FIELD,
  PRODUCT_UNITS_FIELD,
  availabilityText,
  availabilityTone,
  stockText,
} from './adminProducts.js'
import { createProductsTable, type ProductsTable } from './adminProductsPage.js'
import { ProductAddModal } from './ProductAddModal.js'

/** The Products administration page with its live table and Add product dialog. */
export default function AdminProducts() {
  const router = useContext(HilosRouterContext)
  const [products, setProducts] = useState<ProductsTable | null>(null)
  const [adding, setAdding] = useState(false)
  useSignal(hilosTimeZone)

  useLayoutEffect(() => {
    // Disposal is permanent; each setup, including StrictMode replay, owns one.
    const table = createProductsTable(() => setAdding(true))
    table.start()
    setProducts(table)
    return () => table.dispose()
  }, [])

  /**
   * The address of one product's page, or undefined where no route builds it.
   *
   * @param productId The product's number.
   */
  const productPath = (productId: number): string | undefined =>
    router?.resolvePath(PAGE_ADMIN_PRODUCT, { productId: String(productId) })

  return (
    <HilosAdminPage page={PAGE_ADMIN_PRODUCTS} headKind="section">
      {products && (
        <HilosViewportTable
          controller={products.controller}
          dataId="admin-products-table"
          cells={{
            [PRODUCT_NAME_FIELD]: (row) => {
              const path = productPath(row.id)
              return (
                <div className="d-flex align-items-center gap-2">
                  <img
                    src={row.pictureUrl}
                    alt=""
                    width={36}
                    height={27}
                    loading="lazy"
                    className="d-none d-md-block flex-shrink-0 rounded object-fit-cover"
                  />
                  {path ? (
                    <HilosLink
                      to={path}
                      className="fw-medium text-decoration-none"
                    >
                      {row.name}
                    </HilosLink>
                  ) : (
                    <span className="fw-medium">{row.name}</span>
                  )}
                </div>
              )
            },
            [PRODUCT_CATEGORY_NAME_FIELD]: (row) => (
              <span className="small">{row.categoryName}</span>
            ),
            [PRODUCT_KIND_FIELD]: (row) => (
              <span className="small text-nowrap">{kindText(row.kind)}</span>
            ),
            [PRODUCT_PRICE_FIELD]: (row) => formatMoney(row.price),
            [PRODUCT_UNITS_FIELD]: (row) => (
              <span className="small">{stockText(row)}</span>
            ),
            [PRODUCT_AVAILABILITY_FIELD]: (row) => {
              const tone = availabilityTone(row.availability)
              return (
                <span
                  className={`badge rounded-pill bg-${tone}-subtle text-${tone}-emphasis border border-${tone}-subtle`}
                >
                  {row.availability === 'free' && (
                    <i className="bi bi-gem me-1" aria-hidden="true" />
                  )}
                  {availabilityText(row)}
                </span>
              )
            },
            [PRODUCT_CHANGED_AT_FIELD]: (row) => (
              <span className="small">
                {formatMoment(row.changedAt, { kind: 'dateTime' }) ?? ''}
              </span>
            ),
            [HILOS_TABLE_ACTIONS_KEY]: (row) => {
              const path = productPath(row.id)
              return path ? (
                <HilosLink
                  to={path}
                  className="btn btn-sm btn-outline-secondary"
                  aria-label={`Open ${row.name}`}
                >
                  Open
                </HilosLink>
              ) : null
            },
          }}
        />
      )}
      {adding && <ProductAddModal onClose={() => setAdding(false)} />}
    </HilosAdminPage>
  )
}
