import { type ReactNode } from 'react'
import { type ReadonlySignal } from '@hilos/core/base'
import { HilosAdminPage, usePageInstanceName } from '@hilos/react/base'

import { scopes } from '../bootstrap/session.js'
import { PAGE_ADMIN_PRODUCT } from '../pages/keys.js'
import { type AdminProduct } from '../types/AdminProduct.js'
import { type AdminProductPageKey } from './adminProductCard.js'
import { kindText } from './catalog.js'

interface AdminProductHeadProps {
  /** The current product page, used to mark its navigation link. */
  page: AdminProductPageKey
  /** The addressed product, if its card has arrived. */
  product: AdminProduct | null
  /** The live name bound to the Overview owner on either page. */
  name: ReadonlySignal<string | undefined>
  /** The current page's body. */
  children: ReactNode
}

/** The shared head and navigation of the product's admin pages. */
export function AdminProductHead({
  page,
  product,
  name,
  children,
}: AdminProductHeadProps) {
  usePageInstanceName(scopes, PAGE_ADMIN_PRODUCT, name)

  return (
    <HilosAdminPage
      page={page}
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
      {children}
    </HilosAdminPage>
  )
}
