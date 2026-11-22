// One category's storefront in Tiles and Table views.
import { useLayoutEffect, useState } from 'react'
import { HilosLink, HilosViewportTable } from '@hilos/react/base'

import { kindText } from '../../catalog/catalog.js'
import { formatMoney } from '../../money/money.js'
import {
  PRODUCT_AVAILABILITY_FIELD,
  PRODUCT_KIND_FIELD,
  PRODUCT_NAME_FIELD,
  PRODUCT_PICTURE_URL_FIELD,
  PRODUCT_PRICE_FIELD,
  availabilityText,
  availabilityTone,
} from './categoryProducts.js'
import {
  createCategoryProductsTable,
  type CategoryProductsTable as CategoryProductsTableHandle,
} from './categoryProductsTable.js'

/**
 * Render the category window under the page's HilosPageHeadingIdContext.
 *
 * @param props The mounting page key and the category's numeric id.
 */
export default function CategoryProductsTable({
  page,
  categoryId,
}: {
  page: string
  categoryId: number
}) {
  const [products, setProducts] = useState<CategoryProductsTableHandle | null>(
    null,
  )

  useLayoutEffect(() => {
    // Each setup owns a fresh handle because disposal is permanent.
    const table = createCategoryProductsTable(page, categoryId)
    table.start()
    setProducts(table)
    return () => table.dispose()
  }, [page, categoryId])

  return (
    products && (
      <HilosViewportTable
        controller={products.controller}
        dataId="shop-category-products"
        cells={{
          [PRODUCT_PICTURE_URL_FIELD]: (row) => (
            <img
              src={row.pictureUrl}
              alt={row.pictureAlt}
              width={72}
              height={54}
              loading="lazy"
              className="object-fit-cover"
            />
          ),
          [PRODUCT_NAME_FIELD]: (row) => (
            <HilosLink
              to={`/product/${row.slug}`}
              className="fw-medium text-decoration-none"
            >
              {row.name}
            </HilosLink>
          ),
          [PRODUCT_KIND_FIELD]: (row) => (
            <span className="small text-nowrap">{kindText(row.kind)}</span>
          ),
          [PRODUCT_PRICE_FIELD]: (row) => (
            <span className="text-nowrap">{formatMoney(row.price)}</span>
          ),
          [PRODUCT_AVAILABILITY_FIELD]: (row) => {
            const tone = availabilityTone(row)
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
        }}
      />
    )
  )
}
