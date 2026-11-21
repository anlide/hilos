// The shop's Categories admin page: every category in catalog order with its
// picture, description, the number of products it holds, and category reorder
// actions. The table sits under the section head of its own dashboard group.
// Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo } from 'react'
import { HILOS_TABLE_ACTIONS_KEY } from '@hilos/core/base'
import { HilosAdminPage, HilosViewportTable } from '@hilos/react/base'

import { PAGE_ADMIN_CATEGORIES } from '../../pages/keys.js'
import {
  CATEGORY_NAME_FIELD,
  CATEGORY_PICTURE_FIELD,
  CATEGORY_PRODUCT_COUNT_FIELD,
  createCategoriesTable,
} from './adminCategoriesPage.js'
import { CategoryMoveUpButton } from './CategoryMoveUpButton.js'

/** The categories admin page. */
export default function AdminCategories() {
  const categories = useMemo(() => createCategoriesTable(), [])

  // Bind the table on mount, consuming its initial page window, and release its
  // listeners on unmount.
  useEffect(() => {
    categories.start()

    return () => categories.dispose()
  }, [categories])

  return (
    <HilosAdminPage page={PAGE_ADMIN_CATEGORIES} headKind="section">
      <HilosViewportTable
        controller={categories.controller}
        dataId="admin-categories-table"
        cells={{
          [CATEGORY_PICTURE_FIELD]: (row) => (
            <img
              src={row.picture}
              alt=""
              width={36}
              height={27}
              loading="lazy"
              className="d-block rounded object-fit-cover"
            />
          ),
          // The narrow-screen card builds its title from this same cell, so the
          // description shows only where the table row does.
          [CATEGORY_NAME_FIELD]: (row) => (
            <>
              <span className="fw-medium">{row.name}</span>
              <div className="small text-body-secondary d-none d-md-block">
                {row.description}
              </div>
            </>
          ),
          [CATEGORY_PRODUCT_COUNT_FIELD]: (row) => row.productCount,
          [HILOS_TABLE_ACTIONS_KEY]: (row) => (
            <CategoryMoveUpButton row={row} />
          ),
        }}
      />
    </HilosAdminPage>
  )
}
