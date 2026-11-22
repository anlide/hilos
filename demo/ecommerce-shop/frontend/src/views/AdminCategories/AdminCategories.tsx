// The shop's Categories admin page: every category in catalog order with its
// picture, description, the number of products it holds, and category reorder
// actions. The table sits under the section head of its own dashboard group;
// its main action opens Add category, and the pencil opens Edit category.
// Both dialogs are mounted inside the admin page so the view mode reaches
// their submit. Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import { HILOS_TABLE_ACTIONS_KEY } from '@hilos/core/base'
import {
  HilosAdminPage,
  HilosViewportTable,
  useSignal,
} from '@hilos/react/base'

import { PAGE_ADMIN_CATEGORIES } from '../../pages/keys.js'
import {
  CATEGORY_NAME_FIELD,
  CATEGORY_PICTURE_FIELD,
  CATEGORY_PRODUCT_COUNT_FIELD,
  createCategoriesTable,
} from './adminCategoriesPage.js'
import { CategoryAddModal } from './CategoryAddModal.js'
import { createCategoryEdit } from './categoryEdit.js'
import { CategoryEditModal } from './CategoryEditModal.js'
import { CategoryMoveUpButton } from './CategoryMoveUpButton.js'

/** Whether the Add category dialog is open, and the key of its latest opening. */
interface AddOpening {
  readonly open: boolean
  readonly key: number
}

/** The live categories table and its Add and Edit category dialogs. */
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
  const edit = useMemo(
    () => createCategoryEdit(categories.controller),
    [categories],
  )
  const editing = useSignal(edit.opened)
  const [editKey, setEditKey] = useState(0)

  // The edit listens to the live row over the same lifetime as the table.
  useEffect(() => {
    categories.start()
    edit.start()

    return () => {
      edit.dispose()
      categories.dispose()
    }
  }, [categories, edit])

  function openEdit(id: number): void {
    if (edit.open(String(id))) {
      setEditKey((key) => key + 1)
    }
  }

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
            <div className="d-inline-flex gap-2">
              <span className="d-none d-md-inline-flex">
                <CategoryMoveUpButton row={row} />
              </span>
              <button
                type="button"
                className="btn btn-sm btn-outline-secondary"
                aria-label={`Edit · ${row.name}`}
                data-id="admin-categories-edit-open"
                onClick={() => openEdit(row.id)}
              >
                <i className="bi bi-pencil" aria-hidden="true" />
              </button>
            </div>
          ),
        }}
      />
      {adding.open ? (
        <CategoryAddModal
          key={adding.key}
          onClose={() => setAdding((opening) => ({ ...opening, open: false }))}
        />
      ) : null}
      {editing ? <CategoryEditModal key={editKey} edit={edit} /> : null}
    </HilosAdminPage>
  )
}
