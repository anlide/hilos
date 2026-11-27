// The shop's Categories admin page: every category in catalog order with its
// picture, description, the number of products it holds, and category reorder
// actions. The table sits under the section head of its own dashboard group;
// its main action opens Add category, the pencil in a row opens Edit category,
// and the trash can opens Delete category on that row; all are mounted inside
// the admin page so the view mode reaches their submit.
// Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import {
  HILOS_TABLE_ACTIONS_KEY,
  hilosTableRowEditSource,
} from '@hilos/core/base'
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
import { CategoryDeleteModal } from './CategoryDeleteModal.js'
import { createCategoryEdit } from './categoryEdit.js'
import { CategoryEditModal } from './CategoryEditModal.js'
import { CategoryRowActions } from './CategoryRowActions.js'
import { type CategoryRow } from './types/tables/CategoryRow.js'

/** Whether the Add category dialog is open, and the key of its latest opening. */
interface AddOpening {
  readonly open: boolean
  readonly key: number
}

/** The categories admin page: the live table, its Add, Edit and Delete category dialogs. */
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

  const deleteSource = useMemo(
    () =>
      hilosTableRowEditSource(categories.controller, (row) => String(row.id)),
    [categories],
  )
  const [deleteKey, setDeleteKey] = useState(0)
  const [deleteInitialRow, setDeleteInitialRow] = useState<CategoryRow | null>(
    null,
  )

  // The edit listens to the live row over the same lifetime as the table.
  useEffect(() => {
    categories.start()
    edit.start()

    return () => {
      deleteSource.close()
      edit.dispose()
      categories.dispose()
    }
  }, [categories, edit, deleteSource])

  function openEdit(id: number): void {
    if (edit.open(String(id))) {
      setEditKey((key) => key + 1)
    }
  }

  function openDelete(row: CategoryRow): void {
    const initial = deleteSource.open(String(row.id))
    if (initial !== null) {
      setDeleteInitialRow(initial)
      setDeleteKey((key) => key + 1)
    }
  }

  function closeDelete(): void {
    deleteSource.close()
    setDeleteInitialRow(null)
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
            <CategoryRowActions
              row={row}
              onEdit={() => openEdit(row.id)}
              onDelete={() => openDelete(row)}
            />
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
      {deleteInitialRow ? (
        <CategoryDeleteModal
          key={deleteKey}
          source={deleteSource}
          initialRow={deleteInitialRow}
          onClose={closeDelete}
        />
      ) : null}
    </HilosAdminPage>
  )
}
