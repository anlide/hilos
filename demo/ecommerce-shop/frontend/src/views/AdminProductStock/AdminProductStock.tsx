import { useContext, useLayoutEffect, useMemo, useState } from 'react'
import {
  HilosRouterContext,
  HilosViewportTable,
  useSignal,
} from '@hilos/react/base'

import { scopes } from '../../bootstrap/session.js'
import { AdminProductHead } from '../../catalog/AdminProductHead.js'
import { createAdminProductCardSignals } from '../../catalog/adminProductCard.js'
import { PAGE_ADMIN_PRODUCT_STOCK } from '../../pages/keys.js'
import {
  STOCK_UNITS_FIELD,
  STOCK_WAREHOUSE_NAME_FIELD,
  createProductStockTable,
  type ProductStockTable,
} from './adminProductStockPage.js'

/** The addressed product's stock in each warehouse. */
export default function AdminProductStock() {
  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error('AdminProductStock requires a provided Hilos router.')
  }
  const card = useMemo(
    () =>
      createAdminProductCardSignals(
        scopes,
        router.currentRoute,
        PAGE_ADMIN_PRODUCT_STOCK,
      ),
    [router],
  )
  const product = useSignal(card.product)

  return (
    <AdminProductHead
      page={PAGE_ADMIN_PRODUCT_STOCK}
      product={product}
      name={card.name}
    >
      {product && (
        <StockWindow key={product.productId} productId={product.productId} />
      )}
    </AdminProductHead>
  )
}

function StockWindow({ productId }: { productId: number }) {
  const [table, setTable] = useState<ProductStockTable | null>(null)

  useLayoutEffect(() => {
    // Each setup owns its controller so StrictMode can replay the mount.
    const stock = createProductStockTable(productId)
    stock.start()
    setTable(stock)
    return () => stock.dispose()
  }, [productId])

  return table ? (
    <HilosViewportTable
      controller={table.controller}
      dataId="admin-product-stock-table"
      cells={{
        [STOCK_WAREHOUSE_NAME_FIELD]: (row) => (
          <span className="fw-medium">{row.warehouseName}</span>
        ),
        [STOCK_UNITS_FIELD]: (row) => row.units,
      }}
    />
  ) : null
}
