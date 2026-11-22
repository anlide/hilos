// The shop's Categories admin page: every category in catalog order with its
// picture, description, the number of products it holds, and category reorder
// actions. The table sits under the section head of its own dashboard group;
// its main action opens the Add category dialog, mounted inside the admin page
// so the view mode reaches its submit. Bootstrap classes only
// (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import { HILOS_TABLE_ACTIONS_KEY } from '@hilos/core/base'
import { HilosAdminPage, HilosViewportTable } from '@hilos/react/base'

import { PAGE_ADMIN_CATEGORIES } from '../../pages/keys.js'
import {
  CATEGORY_NAME_FIELD,
  CATEGORY_PICTURE_FIELD,
  CATEGORY_PRODUCT_COUNT_FIELD,
  createCategoriesTable,
} from './adminCategoriesPage.js'
import { CategoryAddModal } from './CategoryAddModal.js'
import { CategoryMoveUpButton } from './CategoryMoveUpButton.js'

/** Whether the Add category dialog is open, and the key of its latest opening. */
interface AddOpening {
  readonly open: boolean
  readonly key: number
}

/** The categories admin page: the live table and its Add category dialog. */
export default function AdminCategories() {
  const [adding, setAdding] = useState<AddOpening>({ open: false, key: 0 })
  // The setter is stable, so the table is made once. Every opening takes a new
  // key: a new dialog window, with no refusal left from the one before.
  const categories = useMemo(
    () =>
      createCategoriesTable(() =>
        setAdding((opening) => ({ open: true, key: opening.key + 1 })),
      ),
    [],
  )

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
      {adding.open ? (
        <CategoryAddModal
          key={adding.key}
          onClose={() => setAdding((opening) => ({ ...opening, open: false }))}
        />
      ) : null}
    </HilosAdminPage>
  )
}
